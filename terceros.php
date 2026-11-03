<?php
require __DIR__ . '/app/bootstrap.php';
$u = requerir_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf($_POST['csrf'] ?? null);
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'marcar_principal') {
        $r = Terceros::actualizar((int)($_POST['id'] ?? 0), true, (string)($_POST['nota'] ?? ''), $u['nombre']);
        flash((isset($r['error']) ? $r['error'] : 'Listo, es el pagador principal.') . (!empty($r['aviso']) ? ' ' . $r['aviso'] : ''),
            isset($r['error']) ? 'error' : (!empty($r['aviso']) ? 'info' : 'ok'));
    } elseif ($accion === 'dar_de_baja') {
        $r = Terceros::desactivar((int)($_POST['id'] ?? 0), $u['nombre']);
        flash((isset($r['error']) ? $r['error'] : 'Vínculo dado de baja.') . (!empty($r['aviso']) ? ' ' . $r['aviso'] : ''),
            isset($r['error']) ? 'error' : (!empty($r['aviso']) ? 'info' : 'ok'));
    } elseif ($accion === 'quitar_asignacion') {
        $r = Terceros::quitarAsignacionMovimiento((int)($_POST['movimiento_id'] ?? 0));
        flash((isset($r['error']) ? $r['error'] : 'Se quitó la asignación.') . (!empty($r['aviso']) ? ' ' . $r['aviso'] : ''),
            isset($r['error']) ? 'error' : (!empty($r['aviso']) ? 'info' : 'ok'));
    } elseif ($accion === 'crear') {
        $r = Terceros::crear(
            (int)($_POST['dni_tercero'] ?? 0),
            (int)($_POST['dni_cliente_sas'] ?? 0),
            false,
            (string)($_POST['cuit_tercero'] ?? '') !== '' ? (string)$_POST['cuit_tercero'] : null,
            (string)($_POST['nombre_tercero'] ?? '') !== '' ? (string)$_POST['nombre_tercero'] : null,
            (string)($_POST['nota'] ?? ''),
            $u['nombre']
        );
        flash((isset($r['error']) ? $r['error'] : 'Vínculo creado.') . (!empty($r['aviso']) ? ' ' . $r['aviso'] : ''),
            isset($r['error']) ? 'error' : (!empty($r['aviso']) ? 'info' : 'ok'));
    } elseif ($accion === 'reconciliar') {
        $inicio = microtime(true);
        try {
            Controles::reconciliarTodo();
            $ms = (int)round((microtime(true) - $inicio) * 1000);
            flash("Listo, se volvió a cruzar todo ($ms ms).");
        } catch (Throwable $e) {
            flash('No se pudo recruzar: ' . $e->getMessage(), 'error');
        }
    }
    header('Location: terceros.php' . (($_GET['ver'] ?? '') === 'baja' ? '?ver=baja' : ''));
    exit;
}

$soloActivos = ($_GET['ver'] ?? '') !== 'baja';
$vinculos = Terceros::listar($soloActivos ? true : false);

$sasClientes = Sas::nombres(array_column($vinculos, 'dni_cliente_sas'));
$sasPagadores = Sas::activo() ? Sas::nombres(array_column($vinculos, 'dni_tercero')) : [];

$grupos = [];
foreach ($vinculos as $v) {
    $dniCli = (int)$v['dni_cliente_sas'];
    $grupos[$dniCli] ??= ['dni' => $dniCli, 'nombre' => $sasClientes[$dniCli] ?? null, 'items' => [], 'movs' => 0, 'total' => 0.0];
    $grupos[$dniCli]['items'][] = $v;
    $grupos[$dniCli]['movs'] += (int)$v['movs'];
    $grupos[$dniCli]['total'] += (float)$v['total_movs'];
}
uasort($grupos, fn($a, $b) => strcmp((string)$a['nombre'], (string)$b['nombre']));

$asignaciones = Terceros::asignacionesListar();
$sasAsignados = Sas::nombres(array_column($asignaciones, 'dni_cliente_sas'));

$cantActivos = (int)db()->query('SELECT COUNT(*) FROM pagadores_terceros WHERE activo = 1')->fetchColumn();
$cantBaja = (int)db()->query('SELECT COUNT(*) FROM pagadores_terceros WHERE activo IS NULL')->fetchColumn();

$hoja = ['nombre' => $soloActivos ? 'Vigentes' : 'Dados de baja', 'columnas' => [
    ['Cliente', 't'], ['DNI cliente', 'n'], ['Pagador', 't'], ['DNI pagador', 'n'], ['CUIL pagador', 't'],
    ['Principal', 't'], ['Nota', 't'], ['Transferencias', 'n'], ['Total', 'm'], ['Creado por', 't'], ['Creado', 'f'],
], 'filas' => array_map(fn($v) => [
    $sasClientes[(int)$v['dni_cliente_sas']] ?? '', (int)$v['dni_cliente_sas'],
    $v['nombre_tercero'], (int)$v['dni_tercero'], $v['cuit_tercero'] ? formato_cuit($v['cuit_tercero']) : '',
    $v['es_principal'] ? 'Sí' : 'No', $v['nota'], (int)$v['movs'], (float)$v['total_movs'], $v['creado_por'], $v['creado_en'],
], $vinculos)];

encabezado('Pagadores terceros', 'terceros');
?>
<header class="cabecera">
  <div>
    <h1>Pagadores terceros</h1>
    <p>Clientes que pagan desde la cuenta de otra persona (un familiar, alguien que les hace el trámite). Vinculá al pagador una vez y las próximas transferencias se van a acreditar solas.</p>
  </div>
  <div class="fila" style="gap: 8px">
    <?php if ($vinculos) { boton_excel('terceros-' . ($soloActivos ? 'vigentes-' : 'baja-') . date('Y-m-d'), [$hoja]); } ?>
    <form method="post" style="margin: 0">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="reconciliar">
      <button type="submit" class="boton boton-sec boton-chico"><?= icono('actualizar') ?>Volver a cruzar todo</button>
    </form>
  </div>
</header>

<nav class="chips" aria-label="Estado">
  <a class="chip<?= $soloActivos ? ' activo' : '' ?>" href="terceros.php"<?= $soloActivos ? ' aria-current="true"' : '' ?>>Vigentes <span class="mono"><?= $cantActivos ?></span></a>
  <a class="chip<?= !$soloActivos ? ' activo' : '' ?>" href="terceros.php?ver=baja"<?= !$soloActivos ? ' aria-current="true"' : '' ?>>Dados de baja <span class="mono"><?= $cantBaja ?></span></a>
</nav>

<?php if (!$grupos): ?>
  <div class="tarjeta vacio"><?= $soloActivos ? 'Todavía no hay pagadores terceros vinculados.' : 'No hay vínculos dados de baja.' ?></div>
<?php endif; ?>

<?php foreach ($grupos as $g): ?>
  <section class="tarjeta tarjeta-plana">
    <div class="grupo-cabecera">
      <div>
        <span class="iniciales" aria-hidden="true"><?= h(iniciales($g['nombre'] ?: 'DNI')) ?></span>
        <div>
          <strong><?= $g['nombre'] ? h($g['nombre']) : '<span class="rojo">DNI ' . formato_dni($g['dni']) . ' — no encontrado en SAS</span>' ?></strong>
          <span class="chico"><a href="buscar.php?q=<?= $g['dni'] ?>">Ver ficha →</a> · <span class="mono"><?= $g['movs'] ?></span> transferencias · <span class="mono"><?= dinero($g['total']) ?></span></span>
        </div>
      </div>
    </div>

    <?php foreach ($g['items'] as $v): $dniTerc = (int)$v['dni_tercero']; ?>
      <div class="pend">
        <div class="pend-fila">
          <div class="pend-quien">
            <a class="titular" href="buscar.php?q=<?= $dniTerc ?>"><?= h($v['nombre_tercero'] ?: 'Nombre no informado') ?></a>
            <span class="chico">DNI <span class="mono"><?= formato_dni($dniTerc) ?></span><?= $v['cuit_tercero'] ? ' · CUIL <span class="mono">' . h(formato_cuit($v['cuit_tercero'])) . '</span>' : '' ?></span>
          </div>
          <div class="pend-cuando">
            <?php if ($v['es_principal']): ?><span class="chico" style="font-weight: 700">★ Principal</span><?php endif; ?>
            <?php if (!$soloActivos): ?><span class="chico">de baja: <?= h($v['desactivado_por']) ?>, <?= h(fecha_ar($v['desactivado_en'])) ?></span><?php endif; ?>
          </div>
          <span class="pend-monto"><span class="mono"><?= (int)$v['movs'] ?></span> · <?= dinero($v['total_movs']) ?></span>
        </div>
        <?php if ($v['nota']): ?><div class="sub"><?= h($v['nota']) ?></div><?php endif; ?>
        <div class="sub">Creado por <?= h($v['creado_por']) ?>, <?= h(fecha_ar($v['creado_en'])) ?></div>
        <?php if ($soloActivos && !empty($sasPagadores[$dniTerc])): ?>
          <p class="nota-espera"><?= icono('alerta') ?><span><strong>Ojo:</strong> este DNI también es cliente de SAS Imperio (<?= h($sasPagadores[$dniTerc]) ?>).</span></p>
        <?php endif; ?>
        <?php if ($soloActivos): ?>
          <div class="form-botones" style="justify-content: flex-start">
            <?php if (!$v['es_principal']): ?>
              <form method="post" style="margin: 0">
                <?= csrf_campo() ?><input type="hidden" name="accion" value="marcar_principal">
                <input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><input type="hidden" name="nota" value="<?= h($v['nota'] ?? '') ?>">
                <button type="submit" class="boton boton-sec boton-chico">Marcar principal</button>
              </form>
            <?php endif; ?>
            <form method="post" style="margin: 0">
              <?= csrf_campo() ?><input type="hidden" name="accion" value="dar_de_baja"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
              <button type="submit" class="boton boton-sec boton-chico">Dar de baja</button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>
<?php endforeach; ?>

<?php if ($asignaciones): ?>
  <section class="tarjeta tarjeta-plana">
    <div class="grupo-cabecera">
      <div>
        <span class="iniciales" aria-hidden="true"><?= icono('billete') ?></span>
        <div>
          <strong>Transferencias asignadas a mano</strong>
          <span class="chico">Un pago puntual, sin crear un vínculo permanente con el pagador.</span>
        </div>
      </div>
    </div>
    <?php foreach ($asignaciones as $a): $dniCli = (int)$a['dni_cliente_sas']; ?>
      <div class="pend">
        <div class="pend-fila">
          <div class="pend-quien">
            <a class="titular" href="buscar.php?q=<?= (int)$a['dni'] ?>"><?= h($a['nombre_banco'] ?: 'Nombre no informado') ?></a>
            <span class="chico">→ <?= h($sasAsignados[$dniCli] ?? ('DNI ' . formato_dni($dniCli))) ?> · <span class="mono"><?= h(fecha_ar($a['fecha'])) ?></span></span>
          </div>
          <span class="pend-monto"><?= dinero($a['monto']) ?></span>
        </div>
        <?php if ($a['nota']): ?><div class="sub"><?= h($a['nota']) ?></div><?php endif; ?>
        <div class="sub">Asignado por <?= h($a['asignado_por']) ?>, <?= h(fecha_ar($a['asignado_en'])) ?></div>
        <div class="form-botones" style="justify-content: flex-start">
          <form method="post" style="margin: 0">
            <?= csrf_campo() ?><input type="hidden" name="accion" value="quitar_asignacion">
            <input type="hidden" name="movimiento_id" value="<?= (int)$a['movimiento_id'] ?>">
            <button type="submit" class="boton boton-sec boton-chico">Quitar asignación</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </section>
<?php endif; ?>

<?php if ($soloActivos): ?>
  <details class="resolver">
    <summary class="boton boton-sec boton-chico" style="align-self: flex-start">Vincular un pagador nuevo</summary>
    <form class="form-resolver" method="post">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="crear">
      <div class="comparar">
        <label class="campo">DNI de quien pagó (el pagador)
          <input type="text" inputmode="numeric" name="dni_tercero" placeholder="Ej: 27333444">
        </label>
        <label class="campo">DNI del cliente en SAS Imperio
          <input type="text" inputmode="numeric" name="dni_cliente_sas" placeholder="Ej: 30111222">
        </label>
      </div>
      <div class="comparar">
        <label class="campo">CUIL de quien pagó (opcional)
          <input type="text" name="cuit_tercero" placeholder="Ej: 27333444409">
        </label>
        <label class="campo">Nombre de quien pagó, según el banco (opcional)
          <input type="text" name="nombre_tercero" placeholder="Ej: ANA TERCERA">
        </label>
      </div>
      <label class="campo">Nota (opcional)
        <textarea name="nota" rows="2" maxlength="300" placeholder="Ej: es la hija del cliente"></textarea>
      </label>
      <div class="form-botones">
        <button type="button" class="boton boton-sec cancelar">Cancelar</button>
        <button type="submit" class="boton">Vincular</button>
      </div>
    </form>
  </details>
<?php endif; ?>

<script>
(function () {
  document.querySelectorAll('.cancelar').forEach(function (b) {
    b.addEventListener('click', function () { b.closest('details').open = false; });
  });
})();
</script>
<?php pie();
