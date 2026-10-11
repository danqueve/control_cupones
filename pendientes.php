<?php
require __DIR__ . '/app/bootstrap.php';
$u = requerir_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf($_POST['csrf'] ?? null);
    $id = (int)($_POST['id'] ?? 0);
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'resolver') {
        $nota = trim((string)($_POST['nota'] ?? ''));
        db()->prepare("UPDATE pendientes SET estado = 'resuelto', nota = ?, resuelto_en = NOW(), resuelto_por = ? WHERE id = ? AND estado = 'pendiente'")
            ->execute([$nota !== '' ? mb_substr($nota, 0, 500) : 'Resuelto a mano', $u['nombre'], $id]);
        flash('Listo, se marcó como resuelto.');
    } elseif ($accion === 'reabrir') {
        db()->prepare("UPDATE pendientes SET estado = 'pendiente', resuelto_en = NULL, resuelto_por = NULL WHERE id = ?")
            ->execute([$id]);
        flash('Se volvió a poner en pendientes.');
    }
    header('Location: pendientes.php' . (isset($_GET['ver']) ? '?ver=resueltos' : ''));
    exit;
}

$verResueltos = ($_GET['ver'] ?? '') === 'resueltos';
$st = db()->prepare("SELECT p.*, c.zona, c.fecha_cupon, c.cobrador, c.titular, c.monto, c.billetera, c.nro_operacion, c.dni, c.estado_conc
                     FROM pendientes p JOIN cupones c ON c.id = p.cupon_id
                     WHERE p.estado = ?
                     ORDER BY " . ($verResueltos ? 'p.resuelto_en DESC LIMIT 200' : 'c.fecha_cupon ASC'));
$st->execute([$verResueltos ? 'resuelto' : 'pendiente']);
$lista = $st->fetchAll();
$total = array_sum(array_column($lista, 'monto'));

encabezado('Pendientes', 'pendientes');
?>
<div class="titulo-pagina">
  <h1>Pendientes de seguir</h1>
  <p class="chico">Cupones que llegaron por WhatsApp pero la plata <strong>no figura en el banco</strong>.
    Si en un próximo resumen aparece, se resuelven solos. Si no, consultalo con el cobrador y marcalo como resuelto con una nota.</p>
</div>

<nav class="filtros">
  <a class="<?= !$verResueltos ? 'activo' : '' ?>" href="pendientes.php">Pendientes</a>
  <a class="<?= $verResueltos ? 'activo' : '' ?>" href="pendientes.php?ver=resueltos">Resueltos</a>
</nav>

<?php if (!$verResueltos && $lista): ?>
  <p><strong><?= count($lista) ?></strong> cupones · <strong class="num"><?= dinero($total) ?></strong> sin acreditar</p>
<?php endif; ?>

<div class="lista">
  <?php if (!$lista): ?>
    <div class="tarjeta vacio"><?= $verResueltos ? 'Todavía no hay pendientes resueltos.' : '🎉 No hay cupones pendientes.' ?></div>
  <?php endif; ?>
  <?php foreach ($lista as $p): ?>
    <article class="item <?= $verResueltos ? 'b-ok' : 'b-no' ?>">
      <div class="item-nombre"><?= h($p['titular'] ?: 'Titular no leído') ?></div>
      <div class="item-monto num"><?= dinero($p['monto']) ?></div>
      <div class="item-datos">
        <span>📍 <?= h($p['zona']) ?></span>
        <span>👤 <?= h($p['cobrador']) ?></span>
        <span>📅 cupón del <?= h(fecha_ar($p['fecha_cupon'])) ?></span>
        <?php if ($p['billetera']): ?><span>🏦 <?= h($p['billetera']) ?></span><?php endif; ?>
        <?php if ($p['nro_operacion']): ?><span>N° <?= h($p['nro_operacion']) ?></span><?php endif; ?>
        <span>Detectado en el <a href="control.php?id=<?= (int)$p['control_id'] ?>&f=no">control #<?= (int)$p['control_id'] ?></a></span>
      </div>
      <?php if ($verResueltos): ?>
        <div class="item-banco">✅ <?= h($p['nota']) ?> · <?= h($p['resuelto_por']) ?>, <?= h(fecha_ar($p['resuelto_en'])) ?></div>
        <div class="item-acciones">
          <form method="post" action="pendientes.php?ver=resueltos">
            <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="accion" value="reabrir">
            <button class="boton boton-sec boton-chico" type="submit">Volver a pendientes</button>
          </form>
        </div>
      <?php else: ?>
        <div class="item-acciones">
          <details class="resolver">
            <summary class="boton boton-sec boton-chico">Marcar como resuelto</summary>
            <form method="post">
              <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="accion" value="resolver">
              <label class="campo" style="margin:0"><span>¿Qué pasó? (opcional)</span>
                <textarea name="nota" rows="2" placeholder="Ej: el cliente pagó en efectivo / el cupón era falso / entró en otra cuenta"></textarea>
              </label>
              <button class="boton boton-chico" type="submit">Confirmar</button>
            </form>
          </details>
        </div>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</div>
<?php pie();
