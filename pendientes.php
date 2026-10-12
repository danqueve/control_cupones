<?php
require __DIR__ . '/app/bootstrap.php';
$u = requerir_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf($_POST['csrf'] ?? null);
    $id = (int)($_POST['id'] ?? 0);
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'resolver') {
        $motivos = ['efectivo' => 'Pagó en efectivo', 'otra_cuenta' => 'Entró en otra cuenta', 'falso' => 'Cupón falso o repetido'];
        $motivo = $motivos[$_POST['motivo'] ?? ''] ?? '';
        $nota = trim((string)($_POST['nota'] ?? ''));
        $texto = $motivo !== '' && $nota !== '' ? $motivo . ': ' . $nota : ($motivo ?: $nota);
        db()->prepare("UPDATE pendientes SET estado = 'resuelto', nota = ?, resuelto_en = NOW(), resuelto_por = ? WHERE id = ? AND estado = 'pendiente'")
            ->execute([$texto !== '' ? mb_substr($texto, 0, 500) : 'Resuelto a mano', $u['nombre'], $id]);
        flash('Listo, se marcó como resuelto.');
    } elseif ($accion === 'reabrir') {
        db()->prepare("UPDATE pendientes SET estado = 'pendiente', resuelto_en = NULL, resuelto_por = NULL WHERE id = ?")
            ->execute([$id]);
        flash('Se volvió a poner en pendientes.');
    }
    $q = array_intersect_key($_GET, ['ver' => 1, 'agrupar' => 1]);
    header('Location: pendientes.php' . ($q ? '?' . http_build_query($q) : ''));
    exit;
}

$verResueltos = ($_GET['ver'] ?? '') === 'resueltos';
$agrupaciones = ['cobrador' => 'Cobrador', 'zona' => 'Zona', 'fecha' => 'Fecha'];
$agrupar = isset($agrupaciones[$_GET['agrupar'] ?? '']) ? $_GET['agrupar'] : 'cobrador';

$st = db()->prepare("SELECT p.*, c.zona, c.fecha_cupon, c.cobrador, c.titular, c.monto, c.billetera, c.nro_operacion, c.dni, c.estado_conc
                     FROM pendientes p JOIN cupones c ON c.id = p.cupon_id
                     WHERE p.estado = ?
                     ORDER BY " . ($verResueltos ? 'p.resuelto_en DESC LIMIT 200' : 'c.fecha_cupon ASC'));
$st->execute([$verResueltos ? 'resuelto' : 'pendiente']);
$lista = $st->fetchAll();
$total = array_sum(array_column($lista, 'monto'));
$cantResueltos = $verResueltos ? count($lista) : null;
$cantPendientes = $verResueltos ? pendientes_abiertos() : count($lista);

// Antigüedad (solo pendientes abiertos)
$edades = ['vieja' => 0, 'media' => 0, 'nueva' => 0];
foreach ($lista as $p) {
    $edades[antiguedad($p['fecha_cupon'])['clase']]++;
}

// Agrupar
$grupos = [];
foreach ($lista as $p) {
    $clave = match ($agrupar) {
        'zona' => $p['zona'] ?: 'Sin zona',
        'fecha' => $p['fecha_cupon'] ? fecha_ar($p['fecha_cupon']) : 'Sin fecha',
        default => $p['cobrador'] ?: 'Sin cobrador',
    };
    $grupos[$clave] ??= ['nombre' => $clave, 'items' => [], 'total' => 0.0];
    $grupos[$clave]['items'][] = $p;
    $grupos[$clave]['total'] += (float)$p['monto'];
}
if ($agrupar !== 'fecha') {
    uasort($grupos, fn($a, $b) => $b['total'] <=> $a['total']);
}
$cobradores = count(array_unique(array_filter(array_column($lista, 'cobrador'))));

$url = function (array $cambios) use ($verResueltos, $agrupar): string {
    $q = array_merge(['ver' => $verResueltos ? 'resueltos' : null, 'agrupar' => $agrupar !== 'cobrador' ? $agrupar : null], $cambios);
    $q = array_filter($q, fn($v) => $v !== null);
    return 'pendientes.php' . ($q ? '?' . http_build_query($q) : '');
};
$accion = $url([]);

encabezado('Pendientes', 'pendientes');
?>
<header class="cabecera">
  <div>
    <h1>Pendientes de seguir</h1>
    <p>Cupones que llegaron por WhatsApp pero la plata no figura en el banco. Si aparece en un próximo resumen se resuelven solos; si no, consultalo con el cobrador y cerralo con el motivo.</p>
  </div>
</header>

<?php if (!$verResueltos && $lista): ?>
  <section class="resumen-pend">
    <div class="tarjeta tarjeta-oscura" style="padding: 20px 22px">
      <span class="rotulo">Sin acreditar</span>
      <span class="monto-grande" style="font-size: 32px"><?= dinero($total) ?></span>
      <span style="font-size: 14px; color: var(--lateral-texto)"><span class="mono" style="color: #fff"><?= count($lista) ?></span> <?= count($lista) === 1 ? 'cupón' : 'cupones' ?>
        de <span class="mono" style="color: #fff"><?= $cobradores ?></span> <?= $cobradores === 1 ? 'cobrador' : 'cobradores' ?></span>
    </div>
    <div class="tarjeta" style="padding: 20px 22px">
      <span class="rotulo">Antigüedad del cupón</span>
      <?php $aria = $edades['vieja'] . ' con más de 3 semanas, ' . $edades['media'] . ' de 2 a 3 semanas, ' . $edades['nueva'] . ' de hasta 2 semanas'; ?>
      <div class="barra-estados" style="height: 12px" role="img" aria-label="<?= h($aria) ?>">
        <?php if ($edades['vieja']): ?><span class="b-no" style="flex: <?= $edades['vieja'] ?>"></span><?php endif; ?>
        <?php if ($edades['media']): ?><span class="b-revisar" style="flex: <?= $edades['media'] ?>"></span><?php endif; ?>
        <?php if ($edades['nueva']): ?><span class="b-nuevo" style="flex: <?= $edades['nueva'] ?>"></span><?php endif; ?>
      </div>
      <div class="tres">
        <div><span class="mono rojo"><?= $edades['vieja'] ?></span><span>más de 3 semanas</span></div>
        <div><span class="mono" style="color: var(--rev)"><?= $edades['media'] ?></span><span>de 2 a 3 semanas</span></div>
        <div><span class="mono"><?= $edades['nueva'] ?></span><span>hasta 2 semanas</span></div>
      </div>
    </div>
  </section>
<?php endif; ?>

<div class="fila entre">
  <nav class="chips" aria-label="Estado">
    <a class="chip<?= !$verResueltos ? ' activo' : '' ?>" href="<?= h($url(['ver' => null])) ?>"<?= !$verResueltos ? ' aria-current="true"' : '' ?>>Pendientes <span class="mono"><?= $cantPendientes ?></span></a>
    <a class="chip<?= $verResueltos ? ' activo' : '' ?>" href="<?= h($url(['ver' => 'resueltos'])) ?>"<?= $verResueltos ? ' aria-current="true"' : '' ?>>Resueltos</a>
  </nav>
  <?php if ($lista): ?>
    <div class="fila" style="gap: 10px; font-size: 14px; color: var(--texto-2)">
      <span id="agrupar">Agrupar por</span>
      <nav class="segmentos" aria-labelledby="agrupar">
        <?php foreach ($agrupaciones as $clave => $texto): ?>
          <a href="<?= h($url(['agrupar' => $clave === 'cobrador' ? null : $clave])) ?>" class="<?= $agrupar === $clave ? 'activo' : '' ?>"<?= $agrupar === $clave ? ' aria-current="true"' : '' ?>><?= h($texto) ?></a>
        <?php endforeach; ?>
      </nav>
    </div>
  <?php endif; ?>
</div>

<?php if (!$lista): ?>
  <div class="tarjeta vacio"><?= $verResueltos ? 'Todavía no hay pendientes resueltos.' : icono('check', '') . '<br><strong>No hay cupones pendientes.</strong>' ?></div>
<?php endif; ?>

<?php foreach ($grupos as $g):
    $lineas = [$g['nombre'] . ' — ' . count($g['items']) . ' ' . (count($g['items']) === 1 ? 'cupón' : 'cupones') . ' sin acreditar:'];
    foreach ($g['items'] as $p) {
        $lineas[] = '• ' . ($p['titular'] ?: 'Titular no leído') . ' — ' . dinero($p['monto']) . ' — cupón del ' . fecha_ar($p['fecha_cupon'])
            . ($p['billetera'] ? ' (' . $p['billetera'] . ')' : '') . ($agrupar !== 'zona' && $p['zona'] ? ' · ' . $p['zona'] : '');
    }
?>
  <section class="tarjeta tarjeta-plana">
    <div class="grupo-cabecera">
      <div>
        <span class="iniciales" aria-hidden="true"><?= $agrupar === 'fecha' ? icono('calendario') : h(iniciales($g['nombre'])) ?></span>
        <div>
          <strong><?= h($g['nombre']) ?></strong>
          <span class="chico"><span class="mono"><?= count($g['items']) ?></span> <?= count($g['items']) === 1 ? 'cupón' : 'cupones' ?> · <span class="mono"><?= dinero($g['total']) ?></span></span>
        </div>
      </div>
      <?php if (!$verResueltos): ?>
        <button type="button" class="boton boton-sec boton-chico copiar" data-texto="<?= h(implode("\n", $lineas)) ?>">
          <?= icono('copiar') ?><span>Copiar lista para WhatsApp</span>
        </button>
      <?php endif; ?>
    </div>

    <?php foreach ($g['items'] as $p): $edad = antiguedad($p['fecha_cupon']); $dni = $p['dni'] ? (int)$p['dni'] : null; ?>
      <div class="pend">
        <div class="pend-fila">
          <div class="pend-quien">
            <?= link_persona($dni, $p['titular'] ?: 'Titular no leído') ?>
            <span class="chico">
              <?= h(implode(' · ', array_filter([
                  $agrupar !== 'zona' ? $p['zona'] : null,
                  $agrupar !== 'cobrador' ? $p['cobrador'] : null,
                  $p['billetera'],
              ]))) ?>
              · detectado en <a href="control.php?id=<?= (int)$p['control_id'] ?>&amp;f=no">control <span class="mono">#<?= (int)$p['control_id'] ?></span></a>
              <?php if ($p['nro_operacion']): ?> · <span class="mono">N° <?= h($p['nro_operacion']) ?></span><?php endif; ?>
            </span>
          </div>
          <div class="pend-cuando">
            <span class="mono chico">cupón <?= h(fecha_corta($p['fecha_cupon'])) ?></span>
            <?php if (!$verResueltos): ?><span class="edad edad-<?= $edad['clase'] ?>">hace <span class="mono"><?= $edad['dias'] ?></span> <?= $edad['dias'] === 1 ? 'día' : 'días' ?></span><?php endif; ?>
          </div>
          <span class="pend-monto"><?= dinero($p['monto']) ?></span>
        </div>

        <?php if ($verResueltos): ?>
          <div class="fila entre">
            <div class="nota-resuelto"><?= h($p['nota']) ?> · <?= h($p['resuelto_por']) ?>, <?= h(fecha_ar($p['resuelto_en'])) ?></div>
            <form method="post" action="<?= h($accion) ?>">
              <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="accion" value="reabrir">
              <button class="boton boton-sec boton-chico" type="submit">Volver a pendientes</button>
            </form>
          </div>
        <?php else: ?>
          <details class="resolver">
            <summary class="boton boton-sec boton-chico" style="align-self: flex-start">Resolver</summary>
            <form class="form-resolver" method="post" action="<?= h($accion) ?>">
              <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="accion" value="resolver">
              <fieldset>
                <legend>¿Qué pasó con este cupón?</legend>
                <div class="chips">
                  <label class="motivo"><input type="radio" name="motivo" value="efectivo" checked><span>Pagó en efectivo</span></label>
                  <label class="motivo"><input type="radio" name="motivo" value="otra_cuenta"><span>Entró en otra cuenta</span></label>
                  <label class="motivo"><input type="radio" name="motivo" value="falso"><span>Cupón falso o repetido</span></label>
                  <label class="motivo"><input type="radio" name="motivo" value="otro"><span>Otro</span></label>
                </div>
              </fieldset>
              <label class="campo">Nota (opcional)
                <textarea name="nota" rows="2" maxlength="450" placeholder="Ej: lo confirmó el cobrador el lunes"></textarea>
              </label>
              <div class="form-botones">
                <button type="button" class="boton boton-sec cancelar">Cancelar</button>
                <button type="submit" class="boton">Marcar resuelto</button>
              </div>
            </form>
          </details>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>
<?php endforeach; ?>

<script>
(function () {
  document.querySelectorAll('.cancelar').forEach(function (b) {
    b.addEventListener('click', function () { b.closest('details').open = false; });
  });
  document.querySelectorAll('.copiar').forEach(function (b) {
    b.addEventListener('click', function () {
      var texto = b.getAttribute('data-texto');
      var etiqueta = b.querySelector('span');
      var listo = function () {
        var antes = etiqueta.textContent;
        etiqueta.textContent = '¡Copiado!';
        setTimeout(function () { etiqueta.textContent = antes; }, 2000);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(texto).then(listo);
      } else {
        var t = document.createElement('textarea');
        t.value = texto; document.body.appendChild(t); t.select();
        try { document.execCommand('copy'); listo(); } finally { document.body.removeChild(t); }
      }
    });
  });
})();
</script>
<?php pie();
