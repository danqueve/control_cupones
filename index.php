<?php
require __DIR__ . '/app/bootstrap.php';
$u = requerir_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'sincronizar') {
    verificar_csrf($_POST['csrf'] ?? null);
    try {
        $r = Planilla::sincronizar();
        flash('Planilla leída: ' . $r['cupones'] . ' cupones en ' . count($r['zonas']) . ' zonas (' . $r['nuevos'] . ' nuevos).');
    } catch (Throwable $e) {
        flash($e->getMessage(), 'error');
    }
    $volver = (string)($_POST['volver'] ?? '');
    header('Location: ' . (preg_match('/^[a-z]+\.php(\?[\w=&%.\-+]*)?$/', $volver) ? $volver : 'index.php'));
    exit;
}

$ultimo = Controles::listar(1)[0] ?? null;
$bancoHasta = db()->query('SELECT MAX(fecha) FROM movimientos')->fetchColumn() ?: null;

// Pendientes abiertos: total y antigüedad del cupón
$pendientes = db()->query("SELECT c.monto, c.fecha_cupon FROM pendientes p JOIN cupones c ON c.id = p.cupon_id WHERE p.estado = 'pendiente'")->fetchAll();
$pend = count($pendientes);
$pendTotal = array_sum(array_column($pendientes, 'monto'));
$edades = ['vieja' => 0, 'media' => 0, 'nueva' => 0];
foreach ($pendientes as $p) {
    $edades[antiguedad($p['fecha_cupon'])['clase']]++;
}

encabezado('Subir resumen', 'inicio');
?>
<header class="cabecera">
  <div>
    <span class="sobretitulo"><?= h(fecha_larga()) ?></span>
    <h1>Hola, <?= h(explode(' ', $u['nombre'])[0]) ?></h1>
    <p>Subí el resumen de Macro y lo cruzamos con los cupones que llegaron por WhatsApp.</p>
  </div>
</header>

<div class="columnas">
  <section class="tarjeta principal" style="padding: 28px; gap: 20px">
    <div class="fila entre" style="align-items: baseline">
      <h2 style="font-size: 20px; font-weight: 700; letter-spacing: -.01em">Nuevo control</h2>
      <span class="sobretitulo" style="font-size: 12px">Semanal · Banco Macro</span>
    </div>

    <?php if ($bancoHasta): $desde = date('Y-m-d', strtotime($bancoHasta . ' +1 day')); ?>
      <div class="aviso aviso-info"><?= icono('calendario') ?>
        <span>El banco está cargado hasta el <strong><?= h(dia_corto($bancoHasta)) ?></strong>. Descargá los movimientos desde el <strong><?= h(fecha_corta($desde)) ?></strong> para no dejar días sin controlar.</span>
      </div>
    <?php endif; ?>

    <label class="zona-subida" id="zona-subida">
      <input type="file" id="archivo" accept=".xls,.xlsx,.csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
      <span class="zona-icono"><?= icono('archivo', '') ?></span>
      <strong>Elegí el Excel de “Últimos movimientos”</strong>
      <span class="zona-ayuda">o arrastralo hasta acá · <span class="mono">.xls .xlsx .csv</span></span>
      <span class="boton" style="margin-top: 6px" aria-hidden="true">Elegir archivo</span>
    </label>
    <div class="procesando" id="procesando" role="status" aria-live="polite">
      <div class="ruedita" aria-hidden="true"></div>
      <span id="procesando-texto">Comparando con los cupones… puede tardar unos segundos.</span>
    </div>
    <div class="aviso aviso-error" id="error-subida" role="alert" hidden></div>

    <ol class="pasos">
      <li><span class="mono">01</span><span>En Macro descargá <strong>Últimos movimientos</strong> en Excel.</span></li>
      <li><span class="mono">02</span><span>Elegilo acá, sin abrirlo ni cambiarle nada.</span></li>
      <li><span class="mono">03</span><span>El resultado aparece solo y queda en el Historial.</span></li>
    </ol>
  </section>

  <div class="secundaria">
    <?php if ($ultimo): ?>
      <a class="tarjeta tarjeta-link" href="control.php?id=<?= (int)$ultimo['id'] ?>" style="gap: 14px">
        <div class="fila entre">
          <span class="rotulo">Último control</span>
          <span class="mono chico">#<?= (int)$ultimo['id'] ?></span>
        </div>
        <div style="display: flex; flex-direction: column; gap: 2px">
          <span class="periodo"><?= h(fecha_corta($ultimo['desde'])) ?> → <?= h(fecha_corta($ultimo['hasta'])) ?></span>
          <span class="chico">Subido el <?= h(dia_corto($ultimo['creado_en'])) ?>, <?= h(date('H:i', strtotime($ultimo['creado_en']))) ?> · <?= h($ultimo['usuario']) ?></span>
        </div>
        <?= barra_estados(['ok' => (int)$ultimo['ok'], 'revisar' => (int)$ultimo['revisar'], 'no' => (int)$ultimo['no_figuran'], 'espera' => (int)$ultimo['espera']]) ?>
        <div class="resumen-estados">
          <div><?= etiqueta('ok', 'Acreditados') ?><strong class="mono"><?= (int)$ultimo['ok'] ?></strong></div>
          <div><?= etiqueta('revisar') ?><strong class="mono"><?= (int)$ultimo['revisar'] ?></strong></div>
          <div><?= etiqueta('no', 'No figuran') ?><strong class="mono"><?= (int)$ultimo['no_figuran'] ?></strong></div>
          <div><?= etiqueta('sin_cupon') ?><strong class="mono"><?= (int)$ultimo['sin_cupon'] ?></strong></div>
        </div>
        <span class="mas">Ver resultado →</span>
      </a>
    <?php endif; ?>

    <a class="tarjeta tarjeta-oscura tarjeta-link" href="pendientes.php" style="gap: 14px">
      <div class="fila entre">
        <span class="rotulo">Pendientes de seguir</span>
        <?php if ($pend > 0): ?><span class="burbuja"><?= $pend ?></span><?php endif; ?>
      </div>
      <?php if ($pend > 0): ?>
        <div style="display: flex; flex-direction: column; gap: 2px">
          <span class="monto-grande"><?= dinero($pendTotal) ?></span>
          <span style="font-size: 14px; color: var(--lateral-texto)">en cupones que no figuran en el banco</span>
        </div>
        <div class="antiguedades">
          <div><span class="t-viejo">Más de 3 semanas</span><span class="mono"><?= $edades['vieja'] ?></span></div>
          <div><span class="t-medio">De 2 a 3 semanas</span><span class="mono"><?= $edades['media'] ?></span></div>
          <div><span style="color: var(--lateral-texto)">Hasta 2 semanas</span><span class="mono"><?= $edades['nueva'] ?></span></div>
        </div>
      <?php else: ?>
        <span style="font-size: 15px; color: var(--lateral-claro)">No hay cupones sin acreditar.</span>
      <?php endif; ?>
      <span class="mas">Ver pendientes →</span>
    </a>

    <form class="tarjeta" action="buscar.php" method="get" role="search" style="gap: 10px">
      <label for="q-inicio" class="rotulo">Buscar una persona</label>
      <div class="buscador-mini">
        <input id="q-inicio" type="search" name="q" placeholder="DNI, CUIL o apellido">
        <button class="boton" type="submit">Buscar</button>
      </div>
    </form>
  </div>
</div>

<script>window.CSRF = <?= json_encode(csrf_token()) ?>;</script>
<script src="assets/vendor/xlsx.full.min.js"></script>
<script src="assets/subir.js?v=1"></script>
<?php pie();
