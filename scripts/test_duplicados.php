<?php
// ============================================================
// scripts/test_duplicados.php
// ------------------------------------------------------------
// Casos de transferencias y cupones repetidos, y de confirmaciones
// a mano atadas a su transferencia.
//
// ESTE SCRIPT NO TOCA LA BASE — usa Banco::parsear() y el Conciliador
// con arrays, igual que test_normalizador.php y test_terceros.php.
// Todos los datos son inventados.
//
// Uso: php scripts/test_duplicados.php
// ============================================================

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Este script solo se ejecuta por línea de comandos.');
}

require __DIR__ . '/../app/Texto.php';
require __DIR__ . '/../app/Banco.php';
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

/** Un resumen del banco con las filas dadas: [fecha, referencia, concepto, crédito]. */
function resumen(array $filas): array
{
    $excel = [['Fecha', 'Referencia', 'Codigo Causal', 'Concepto', 'Debito', 'Credito', 'Saldo']];
    foreach ($filas as $f) {
        $excel[] = [$f[0], $f[1], '493', $f[2], '', $f[3], ''];
    }
    return Banco::parsear($excel)['movs'];
}

echo "=== Clave de las transferencias ===" . PHP_EOL . PHP_EOL;

// El banco primero la informa sin nombre y en el resumen siguiente con nombre
$antes = resumen([['13/10/2026', '554914', 'TRANSF  27111222333 VAR', '41000,00']]);
$despues = resumen([['13/10/2026', '554914', 'TRANSF ANA PRUEBA 27111222333 VAR VARIOS', '41000,00']]);
chequear('misma transferencia con el concepto reescrito → misma clave', $antes[0]['clave'] === $despues[0]['clave']);
chequear('el resumen nuevo sí trae el nombre', $despues[0]['nombre'] === 'ANA PRUEBA', var_export($despues[0]['nombre'], true));

// Dos transferencias distintas de la misma persona, mismo día y monto: la referencia las separa
$dos = resumen([
    ['13/10/2026', '100001', 'TRANSF  27111222333 VAR', '41000,00'],
    ['13/10/2026', '100002', 'TRANSF  27111222333 VAR', '41000,00'],
]);
chequear('dos transferencias con distinta referencia → claves distintas', $dos[0]['clave'] !== $dos[1]['clave']);

// Sin referencia, el concepto sigue siendo lo único que las distingue (comportamiento anterior)
$sinRefA = resumen([['13/10/2026', '', 'TRANSF  27111222333 VAR', '41000,00']]);
$sinRefB = resumen([['13/10/2026', '', 'TRANSF ANA PRUEBA 27111222333 VAR', '41000,00']]);
chequear('sin referencia, el concepto distinto da otra clave', $sinRefA[0]['clave'] !== $sinRefB[0]['clave']);

// La misma fila dos veces en un mismo archivo (sin referencia) no se pisa
$repetida = resumen([
    ['13/10/2026', '', 'TRANSF  27111222333 VAR', '41000,00'],
    ['13/10/2026', '', 'TRANSF  27111222333 VAR', '41000,00'],
]);
chequear('fila idéntica repetida en un archivo → claves distintas', $repetida[0]['clave'] !== $repetida[1]['clave']);

echo PHP_EOL . "=== Cupones repetidos ===" . PHP_EOL . PHP_EOL;

$fecha = new DateTimeImmutable('2026-10-10');
$cupon = fn(array $x) => $x + ['fecha' => $fecha, 'monto' => 38000.0, 'titular' => 'ANA PRUEBA', 'nro_operacion' => '', 'estado_bot' => 'ok', 'dni' => null];
$mov = fn(array $x) => $x + ['fecha' => $fecha, 'monto' => 38000.0, 'nombre' => '', 'dni' => null, 'referencia' => '', 'concepto' => 'TRANSF'];
$conc = new Conciliador(3, 5, 2);

$r = $conc->conciliar(
    [
        12 => $cupon(['nro_operacion' => '326470']),
        11 => $cupon(['nro_operacion' => '326470']),
        13 => $cupon(['nro_operacion' => '326470', 'monto' => 30000.0]),   // mismo número, otro monto: no es el mismo
    ],
    [90 => $mov(['concepto' => 'TRANSF 326470'])]
)['cupones'];
chequear('se queda el primero (menor id) y cruza', ($r[11]['estado'] ?? '') === 'ok', json_encode($r[11] ?? null));
chequear('el repetido queda omitido como "Cupón duplicado"',
    ($r[12]['estado'] ?? '') === 'omitido' && ($r[12]['detalle'] ?? '') === 'Cupón duplicado', json_encode($r[12] ?? null));
chequear('mismo N° de operación con otro monto no se toma como repetido', ($r[13]['estado'] ?? '') !== 'omitido', json_encode($r[13] ?? null));

echo PHP_EOL . "=== Confirmaciones a mano ===" . PHP_EOL . PHP_EOL;

// Dos cupones del mismo monto y fecha sin nada que los distinga, una sola transferencia.
// Sin confirmación, el paso de monto+fecha se la da a uno cualquiera.
$cupones = [1 => $cupon(['titular' => 'BETO OTRO']), 2 => $cupon(['titular' => 'CARLA OTRA'])];
$movs = [90 => $mov(['nombre' => 'NADIE CONOCIDO'])];
$r = $conc->conciliar($cupones, $movs, [], [], [2 => 90])['cupones'];
chequear('la confirmación se respeta: el cupón confirmado queda con su transferencia',
    ($r[2]['estado'] ?? '') === 'ok' && ($r[2]['movimiento_id'] ?? 0) === 90, json_encode($r[2] ?? null));
chequear('el otro cupón no se la lleva', ($r[1]['movimiento_id'] ?? null) !== 90, json_encode($r[1] ?? null));

// Si aparece un cupón que coincide por CUIL con esa transferencia, gana el CUIL
$cupones = [1 => $cupon(['titular' => 'CARLA OTRA']), 2 => $cupon(['titular' => 'DIEGO DUENO', 'dni' => 11222333])];
$movs = [90 => $mov(['dni' => 11222333])];
$r = $conc->conciliar($cupones, $movs, [], [], [1 => 90])['cupones'];
chequear('un cruce por CUIL le gana a una confirmación a mano',
    ($r[2]['estado'] ?? '') === 'ok' && ($r[2]['movimiento_id'] ?? 0) === 90, json_encode($r[2] ?? null));
chequear('el cupón confirmado no queda acreditado con plata de otro',
    !(($r[1]['estado'] ?? '') === 'ok' && ($r[1]['movimiento_id'] ?? 0) === 90), json_encode($r[1] ?? null));

echo PHP_EOL . ($fallos === 0 ? "=== Todos los casos OK ===" : "=== {$fallos} caso(s) FALLARON — revisar arriba ===") . PHP_EOL;
exit($fallos === 0 ? 0 : 1);
