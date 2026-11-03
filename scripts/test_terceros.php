<?php
// ============================================================
// scripts/test_terceros.php
// ------------------------------------------------------------
// Casos de cruce cuando el pago lo hizo un tercero por el cliente.
//
// ESTE SCRIPT NO TOCA LA BASE — le pasa arrays al Conciliador,
// igual que test_normalizador.php.
//
// Uso: php scripts/test_terceros.php
// ============================================================

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Este script solo se ejecuta por línea de comandos.');
}

require __DIR__ . '/../app/Texto.php';
require __DIR__ . '/../app/Conciliador.php';

const CLIENTE = 30111222;   // DNI del cliente en SAS
const TERCERO = 27333444;   // DNI de quien le hace la transferencia
const OTRO    = 25999888;   // otro cliente, para los casos ambiguos

function fecha(string $f): DateTimeImmutable
{
    return new DateTimeImmutable($f);
}

/** Un cupón mínimo con lo que mira el Conciliador. */
function cupon(array $extra = []): array
{
    return $extra + [
        'fecha' => fecha('2026-10-10'),
        'monto' => 50000.0,
        'titular' => 'JUAN PEREZ',
        'nro_operacion' => '',
        'estado_bot' => null,
        'dni' => CLIENTE,
    ];
}

/** Una transferencia mínima con lo que mira el Conciliador. */
function mov(array $extra = []): array
{
    return $extra + [
        'fecha' => fecha('2026-10-10'),
        'monto' => 50000.0,
        'nombre' => '',
        'dni' => TERCERO,
        'referencia' => '',
        'concepto' => 'ING TRANSF',
    ];
}

$casos = [
    [
        'Tercero vinculado: el cupón del cliente se cruza con la transferencia del tercero',
        [1 => cupon()],
        [10 => mov()],
        [TERCERO => [CLIENTE]],
        ['estado' => 'ok', 'mov' => 10, 'detalle' => 'pagó un tercero'],
    ],
    [
        'Sin vínculo cargado: no se cruza por CUIL, queda a revisar por monto y fecha',
        [1 => cupon()],
        [10 => mov()],
        [],
        ['estado' => 'revisar', 'mov' => 10, 'detalle' => 'no el nombre'],
    ],
    [
        'Mismo DNI en cupón y banco: sigue cruzando igual que antes (sin tocar nada)',
        [1 => cupon()],
        [10 => mov(['dni' => CLIENTE])],
        [TERCERO => [CLIENTE]],
        ['estado' => 'ok', 'mov' => 10, 'detalle' => 'Coincide CUIL y monto'],
    ],
    [
        'Cupón cargado con el CUIL del tercero y transferencia del cliente: cruza al revés',
        [1 => cupon(['dni' => TERCERO])],
        [10 => mov(['dni' => CLIENTE])],
        [TERCERO => [CLIENTE]],
        ['estado' => 'ok', 'mov' => 10, 'detalle' => 'pagó un tercero'],
    ],
    [
        'Tercero que paga por dos clientes: el cupón define a cuál va',
        [1 => cupon(['dni' => OTRO])],
        [10 => mov()],
        [TERCERO => [CLIENTE, OTRO]],
        ['estado' => 'ok', 'mov' => 10, 'detalle' => 'pagó un tercero'],
    ],
    [
        'El N° de operación manda sobre todo: cruza aunque los DNI no tengan nada que ver',
        [1 => cupon(['dni' => null, 'nro_operacion' => '184877173627'])],
        [10 => mov(['dni' => 11111111, 'concepto' => 'ING TRANSF 184877173627'])],
        [],
        ['estado' => 'ok', 'mov' => 10, 'detalle' => 'N° de operación'],
    ],
    [
        'Vínculo que no corresponde a esta transferencia: no la cruza por CUIL',
        [1 => cupon()],
        [10 => mov(['dni' => 44444444])],
        [TERCERO => [CLIENTE]],
        ['estado' => 'revisar', 'mov' => 10, 'detalle' => 'no el nombre'],
    ],
    [
        'Fuera de la tolerancia de días: el vínculo no alcanza para cruzarlo',
        [1 => cupon(['fecha' => fecha('2026-10-01')])],
        [10 => mov(['fecha' => fecha('2026-10-20')])],
        [TERCERO => [CLIENTE]],
        ['estado' => 'espera', 'mov' => null, 'detalle' => ''],
    ],
];

$conc = new Conciliador(3, 5, 2);
$fallos = 0;

echo "=== Conciliador con pagadores terceros ===" . PHP_EOL . PHP_EOL;
foreach ($casos as [$desc, $cupones, $movs, $terceros, $esperado]) {
    $res = $conc->conciliar($cupones, $movs, [], $terceros);
    $r = $res['cupones'][1] ?? ['estado' => '?', 'detalle' => '', 'movimiento_id' => null];

    $okEstado = $r['estado'] === $esperado['estado'];
    $okMov = (int)($r['movimiento_id'] ?? 0) === (int)($esperado['mov'] ?? 0);
    $okDet = $esperado['detalle'] === '' || stripos((string)$r['detalle'], $esperado['detalle']) !== false;
    $ok = $okEstado && $okMov && $okDet;
    if (!$ok) {
        $fallos++;
    }

    printf("[%s] %s" . PHP_EOL, $ok ? 'OK' : 'FALLO', $desc);
    printf("       estado=%-8s mov=%-5s detalle=%s" . PHP_EOL,
        $r['estado'], $r['movimiento_id'] ?? '-', $r['detalle']);
    if (!$ok) {
        printf("       esperado: estado=%s mov=%s detalle~=%s" . PHP_EOL,
            $esperado['estado'], $esperado['mov'] ?? '-', $esperado['detalle'] ?: '(cualquiera)');
    }
    echo PHP_EOL;
}

// Un tercero vinculado no se queda con la transferencia de otra persona que
// tiene el mismo monto el mismo día: cada transferencia va a un solo cupón.
echo "=== Dos cupones del mismo monto, uno del cliente y otro ajeno ===" . PHP_EOL . PHP_EOL;
$res = $conc->conciliar(
    [1 => cupon(), 2 => cupon(['dni' => 44444444, 'titular' => 'OTRA PERSONA'])],
    [10 => mov(), 20 => mov(['dni' => 44444444, 'nombre' => 'OTRA PERSONA'])],
    [],
    [TERCERO => [CLIENTE]]
);
$cruceCliente = (int)($res['cupones'][1]['movimiento_id'] ?? 0);
$cruceAjeno = (int)($res['cupones'][2]['movimiento_id'] ?? 0);
$ok = $cruceCliente === 10 && $cruceAjeno === 20;
if (!$ok) {
    $fallos++;
}
printf("[%s] cliente→mov %d (esperado 10), ajeno→mov %d (esperado 20)" . PHP_EOL,
    $ok ? 'OK' : 'FALLO', $cruceCliente, $cruceAjeno);

echo PHP_EOL . ($fallos === 0 ? "=== Todos los casos OK ===" : "=== {$fallos} caso(s) FALLARON — revisar arriba ===") . PHP_EOL;
exit($fallos === 0 ? 0 : 1);
