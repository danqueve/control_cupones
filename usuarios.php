<?php
require __DIR__ . '/app/bootstrap.php';
$u = requerir_login();

$previo = ['usuario' => '', 'nombre' => ''];
$abrirForm = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf($_POST['csrf'] ?? null);
    $r = Usuarios::crear(
        (string)($_POST['usuario'] ?? ''),
        (string)($_POST['nombre'] ?? ''),
        (string)($_POST['clave'] ?? ''),
        (string)($_POST['clave2'] ?? '')
    );
    if (isset($r['error'])) {
        flash($r['error'], 'error');
        $previo = ['usuario' => trim((string)($_POST['usuario'] ?? '')), 'nombre' => trim((string)($_POST['nombre'] ?? ''))];
        $abrirForm = true;
    } else {
        flash("Usuario '" . trim((string)$_POST['usuario']) . "' creado.");
        header('Location: usuarios.php');
        exit;
    }
}

$usuarios = Usuarios::listar();

encabezado('Usuarios', 'usuarios');
?>
<header class="cabecera">
  <div>
    <h1>Usuarios</h1>
    <p>Personas que pueden ingresar al sistema. Cualquiera de ellas puede crear usuarios nuevos.</p>
  </div>
</header>

<section class="tarjeta tarjeta-plana">
  <div class="tabla-caja">
    <table class="tabla tabla-tarjetas" style="min-width: 560px">
      <thead>
        <tr><th>Nombre</th><th>Usuario</th><th>Último acceso</th><th>Creado</th></tr>
      </thead>
      <tbody>
        <?php foreach ($usuarios as $x): ?>
          <tr class="centrado">
            <td><strong><?= h($x['nombre']) ?></strong><?= (int)$x['id'] === (int)$u['id'] ? ' <span class="chico">(vos)</span>' : '' ?><?= $x['activo'] ? '' : ' <span class="chico">· desactivado</span>' ?></td>
            <td><span class="mono"><?= h($x['usuario']) ?></span></td>
            <td><?= $x['ultimo_acceso'] ? h(fecha_ar($x['ultimo_acceso'])) : '<span class="chico">Nunca ingresó</span>' ?></td>
            <td><?= h(fecha_ar($x['creado_en'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<details class="resolver"<?= $abrirForm ? ' open' : '' ?>>
  <summary class="boton boton-chico" style="align-self: flex-start">Crear usuario nuevo</summary>
  <form class="form-resolver" method="post" autocomplete="off">
    <?= csrf_campo() ?>
    <div class="comparar">
      <label class="campo">Nombre y apellido
        <input type="text" name="nombre" required maxlength="100" placeholder="Ej: Ana Pérez" value="<?= h($previo['nombre']) ?>">
      </label>
      <label class="campo">Usuario (para ingresar)
        <input type="text" name="usuario" required minlength="3" maxlength="50" pattern="[A-Za-z0-9._\-]{3,50}" autocapitalize="none" placeholder="Ej: aperez" value="<?= h($previo['usuario']) ?>">
      </label>
    </div>
    <div class="comparar">
      <label class="campo">Contraseña (mínimo <?= Usuarios::CLAVE_MIN ?> caracteres)
        <input type="password" name="clave" required minlength="<?= Usuarios::CLAVE_MIN ?>" autocomplete="new-password">
      </label>
      <label class="campo">Repetir contraseña
        <input type="password" name="clave2" required minlength="<?= Usuarios::CLAVE_MIN ?>" autocomplete="new-password">
      </label>
    </div>
    <div class="form-botones">
      <button type="button" class="boton boton-sec cancelar">Cancelar</button>
      <button type="submit" class="boton">Crear usuario</button>
    </div>
  </form>
</details>

<script>
(function () {
  document.querySelectorAll('.cancelar').forEach(function (b) {
    b.addEventListener('click', function () { b.closest('details').open = false; });
  });
})();
</script>
<?php pie();
