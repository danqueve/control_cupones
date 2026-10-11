<?php
declare(strict_types=1);

/**
 * Carga configuración, base de datos, sesión y funciones comunes.
 * Toda página PHP pública empieza con: require __DIR__ . '/app/bootstrap.php';
 */

if (!is_file(__DIR__ . '/config.php')) {
    http_response_code(500);
    exit('Falta app/config.php (copiar app/config.example.php y completar).');
}

$CONFIG = require __DIR__ . '/config.php';
date_default_timezone_set($CONFIG['zona_horaria'] ?? 'America/Argentina/Tucuman');

require_once __DIR__ . '/Texto.php';
require_once __DIR__ . '/Banco.php';
require_once __DIR__ . '/Conciliador.php';
require_once __DIR__ . '/Planilla.php';
require_once __DIR__ . '/Sas.php';
require_once __DIR__ . '/Controles.php';
require_once __DIR__ . '/Busqueda.php';
require_once __DIR__ . '/layout.php';

function config(string $clave, $defecto = null)
{
    global $CONFIG;
    $valor = $CONFIG;
    foreach (explode('.', $clave) as $parte) {
        if (!is_array($valor) || !array_key_exists($parte, $valor)) {
            return $defecto;
        }
        $valor = $valor[$parte];
    }
    return $valor;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . config('db.host') . ';dbname=' . config('db.name') . ';charset=utf8mb4',
            config('db.user'),
            config('db.pass'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function ajuste(string $clave, ?string $valor = null): ?string
{
    if ($valor !== null) {
        db()->prepare('INSERT INTO ajustes (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')
            ->execute([$clave, $valor]);
        return $valor;
    }
    $st = db()->prepare('SELECT valor FROM ajustes WHERE clave = ?');
    $st->execute([$clave]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

// ─── Sesión y acceso ─────────────────────────────────────────────
function iniciar_sesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('control_cupones');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    // Cierra la sesión tras 8 horas sin uso
    if (isset($_SESSION['ultimo_uso']) && time() - $_SESSION['ultimo_uso'] > 8 * 3600) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['ultimo_uso'] = time();
}

function usuario(): ?array
{
    iniciar_sesion();
    return $_SESSION['usuario'] ?? null;
}

function requerir_login(bool $json = false): array
{
    $u = usuario();
    if ($u) {
        return $u;
    }
    if ($json) {
        responder_json(['error' => 'La sesión se cerró. Volvé a ingresar.'], 401);
    }
    header('Location: login.php');
    exit;
}

function intentar_login(string $usuario, string $clave): ?string
{
    $st = db()->prepare('SELECT * FROM usuarios WHERE usuario = ? AND activo = 1');
    $st->execute([trim($usuario)]);
    $u = $st->fetch();

    if ($u && $u['bloqueado_hasta'] && strtotime($u['bloqueado_hasta']) > time()) {
        return 'Demasiados intentos. Esperá unos minutos y volvé a probar.';
    }
    if (!$u || !password_verify($clave, $u['pass_hash'])) {
        if ($u) {
            $intentos = (int)$u['intentos'] + 1;
            $bloqueo = $intentos >= 5 ? date('Y-m-d H:i:s', time() + 15 * 60) : null;
            db()->prepare('UPDATE usuarios SET intentos = ?, bloqueado_hasta = ? WHERE id = ?')
                ->execute([$bloqueo ? 0 : $intentos, $bloqueo, $u['id']]);
        }
        return 'Usuario o contraseña incorrectos.';
    }

    db()->prepare('UPDATE usuarios SET intentos = 0, bloqueado_hasta = NULL, ultimo_acceso = NOW() WHERE id = ?')
        ->execute([$u['id']]);
    iniciar_sesion();
    session_regenerate_id(true);
    $_SESSION['usuario'] = ['id' => (int)$u['id'], 'usuario' => $u['usuario'], 'nombre' => $u['nombre']];
    return null;
}

function cerrar_sesion(): void
{
    iniciar_sesion();
    $_SESSION = [];
    session_destroy();
}

// ─── Protección de formularios (CSRF) ────────────────────────────
function csrf_token(): string
{
    iniciar_sesion();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_campo(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function verificar_csrf(?string $token): void
{
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(400);
        exit('La página venció. Volvé atrás y recargala.');
    }
}

// ─── Utilidades de salida ────────────────────────────────────────
function h($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function dinero($n): string
{
    return '$ ' . number_format((float)$n, 2, ',', '.');
}

function fecha_ar(?string $fecha): string
{
    if (!$fecha) {
        return '';
    }
    $t = strtotime($fecha);
    return $t ? date(strlen($fecha) > 10 ? 'd/m/Y H:i' : 'd/m/Y', $t) : '';
}

function responder_json(array $datos, int $codigo = 200): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Etiquetas y colores de cada estado de conciliación. */
function estado_info(string $estado): array
{
    $map = [
        'ok'        => ['Acreditado', 'ok', '✅'],
        'revisar'   => ['A revisar', 'revisar', '🟡'],
        'no'        => ['No figura en el banco', 'no', '❌'],
        'espera'    => ['En espera', 'espera', '⏳'],
        'omitido'   => ['No se controla', 'omitido', '⚪'],
        'sin_cupon' => ['Sin cupón', 'sincupon', '💵'],
    ];
    [$texto, $clase, $icono] = $map[$estado] ?? ['Sin controlar', 'omitido', '·'];
    return ['texto' => $texto, 'clase' => $clase, 'icono' => $icono];
}
