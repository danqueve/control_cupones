<?php
declare(strict_types=1);

/** Alta y listado de usuarios del sistema. */
final class Usuarios
{
    public const CLAVE_MIN = 8;

    /** @return array{error?:string, id?:int} */
    public static function crear(string $usuario, string $nombre, string $clave, string $clave2): array
    {
        $usuario = trim($usuario);
        $nombre = trim($nombre);

        if (!preg_match('/^[a-z0-9._-]{3,50}$/i', $usuario)) {
            return ['error' => 'El usuario debe tener entre 3 y 50 caracteres: letras, números, punto, guion o guion bajo.'];
        }
        if ($nombre === '' || mb_strlen($nombre) > 100) {
            return ['error' => 'Escribí el nombre y apellido (hasta 100 caracteres).'];
        }
        if (mb_strlen($clave) < self::CLAVE_MIN) {
            return ['error' => 'La contraseña es muy corta: mínimo ' . self::CLAVE_MIN . ' caracteres.'];
        }
        if (!hash_equals($clave, $clave2)) {
            return ['error' => 'Las contraseñas no coinciden.'];
        }

        $st = db()->prepare('SELECT 1 FROM usuarios WHERE usuario = ?');
        $st->execute([$usuario]);
        if ($st->fetchColumn()) {
            return ['error' => "Ya existe el usuario '$usuario'."];
        }

        db()->prepare('INSERT INTO usuarios (usuario, nombre, pass_hash) VALUES (?, ?, ?)')
            ->execute([$usuario, $nombre, password_hash($clave, PASSWORD_DEFAULT)]);
        return ['id' => (int)db()->lastInsertId()];
    }

    public static function listar(): array
    {
        return db()->query('SELECT id, usuario, nombre, activo, ultimo_acceso, creado_en FROM usuarios ORDER BY nombre')->fetchAll();
    }
}
