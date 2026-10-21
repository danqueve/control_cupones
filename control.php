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

$st = db()->prepare("SELECT estado, SUM(monto) FROM control_items WHERE control_id = ? AND (detalle IS NULL OR detalle <> 'Cupón duplicado') GROUP BY estado");
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
    $info = estado_info($estado);
    $elegido = $f === $filtro ? ' elegido' : '';
    return '<a class="cuadro c-' . $info['clase'] . $elegido . '" href="control.php?id=' . $id . '&amp;f=' . $filtro . '#lista"'
        . ($f === $filtro ? ' aria-current="true"' : '') . '>'
        . '<span class="cuadro-titulo">' . icono($info['icono'], '') . h($rotulo) . '</span>'
        . '<span class="cifra">' . $cantidad . '</span>'
        . '<span class="monto">' . dinero($montos[$estado] ?? 0) . '</span></a>';
};

$cuentas = ['problemas' => (int)$ctrl['no_figuran'] + (int)$ctrl['revisar'], 'no' => (int)$ctrl['no_figuran'], 'revisar' => (int)$ctrl['revisar'],
    'ok' => (int)$ctrl['ok'], 'espera' => (int)$ctrl['espera'], 'sin_cupon' => (int)$ctrl['sin_cupon'], 'todos' => null];
$filtros['todos'] = 'Todos';

encabezado('Control #' . $id, 'control');
?>
<header class="cabecera">
  <div>
    <nav class="ruta" aria-label="Ruta"><a href="historial.php">Historial</a><span aria-hidden="true">/</span><span>Control <span class="mono">#<?= $id ?></span></span></nav>
    <h1>Movimientos del <span class="mono" style="font-weight: 600; letter-spacing: -.03em"><?= h(fecha_corta($ctrl['desde'])) ?> → <?= h(fecha_corta($ctrl['hasta'])) ?></span></h1>
    <p style="font-size: 14px">Subido el <?= h(dia_corto($ctrl['creado_en'])) ?>, <?= h(date('H:i', strtotime($ctrl['creado_en']))) ?> por <?= h($ctrl['usuario']) ?>
      · <span class="mono"><?= (int)$ctrl['mov_archivo'] ?></span> transferencias por <span class="mono"><?= dinero($ctrl['total_archivo']) ?></span>
      · <span class="mono"><?= (int)$ctrl['mov_nuevos'] ?></span> nuevas</p>
  </div>
  <a class="boton boton-sec" href="index.php">Subir otro resumen</a>
</header>

<section class="tarjeta" style="gap: 18px">
  <?= barra_estados(['ok' => (int)$ctrl['ok'], 'revisar' => (int)$ctrl['revisar'], 'no' => (int)$ctrl['no_figuran'], 'espera' => (int)$ctrl['espera']], 'gruesa') ?>
  <div class="cuadros">
    <?= $cuadro('ok', 'Acreditados', (int)$ctrl['ok'], 'ok') ?>
    <?= $cuadro('revisar', 'A revisar', (int)$ctrl['revisar'], 'revisar') ?>
    <?= $cuadro('no', 'No figuran en el banco', (int)$ctrl['no_figuran'], 'no') ?>
    <?= $cuadro('sin_cupon', 'Plata sin cupón', (int)$ctrl['sin_cupon'], 'sin_cupon') ?>
  </div>
  <?php if ((int)$ctrl['espera'] > 0): ?>
    <p class="nota-espera"><?= icono('reloj') ?>
      <span><span class="mono"><?= (int)$ctrl['espera'] ?></span> <?= (int)$ctrl['espera'] === 1 ? 'cupón quedó' : 'cupones quedaron' ?>
        <a href="control.php?id=<?= $id ?>&amp;f=espera#lista">en espera</a>: son de los últimos días o de fechas sin resumen, y se vuelven a controlar con el próximo resumen.</span></p>
  <?php endif; ?>
</section>

<section class="tarjeta tarjeta-plana" id="lista">
  <div class="lista-cabecera">
    <nav class="chips" aria-label="Filtrar">
      <?php foreach ($filtros as $clave => $texto): ?>
        <a class="chip<?= $f === $clave ? ' activo' : '' ?>" href="control.php?id=<?= $id ?>&amp;f=<?= $clave ?>#lista"<?= $f === $clave ? ' aria-current="true"' : '' ?>>
          <?= h($texto) ?><?php if ($cuentas[$clave] !== null): ?> <span class="mono"><?= $cuentas[$clave] ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <?php if ($items): ?>
      <label class="filtro-texto">
        <span class="oculto">Filtrar la lista</span>
        <?= icono('buscar') ?>
        <input type="search" id="filtro-lista" placeholder="Nombre, zona o cobrador" autocomplete="off">
      </label>
    <?php endif; ?>
  </div>

  <?php if (!$items): ?>
    <div class="vacio"><?= $f === 'problemas' ? icono('check', '') . '<br><strong>No hay nada para revisar en este control.</strong>' : 'No hay nada en esta lista.' ?></div>
  <?php else: ?>
    <div class="tabla-caja">
      <table class="tabla tabla-tarjetas" style="min-width: 960px">
        <thead>
          <tr>
            <th scope="col">Estado</th>
            <th scope="col">Titular del cupón</th>
            <th scope="col">Zona · cobrador</th>
            <th scope="col">Cupón</th>
            <th scope="col">En el banco</th>
            <th scope="col" class="der">Monto</th>
            <th scope="col"><span class="oculto">Acciones</span></th>
          </tr>
        </thead>
        <tbody id="filas">
          <?php foreach ($items as $it): $dni = $it['dni'] ? (int)$it['dni'] : null; ?>
            <?php if ($it['tipo'] === 'sin_cupon'): ?>
              <tr>
                <td><?= etiqueta('sin_cupon') ?></td>
                <td>
                  <?= link_persona($dni, $it['nombre_banco'] ?: 'Nombre no informado') ?>
                  <?= cliente_sas($dni, $sas) ?>
                </td>
                <td class="sub ocultar-cel">—</td>
                <td class="sub">No mandó cupón</td>
                <td>
                  <div><span class="mono" style="font-size: 13px"><?= h(fecha_ar($it['fecha_banco'])) ?></span><?= $it['cuit_banco'] ? ' · <span class="mono" style="font-size: 13px">CUIL ' . h(formato_cuit($it['cuit_banco'])) . '</span>' : '' ?></div>
                  <?php if ($it['detalle']): ?><div class="sub"><?= h($it['detalle']) ?></div><?php endif; ?>
                </td>
                <td class="monto-fila"><?= dinero($it['monto']) ?></td>
                <td class="acciones"><?= $dni ? '<a href="buscar.php?q=' . $dni . '">Ver persona →</a>' : '' ?></td>
              </tr>
            <?php else: ?>
              <tr>
                <td><?= etiqueta($it['estado'], $it['estado'] === 'no' ? 'No figura' : null) ?></td>
                <td>
                  <?= link_persona($dni, $it['titular'] ?: 'Titular no leído') ?>
                  <?= cliente_sas($dni, $sas) ?>
                </td>
                <td><div><?= h($it['zona']) ?></div><div class="sub"><?= h($it['cobrador']) ?></div></td>
                <td>
                  <div class="mono" style="font-size: 13px"><?= h(fecha_ar($it['fecha_cupon'])) ?></div>
                  <?php if ($it['billetera']): ?><div class="sub"><?= h($it['billetera']) ?></div><?php endif; ?>
                  <?php if ($it['nro_operacion']): ?><div class="mono sub" style="font-size: 12px">N° <?= h($it['nro_operacion']) ?></div><?php endif; ?>
                </td>
                <td style="max-width: 280px">
                  <?php if ($it['fecha_banco']): ?>
                    <div><span class="mono" style="font-size: 13px"><?= h(fecha_ar($it['fecha_banco'])) ?></span><?= $it['nombre_banco'] ? ' · ' . h($it['nombre_banco']) : '' ?></div>
                    <?php if ($it['cuit_banco']): ?><div class="mono sub" style="font-size: 12px">CUIL <?= h(formato_cuit($it['cuit_banco'])) ?></div><?php endif; ?>
                  <?php elseif ($it['estado'] === 'no'): ?>
                    <div class="mono rojo" style="font-size: 13px">Sin transferencia</div>
                  <?php endif; ?>
                  <?php if ($it['detalle']): ?><div class="sub" style="text-wrap: pretty"><?= h($it['detalle']) ?></div><?php endif; ?>
                </td>
                <td class="monto-fila"><?= dinero($it['monto']) ?></td>
                <td class="acciones">
                  <?php if (isset($pendAbiertos[$it['cupon_id']])): ?><a href="pendientes.php">En seguimiento →</a>
                  <?php elseif ($dni): ?><a href="buscar.php?q=<?= $dni ?>">Ver persona →</a><?php endif; ?>
                </td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="lista-pie" id="contador" aria-live="polite">Mostrando <span class="mono"><?= count($items) ?></span> de <span class="mono"><?= count($items) ?></span></div>
  <?php endif; ?>
</section>

<script>
(function () {
  var campo = document.getElementById('filtro-lista');
  if (!campo) return;
  var filas = Array.prototype.slice.call(document.querySelectorAll('#filas tr'));
  var contador = document.getElementById('contador');
  var sinTildes = function (t) { return t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); };
  var textos = filas.map(function (f) { return sinTildes(f.textContent); });
  campo.addEventListener('input', function () {
    var q = sinTildes(campo.value.trim());
    var visibles = 0;
    filas.forEach(function (f, i) {
      var ok = !q || textos[i].indexOf(q) !== -1;
      f.hidden = !ok;
      if (ok) visibles++;
    });
    contador.innerHTML = 'Mostrando <span class="mono">' + visibles + '</span> de <span class="mono">' + filas.length + '</span>';
  });
})();
</script>
<?php pie();
