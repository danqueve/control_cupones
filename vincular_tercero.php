<?php
require __DIR__ . '/app/bootstrap.php';
$u = requerir_login();

$movId = (int)($_REQUEST['mov'] ?? 0);
$dniParam = (int)($_REQUEST['dni'] ?? 0);
$cuponId = (int)($_REQUEST['cupon'] ?? 0);
$controlId = (int)($_REQUEST['control'] ?? 0);
$f = (string)($_REQUEST['f'] ?? '');
$volverParam = (string)($_REQUEST['volver'] ?? '');
$dniClienteParam = (int)($_REQUEST['dni_cliente'] ?? 0);

// Modo "cupon": el cliente ya se conoce (es el dueño del cupón), falta elegir quién pagó.
$cupon = null;
if ($cuponId) {
    $st = db()->prepare('SELECT * FROM cupones WHERE id = ?');
    $st->execute([$cuponId]);
    $cupon = $st->fetch();
    if (!$cupon || !$cupon['dni']) {
        flash(!$cupon ? 'No encontré ese cupón.' : 'Ese cupón no tiene un DNI de cliente: no se puede vincular.', 'error');
        header('Location: pendientes.php');
        exit;
    }
    $dniClienteParam = (int)$cupon['dni'];
}
$esperandoCandidato = $cuponId > 0 && $movId === 0;

$mov = null;
if ($movId) {
    $st = db()->prepare('SELECT * FROM movimientos WHERE id = ?');
    $st->execute([$movId]);
    $mov = $st->fetch();
}
$dniTercero = $mov ? ($mov['dni'] ? (int)$mov['dni'] : 0) : $dniParam;

if (!$esperandoCandidato && (($movId && !$mov) || !$dniTercero)) {
    flash($movId && !$mov ? 'No encontré esa transferencia.' : 'Esa transferencia no tiene un DNI para vincular.', 'error');
    header('Location: ' . ($controlId ? 'control.php?id=' . $controlId . '&f=' . urlencode($f ?: 'sin_cupon') . '#lista'
        : ($cuponId ? 'pendientes.php' : 'index.php')));
    exit;
}

// Solo se admiten rutas propias de la aplicación como "volver"
$volverValido = fn($v) => preg_match('#^[a-z_]+\.php(\?[\w=&%.\-+#]*)?$#', $v) === 1;
$volver = $volverParam !== '' && $volverValido($volverParam) ? $volverParam
    : ($controlId ? 'control.php?id=' . $controlId . '&f=' . urlencode($f ?: 'sin_cupon') . '#lista'
        : ($cuponId ? 'pendientes.php' : 'buscar.php?q=' . $dniTercero));

$paramsBase = ['mov' => $movId ?: null, 'dni' => ($movId || $cuponId) ? null : $dniTercero, 'cupon' => $cuponId ?: null,
    'control' => $controlId ?: null, 'f' => $f !== '' ? $f : null, 'volver' => $volverParam !== '' ? $volverParam : null];
$aquiSinCliente = 'vincular_tercero.php?' . http_build_query(array_filter($paramsBase, fn($v) => $v !== null));
$aqui = 'vincular_tercero.php?' . http_build_query(array_filter($paramsBase + ['dni_cliente' => $dniClienteParam ?: null], fn($v) => $v !== null));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf($_POST['csrf'] ?? null);
    $accion = $_POST['accion'] ?? '';
    $volverAqui = (string)($_POST['aqui'] ?? $aqui);

    if ($accion === 'quitar_vinculo') {
        $r = Terceros::desactivar((int)($_POST['id'] ?? 0), $u['nombre']);
        flash((isset($r['error']) ? $r['error'] : 'Vínculo dado de baja.') . (!empty($r['aviso']) ? ' ' . $r['aviso'] : ''),
            isset($r['error']) ? 'error' : (!empty($r['aviso']) ? 'info' : 'ok'));
        header('Location: ' . $volverAqui);
        exit;
    }

    if ($accion === 'quitar_asignacion') {
        $r = Terceros::quitarAsignacionMovimiento($movId);
        flash((isset($r['error']) ? $r['error'] : 'Se quitó la asignación.') . (!empty($r['aviso']) ? ' ' . $r['aviso'] : ''),
            isset($r['error']) ? 'error' : (!empty($r['aviso']) ? 'info' : 'ok'));
        header('Location: ' . $volverAqui);
        exit;
    }

    if ($accion === 'vincular') {
        $dniCliente = (int)($_POST['dni_cliente'] ?? 0);
        $nota = (string)($_POST['nota'] ?? '');
        $crear = ($_POST['crear_vinculo'] ?? '') === '1';
        $asignar = $movId > 0 && ($_POST['asignar_mov'] ?? '') === '1';
        $esPrincipal = ($_POST['es_principal'] ?? '') === '1';
        $volverFinal = (string)($_POST['volver_final'] ?? $volver);

        if (!$dniCliente || (!$crear && !$asignar)) {
            flash(!$dniCliente ? 'Elegí un cliente antes de confirmar.'
                : 'Elegí qué hacer: vincular al pagador, asignar la transferencia, o las dos cosas.', 'error');
            header('Location: ' . $volverAqui);
            exit;
        }

        $textos = [];
        $hayError = false;
        $hayAviso = false;
        if ($crear) {
            $snap = db()->prepare('SELECT cuit, nombre FROM movimientos WHERE dni = ? AND (cuit IS NOT NULL OR nombre IS NOT NULL)
                                   ORDER BY fecha DESC LIMIT 1');
            $snap->execute([$dniTercero]);
            $snapRow = $snap->fetch() ?: ['cuit' => null, 'nombre' => null];
            $r = Terceros::crear($dniTercero, $dniCliente, $esPrincipal, $snapRow['cuit'], $snapRow['nombre'], $nota, $u['nombre']);
            if (isset($r['error'])) {
                $hayError = true;
                $textos[] = $r['error'];
            } else {
                $textos[] = 'Vínculo creado: las próximas transferencias de este pagador van a este cliente.';
                if (!empty($r['aviso'])) {
                    $hayAviso = true;
                    $textos[] = $r['aviso'];
                }
            }
        }
        if ($asignar) {
            $r = Terceros::asignarMovimiento($movId, $dniCliente, $nota, $u['nombre']);
            // Si ya se cruzó con un cupón (por ejemplo porque el vínculo recién creado hizo que el
            // cruce la resolviera sola), no es un error: ya se sabe de quién es esta transferencia.
            if (isset($r['error']) && str_contains($r['error'], 'ya está cruzada con un cupón')) {
                $textos[] = 'La transferencia se terminó cruzando sola con el cupón del cliente.';
            } elseif (isset($r['error'])) {
                $hayError = true;
                $textos[] = $r['error'];
            } else {
                $textos[] = 'Transferencia asignada a este cliente.';
                if (!empty($r['aviso'])) {
                    $hayAviso = true;
                    $textos[] = $r['aviso'];
                }
            }
        }
        flash(implode(' ', $textos), $hayError ? 'error' : ($hayAviso ? 'info' : 'ok'));
        header('Location: ' . $volverFinal);
        exit;
    }
}

// ─── Datos para mostrar ───────────────────────────────────────────

$transferencias = db()->prepare('SELECT fecha, monto, nombre, cuit FROM movimientos WHERE dni = ? ORDER BY fecha DESC LIMIT 8');
$transferencias->execute([$dniTercero]);
$transferencias = $transferencias->fetchAll();

$ultimoNombre = null;
$ultimoCuit = null;
foreach ($transferencias as $t) {
    $ultimoNombre = $ultimoNombre ?: $t['nombre'];
    $ultimoCuit = $ultimoCuit ?: $t['cuit'];
}

$vinculosExistentes = Terceros::porTercero($dniTercero);
$sasClientesExistentes = Sas::nombres(array_column($vinculosExistentes, 'dni_cliente_sas'));
$asignacionExistente = $movId ? Terceros::asignacion($movId) : null;
$clienteAsignado = $asignacionExistente
    ? (Sas::nombres([(int)$asignacionExistente['dni_cliente_sas']])[(int)$asignacionExistente['dni_cliente_sas']] ?? null) : null;
$pagadorEsCliente = Sas::activo() ? (Sas::nombres([$dniTercero])[$dniTercero] ?? null) : null;

$errorBusqueda = null;
$nombreCliente = null;
if ($dniClienteParam) {
    if ($dniClienteParam === $dniTercero) {
        $errorBusqueda = 'Es el mismo DNI del pagador: no hace falta vincular nada.';
    } elseif (!Sas::activo()) {
        $errorBusqueda = 'SAS Imperio no está disponible ahora, así que no se puede comprobar el DNI. Probá más tarde.';
    } else {
        $nombreCliente = Sas::nombres([$dniClienteParam])[$dniClienteParam] ?? null;
        if (!$nombreCliente) {
            $errorBusqueda = 'No encontré ese DNI entre los clientes de SAS Imperio.';
        }
    }
}
$paso2 = $dniClienteParam > 0 && $nombreCliente !== null;

$yaVinculadoEsteCliente = false;
foreach ($vinculosExistentes as $v) {
    if ((int)$v['dni_cliente_sas'] === $dniClienteParam) {
        $yaVinculadoEsteCliente = true;
        break;
    }
}
$yaAsignadoEsteCliente = $asignacionExistente && (int)$asignacionExistente['dni_cliente_sas'] === $dniClienteParam;
$puedeAsignar = $movId && !($mov['cupon_id'] ?? null);
$nadaQueHacer = $paso2 && $yaVinculadoEsteCliente && (!$puedeAsignar || $yaAsignadoEsteCliente);

// Modo "cupon" sin pagador elegido todavía: transferencias sin cupón parecidas en fecha y monto
$candidatos = [];
if ($esperandoCandidato && $cupon['fecha_cupon']) {
    $st = db()->prepare("SELECT id, fecha, nombre, cuit, dni, monto FROM movimientos
                         WHERE cupon_id IS NULL AND dni IS NOT NULL
                           AND fecha BETWEEN DATE_SUB(?, INTERVAL 10 DAY) AND DATE_ADD(?, INTERVAL 10 DAY)
                         ORDER BY ABS(monto - ?), ABS(DATEDIFF(fecha, ?))
                         LIMIT 20");
    $st->execute([$cupon['fecha_cupon'], $cupon['fecha_cupon'], (float)$cupon['monto'], $cupon['fecha_cupon']]);
    $candidatos = $st->fetchAll();
}

encabezado('Vincular pagador', 'terceros');
?>
<header class="cabecera">
  <div>
    <nav class="ruta" aria-label="Ruta">
      <?php if ($controlId): ?><a href="control.php?id=<?= $controlId ?>">Control <span class="mono">#<?= $controlId ?></span></a><span aria-hidden="true">/</span>
      <?php elseif ($cuponId): ?><a href="pendientes.php">Pendientes</a><span aria-hidden="true">/</span>
      <?php endif; ?>
      <span>Vincular pagador</span>
    </nav>
    <?php if ($esperandoCandidato): ?>
      <h1>¿Quién pagó este cupón?</h1>
      <p>Elegí, entre las transferencias sin cupón de fecha y monto parecidos, cuál es la de este cliente.</p>
    <?php else: ?>
      <h1>¿De qué cliente es este pago?</h1>
      <p>Mirá quién transfirió según el banco y elegí a qué cliente de SAS Imperio corresponde. Las próximas transferencias de este pagador se van a vincular solas.</p>
    <?php endif; ?>
  </div>
</header>

<?php if ($esperandoCandidato): ?>
  <section class="tarjeta" style="gap: 14px">
    <dl class="datos">
      <dt>Cupón</dt><dd><?= h($cupon['titular'] ?: 'Titular no leído') ?></dd>
      <dt>Fecha</dt><dd class="mono"><?= h(fecha_ar($cupon['fecha_cupon'])) ?></dd>
      <dt>Monto</dt><dd class="mono"><?= dinero($cupon['monto']) ?></dd>
    </dl>
    <?php if (!$candidatos): ?>
      <div class="tarjeta vacio">No encontré transferencias sin cupón parecidas en fecha y monto.<br>Probá buscar a la persona a mano desde <a href="buscar.php">Buscar</a>.</div>
    <?php else: ?>
      <div style="display: flex; flex-direction: column; gap: 8px">
        <?php foreach ($candidatos as $c): $exacto = abs((float)$c['monto'] - (float)$cupon['monto']) < 0.005; ?>
          <a class="pend" style="text-decoration: none; display: block"
             href="vincular_tercero.php?<?= http_build_query(array_filter([
                 'cupon' => $cuponId, 'mov' => (int)$c['id'], 'control' => $controlId ?: null,
                 'f' => $f !== '' ? $f : null, 'volver' => $volverParam !== '' ? $volverParam : null,
             ], fn($v) => $v !== null)) ?>">
            <div class="pend-fila">
              <div class="pend-quien">
                <strong class="titular"><?= h($c['nombre'] ?: 'Nombre no informado') ?></strong>
                <span class="chico">DNI <span class="mono"><?= formato_dni((int)$c['dni']) ?></span><?= $c['cuit'] ? ' · CUIL <span class="mono">' . h(formato_cuit($c['cuit'])) . '</span>' : '' ?></span>
              </div>
              <div class="pend-cuando"><span class="mono chico"><?= h(fecha_ar($c['fecha'])) ?></span></div>
              <span class="pend-monto<?= $exacto ? '' : ' sub' ?>"><?= dinero($c['monto']) ?></span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <a class="boton boton-sec" style="align-self: flex-start" href="<?= h($volver) ?>">Cancelar</a>
  </section>
<?php else: ?>

<?php if ($pagadorEsCliente): ?>
  <p class="nota-espera"><?= icono('alerta') ?><span><strong>Ojo:</strong> este DNI también es cliente de SAS Imperio (<?= h($pagadorEsCliente) ?>) — confirmá que estos pagos no son suyos propios.</span></p>
<?php endif; ?>

<section class="tarjeta" style="gap: 18px">
  <div class="comparar">
    <div>
      <h2>Pagador según el banco</h2>
      <dl class="datos">
        <dt>Nombre</dt><dd><?= h($ultimoNombre ?: 'El banco no informa el nombre') ?></dd>
        <dt>DNI</dt><dd class="mono"><?= formato_dni($dniTercero) ?></dd>
        <dt>CUIL</dt><dd class="mono"><?= h($ultimoCuit ? formato_cuit($ultimoCuit) : '—') ?></dd>
      </dl>
      <?php if ($transferencias): ?>
        <span class="rotulo">Últimas transferencias de este DNI</span>
        <div style="display: flex; flex-direction: column; gap: 6px; margin-top: 8px">
          <?php foreach ($transferencias as $t): ?>
            <div class="fila entre chico">
              <span class="mono"><?= h(fecha_ar($t['fecha'])) ?></span>
              <span class="mono"><?= dinero($t['monto']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <div>
      <h2><?= $paso2 ? 'Cliente elegido' : 'Buscar cliente' ?></h2>
      <?php if (!$paso2): ?>
        <?php if ($errorBusqueda): ?>
          <div class="aviso aviso-error" role="alert"><?= icono('alerta') ?><span><?= h($errorBusqueda) ?></span></div>
        <?php endif; ?>
        <form method="get" class="form-resolver">
          <input type="hidden" name="mov" value="<?= $movId ?: '' ?>">
          <input type="hidden" name="dni" value="<?= $movId ? '' : $dniTercero ?>">
          <input type="hidden" name="control" value="<?= $controlId ?: '' ?>">
          <input type="hidden" name="f" value="<?= h($f) ?>">
          <input type="hidden" name="volver" value="<?= h($volverParam) ?>">
          <label class="campo">DNI del cliente en SAS Imperio
            <input type="text" inputmode="numeric" name="dni_cliente" value="<?= $dniClienteParam ?: '' ?>" placeholder="Ej: 30111222" autofocus>
          </label>
          <div class="form-botones">
            <button type="submit" class="boton">Buscar cliente</button>
          </div>
        </form>
      <?php elseif ($nadaQueHacer): ?>
        <?= cliente_sas($dniClienteParam, [$dniClienteParam => $nombreCliente]) ?>
        <p class="sub">Esta transferencia y este pagador ya están vinculados a este cliente. No hay nada más para hacer.</p>
        <?php if (!$cuponId): ?><p class="chico"><a href="<?= h($aquiSinCliente) ?>">Buscar otro DNI</a></p><?php endif; ?>
      <?php else: ?>
        <?= cliente_sas($dniClienteParam, [$dniClienteParam => $nombreCliente]) ?>
        <?php if (!$cuponId): ?><p class="chico"><a href="<?= h($aquiSinCliente) ?>">Buscar otro DNI</a></p><?php endif; ?>
        <form method="post" class="form-resolver">
          <?= csrf_campo() ?>
          <input type="hidden" name="accion" value="vincular">
          <input type="hidden" name="dni_cliente" value="<?= $dniClienteParam ?>">
          <input type="hidden" name="aqui" value="<?= h($aqui) ?>">
          <input type="hidden" name="volver_final" value="<?= h($volver) ?>">
          <fieldset>
            <legend>¿Qué hacemos con este pago?</legend>
            <?php if (!$yaVinculadoEsteCliente): ?>
              <label class="campo" style="flex-direction: row; align-items: center; gap: 8px; font-weight: 400">
                <input type="checkbox" name="crear_vinculo" value="1" checked>
                Vincular a este pagador: sus próximas transferencias van a este cliente
              </label>
            <?php else: ?>
              <p class="sub">Este pagador ya está vinculado a este cliente.</p>
            <?php endif; ?>
            <?php if ($puedeAsignar && !$yaAsignadoEsteCliente): ?>
              <label class="campo" style="flex-direction: row; align-items: center; gap: 8px; font-weight: 400">
                <input type="checkbox" name="asignar_mov" value="1" checked>
                Asignar esta transferencia puntual a este cliente
              </label>
            <?php elseif ($puedeAsignar): ?>
              <p class="sub">Esta transferencia ya está asignada a este cliente.</p>
            <?php endif; ?>
            <?php if ($vinculosExistentes && !$yaVinculadoEsteCliente): ?>
              <label class="campo" style="flex-direction: row; align-items: center; gap: 8px; font-weight: 400">
                <input type="checkbox" name="es_principal" value="1">
                Es el pagador principal (se usa cuando no hay cupón que aclare a cuál de sus clientes corresponde)
              </label>
            <?php endif; ?>
          </fieldset>
          <label class="campo">Nota (opcional)
            <textarea name="nota" rows="2" maxlength="300" placeholder="Ej: es la hija del cliente"></textarea>
          </label>
          <div class="form-botones">
            <a class="boton boton-sec" href="<?= h($volver) ?>">Cancelar</a>
            <button type="submit" class="boton">Confirmar</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($asignacionExistente): ?>
    <p class="nota-espera"><?= icono('check') ?><span>Esta transferencia ya está asignada a mano a <strong><?= h($clienteAsignado ?: ('DNI ' . formato_dni((int)$asignacionExistente['dni_cliente_sas']))) ?></strong>
      (<?= h($asignacionExistente['asignado_por']) ?>, <?= h(fecha_ar($asignacionExistente['asignado_en'])) ?>).</span></p>
    <form method="post" class="form-botones" style="justify-content: flex-start">
      <?= csrf_campo() ?>
      <input type="hidden" name="accion" value="quitar_asignacion">
      <input type="hidden" name="aqui" value="<?= h($aqui) ?>">
      <button type="submit" class="boton boton-sec">Quitar la asignación</button>
    </form>
  <?php endif; ?>

  <?php if ($vinculosExistentes): ?>
    <div style="display: flex; flex-direction: column; gap: 8px">
      <span class="rotulo">Vínculos ya cargados para este pagador</span>
      <?php foreach ($vinculosExistentes as $v): ?>
        <div class="fila entre" style="background: var(--superficie-2); border: 1px solid var(--borde); border-radius: 10px; padding: 10px 14px">
          <div>
            <strong><?= h($sasClientesExistentes[(int)$v['dni_cliente_sas']] ?? ('DNI ' . formato_dni((int)$v['dni_cliente_sas']))) ?></strong>
            <?php if ($v['es_principal']): ?><span class="chico"> · principal</span><?php endif; ?>
            <?php if ($v['nota']): ?><div class="sub"><?= h($v['nota']) ?></div><?php endif; ?>
          </div>
          <form method="post" style="margin: 0">
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="quitar_vinculo">
            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
            <input type="hidden" name="aqui" value="<?= h($aqui) ?>">
            <button type="submit" class="boton boton-sec boton-chico">Dar de baja</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php pie();
