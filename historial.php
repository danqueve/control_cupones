<?php
require __DIR__ . '/app/bootstrap.php';
requerir_login();

$controles = Controles::listar(100);
$busquedas = Busqueda::recientes(40);

encabezado('Historial', 'historial');
?>
<header class="cabecera">
  <div>
    <h1>Historial</h1>
    <p>Cada resumen que se subió, semana por semana. Tocá uno para ver su resultado.</p>
  </div>
  <div class="leyenda" aria-hidden="true">
    <span><i class="b-ok"></i>Acreditados</span>
    <span><i class="b-revisar"></i>A revisar</span>
    <span><i class="b-no"></i>No figuran</span>
    <span><i class="b-sincupon"></i>Sin cupón</span>
  </div>
</header>

<section class="tarjeta tarjeta-plana">
  <?php if (!$controles): ?>
    <p class="vacio">Todavía no se subió ningún resumen del banco. <a href="index.php">Subir el primero</a></p>
  <?php else: ?>
    <div class="tabla-caja">
      <table class="tabla" style="min-width: 820px">
        <thead>
          <tr>
            <th scope="col">Control</th>
            <th scope="col">Período del banco</th>
            <th scope="col" style="width: 34%">Resultado</th>
            <th scope="col" class="der">No figuran</th>
            <th scope="col" class="der">Sin cupón</th>
            <th scope="col">Subido</th>
          </tr>
        </thead>
        <tbody class="centrado">
          <?php foreach ($controles as $c):
              $controlados = (int)$c['ok'] + (int)$c['revisar'] + (int)$c['no_figuran'];
              $pct = $controlados ? round((int)$c['ok'] * 100 / $controlados) : null; ?>
            <tr>
              <td><a href="control.php?id=<?= (int)$c['id'] ?>" class="mono" style="font-weight: 600; font-size: 15px">#<?= (int)$c['id'] ?></a></td>
              <td class="mono" style="white-space: nowrap"><?= h(fecha_corta($c['desde'])) ?> → <?= h(fecha_corta($c['hasta'])) ?></td>
              <td>
                <div class="fila" style="gap: 10px; flex-wrap: nowrap">
                  <div style="flex: 1"><?= barra_estados(['ok' => (int)$c['ok'], 'revisar' => (int)$c['revisar'], 'no' => (int)$c['no_figuran'], 'sincupon' => (int)$c['sin_cupon']]) ?></div>
                  <?php if ($pct !== null): ?><span class="mono" style="font-size: 13px; color: var(--ok); font-weight: 600; white-space: nowrap"><?= $pct ?>%</span><?php endif; ?>
                </div>
              </td>
              <td class="der mono rojo" style="font-weight: 600"><?= (int)$c['no_figuran'] ?></td>
              <td class="der mono" style="color: var(--sin); font-weight: 600"><?= (int)$c['sin_cupon'] ?></td>
              <td class="sub" style="white-space: nowrap"><span class="mono"><?= h(fecha_ar($c['creado_en'])) ?></span> · <?= h($c['usuario']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="tarjeta" style="padding: 18px 18px 10px; gap: 8px">
  <h2 class="rotulo" style="margin-bottom: 4px">Búsquedas recientes</h2>
  <?php if (!$busquedas): ?>
    <p class="vacio" style="padding: 16px">Todavía no hay búsquedas.</p>
  <?php else: ?>
    <div class="busquedas">
      <?php foreach ($busquedas as $b): $esNumero = (bool)preg_match('/^[\d\s.\-\/]+$/', $b['texto']); ?>
        <a href="buscar.php?q=<?= urlencode($b['texto']) ?>">
          <span><strong<?= $esNumero ? ' class="mono"' : '' ?>><?= h($b['texto']) ?></strong>
            <span class="sub">· <?= (int)$b['resultados'] ?> <?= (int)$b['resultados'] === 1 ? 'resultado' : 'resultados' ?></span></span>
          <span class="sub" style="white-space: nowrap"><?= h(hace_cuanto($b['creado_en'])) ?><span class="ocultar-cel"> · <?= h($b['usuario']) ?></span></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php pie();
