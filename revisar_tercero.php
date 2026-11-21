<?php
require __DIR__ . '/app/bootstrap.php';
$u = requerir_login();

$id = (int)($_REQUEST['id'] ?? 0);
$dniPagador = (int)($_REQUEST['dni'] ?? 0);
$dniClienteVinculo = (int)($_REQUEST['cliente'] ?? 0);

// Ayuda para volver con un mensaje
$avisar = function (array $r, string $ok): void {
    flash((isset($r['error']) ? $r['error'] : $ok) . (!empty($r['aviso']) ? ' ' . $r['aviso'] : ''),
        isset($r['error']) ? 'error' : (!empty($r['aviso']) ? 'info' : 'ok'));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf($_POST['csrf'] ?? null);
    $accion = $_POST['accion'] ?? '';
    $aqui = 'revisar_tercero.php?id=' . $id;

    if ($accion === 'cambiar_cliente') {
        $r = Terceros::cambiarCliente($id, (int)($_POST['dni_cliente'] ?? 0), (string)($_POST['nota'] ?? ''), $u['nombre']);
        $avisar($r, 'Listo: el vínculo ahora es con el cliente correcto y los pagos de este pagador ya se le atribuyen a él.');
        $aqui = isset($r['id']) ? 'revisar_tercero.php?id=' . (int)$r['id'] : $aqui . '&dni_cliente=' . (int)($_POST['dni_cliente'] ?? 0);
    } elseif ($accion === 'dar_de_baja') {
        $r = Terceros::desactivar($id, $u['nombre']);
        $avisar($r, 'Vínculo dado de baja: los pagos de este pagador ya no se atribuyen a ese cliente.');
        $aqui = isset($r['error']) ? $aqui : 'terceros.php';
    } elseif ($accion === 'sacar') {
        $r = Terceros::sacarDelVinculo((int)($_POST['mov'] ?? 0), (string)($_POST['nota'] ?? ''), $u['nombre']);
        $avisar($r, 'Listo: esa transferencia quedó a nombre de quien la hizo y no se acredita al cliente del vínculo.');
    } elseif ($accion === 'volver_a_incluir') {
        $r = Terceros::volverAIncluir((int)($_POST['mov'] ?? 0));
        $avisar($r, 'Listo: esa transferencia vuelve a seguir el vínculo.');
    }
    header('Location: ' . $aqui . '#transferencias');
    exit;
}

// ─── Qué vínculo se revisa ────────────────────────────────────────
if (!$id) {
    $candidatos = $dniPagador ? Terceros::porTercero($dniPagador) : ($dniClienteVinculo ? Terceros::porCliente($dniClienteVinculo) : []);
    if (count($candidatos) === 1) {
        header('Location: revisar_tercero.php?id=' . (int)$candidatos[0]['id']);
        exit;
    }
    if (!$candidatos) {
        flash('No hay vínculos vigentes para revisar.', 'info');
        header('Location: terceros.php');
        exit;
    }
    // Varios: se elige uno
    $nombres = Sas::nombres(array_merge(array_column($candidatos, 'dni_cliente_sas'), array_column($candidatos, 'dni_tercero')));
    $estados = VerificacionTerceros::estados($candidatos);
    encabezado('Revisar vínculo', 'terceros');
    ?>
    <header class="cabecera"><div>
      <nav class="ruta" aria-label="Ruta"><a href="terceros.php">Pagadores terceros</a><span aria-hidden="true">/</span><span>Revisar vínculo</span></nav>
      <h1>¿Qué vínculo querés revisar?</h1>
      <p>Hay más de un vínculo cargado. Elegí cuál.</p>
    </div></header>
    <section class="tarjeta tarjeta-plana">
      <?php foreach ($candidatos as $c): ?>
        <a class="pend" style="text-decoration: none; display: block" href="revisar_tercero.php?id=<?= (int)$c['id'] ?>">
          <div class="pend-fila">
            <div class="pend-quien">
              <strong class="titular"><?= h($c['nombre_tercero'] ?: ($nombres[(int)$c['dni_tercero']] ?? 'Pagador DNI ' . formato_dni((int)$c['dni_tercero']))) ?>
                → <?= h($nombres[(int)$c['dni_cliente_sas']] ?? 'DNI ' . formato_dni((int)$c['dni_cliente_sas'])) ?></strong>
              <span class="chico">Cargado por <?= h($c['creado_por']) ?>, <?= h(fecha_ar($c['creado_en'])) ?></span>
            </div>
            <div class="pend-cuando"><?= etiqueta_vinculo($estados[(int)$c['id']] ?? 'sin_datos') ?></div>
          </div>
        </a>
      <?php endforeach; ?>
    </section>
    <?php
    pie();
    exit;
}

$datos = VerificacionTerceros::cargar($id);
if (!$datos) {
    flash('No encontré ese vínculo.', 'error');
    header('Location: terceros.php');
    exit;
}
$v = $datos['vinculo'];
$vigente = $v['activo'] !== null;
$eval = VerificacionTerceros::evaluar($datos);
$dniTercero = $datos['dni_tercero'];
$dniCliente = $datos['dni_cliente'];
$nombreCliente = $datos['nombre_cliente'];

// Cliente SAS de lo asignado a mano a otro cliente (se muestra en la tabla)
$nombresExtra = Sas::nombres(array_filter(array_unique(array_merge(
    array_map(fn($t) => $t['dni_efectivo'], $eval['transferencias']),
    array_map(fn($t) => $t['cupon']['dni'] ?? null, $eval['transferencias'])
))));
$nombresExtra[$dniCliente] = $nombreCliente ?? ($nombresExtra[$dniCliente] ?? null);

// Segundo paso del cambio de cliente (sin JavaScript: buscar → confirmar)
$dniNuevo = (int)($_GET['dni_cliente'] ?? 0);
$nombreNuevo = null;
$errorNuevo = null;
if ($dniNuevo) {
    if ($dniNuevo === $dniCliente) {
        $errorNuevo = 'Ese ya es el cliente de este vínculo.';
    } elseif ($dniNuevo === $dniTercero) {
        $errorNuevo = 'Es el mismo DNI de quien pagó: no hace falta vincularlo.';
    } elseif (!Sas::activo()) {
        $errorNuevo = 'SAS Imperio no está disponible ahora, así que no se puede comprobar el DNI. Probá más tarde.';
    } else {
        $nombreNuevo = Sas::nombres([$dniNuevo])[$dniNuevo] ?? null;
        $errorNuevo = $nombreNuevo ? null : 'No encontré ese DNI entre los clientes de SAS Imperio.';
    }
}

$claseTexto = [
    'coincide' => ['Coincide con el cliente', 'ok'],
    'propio' => ['Cupón del propio pagador', 'espera'],
    'ajeno' => ['Cupón de otra persona', 'revisar'],
    'sin_cupon' => ['Sin cupón', 'sincupon'],
    'excluida' => ['Sacada del vínculo', 'omitido'],
];
$totalMonto = array_sum(array_map(fn($t) => $t['excluida'] ? 0 : $t['monto'], $eval['transferencias']));

encabezado('Revisar vínculo', 'terceros');
?>
<header class="cabecera">
  <div>
    <nav class="ruta" aria-label="Ruta"><a href="terceros.php">Pagadores terceros</a><span aria-hidden="true">/</span><span>Revisar vínculo</span></nav>
    <h1>¿Este pagador está vinculado al cliente correcto?</h1>
    <p>Acá se junta lo que ya sabe el sistema (cupones y transferencias) para que veas si el vínculo tiene sentido y, si no, lo corrijas.</p>
  </div>
</header>

<?php if (!$vigente): ?>
  <div class="aviso aviso-info" role="status"><?= icono('calendario') ?><span>Este vínculo está <strong>dado de baja</strong> (<?= h($v['desactivado_por']) ?>, <?= h(fecha_ar($v['desactivado_en'])) ?>). Se muestra solo como consulta.</span></div>
<?php endif; ?>

<section class="tarjeta" style="gap: 18px">
  <div class="comparar">
    <div>
      <h2>Quien paga</h2>
      <dl class="datos">
        <dt>Nombre</dt><dd><?= h($datos['nombre_pagador'] ?: 'El banco no informa el nombre') ?></dd>
        <dt>DNI</dt><dd class="mono"><?= link_persona($dniTercero, formato_dni($dniTercero), 'mono') ?></dd>
        <?php if ($v['cuit_tercero']): ?><dt>CUIL</dt><dd class="mono"><?= h(formato_cuit($v['cuit_tercero'])) ?></dd><?php endif; ?>
        <?php if ($datos['pagador_es_cliente']): ?><dt>En SAS</dt><dd><span class="tercero">También es cliente de SAS</span></dd><?php endif; ?>
      </dl>
    </div>
    <div>
      <h2>Vinculado al cliente</h2>
      <dl class="datos">
        <dt>Nombre</dt><dd><?= h($nombreCliente ?: 'No encontrado en SAS') ?></dd>
        <dt>DNI</dt><dd class="mono"><?= link_persona($dniCliente, formato_dni($dniCliente), 'mono') ?></dd>
        <dt>Cargado</dt><dd><?= h($v['creado_por']) ?>, <?= h(fecha_ar($v['creado_en'])) ?></dd>
        <?php if ($v['nota']): ?><dt>Nota</dt><dd><?= h($v['nota']) ?></dd><?php endif; ?>
      </dl>
    </div>
  </div>

  <div style="display: flex; flex-direction: column; gap: 10px">
    <div class="fila entre">
      <span class="rotulo">Qué dice la evidencia</span>
      <?= etiqueta_vinculo($eval['estado']) ?>
    </div>
    <p class="sub" style="margin: 0"><?= h($eval['resumen']) ?></p>
    <?php foreach ($eval['senales'] as $s): ?>
      <div class="fila entre" style="background: var(--superficie-2); border: 1px solid var(--borde); border-radius: 10px; padding: 10px 14px; align-items: flex-start">
        <div>
          <strong><?= h($s['titulo']) ?></strong><?= $s['debil'] ? ' <span class="chico">(pista)</span>' : '' ?>
          <div class="sub"><?= h($s['texto']) ?></div>
        </div>
        <?= etiqueta_vinculo($s['estado']) ?>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="tarjeta" style="gap: 12px" id="transferencias">
  <div class="fila entre">
    <div>
      <h2 style="margin: 0">Transferencias de este pagador</h2>
      <span class="sub"><?= count($eval['transferencias']) ?> (las últimas 200) · <span class="mono"><?= dinero($totalMonto) ?></span> se acreditan por este vínculo</span>
    </div>
  </div>
  <?php if (!$eval['transferencias']): ?>
    <div class="vacio">Este pagador todavía no tiene transferencias.</div>
  <?php else: ?>
    <div class="tabla-caja">
      <table class="tabla tabla-tarjetas" style="min-width: 860px">
        <thead><tr>
          <th scope="col">Fecha</th><th scope="col">Nombre en el banco</th><th scope="col">Cupón cruzado</th>
          <th scope="col">Se acredita a</th><th scope="col" class="der">Monto</th><th scope="col"><span class="oculto">Acciones</span></th>
        </tr></thead>
        <tbody>
        <?php foreach ($eval['transferencias'] as $t): [$txt, $cls] = $claseTexto[$t['clase']]; $c = $t['cupon']; ?>
          <tr>
            <td class="mono" style="font-size: 13px"><?= h(fecha_ar($t['fecha'])) ?></td>
            <td><?= h($t['nombre'] ?: 'Nombre no informado') ?></td>
            <td>
              <span class="etiqueta e-<?= $cls ?>"><?= h($txt) ?></span>
              <?php if ($c): ?>
                <div style="margin-top: 4px"><?= h($c['titular'] ?: 'Titular no leído') ?></div>
                <div class="sub"><?= h(trim(($c['zona'] ?? '') . ' · ' . ($c['cobrador'] ?? ''), ' ·')) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php $efectivo = $t['dni_efectivo']; ?>
              <?= h(($efectivo && !empty($nombresExtra[$efectivo])) ? $nombresExtra[$efectivo] : ($efectivo ? 'DNI ' . formato_dni($efectivo) : '—')) ?>
              <?php if ($t['excluida']): ?><div class="sub">a nombre de quien la hizo</div>
              <?php elseif ($t['asignado_a']): ?><div class="sub">asignada a mano</div><?php endif; ?>
            </td>
            <td class="monto-fila"><?= dinero($t['monto']) ?></td>
            <td class="acciones">
              <?php if ($vigente): ?>
                <form method="post" style="margin: 0">
                  <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="mov" value="<?= (int)$t['id'] ?>">
                  <?php if ($t['excluida'] || $t['asignado_a']): ?>
                    <button type="submit" name="accion" value="volver_a_incluir" class="boton boton-sec boton-chico">Volver a incluir</button>
                  <?php else: ?>
                    <button type="submit" name="accion" value="sacar" class="boton boton-sec boton-chico" title="Queda a nombre de quien la hizo, sin tocar el resto de sus pagos">Sacar del vínculo</button>
                  <?php endif; ?>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php if ($vigente): ?>
<section class="tarjeta" style="gap: 14px" id="corregir">
  <h2 style="margin: 0">Corregir</h2>
  <p class="nota-espera"><?= icono('alerta') ?><span>Si SAS Imperio ya acreditó estos pagos al cliente anterior, hay que corregirlo también allá: acá solo cambia a quién se atribuyen de ahora en más (la API se lee en vivo, pero lo ya acreditado no se revierte solo).</span></p>

  <?php if ($nombreNuevo !== null): ?>
    <form method="post" class="form-resolver">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="cambiar_cliente"><input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="dni_cliente" value="<?= $dniNuevo ?>">
      <p style="margin: 0">El pagador pasa a estar vinculado a:</p>
      <?= cliente_sas($dniNuevo, [$dniNuevo => $nombreNuevo]) ?>
      <p class="sub" style="margin: 0">Todos los pagos de este pagador (<?= count($eval['transferencias']) ?> cargados y los que lleguen) se le atribuyen a él. El vínculo con <?= h($nombreCliente ?: 'DNI ' . formato_dni($dniCliente)) ?> queda en "Dados de baja" con el detalle de la corrección.</p>
      <label class="campo">Nota (opcional)
        <textarea name="nota" rows="2" maxlength="300" placeholder="Ej: lo había cargado al hermano"></textarea>
      </label>
      <div class="form-botones">
        <a class="boton boton-sec" href="revisar_tercero.php?id=<?= $id ?>#corregir">Cancelar</a>
        <button type="submit" class="boton">Sí, cambiar de cliente</button>
      </div>
    </form>
  <?php else: ?>
    <?php if ($errorNuevo): ?><div class="aviso aviso-error" role="alert"><?= icono('alerta') ?><span><?= h($errorNuevo) ?></span></div><?php endif; ?>
    <form method="get" class="form-resolver">
      <input type="hidden" name="id" value="<?= $id ?>">
      <label class="campo">Cambiar al cliente correcto: DNI en SAS Imperio
        <input type="text" inputmode="numeric" name="dni_cliente" value="<?= $dniNuevo ?: '' ?>" placeholder="Ej: 30111222">
      </label>
      <div class="form-botones"><button type="submit" class="boton">Buscar cliente</button></div>
    </form>
  <?php endif; ?>

  <form method="post" class="form-botones" style="justify-content: flex-start">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="dar_de_baja"><input type="hidden" name="id" value="<?= $id ?>">
    <button type="submit" class="boton boton-sec" style="white-space: normal; text-align: left">Dar de baja el vínculo (no corresponde a ningún cliente)</button>
  </form>
</section>
<?php endif; ?>
<?php pie();
