<?php
// ============================================================
// scripts/test_descartes.php
// ------------------------------------------------------------
// "No es el mismo pago": un cupón "a revisar" cuya transferencia es de
// otro cliente se puede descartar, y ese par no se vuelve a ofrecer.
//
// ESTE SCRIPT NO TOCA LA BASE — usa el Conciliador con arrays, igual
// que test_duplicados.php. Todos los datos son inventados.
//
// Uso: php scripts/test_descartes.php
// ============================================================

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Este script solo se ejecuta por línea de comandos.');
}

require __DIR__ . '/../app/Texto.php';
require __DIR__ . '/../app/Conciliador.php';

$fallos = 0;
function chequear(string $desc, bool $ok, string $extra = ''): void
{
    global $fallos;
    if (!$ok) {
        $fallos++;
    }
    echo '[' . ($ok ? 'OK' : 'FALLO') . '] ' . $desc . ($ok || $extra === '' ? '' : "  ($extra)") . PHP_EOL;
}

$conc = new Conciliador(3, 5, 2);
$cupon = fn(array $x) => $x + ['fecha' => new DateTimeImmutable('2026-11-05'), 'monto' => 42000.0, 'titular' => 'ELISA PRUEBA',
    'nro_operacion' => '', 'estado_bot' => 'ok', 'dni' => null];
// La transferencia de OTRO cliente: 3 días antes, mismo monto, sin nada que la una al cupón
$mov = fn(array $x) => $x + ['fecha' => new DateTimeImmutable('2026-11-02'), 'monto' => 42000.0, 'nombre' => 'MONGE JOAQUIN',
    'dni' => 43191279, 'referencia' => '', 'concepto' => 'TRANSF'];

echo "=== Cruce por monto y fecha con un cliente distinto ===" . PHP_EOL . PHP_EOL;

$cupones = [1 => $cupon([])];
$movs = [90 => $mov([])];

$r = $conc->conciliar($cupones, $movs)['cupones'];
chequear('sin descarte: se le ofrece la transferencia de otro cliente ("a revisar")',
    ($r[1]['estado'] ?? '') === 'revisar' && ($r[1]['movimiento_id'] ?? 0) === 90, json_encode($r[1] ?? null));

$res = $conc->conciliar($cupones, $movs, [], [], [], [1 => [90 => true]]);
$r = $res['cupones'];
chequear('con el par descartado: el cupón no se queda con esa transferencia',
    ($r[1]['movimiento_id'] ?? null) === null && !in_array($r[1]['estado'] ?? '', ['ok', 'revisar'], true), json_encode($r[1] ?? null));
chequear('...y la transferencia queda libre', !isset($res['movs'][90]));

echo PHP_EOL . "=== La transferencia queda libre para su cupón ===" . PHP_EOL . PHP_EOL;

// Aparece el cupón de Monge: se cruza solo por CUIL aunque el de Elisa lo haya tenido descartado
$cupones = [1 => $cupon([]), 2 => $cupon(['titular' => 'MONGE JOAQUIN ESTEBAN', 'dni' => 43191279, 'fecha' => new DateTimeImmutable('2026-11-02')])];
$r = $conc->conciliar($cupones, $movs, [], [], [], [1 => [90 => true]])['cupones'];
chequear('el cupón del dueño de la transferencia se cruza solo por CUIL',
    ($r[2]['estado'] ?? '') === 'ok' && ($r[2]['movimiento_id'] ?? 0) === 90, json_encode($r[2] ?? null));
chequear('el cupón de Elisa sigue sin esa transferencia', ($r[1]['movimiento_id'] ?? null) === null, json_encode($r[1] ?? null));

echo PHP_EOL . "=== El descarte vale en todos los pasos ===" . PHP_EOL . PHP_EOL;

// Paso 1: mismo N° de operación en el concepto
$r = $conc->conciliar(
    [1 => $cupon(['nro_operacion' => '8547MNQMDOG'])],
    [90 => $mov(['concepto' => 'TRANSF 8547MNQMDOG'])],
    [], [], [], [1 => [90 => true]]
)['cupones'];
chequear('paso 1 (N° de operación): descartado, no se cruza', ($r[1]['movimiento_id'] ?? null) === null, json_encode($r[1] ?? null));

// Paso 2: mismo CUIL
$r = $conc->conciliar(
    [1 => $cupon(['dni' => 43191279])],
    [90 => $mov([])],
    [], [], [], [1 => [90 => true]]
)['cupones'];
chequear('paso 2 (CUIL): descartado, no se cruza', ($r[1]['movimiento_id'] ?? null) === null, json_encode($r[1] ?? null));

// Paso 3: mismo nombre
$r = $conc->conciliar(
    [1 => $cupon(['titular' => 'Joaquin Monge'])],
    [90 => $mov([])],
    [], [], [], [1 => [90 => true]]
)['cupones'];
chequear('paso 3 (nombre): descartado, no se cruza', ($r[1]['movimiento_id'] ?? null) === null, json_encode($r[1] ?? null));

// Una confirmación a mano sobre un par descartado no lo cruza
$r = $conc->conciliar([1 => $cupon([])], [90 => $mov([])], [], [], [1 => 90], [1 => [90 => true]])['cupones'];
chequear('par confirmado y descartado a la vez: no se cruza', ($r[1]['movimiento_id'] ?? null) === null, json_encode($r[1] ?? null));

echo PHP_EOL . "=== Un descarte no afecta a otros pares ===" . PHP_EOL . PHP_EOL;

// Descartar la transferencia 90 para el cupón 1 no impide ofrecerle la 91 (otra del mismo monto)
$r = $conc->conciliar(
    [1 => $cupon([])],
    [90 => $mov([]), 91 => $mov(['nombre' => 'OTRA PERSONA', 'dni' => 20111222339])],
    [], [], [], [1 => [90 => true]]
)['cupones'];
chequear('se le ofrece otra transferencia del mismo monto', ($r[1]['movimiento_id'] ?? 0) === 91, json_encode($r[1] ?? null));

// El descarte es por cupón: otro cupón sí puede quedarse con esa transferencia
$r = $conc->conciliar(
    [1 => $cupon([]), 3 => $cupon(['titular' => 'MONGE JOAQUIN', 'fecha' => new DateTimeImmutable('2026-11-03')])],
    [90 => $mov([])],
    [], [], [], [1 => [90 => true]]
)['cupones'];
chequear('el descarte es solo para ese cupón', ($r[3]['movimiento_id'] ?? 0) === 90, json_encode($r[3] ?? null));

echo PHP_EOL . ($fallos === 0 ? "=== Todos los casos OK ===" : "=== {$fallos} caso(s) FALLARON — revisar arriba ===") . PHP_EOL;
exit($fallos === 0 ? 0 : 1);
