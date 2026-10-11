<?php
require __DIR__ . '/app/bootstrap.php';
requerir_login();

$controles = Controles::listar(100);
$busquedas = Busqueda::recientes(40);

encabezado('Historial', 'historial');
?>
<div class="titulo-pagina">
  <h1>Historial</h1>
  <p class="chico">Cada resumen que se subió y las últimas búsquedas. Tocá un control para ver su resultado.</p>
</div>

<section class="tarjeta">
  <h2>Controles</h2>
  <?php if (!$controles): ?>
    <p class="vacio">Todavía no se subió ningún resumen del banco.</p>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tabla">
        <thead>
          <tr>
            <th>#</th><th>Subido</th><th>Período del banco</th>
            <th class="der">✅</th><th class="der">🟡</th><th class="der">❌</th><th class="der">💵 Sin cupón</th>
            <th class="ocultar-cel">Por</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($controles as $c): ?>
            <tr class="clic" onclick="location.href='control.php?id=<?= (int)$c['id'] ?>'">
              <td><a href="control.php?id=<?= (int)$c['id'] ?>"><?= (int)$c['id'] ?></a></td>
              <td><?= h(fecha_ar($c['creado_en'])) ?></td>
              <td><?= h(fecha_ar($c['desde'])) ?> al <?= h(fecha_ar($c['hasta'])) ?></td>
              <td class="der num"><?= (int)$c['ok'] ?></td>
              <td class="der num"><?= (int)$c['revisar'] ?></td>
              <td class="der num"><?= (int)$c['no_figuran'] ?></td>
              <td class="der num"><?= (int)$c['sin_cupon'] ?></td>
              <td class="ocultar-cel"><?= h($c['usuario']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="tarjeta">
  <h2>Búsquedas recientes</h2>
  <?php if (!$busquedas): ?>
    <p class="vacio">Todavía no hay búsquedas.</p>
  <?php else: ?>
    <table class="tabla">
      <thead><tr><th>Cuándo</th><th>Buscado</th><th class="der">Resultados</th><th class="ocultar-cel">Por</th></tr></thead>
      <tbody>
        <?php foreach ($busquedas as $b): ?>
          <tr>
            <td><?= h(fecha_ar($b['creado_en'])) ?></td>
            <td><a href="buscar.php?q=<?= urlencode($b['texto']) ?>"><?= h($b['texto']) ?></a></td>
            <td class="der num"><?= (int)$b['resultados'] ?></td>
            <td class="ocultar-cel"><?= h($b['usuario']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
<?php pie();
