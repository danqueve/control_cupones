<?php
declare(strict_types=1);

// api/v1/movimientos.php — GET read-only, server-to-server (Bearer token)
// Devuelve los movimientos del extracto Macro en un rango de fechas, cada
// uno con el resultado de la conciliación que YA corrió en este sistema
// (app/Conciliador.php) — no es un dato crudo para que el consumidor
// re-matchee por su cuenta.

require __DIR__ . '/../../app/bootstrap.php';

$inicio = microtime(true);

requerir_token_api();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    $r = ['error' => 'Método no permitido.'];
    log_api_request('/api/v1/movimientos', 405, $inicio);
    responder_json($r, 405);
}

$desde = trim((string)($_GET['desde'] ?? ''));
$hasta = trim((string)($_GET['hasta'] ?? ''));
$fecha_valida = static fn(string $f): bool => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && strtotime($f) !== false;

if (!$fecha_valida($desde) || !$fecha_valida($hasta) || $desde > $hasta) {
    log_api_request('/api/v1/movimientos', 400, $inicio, "desde=$desde hasta=$hasta");
    responder_json(['error' => 'Parámetros desde/hasta inválidos (formato YYYY-MM-DD, desde <= hasta).'], 400);
}
// Tope de rango para evitar consultas desmedidas (un trimestre de margen).
if ((strtotime($hasta) - strtotime($desde)) > 100 * 86400) {
    log_api_request('/api/v1/movimientos', 400, $inicio, 'rango>100d');
    responder_json(['error' => 'El rango no puede superar los 100 días.'], 400);
}

try {
    $st = db()->prepare("
        SELECT
            m.fecha, m.monto, m.concepto, m.referencia, m.codigo,
            m.cuit, m.dni, m.nombre,
            (m.cupon_id IS NOT NULL) AS vinculado_a_cupon,
            c.estado_conc   AS cupon_estado,
            c.cobrador      AS cupon_cobrador,
            c.zona          AS cupon_zona,
            c.titular       AS cupon_titular,
            c.nro_operacion AS cupon_nro_operacion
        FROM movimientos m
        LEFT JOIN cupones c ON c.id = m.cupon_id
        WHERE m.fecha BETWEEN ? AND ?
        ORDER BY m.fecha ASC, m.id ASC
    ");
    $st->execute([$desde, $hasta]);
    $filas = $st->fetchAll();

    $data = array_map(static function (array $m): array {
        return [
            'fecha'              => $m['fecha'],
            'monto'              => (float)$m['monto'],
            'concepto'           => $m['concepto'],
            'referencia'         => $m['referencia'],
            'codigo'             => $m['codigo'],
            'cuit'               => $m['cuit'],
            'dni'                => $m['dni'] !== null ? (int)$m['dni'] : null,
            'nombre'             => $m['nombre'],
            'vinculado_a_cupon'  => (bool)$m['vinculado_a_cupon'],
            'cupon_estado'       => $m['cupon_estado'],       // ok | revisar | no | espera | omitido | null
            'cupon_cobrador'     => $m['cupon_cobrador'],
            'cupon_zona'         => $m['cupon_zona'],
            'cupon_titular'      => $m['cupon_titular'],
            'cupon_nro_operacion' => $m['cupon_nro_operacion'],
        ];
    }, $filas);

    log_api_request('/api/v1/movimientos', 200, $inicio, 'rango=' . $desde . '..' . $hasta . ' filas=' . count($data));
    responder_json([
        'data' => $data,
        'meta' => [
            'desde'          => $desde,
            'hasta'          => $hasta,
            'total_registros' => count($data),
            'generado_en'    => date('c'),
        ],
    ]);
} catch (Throwable $e) {
    $error_id = bin2hex(random_bytes(4));
    error_log('[control_cupones] api/v1/movimientos error_id=' . $error_id . ': ' . $e);
    log_api_request('/api/v1/movimientos', 500, $inicio, 'error_id=' . $error_id);
    responder_json(['error' => 'Error interno.', 'error_id' => $error_id], 500);
}
