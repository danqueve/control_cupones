<?php
/**
 * Crear o cambiar la contraseña de un usuario.
 * Uso:  php bin/crear_usuario.php <usuario> "<Nombre Apellido>"
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../app/bootstrap.php';

[$script, $usuario, $nombre] = array_pad($argv, 3, null);
if (!$usuario || !preg_match('/^[a-z0-9._-]{3,50}$/i', $usuario)) {
    fwrite(STDERR, "Uso: php bin/crear_usuario.php <usuario> \"<Nombre Apellido>\"\n");
    exit(1);
}

function pedir_clave(string $texto): string
{
    fwrite(STDOUT, $texto);
    @system('stty -echo 2>/dev/null');
    $clave = trim((string)fgets(STDIN));
    @system('stty echo 2>/dev/null');
    fwrite(STDOUT, "\n");
    return $clave;
}

$clave = pedir_clave('Contraseña (mínimo 8 caracteres): ');
if (strlen($clave) < 8) {
    fwrite(STDERR, "La contraseña es muy corta.\n");
    exit(1);
}
if (pedir_clave('Repetir contraseña: ') !== $clave) {
    fwrite(STDERR, "Las contraseñas no coinciden.\n");
    exit(1);
}

$hash = password_hash($clave, PASSWORD_DEFAULT);
$st = db()->prepare('SELECT id FROM usuarios WHERE usuario = ?');
$st->execute([$usuario]);
if ($id = $st->fetchColumn()) {
    db()->prepare('UPDATE usuarios SET pass_hash = ?, activo = 1, intentos = 0, bloqueado_hasta = NULL' . ($nombre ? ', nombre = ?' : '') . ' WHERE id = ?')
        ->execute($nombre ? [$hash, $nombre, $id] : [$hash, $id]);
    echo "Contraseña actualizada para '$usuario'.\n";
} else {
    db()->prepare('INSERT INTO usuarios (usuario, nombre, pass_hash) VALUES (?, ?, ?)')
        ->execute([$usuario, $nombre ?: $usuario, $hash]);
    echo "Usuario '$usuario' creado.\n";
}
