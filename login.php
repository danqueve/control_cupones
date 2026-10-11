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
    <div class="marca-logo" aria-hidden="true">✓</div>
    <h1>Control de Cupones</h1>
    <p class="chico">Imperio Comercial · Banco Macro</p>
    <?php if ($error): ?><div class="aviso aviso-error" role="alert"><?= h($error) ?></div><?php endif; ?>
    <?= csrf_campo() ?>
    <label class="campo"><span>Usuario</span>
      <input type="text" name="usuario" required autofocus autocapitalize="none" autocomplete="username" value="<?= h($_POST['usuario'] ?? '') ?>">
    </label>
    <label class="campo"><span>Contraseña</span>
      <input type="password" name="clave" required autocomplete="current-password">
    </label>
    <button class="boton" type="submit">Ingresar</button>
  </form>
</div>
<?php pie();
