<?php
// ============================================================
// scripts/test_verificacion.php
// ------------------------------------------------------------
// Señales con las que se revisa si un pagador tercero está vinculado
// al cliente correcto (VerificacionTerceros::evaluar).
//
// ESTE SCRIPT NO TOCA LA BASE: le pasa arrays a evaluar().
//
// Uso: php scripts/test_verificacion.php
// ============================================================

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Este script solo se ejecuta por línea de comandos.');
}

require __DIR__ . '/../app/Texto.php';
require __DIR__ . '/../app/VerificacionTerceros.php';

const CLIENTE = 30111222;
const TERCERO = 27333444;
const OTRA    = 25999888;

function cupon(array $extra = []): array
{
    return $extra + ['id' => 1, 'dni' => CLIENTE, 'titular' => 'PEREZ JUAN CARLOS', 'cobrador' => 'Richard Ibarra', 'zona' => 'Suroeste', 'monto' => 42000.0];
}

function transf(int $id, float $monto, ?array $cupon, array $extra = []): array
{
    return $extra + ['id' => $id, 'monto' => $monto, 'excluida' => false, 'cupon' => $cupon];
}

/** Un vínculo base que "cierra": los cupones del cliente y los cruzados con el pagador coinciden. */
function base(array $extra = []): array
{
    return $extra + [
        'dni_tercero' => TERCERO, 'dni_cliente' => CLIENTE, 'otros_clientes' => [],
        'nombre_pagador' => 'LOPEZ MARIA ELENA', 'nombre_cliente' => 'PEREZ, JUAN CARLOS',
        'pagador_es_cliente' => false,
        'transferencias' => [
            transf(1, 42000.0, cupon(['id' => 10])),
            transf(2, 38000.0, cupon(['id' => 11, 'monto' => 38000.0])),
        ],
        'cupones_cliente' => [
            ['id' => 10, 'monto' => 42000.0, 'cobrador' => 'Richard Ibarra', 'zona' => 'Suroeste'],
            ['id' => 11, 'monto' => 38000.0, 'cobrador' => 'Richard Ibarra', 'zona' => 'Suroeste'],
        ],
        'cupones_pagador' => [],
    ];
}

function senal(array $r, string $clave): string
{
    foreach ($r['senales'] as $s) {
        if ($s['clave'] === $clave) {
            return $s['estado'];
        }
    }
    return '?';
}

$fallos = 0;
function chequear(string $desc, bool $ok, string $detalle = ''): void
{
    global $fallos;
    if (!$ok) {
        $fallos++;
    }
    printf("[%s] %s%s" . PHP_EOL, $ok ? 'OK' : 'FALLO', $desc, $detalle !== '' ? "  ($detalle)" : '');
}

echo "=== Verificación de vínculos de pagadores terceros ===" . PHP_EOL . PHP_EOL;

// 1) Todo coincide: pero los cupones del cliente cruzados por el vínculo NO cuentan como evidencia de cobrador
$r = VerificacionTerceros::evaluar(base());
chequear('vínculo coherente (importes coinciden, nada contradice) → ok', $r['estado'] === 'ok', $r['estado']);
chequear('...los importes dan ok', senal($r, 'importes') === 'ok');
chequear('...el cobrador no se infla con el cupón del propio cliente (sin datos)', senal($r, 'cobrador') === 'sin_datos', senal($r, 'cobrador'));

// 2) Cupones propios del pagador con el mismo cobrador y zona que el cliente → ok fuerte
$r = VerificacionTerceros::evaluar(base([
    'cupones_pagador' => [['id' => 90, 'cobrador' => 'richard ibarra', 'zona' => 'SUROESTE']],
]));
chequear('mismo cobrador y zona (sin importar mayúsculas) → ok', $r['estado'] === 'ok' && senal($r, 'cobrador') === 'ok' && senal($r, 'zona') === 'ok');

// 3) Cobrador y zona distintos → revisar
$r = VerificacionTerceros::evaluar(base([
    'cupones_pagador' => [['id' => 90, 'cobrador' => 'Pablo Soria', 'zona' => 'Norte']],
]));
chequear('el pagador tiene otro cobrador y otra zona que el cliente → revisar', $r['estado'] === 'revisar'
    && senal($r, 'cobrador') === 'mal' && senal($r, 'zona') === 'mal');

// 4) Transferencias cruzadas con el cupón de otra persona → revisar
$r = VerificacionTerceros::evaluar(base([
    'transferencias' => [
        transf(1, 42000.0, cupon(['id' => 20, 'dni' => OTRA, 'titular' => 'GOMEZ MARIA', 'cobrador' => 'Pablo Soria', 'zona' => 'Norte'])),
        transf(2, 38000.0, null),
    ],
]));
chequear('una transferencia cruzada con el cupón de otra persona → revisar', $r['estado'] === 'revisar' && senal($r, 'ajenos') === 'mal');
chequear('...y la transferencia se clasifica como "ajeno"', $r['transferencias'][0]['clase'] === 'ajeno' && $r['transferencias'][1]['clase'] === 'sin_cupon');
chequear('...el cobrador de ese cupón ajeno se compara con el del cliente → mal', senal($r, 'cobrador') === 'mal');

// 5) Un cupón de otro cliente del MISMO pagador no es "ajeno"
$r = VerificacionTerceros::evaluar(base([
    'otros_clientes' => [OTRA],
    'transferencias' => [transf(1, 42000.0, cupon(['id' => 20, 'dni' => OTRA, 'titular' => 'GOMEZ MARIA']))],
]));
chequear('cupón de otro cliente vinculado al mismo pagador → no es ajeno', senal($r, 'ajenos') !== 'mal' && $r['transferencias'][0]['clase'] === 'coincide');

// 6) Sin nada que comparar → sin_datos
$r = VerificacionTerceros::evaluar(base(['transferencias' => [], 'cupones_cliente' => []]));
chequear('vínculo nuevo, sin transferencias ni cupones → sin_datos', $r['estado'] === 'sin_datos', $r['estado']);

// 7) Importes que no coinciden con ningún cupón del cliente (2+ transferencias): señal mala pero no alcanza para "revisar"
$r = VerificacionTerceros::evaluar(base([
    'transferencias' => [transf(1, 11111.0, null), transf(2, 22222.0, null)],
]));
chequear('ningún importe coincide con los cupones del cliente → la señal es mala', senal($r, 'importes') === 'mal');
chequear('...pero solo, sin otra señal, el estado no es "ok"', $r['estado'] !== 'ok', $r['estado']);

// 8) El apellido igual NO alcanza para dar ok
$r = VerificacionTerceros::evaluar(base([
    'nombre_pagador' => 'PEREZ ANA', 'transferencias' => [], 'cupones_cliente' => [],
]));
chequear('compartir apellido es una pista pero no da "ok" por sí sola', senal($r, 'nombre') === 'ok' && $r['estado'] === 'sin_datos', $r['estado']);
chequear('no compartir apellido nunca es "mal"', senal(VerificacionTerceros::evaluar(base()), 'nombre') !== 'mal');

// 9) El pagador también es cliente de SAS y tiene cupones propios → revisar
$r = VerificacionTerceros::evaluar(base([
    'pagador_es_cliente' => true,
    'cupones_pagador' => [['id' => 90, 'cobrador' => 'Richard Ibarra', 'zona' => 'Suroeste']],
]));
chequear('el pagador es cliente de SAS y tiene cupones propios → revisar', $r['estado'] === 'revisar' && senal($r, 'pagador_cliente') === 'mal');
$r = VerificacionTerceros::evaluar(base(['pagador_es_cliente' => true]));
chequear('es cliente de SAS pero sin cupones propios → solo un aviso', senal($r, 'pagador_cliente') === 'sin_datos' && $r['estado'] === 'ok');

// 10) Transferencia sacada del vínculo: no cuenta en ninguna señal
$r = VerificacionTerceros::evaluar(base([
    'transferencias' => [
        transf(1, 42000.0, cupon(['id' => 20, 'dni' => OTRA, 'titular' => 'GOMEZ MARIA', 'cobrador' => 'Pablo Soria', 'zona' => 'Norte']), ['excluida' => true]),
        transf(2, 38000.0, cupon(['id' => 11, 'monto' => 38000.0])),
    ],
]));
chequear('la transferencia sacada del vínculo se marca "excluida" y no genera alerta', $r['transferencias'][0]['clase'] === 'excluida' && senal($r, 'ajenos') !== 'mal' && $r['estado'] === 'ok', $r['estado']);

// 11) Cupón sin CUIL: se decide por el nombre del titular
$r = VerificacionTerceros::evaluar(base([
    'transferencias' => [
        transf(1, 42000.0, cupon(['id' => 30, 'dni' => null, 'titular' => 'Juan Carlos Perez'])),
        transf(2, 42000.0, cupon(['id' => 31, 'dni' => null, 'titular' => 'Maria Elena Lopez'])),
        transf(3, 42000.0, cupon(['id' => 32, 'dni' => null, 'titular' => 'Roberto Quiroga'])),
    ],
]));
chequear('cupón sin CUIL con el nombre del cliente → coincide', $r['transferencias'][0]['clase'] === 'coincide');
chequear('...con el nombre del pagador → propio', $r['transferencias'][1]['clase'] === 'propio');
chequear('...con otro nombre → ajeno', $r['transferencias'][2]['clase'] === 'ajeno');

echo PHP_EOL . ($fallos === 0 ? "=== Todos los casos OK ===" : "=== {$fallos} caso(s) FALLARON — revisar arriba ===") . PHP_EOL;
exit($fallos === 0 ? 0 : 1);
