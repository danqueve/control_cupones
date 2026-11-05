<?php
require __DIR__ . '/app/bootstrap.php';
$u = requerir_login();

$cuponId = (int)($_REQUEST['cupon'] ?? 0);
$controlId = (int)($_REQUEST['control'] ?? 0);
$volver = $controlId ? 'control.php?id=' . $controlId . '&f=' . urlencode((string)($_REQUEST['f'] ?? 'problemas')) . '#lista' : 'index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf($_POST['csrf'] ?? null);
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'deshacer') {
        $error = Controles::quitarConfirmacion($cuponId);
        $error ? flash($error, 'error') : flash('Listo, el cupón volvió a "a revisar".');
    } elseif ($accion === 'descartar' || $accion === 'permitir') {
        $r = $accion === 'descartar'
            ? Controles::descartarCruce($cuponId, (string)($_POST['nota'] ?? ''), $u['nombre'])
            : Controles::permitirCruce($cuponId, (int)($_POST['mov'] ?? 0));
        $texto = isset($r['error']) ? $r['error']
            : ($accion === 'descartar' ? 'Listo: esa transferencia ya no se le ofrece a este cupón.' : 'Listo: ese cruce se puede volver a ofrecer.')
              . (!empty($r['aviso']) ? ' ' . $r['aviso'] : '');
        flash($texto, isset($r['error']) ? 'error' : (!empty($r['aviso']) ? 'info' : 'ok'));
    } else {
        $error = Controles::confirmarCupon($cuponId, (string)($_POST['nota'] ?? ''), $u['nombre']);
        $error ? flash($error, 'error') : flash('Listo, el cupón quedó confirmado como acreditado.');
    }
    header('Location: ' . $volver);
    exit;
}

$st = db()->prepare('SELECT c.*, m.fecha AS m_fecha, m.nombre AS m_nombre, m.cuit AS m_cuit, m.monto AS m_monto, m.dni AS m_dni
                     FROM cupones c LEFT JOIN movimientos m ON m.id = c.movimiento_id WHERE c.id = ?');
$st->execute([$cuponId]);
$c = $st->fetch();
if (!$c) {
    flash('No encontré ese cupón.', 'error');
    header('Location: ' . $volver);
    exit;
}
$conf = Controles::confirmaciones([$cuponId])[$cuponId] ?? null;
// Cada lado muestra SU cliente de SAS: el del cupón bajo el cupón, el de quien transfirió bajo la transferencia
$dniCupon = $c['dni'] ? (int)$c['dni'] : null;
$dniBanco = $c['m_dni'] ? (int)$c['m_dni'] : ($c['m_cuit'] ? Texto::dniDeCuit($c['m_cuit']) : null);
$sas = Sas::nombres(array_filter([$dniCupon, $dniBanco]));
$puede = $c['estado_conc'] === 'revisar';
$varios = str_starts_with((string)$c['detalle_conc'], 'Había');
$descartes = Controles::descartes([$cuponId])[$cuponId] ?? [];

encabezado('Confirmar cupón', 'control');
?>
<header class="cabecera">
  <div>
    <nav class="ruta" aria-label="Ruta">
      <?= $controlId ? '<a href="control.php?id=' . $controlId . '">Control <span class="mono">#' . $controlId . '</span></a><span aria-hidden="true">/</span>' : '' ?>
      <span>Confirmar cupón</span>
    </nav>
    <h1><?= $conf ? 'Cupón confirmado a mano' : ($descartes && !$puede ? 'Cruces descartados' : 'Confirmar cupón a mano') ?></h1>
    <?php if ($puede): ?>
      <p>Mirá el cupón y la transferencia del banco. Si estás seguro de que es el mismo pago, confirmalo y pasa a acreditado.
        Si no lo es (por ejemplo, la hizo otro cliente que pagó el mismo monto), tocá "No es el mismo pago": no se le vuelve a ofrecer a este cupón.</p>
    <?php else: ?>
      <p>Mirá el cupón y la transferencia del banco.</p>
    <?php endif; ?>
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
        <?php if ($dniCupon && !empty($sas[$dniCupon])): ?><dt>Cliente SAS</dt><dd><?= h($sas[$dniCupon]) ?></dd><?php endif; ?>
      </dl>
    </div>
    <div>
      <h2>Transferencia en el banco</h2>
      <?php if ($c['m_fecha']): ?>
        <dl class="datos">
          <dt>Nombre</dt><dd><?= h($c['m_nombre'] ?: 'El banco no informa el nombre') ?><?= $dniBanco && !empty($sas[$dniBanco]) ? '<div class="sas">Cliente SAS: ' . h($sas[$dniBanco]) . '</div>' : '' ?></dd>
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
      <input type="hidden" name="f" value="<?= h($_GET['f'] ?? '') ?>">
      <label class="campo">Nota (opcional)
        <textarea name="nota" rows="2" maxlength="250" placeholder="Ej: lo confirmé con el cobrador por teléfono"></textarea>
      </label>
      <div class="form-botones">
        <a class="boton boton-sec" href="<?= h($volver) ?>">Cancelar</a>
        <button type="submit" name="accion" value="descartar" class="boton boton-sec">No es el mismo pago</button>
        <button type="submit" name="accion" value="confirmar" class="boton">Sí, es el mismo pago</button>
      </div>
    </form>
  <?php else: ?>
    <p class="sub">Este cupón ya no está "a revisar", así que no hace falta confirmarlo.</p>
    <a class="boton boton-sec" style="align-self: flex-start" href="<?= h($volver) ?>">Volver</a>
  <?php endif; ?>

  <?php if ($descartes): ?>
    <div style="display: flex; flex-direction: column; gap: 8px">
      <span class="rotulo">Transferencias descartadas para este cupón</span>
      <?php foreach ($descartes as $d): ?>
        <div class="fila entre" style="background: var(--superficie-2); border: 1px solid var(--borde); border-radius: 10px; padding: 10px 14px">
          <div>
            <strong><?= h($d['nombre'] ?: 'Nombre no informado') ?></strong>
            <span class="mono chico"> · <?= h(fecha_ar($d['fecha'])) ?> · <?= dinero($d['monto']) ?></span>
            <div class="sub">Descartada por <?= h($d['descartado_por']) ?>, <?= h(fecha_ar($d['descartado_en'])) ?><?= $d['nota'] ? ' — ' . h($d['nota']) : '' ?></div>
          </div>
          <form method="post" style="margin: 0">
            <?= csrf_campo() ?>
            <input type="hidden" name="cupon" value="<?= $cuponId ?>"><input type="hidden" name="control" value="<?= $controlId ?>">
            <input type="hidden" name="f" value="<?= h($_GET['f'] ?? '') ?>"><input type="hidden" name="mov" value="<?= (int)$d['movimiento_id'] ?>">
            <button type="submit" name="accion" value="permitir" class="boton boton-sec boton-chico">Permitir de nuevo</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php pie();
