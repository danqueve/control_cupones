<?php
require __DIR__ . '/app/bootstrap.php';
requerir_login();

$id = (int)($_GET['id'] ?? 0);
$ctrl = $id ? Controles::obtener($id) : null;
if (!$ctrl) {
    flash('No encontré ese control.', 'error');
    header('Location: historial.php');
    exit;
}

$filtros = ['problemas' => 'Para revisar', 'no' => 'No figuran', 'revisar' => 'A revisar', 'ok' => 'Acreditados',
    'espera' => 'En espera', 'sin_cupon' => 'Sin cupón', 'todos' => 'Todos los cupones'];
$f = $_GET['f'] ?? 'problemas';
if (!isset($filtros[$f])) {
    $f = 'problemas';
}

if ($f === 'sin_cupon') {
    $items = Controles::items($id, 'sin_cupon');
} elseif ($f === 'problemas') {
    $items = array_values(array_filter(Controles::items($id, 'cupon'), fn($i) => in_array($i['estado'], ['no', 'revisar'], true)));
} elseif ($f === 'todos') {
    $items = Controles::items($id, 'cupon');
} else {
    $items = Controles::items($id, 'cupon', $f);
}

$st = db()->prepare("SELECT estado, SUM(monto) FROM control_items WHERE control_id = ? GROUP BY estado");
$st->execute([$id]);
$montos = $st->fetchAll(PDO::FETCH_KEY_PAIR);

$sas = Sas::nombres(array_column($items, 'dni'));
$pendAbiertos = [];
if ($items && $f !== 'sin_cupon') {
    $ids = array_filter(array_column($items, 'cupon_id'));
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $pendAbiertos = array_flip(db()->query("SELECT cupon_id FROM pendientes WHERE estado = 'pendiente' AND cupon_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
    }
}

$cuadro = function (string $estado, string $rotulo, int $cantidad, string $filtro) use ($montos, $f, $id) {
    $clase = estado_info($estado)['clase'];
    $elegido = $f === $filtro ? ' elegido' : '';
    return '<a class="cuadro c-' . $clase . $elegido . '" href="control.php?id=' . $id . '&f=' . $filtro . '">'
        . '<div class="cifra num">' . $cantidad . '</div><div class="rotulo">' . h($rotulo) . '</div>'
        . '<div class="monto num">' . dinero($montos[$estado] ?? 0) . '</div></a>';
};

encabezado('Control #' . $id, 'historial');
?>
<div class="titulo-pagina fila entre">
  <div>
    <h1>Control #<?= $id ?></h1>
    <p class="chico">Movimientos del banco del <strong><?= h(fecha_ar($ctrl['desde'])) ?></strong> al <strong><?= h(fecha_ar($ctrl['hasta'])) ?></strong>
      · subido el <?= h(fecha_ar($ctrl['creado_en'])) ?> por <?= h($ctrl['usuario']) ?>
      · <?= (int)$ctrl['mov_archivo'] ?> transferencias (<?= dinero($ctrl['total_archivo']) ?>), <?= (int)$ctrl['mov_nuevos'] ?> nuevas</p>
  </div>
  <a class="boton boton-sec boton-chico" href="index.php">Subir otro resumen</a>
</div>

<div class="cuadros">
  <?= $cuadro('ok', 'Acreditados', (int)$ctrl['ok'], 'ok') ?>
  <?= $cuadro('revisar', 'A revisar', (int)$ctrl['revisar'], 'revisar') ?>
  <?= $cuadro('no', 'No figuran en el banco', (int)$ctrl['no_figuran'], 'no') ?>
  <?= $cuadro('sin_cupon', 'Plata sin cupón', (int)$ctrl['sin_cupon'], 'sin_cupon') ?>
</div>

<nav class="filtros" aria-label="Filtrar">
  <?php foreach ($filtros as $clave => $texto): ?>
    <a class="<?= $f === $clave ? 'activo' : '' ?>" href="control.php?id=<?= $id ?>&f=<?= $clave ?>"><?= h($texto) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ((int)$ctrl['espera'] > 0 && $f === 'problemas'): ?>
  <p class="chico">⏳ <?= (int)$ctrl['espera'] ?> <?= (int)$ctrl['espera'] === 1 ? 'cupón quedó' : 'cupones quedaron' ?> <a href="control.php?id=<?= $id ?>&f=espera">en espera</a> (son de los últimos días o de fechas sin resumen): se vuelven a controlar con el próximo resumen.</p>
<?php endif; ?>

<div class="lista">
  <?php if (!$items): ?>
    <div class="tarjeta vacio"><?= $f === 'problemas' ? '🎉 No hay nada para revisar en este control.' : 'No hay nada en esta lista.' ?></div>
  <?php endif; ?>

  <?php foreach ($items as $it): $info = estado_info($it['estado']); $dni = $it['dni'] ? (int)$it['dni'] : null; ?>
    <?php if ($it['tipo'] === 'sin_cupon'): ?>
      <article class="item b-sincupon">
        <div>
          <div class="item-nombre"><?= $it['nombre_banco'] ? h($it['nombre_banco']) : '<span class="chico">Nombre no informado</span>' ?></div>
          <?= cliente_sas($dni, $sas) ?>
        </div>
        <div class="item-monto num"><?= dinero($it['monto']) ?></div>
        <div class="item-datos">
          <span><?= etiqueta('sin_cupon') ?></span>
          <span>📅 <?= h(fecha_ar($it['fecha_banco'])) ?></span>
          <span>CUIL <?= h(formato_cuit($it['cuit_banco'])) ?></span>
          <span><?= link_persona($dni, 'Ver todo de esta persona') ?></span>
          <?php if ($it['detalle']): ?><span><?= h($it['detalle']) ?></span><?php endif; ?>
        </div>
      </article>
    <?php else: ?>
      <article class="item b-<?= h($info['clase']) ?>">
        <div>
          <div class="item-nombre"><?= h($it['titular'] ?: 'Titular no leído') ?></div>
          <?= cliente_sas($dni, $sas) ?>
        </div>
        <div class="item-monto num"><?= dinero($it['monto']) ?></div>
        <div class="item-datos">
          <span><?= etiqueta($it['estado']) ?></span>
          <span>📍 <?= h($it['zona']) ?></span>
          <span>👤 <?= h($it['cobrador']) ?></span>
          <span>📅 <?= h(fecha_ar($it['fecha_cupon'])) ?></span>
          <?php if ($it['billetera']): ?><span>🏦 <?= h($it['billetera']) ?></span><?php endif; ?>
          <?php if ($it['nro_operacion']): ?><span>N° <?= h($it['nro_operacion']) ?></span><?php endif; ?>
        </div>
        <?php if ($it['fecha_banco']): ?>
          <div class="item-banco">En el banco: <strong><?= h(fecha_ar($it['fecha_banco'])) ?></strong>
            <?= $it['nombre_banco'] ? ' · ' . h($it['nombre_banco']) : '' ?>
            <?= $it['cuit_banco'] ? ' · CUIL ' . h(formato_cuit($it['cuit_banco'])) : '' ?>
            <?= $dni ? ' · ' . link_persona($dni, 'ver persona') : '' ?>
            <?= $it['detalle'] ? '<br><span class="chico">' . h($it['detalle']) . '</span>' : '' ?>
          </div>
        <?php elseif ($it['detalle']): ?>
          <div class="item-banco chico"><?= h($it['detalle']) ?>
            <?= isset($pendAbiertos[$it['cupon_id']]) ? ' · <a href="pendientes.php">En seguimiento</a>' : '' ?></div>
        <?php endif; ?>
      </article>
    <?php endif; ?>
  <?php endforeach; ?>
</div>
<?php pie();
