<?php
require __DIR__ . '/app/bootstrap.php';
$u = requerir_login();

$q = trim((string)($_GET['q'] ?? ''));
$res = null;
$avisoPlanilla = null;
if ($q !== '') {
    try {
        Planilla::sincronizarSiHaceFalta();
    } catch (Throwable $e) {
        $avisoPlanilla = 'No se pudo actualizar la planilla; se muestran los cupones de la última lectura.';
    }
    $res = Busqueda::buscar($q);
    if (empty($res['error'])) {
        Busqueda::registrar((int)$u['id'], $q, (int)$res['cantidad']);
    }
}

encabezado('Buscar', 'buscar');
?>
<div class="titulo-pagina">
  <h1>Buscar</h1>
  <p class="chico">Por DNI o CUIL (completo o una parte del número) o por nombre y apellido de quien pagó.</p>
</div>

<form class="tarjeta buscador" method="get">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="Ej: 43225136, 27-43225136-0 o Delgadillo" aria-label="Buscar" autofocus>
  <button class="boton" type="submit">Buscar</button>
</form>

<?php if ($avisoPlanilla): ?><div class="aviso aviso-info"><?= h($avisoPlanilla) ?></div><?php endif; ?>

<?php if ($res && !empty($res['error'])): ?>
  <div class="aviso aviso-error"><?= h($res['error']) ?></div>
<?php elseif ($res): ?>

  <?php if (!$res['cantidad']): ?>
    <div class="tarjeta vacio">No encontré nada con “<?= h($q) ?>”.<br>Probá con menos números o solo el apellido.</div>
  <?php endif; ?>

  <?php if ($res['personas']): ?>
    <h2>Personas encontradas</h2>
    <div class="personas">
      <?php foreach (array_slice($res['personas'], 0, 12) as $p): ?>
        <a class="persona" style="text-decoration:none;color:inherit" href="buscar.php?q=<?= (int)$p['dni'] ?>">
          <strong><?= h($p['cliente_sas'] ?: ($p['nombres'][0] ?? 'Nombre no informado')) ?></strong>
          <?php if ($p['cliente_sas']): ?><div class="sas">Cliente de SAS</div><?php endif; ?>
          <div class="chico">DNI <?= number_format($p['dni'], 0, '', '.') ?><?= $p['cuit'] ? ' · CUIL ' . h(formato_cuit($p['cuit'])) : '' ?></div>
          <?php if (count($p['nombres']) > 1 || ($p['cliente_sas'] && $p['nombres'])): ?>
            <div class="chico">También figura como: <?= h(implode(', ', $p['nombres'])) ?></div>
          <?php endif; ?>
          <div class="cifras num">
            <span><strong><?= $p['transferencias'] ?></strong> en banco · <?= dinero($p['total_banco']) ?></span>
          </div>
          <div class="cifras num">
            <span><strong><?= $p['cupones'] ?></strong> <?= $p['cupones'] === 1 ? 'cupón' : 'cupones' ?></span>
            <?php if ($p['sin_cupon']): ?><span style="color:var(--sin)"><strong><?= $p['sin_cupon'] ?></strong> sin cupón</span><?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($res['movs']): ?>
    <section class="tarjeta">
      <h2>Lo que entró al banco · <?= count($res['movs']) ?> · <span class="num"><?= dinero($res['total_banco']) ?></span></h2>
      <div class="lista">
        <?php foreach ($res['movs'] as $m): $con = !empty($m['cupon_id']); ?>
          <article class="item <?= $con ? 'b-' . estado_info($m['cup_estado'] ?? 'ok')['clase'] : 'b-sincupon' ?>">
            <div class="item-nombre"><?= $m['nombre'] ? h($m['nombre']) : '<span class="chico">Nombre no informado por el banco</span>' ?></div>
            <div class="item-monto num"><?= dinero($m['monto']) ?></div>
            <div class="item-datos">
              <span>📅 <?= h(fecha_ar($m['fecha'])) ?></span>
              <?php if ($m['cuit']): ?><span>CUIL <?= h(formato_cuit($m['cuit'])) ?></span><?php endif; ?>
              <span><?= $con ? etiqueta($m['cup_estado'] ?? 'ok') : etiqueta('sin_cupon') ?></span>
            </div>
            <?php if ($con): ?>
              <div class="item-banco">Cupón: <?= h($m['cup_titular']) ?> · 📍 <?= h($m['cup_zona']) ?> · 👤 <?= h($m['cup_cobrador']) ?></div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php if ($res['cupones']): ?>
    <section class="tarjeta">
      <h2>Cupones recibidos por WhatsApp · <?= count($res['cupones']) ?> · <span class="num"><?= dinero($res['total_cupones']) ?></span></h2>
      <div class="lista">
        <?php foreach ($res['cupones'] as $c): $estado = $c['estado_conc'] ?: 'omitido'; ?>
          <article class="item b-<?= h(estado_info($estado)['clase']) ?>">
            <div class="item-nombre"><?= h($c['titular'] ?: 'Titular no leído') ?></div>
            <div class="item-monto num"><?= dinero($c['monto']) ?></div>
            <div class="item-datos">
              <span><?= $c['estado_conc'] ? etiqueta($estado) : '<span class="etiqueta e-omitido">Sin controlar todavía</span>' ?></span>
              <span>📍 <?= h($c['zona']) ?></span>
              <span>👤 <?= h($c['cobrador']) ?></span>
              <span>📅 <?= h(fecha_ar($c['fecha_cupon'])) ?></span>
              <?php if ($c['billetera']): ?><span>🏦 <?= h($c['billetera']) ?></span><?php endif; ?>
              <?php if ($c['nro_operacion']): ?><span>N° <?= h($c['nro_operacion']) ?></span><?php endif; ?>
              <?php if (!$c['en_planilla']): ?><span>Ya no está en la planilla</span><?php endif; ?>
            </div>
            <?php if ($c['mov_fecha']): ?>
              <div class="item-banco">En el banco: <strong><?= h(fecha_ar($c['mov_fecha'])) ?></strong>
                <?= $c['mov_nombre'] ? ' · ' . h($c['mov_nombre']) : '' ?><?= $c['mov_cuit'] ? ' · CUIL ' . h(formato_cuit($c['mov_cuit'])) : '' ?></div>
            <?php elseif ($c['pend_estado'] === 'pendiente'): ?>
              <div class="item-banco">📌 <a href="pendientes.php">En seguimiento</a> — no figura en el banco</div>
            <?php elseif ($c['pend_estado'] === 'resuelto' && $c['pend_nota']): ?>
              <div class="item-banco chico">Resuelto: <?= h($c['pend_nota']) ?></div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
<?php endif; ?>
<?php pie();
