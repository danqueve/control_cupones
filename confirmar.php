<?php
require __DIR__ . '/app/bootstrap.php';
$u = requerir_login();

$cuponId = (int)($_REQUEST['cupon'] ?? 0);
$controlId = (int)($_REQUEST['control'] ?? 0);
$volver = $controlId ? 'control.php?id=' . $controlId . '&f=' . urlencode((string)($_REQUEST['f'] ?? 'problemas')) . '#lista' : 'index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf($_POST['csrf'] ?? null);
    if (($_POST['accion'] ?? '') === 'deshacer') {
        $error = Controles::quitarConfirmacion($cuponId);
        $error ? flash($error, 'error') : flash('Listo, el cupón volvió a "a revisar".');
    } else {
        $error = Controles::confirmarCupon($cuponId, (string)($_POST['nota'] ?? ''), $u['nombre']);
        $error ? flash($error, 'error') : flash('Listo, el cupón quedó confirmado como acreditado.');
    }
    header('Location: ' . $volver);
    exit;
}

$st = db()->prepare('SELECT c.*, m.fecha AS m_fecha, m.nombre AS m_nombre, m.cuit AS m_cuit, m.monto AS m_monto
                     FROM cupones c LEFT JOIN movimientos m ON m.id = c.movimiento_id WHERE c.id = ?');
$st->execute([$cuponId]);
$c = $st->fetch();
if (!$c) {
    flash('No encontré ese cupón.', 'error');
    header('Location: ' . $volver);
    exit;
}
$conf = Controles::confirmaciones([$cuponId])[$cuponId] ?? null;
$sas = Sas::nombres([$c['dni'] ?: ($c['m_cuit'] ? Texto::dniDeCuit($c['m_cuit']) : 0)]);
$dni = $c['dni'] ? (int)$c['dni'] : ($c['m_cuit'] ? Texto::dniDeCuit($c['m_cuit']) : null);
$puede = $c['estado_conc'] === 'revisar';
$varios = str_starts_with((string)$c['detalle_conc'], 'Había');

encabezado('Confirmar cupón', 'control');
?>
<header class="cabecera">
  <div>
    <nav class="ruta" aria-label="Ruta">
      <?= $controlId ? '<a href="control.php?id=' . $controlId . '">Control <span class="mono">#' . $controlId . '</span></a><span aria-hidden="true">/</span>' : '' ?>
      <span>Confirmar cupón</span>
    </nav>
    <h1><?= $conf ? 'Cupón confirmado a mano' : 'Confirmar cupón a mano' ?></h1>
    <p>Mirá el cupón y la transferencia del banco. Si estás seguro de que es el mismo pago, confirmalo y pasa a acreditado.</p>
  </div>
</header>

<section class="tarjeta" style="gap: 18px">
  <div class="comparar">
    <div>
      <h2>Cupón (WhatsApp)</h2>
      <dl class="datos">
        <dt>Titular</dt><dd><?= h($c['titular'] ?: 'Sin nombre leído') ?></dd>
        <dt>Zona · cobrador</dt><dd><?= h($c['zona']) ?> · <?= h($c['cobrador']) ?></dd>
        <dt>Fecha</dt><dd class="mono"><?= h(fecha_ar($c['fecha_cupon'])) ?></dd>
        <dt>Monto</dt><dd class="mono"><?= dinero($c['monto']) ?></dd>
        <dt>Banco o billetera</dt><dd><?= h($c['billetera'] ?: '—') ?></dd>
        <dt>N° de operación</dt><dd class="mono"><?= h($c['nro_operacion'] ?: '—') ?></dd>
      </dl>
    </div>
    <div>
      <h2>Transferencia en el banco</h2>
      <?php if ($c['m_fecha']): ?>
        <dl class="datos">
          <dt>Nombre</dt><dd><?= h($c['m_nombre'] ?: 'El banco no informa el nombre') ?><?= $dni && !empty($sas[$dni]) ? '<div class="sas">Cliente SAS: ' . h($sas[$dni]) . '</div>' : '' ?></dd>
          <dt>CUIL</dt><dd class="mono"><?= h($c['m_cuit'] ? formato_cuit($c['m_cuit']) : '—') ?></dd>
          <dt>Fecha</dt><dd class="mono"><?= h(fecha_ar($c['m_fecha'])) ?></dd>
          <dt>Monto</dt><dd class="mono"><?= dinero($c['m_monto']) ?></dd>
        </dl>
      <?php else: ?>
        <p class="sub">Este cupón no está cruzado con ninguna transferencia.</p>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($varios && $puede): ?>
    <p class="nota-espera"><?= icono('alerta') ?><span><strong>Ojo:</strong> <?= h($c['detalle_conc']) ?>. Confirmá solo si estás seguro de que es la de este cliente.</span></p>
  <?php endif; ?>

  <?php if ($conf): ?>
    <p class="nota-espera"><?= icono('check') ?><span><?= h(Controles::textoConfirmacion($conf)) ?></span></p>
    <form method="post" class="form-botones" style="justify-content: flex-start">
      <?= csrf_campo() ?>
      <input type="hidden" name="cupon" value="<?= $cuponId ?>"><input type="hidden" name="control" value="<?= $controlId ?>">
      <input type="hidden" name="f" value="<?= h($_GET['f'] ?? '') ?>"><input type="hidden" name="accion" value="deshacer">
      <a class="boton boton-sec" href="<?= h($volver) ?>">Volver</a>
      <button type="submit" class="boton boton-sec">Quitar la confirmación</button>
    </form>
  <?php elseif ($puede): ?>
    <form method="post" class="form-resolver">
      <?= csrf_campo() ?>
      <input type="hidden" name="cupon" value="<?= $cuponId ?>"><input type="hidden" name="control" value="<?= $controlId ?>">
      <input type="hidden" name="f" value="<?= h($_GET['f'] ?? '') ?>"><input type="hidden" name="accion" value="confirmar">
      <label class="campo">Nota (opcional)
        <textarea name="nota" rows="2" maxlength="250" placeholder="Ej: lo confirmé con el cobrador por teléfono"></textarea>
      </label>
      <div class="form-botones">
        <a class="boton boton-sec" href="<?= h($volver) ?>">Cancelar</a>
        <button type="submit" class="boton">Sí, es el mismo pago</button>
      </div>
    </form>
  <?php else: ?>
    <p class="sub">Este cupón ya no está "a revisar", así que no hace falta confirmarlo.</p>
    <a class="boton boton-sec" style="align-self: flex-start" href="<?= h($volver) ?>">Volver</a>
  <?php endif; ?>
</section>
<?php pie();
