<?php
declare(strict_types=1);

// api/v1/comprobantes.php — GET read-only, server-to-server (Bearer token)
// Devuelve los comprobantes del bot de WhatsApp en un rango de fechas
// (por Fecha Comprobante), con CUIL/DNI de origen ya extraídos
// (lib/Normalizador.php, que delega en app/Texto.php — mismo extractor
// que ya usa la conciliación interna) y, si ya se concilió contra el
// extracto Macro, el resultado de esa conciliación.
//
// Reemplaza a /api/v1/movimientos.php como fuente para SAS Imperio
// (ver docs/SAS/plan-rendicion-transferencias-v2.md) — ese endpoint
// queda igual, sin tocar, por si sirve para otra cosa más adelante.

require __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/../../lib/Normalizador.php';

$inicio = microtime(true);

requerir_token_api();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    log_api_request('/api/v1/comprobantes', 405, $inicio);
    responder_json(['error' => 'Método no permitido.'], 405);
}

$desde = trim((string) ($_GET['desde'] ?? ''));
$hasta = trim((string) ($_GET['hasta'] ?? ''));
$fechaValida = static fn(string $f): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && strtotime($f) !== false;

if (!$fechaValida($desde) || !$fechaValida($hasta) || $desde > $hasta) {
    log_api_request('/api/v1/comprobantes', 400, $inicio, "desde=$desde hasta=$hasta");
    responder_json(['error' => 'Parámetros desde/hasta inválidos (formato YYYY-MM-DD, desde <= hasta).'], 400);
}
if ((strtotime($hasta) - strtotime($desde)) > 100 * 86400) {
    log_api_request('/api/v1/comprobantes', 400, $inicio, 'rango>100d');
    responder_json(['error' => 'El rango no puede superar los 100 días.'], 400);
}

try {
    $st = db()->prepare("
        SELECT c.id, c.msg_id, c.fecha_cupon, c.hora, c.monto, c.zona, c.cobrador, c.billetera,
               c.nro_operacion, c.titular, c.cbu_alias, c.cuit, c.dni, c.estado_bot, c.estado_conc,
               m.dni_efectivo, m.es_tercero
        FROM cupones c
        LEFT JOIN movimientos m ON m.id = c.movimiento_id
        WHERE c.fecha_cupon BETWEEN ? AND ?
        ORDER BY c.fecha_cupon ASC, c.hora ASC, c.id ASC
    ");
    $st->execute([$desde, $hasta]);
    $filas = $st->fetchAll();

    // Pagadores terceros vigentes, para los cupones que el bot cargó ya con el CUIL
    // del tercero pero todavía no se cruzaron contra ninguna transferencia del banco
    // (si ya se cruzaron, se usa el dni_efectivo real de esa transferencia, más abajo).
    $mapaTerceros = Terceros::mapaActivo();

    $data = array_map(static function (array $c) use ($mapaTerceros): array {
        $esDuplicado = (bool) preg_match('/DUPLICADO/i', (string) ($c['estado_bot'] ?? ''));

        $acreditadoMacro = match ($c['estado_conc']) {
            null    => null, // todavía no se concilió contra ningún extracto
            'ok'    => true,
            default => false, // revisar | no | espera | omitido: se concilió, no quedó acreditado
        };

        if ($c['dni_efectivo'] !== null) {
            // Ya se cruzó con una transferencia: se usa el dato real que decidió el sistema.
            $dniEfectivo = (int) $c['dni_efectivo'];
            $esTercero = (bool) $c['es_tercero'];
        } else {
            // Todavía sin cruzar: si el CUIL que trajo el bot ya es un pagador conocido, se
            // resuelve igual que lo haría el cruce (Terceros::resolverPrincipal).
            $dniOrigen = $c['dni'] !== null ? (int) $c['dni'] : null;
            $dniEfectivo = $dniOrigen !== null ? Terceros::resolverPrincipal($dniOrigen, $mapaTerceros) : null;
            $esTercero = $dniEfectivo !== null;
        }

        return [
            'id'                => $c['msg_id'] !== null && $c['msg_id'] !== '' ? $c['msg_id'] : ('cupon-' . $c['id']),
            'fecha_comprobante' => $c['fecha_cupon'],
            'hora'              => $c['hora'],
            'monto_centavos'    => Normalizador::montoCentavos($c['monto']),
            'grupo'             => $c['zona'],
            'remitente'         => $c['cobrador'],
            'banco'             => $c['billetera'],
            'nro_operacion'     => Normalizador::nroOperacion($c['nro_operacion']),
            'titular_origen'    => $c['titular'],
            // cuil_origen/dni_origen: se leen ya calculados por la sincronización
            // (Planilla::sincronizar() -> Texto::extraerCuitAjeno()/dniDeCuit()),
            // no se recalculan acá — mismo criterio que /api/v1/movimientos con
            // movimientos.dni. Si Texto.php mejora su extracción (ver fix de
            // zero-width space), el valor se actualiza en la próxima sincronización.
            'cuil_origen'       => $c['cuit'] ?: null,
            'dni_origen'        => $c['dni'] !== null ? (int) $c['dni'] : null,
            // Si dni_origen es el CUIL de un pagador vinculado a mano a un cliente de SAS
            // (ver app/Terceros.php), acá se informa a quién corresponde acreditar el pago;
            // si no, dni_cliente_sas viene null y el consumidor sigue usando dni_origen.
            ...Normalizador::payloadTercero($dniEfectivo, $esTercero),
            'estado'            => $esDuplicado ? 'duplicado' : 'ok',
            'acreditado_macro'  => $acreditadoMacro,
        ];
    }, $filas);

    log_api_request('/api/v1/comprobantes', 200, $inicio, 'rango=' . $desde . '..' . $hasta . ' filas=' . count($data));
    responder_json([
        'data' => $data,
        'meta' => [
            'desde'           => $desde,
            'hasta'           => $hasta,
            'total_registros' => count($data),
            'generado_en'     => date('c'),
        ],
    ]);
} catch (Throwable $e) {
    $errorId = bin2hex(random_bytes(4));
    error_log('[control_cupones] api/v1/comprobantes error_id=' . $errorId . ': ' . $e);
    log_api_request('/api/v1/comprobantes', 500, $inicio, 'error_id=' . $errorId);
    responder_json(['error' => 'Error interno.', 'error_id' => $errorId], 500);
}
