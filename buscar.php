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

// Persona elegida: la única encontrada o la del DNI buscado
$elegida = null;
$filas = [];
$cifras = null;
if ($res && empty($res['error']) && $res['cantidad']) {
    $digitos = preg_replace('/\D/', '', $q);
    foreach ($res['personas'] as $p) {
        if (count($res['personas']) === 1 || ($digitos !== '' && ((string)$p['dni'] === $digitos || $p['cuit'] === $digitos))) {
            $elegida = $p;
            break;
        }
    }

    // Cruce cupón ↔ banco, ordenado por fecha
    $idsCupones = [];
    foreach ($res['cupones'] as $c) {
        $idsCupones[(int)$c['id']] = true;
        $filas[] = ['fecha' => (string)($c['fecha_cupon'] ?: $c['mov_fecha']), 'cupon' => $c, 'mov' => null];
    }
    $sinCupon = ['n' => 0, 'total' => 0.0];
    foreach ($res['movs'] as $m) {
        if (!$m['cupon_id']) {
            $sinCupon['n']++;
            $sinCupon['total'] += (float)$m['monto'];
        }
        if (!$m['cupon_id'] || !isset($idsCupones[(int)$m['cupon_id']])) {
            $filas[] = ['fecha' => (string)$m['fecha'], 'cupon' => null, 'mov' => $m];
        }
    }
    usort($filas, fn($a, $b) => strcmp($b['fecha'], $a['fecha']));

    $seguimiento = array_filter($res['cupones'], fn($c) => $c['pend_estado'] === 'pendiente');
    $cifras = [
        'cupones' => [count($res['cupones']), $res['total_cupones']],
        'banco' => [count($res['movs']), $res['total_banco']],
        'sin' => [$sinCupon['n'], $sinCupon['total']],
        'seguimiento' => [count($seguimiento), array_sum(array_map(fn($c) => (float)$c['monto'], $seguimiento))],
    ];
}

encabezado('Buscar', 'buscar');
?>
<form class="busqueda-grande" method="get" role="search">
  <label>
    <span class="oculto">Buscar por DNI, CUIL o nombre</span>
    <?= icono('buscar') ?>
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="DNI, CUIL (completo o parte) o apellido"<?= $q === '' ? ' autofocus' : '' ?>>
  </label>
  <button class="boton" type="submit">Buscar</button>
</form>

<?php if ($q === ''): ?>
  <p class="chico" style="font-size: 15px">Buscá por DNI o CUIL (completo o una parte del número) o por nombre y apellido de quien pagó. Ej: <span class="mono">43225136</span>, <span class="mono">27-43225136-0</span> o Delgadillo.</p>
<?php endif; ?>

<?php if ($avisoPlanilla): ?><div class="aviso aviso-info"><?= icono('alerta') ?><span><?= h($avisoPlanilla) ?></span></div><?php endif; ?>

<?php if ($res && !empty($res['error'])): ?>
  <div class="aviso aviso-error"><?= icono('alerta') ?><span><?= h($res['error']) ?></span></div>
<?php elseif ($res && !$res['cantidad']): ?>
  <div class="tarjeta vacio">No encontré nada con “<?= h($q) ?>”.<br>Probá con menos números o solo el apellido.</div>
<?php elseif ($res): ?>

  <?php if ($res['personas']): ?>
    <section style="display: flex; flex-direction: column; gap: 10px">
      <span class="rotulo"><span class="mono"><?= count($res['personas']) ?></span> <?= count($res['personas']) === 1 ? 'persona' : 'personas' ?> con “<?= h($q) ?>”</span>
      <div class="personas">
        <?php foreach (array_slice($res['personas'], 0, 12) as $p): $esta = $elegida && $elegida['dni'] === $p['dni']; ?>
          <a class="persona" href="buscar.php?q=<?= (int)$p['dni'] ?>"<?= $esta ? ' aria-current="true"' : '' ?>>
            <strong><?= h($p['cliente_sas'] ?: ($p['nombres'][0] ?? 'Nombre no informado')) ?></strong>
            <span class="mono chico">DNI <?= formato_dni($p['dni']) ?></span>
            <?php if ($p['cliente_sas']): ?>
              <span class="sas">Cliente SAS · <?= $p['cupones'] ?> <?= $p['cupones'] === 1 ? 'cupón' : 'cupones' ?></span>
            <?php elseif ($p['sin_cupon'] && !$p['cupones']): ?>
              <span class="chico" style="color: var(--sin); font-weight: 600"><?= $p['sin_cupon'] ?> <?= $p['sin_cupon'] === 1 ? 'transferencia' : 'transferencias' ?> sin cupón</span>
            <?php else: ?>
              <span class="chico"><?= $p['cupones'] ?> <?= $p['cupones'] === 1 ? 'cupón' : 'cupones' ?> · <?= $p['transferencias'] ?> en banco</span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <section class="tarjeta" style="padding: 24px; gap: 20px">
    <div class="fila entre" style="align-items: flex-start; gap: 16px">
      <div class="ficha-titulo" style="display: flex; flex-direction: column; gap: 6px">
        <?php if ($elegida): ?>
          <h1><?= h($elegida['cliente_sas'] ?: ($elegida['nombres'][0] ?? 'DNI ' . formato_dni($elegida['dni']))) ?></h1>
          <div class="ficha-datos">
            <span class="mono">DNI <?= formato_dni($elegida['dni']) ?></span>
            <?php if ($elegida['cuit']): ?><span class="mono">CUIL <?= h(formato_cuit($elegida['cuit'])) ?></span><?php endif; ?>
            <?php if ($elegida['nombres']): ?><span>En el banco y los cupones figura como <strong><?= h(implode(', ', $elegida['nombres'])) ?></strong></span><?php endif; ?>
          </div>
        <?php else: ?>
          <h1>Resultados para “<?= h($q) ?>”</h1>
          <div class="ficha-datos"><span>Tocá una persona para ver solo lo suyo.</span></div>
        <?php endif; ?>
      </div>
      <?php if ($elegida && ($link = Sas::link((int)$elegida['dni']))): ?>
        <a class="boton boton-sec" href="<?= h($link) ?>" target="_blank" rel="noopener" style="min-height: 42px; font-size: 14px">Ver en SAS Imperio <?= icono('externo') ?></a>
      <?php endif; ?>
    </div>

    <div class="cifras">
      <div><span>Cupones por WhatsApp</span><span class="mono"><?= $cifras['cupones'][0] ?> · <?= dinero($cifras['cupones'][1]) ?></span></div>
      <div><span>Entró al banco</span><span class="mono"><?= $cifras['banco'][0] ?> · <?= dinero($cifras['banco'][1]) ?></span></div>
      <div class="<?= $cifras['sin'][0] ? 'c-sin' : '' ?>"><span>Sin cupón</span><span class="mono"><?= $cifras['sin'][0] ?> · <?= dinero($cifras['sin'][1]) ?></span></div>
      <div class="<?= $cifras['seguimiento'][0] ? 'c-no' : '' ?>"><span>En seguimiento</span><span class="mono"><?= $cifras['seguimiento'][0] ?> · <?= dinero($cifras['seguimiento'][1]) ?></span></div>
    </div>

    <div class="cruces">
      <div class="cruce cruce-cabecera" aria-hidden="true"><span>Cupón por WhatsApp</span><span>Cruce</span><span>Banco Macro</span></div>
      <?php foreach ($filas as $fila): $c = $fila['cupon']; $m = $fila['mov']; ?>
        <div class="cruce">
          <?php if ($c): $estado = $c['estado_conc'] ?: null; ?>
            <div class="lado">
              <div><span class="mono"><?= h(fecha_ar($c['fecha_cupon'])) ?></span><span class="mono" style="font-size: 15px"><?= dinero($c['monto']) ?></span></div>
              <?php if (!$elegida): ?><strong><?= h($c['titular'] ?: 'Titular no leído') ?></strong><?php endif; ?>
              <span class="sub"><?= h(implode(' · ', array_filter([$c['zona'], $c['cobrador'], $c['billetera']]))) ?></span>
              <?php if ($c['nro_operacion']): ?><span class="mono sub" style="font-size: 12px; font-weight: 400">N° <?= h($c['nro_operacion']) ?></span><?php endif; ?>
              <?php if (!$c['en_planilla']): ?><span class="sub">Ya no está en la planilla</span><?php endif; ?>
            </div>
            <div class="medio">
              <?= $estado ? etiqueta($estado, $estado === 'no' ? 'No figura' : null) : '<span class="etiqueta e-omitido">Sin controlar todavía</span>' ?>
              <?php if ($c['pend_estado'] === 'pendiente'): ?>
                <small><a href="pendientes.php">En seguimiento</a></small>
              <?php elseif ($c['pend_estado'] === 'resuelto' && $c['pend_nota']): ?>
                <small>Resuelto: <?= h($c['pend_nota']) ?></small>
              <?php elseif ($c['detalle_conc']): ?>
                <small><?= h($c['detalle_conc']) ?></small>
              <?php endif; ?>
            </div>
            <?php if ($c['mov_fecha']): ?>
              <div class="lado">
                <div><span class="mono"><?= h(fecha_ar($c['mov_fecha'])) ?></span><span class="mono" style="font-size: 15px"><?= dinero($c['mov_monto']) ?></span></div>
                <span class="sub"><?= h(implode(' · ', array_filter([$c['mov_nombre'], $c['mov_cuit'] ? 'CUIL ' . formato_cuit($c['mov_cuit']) : null]))) ?></span>
              </div>
            <?php elseif ($estado === 'espera'): ?>
              <div class="lado-vacio">Todavía sin resumen de esa fecha</div>
            <?php elseif ($estado === 'no' || $c['pend_estado'] === 'pendiente'): ?>
              <div class="lado-vacio rojo">No entró al banco</div>
            <?php else: ?>
              <div class="lado-vacio">Sin transferencia asociada</div>
            <?php endif; ?>
          <?php else: ?>
            <?php if ($m['cupon_id']): ?>
              <div class="lado">
                <strong><?= h($m['cup_titular'] ?: 'Titular no leído') ?></strong>
                <span class="sub"><?= h(implode(' · ', array_filter([$m['cup_zona'], $m['cup_cobrador']]))) ?></span>
              </div>
              <div class="medio"><?= etiqueta($m['cup_estado'] ?: 'ok') ?></div>
            <?php else: ?>
              <div class="lado-vacio">No mandó cupón</div>
              <div class="medio"><?= etiqueta('sin_cupon') ?></div>
            <?php endif; ?>
            <div class="lado">
              <div><span class="mono"><?= h(fecha_ar($m['fecha'])) ?></span><span class="mono" style="font-size: 15px"><?= dinero($m['monto']) ?></span></div>
              <span class="sub"><?= h(implode(' · ', array_filter([$m['nombre'] ?: 'Nombre no informado por el banco', $m['cuit'] ? 'CUIL ' . formato_cuit($m['cuit']) : null]))) ?></span>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>
<?php pie();
