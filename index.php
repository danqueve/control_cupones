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
    header('Location: index.php');
    exit;
}

$ultimo = Controles::listar(1)[0] ?? null;
$pend = pendientes_abiertos();
$ultimaLectura = ajuste('ultima_sincronizacion');
$totalCupones = (int)db()->query('SELECT COUNT(*) FROM cupones WHERE en_planilla = 1')->fetchColumn();

encabezado('Subir resumen', 'inicio');
?>
<div class="titulo-pagina">
  <h1>Hola, <?= h(explode(' ', $u['nombre'])[0]) ?></h1>
  <p class="chico">Subí el resumen del Banco Macro y el sistema lo compara con los cupones que llegaron por WhatsApp.</p>
</div>

<div class="grilla-2">
  <section class="tarjeta">
    <h2>1. Elegí el Excel del banco</h2>
    <label class="zona-subida" id="zona-subida">
      <input type="file" id="archivo" accept=".xls,.xlsx,.csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
      <span class="icono" aria-hidden="true">📄</span>
      <strong>Tocá acá para elegir el archivo</strong>
      <span class="chico">o arrastralo hasta este recuadro</span>
    </label>
    <div class="procesando" id="procesando" role="status" aria-live="polite">
      <div class="ruedita" aria-hidden="true"></div>
      <span id="procesando-texto">Comparando con los cupones… puede tardar unos segundos.</span>
    </div>
    <div class="aviso aviso-error" id="error-subida" role="alert" hidden></div>
    <ol class="pasos">
      <li>Entrá a Macro y descargá <strong>Últimos movimientos</strong> en Excel.</li>
      <li>Elegí ese archivo acá. No hace falta abrirlo ni cambiarle nada.</li>
      <li>El resultado aparece solo y queda guardado en el Historial.</li>
    </ol>
  </section>

  <div>
    <?php if ($ultimo): ?>
      <a class="tarjeta" style="display:block;text-decoration:none;color:inherit" href="control.php?id=<?= (int)$ultimo['id'] ?>">
        <h2>Último control</h2>
        <p class="chico">#<?= (int)$ultimo['id'] ?> · <?= h(fecha_ar($ultimo['creado_en'])) ?> · <?= h($ultimo['usuario']) ?><br>
          Movimientos del <?= h(fecha_ar($ultimo['desde'])) ?> al <?= h(fecha_ar($ultimo['hasta'])) ?></p>
        <div class="fila">
          <?= etiqueta('ok') ?> <strong class="num"><?= (int)$ultimo['ok'] ?></strong>
          <?= etiqueta('no') ?> <strong class="num"><?= (int)$ultimo['no_figuran'] ?></strong>
        </div>
      </a>
    <?php endif; ?>

    <a class="tarjeta" style="display:block;text-decoration:none;color:inherit" href="pendientes.php">
      <h2>Pendientes de seguir</h2>
      <p><strong class="num" style="font-size:1.6rem"><?= $pend ?></strong> cupones que no figuran en el banco</p>
    </a>

    <section class="tarjeta">
      <h2>Buscar una persona</h2>
      <form action="buscar.php" method="get" class="buscador">
        <input type="search" name="q" placeholder="DNI, CUIL o nombre" aria-label="DNI, CUIL o nombre">
        <button class="boton" type="submit">Buscar</button>
      </form>
    </section>

    <section class="tarjeta">
      <h2>Cupones de la planilla</h2>
      <p class="chico"><?= $totalCupones ?> cupones cargados<?= $ultimaLectura ? ' · última lectura ' . h(fecha_ar($ultimaLectura)) : ' · todavía no se leyó la planilla' ?>.
        Se actualiza sola al subir un resumen.</p>
      <form method="post">
        <?= csrf_campo() ?>
        <input type="hidden" name="accion" value="sincronizar">
        <button class="boton boton-sec boton-chico" type="submit">Actualizar ahora</button>
      </form>
    </section>
  </div>
</div>

<script>window.CSRF = <?= json_encode(csrf_token()) ?>;</script>
<script src="assets/vendor/xlsx.full.min.js"></script>
<script src="assets/subir.js?v=1"></script>
<?php pie();
