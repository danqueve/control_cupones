<?php
require __DIR__ . '/app/bootstrap.php';

if (usuario()) {
    header('Location: index.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf($_POST['csrf'] ?? null);
    $error = intentar_login((string)($_POST['usuario'] ?? ''), (string)($_POST['clave'] ?? ''));
    if (!$error) {
        header('Location: index.php');
        exit;
    }
}

encabezado('Ingresar');
?>
<div class="login">
  <form class="tarjeta" method="post" autocomplete="on">
    <span class="marca-logo" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
    <div style="display: flex; flex-direction: column; gap: 4px">
      <h1>Control de Cupones</h1>
      <p class="chico">Imperio Comercial · Banco Macro</p>
    </div>
    <?php if ($error): ?><div class="aviso aviso-error" role="alert"><?= icono('alerta') ?><span><?= h($error) ?></span></div><?php endif; ?>
    <?= csrf_campo() ?>
    <label class="campo">Usuario
      <input type="text" name="usuario" required autofocus autocapitalize="none" autocomplete="username" value="<?= h($_POST['usuario'] ?? '') ?>">
    </label>
    <label class="campo">Contraseña
      <input type="password" name="clave" required autocomplete="current-password">
    </label>
    <button class="boton" type="submit">Ingresar</button>
  </form>
</div>
<?php pie();
