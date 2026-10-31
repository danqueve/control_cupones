<?php
// ============================================================
// scripts/test_normalizador.php
// ------------------------------------------------------------
// Test de Normalizador contra los formatos reales listados en
// plan-rendicion-transferencias-v2.md (sección 2) y pedidos
// explícitamente en prompt-claude-code-migracion-v2.md.
//
// ESTE SCRIPT NO TOCA LA BASE — es una tabla de casos puros.
//
// Uso: php scripts\test_normalizador.php
// ============================================================

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Este script solo se ejecuta por línea de comandos.');
}

require __DIR__ . '/../app/Texto.php';
require __DIR__ . '/../lib/Normalizador.php';

$zwsp = "\xE2\x80\x8B"; // zero-width space (U+200B)

$casos = [
    // [descripcion, crudo, cuil_esperado, dni_esperado]
    ['CUIL sin guiones (11 dígitos)', '20316199918', '20316199918', 31619991],
    ['CUIL con guiones (ejemplo del plan)', '27-34633047-1', '27346330471', 34633047],
    ['CUIL + CBU separados por /', '20-36867683-8 / 4530000800019020349079', '20368676838', 36867683],
    // Mismo CUIL que el primer caso (20316199918), con un zero-width
    // space insertado en el medio sin agregar ni quitar dígitos — tiene
    // que normalizar exactamente igual.
    ['CUIL con zero-width space en el medio', '203161' . $zwsp . '99918', '20316199918', 31619991],
    ['Campo vacío', '', null, null],
    ['Campo null', null, null, null],
    ['Solo CBU de 22 dígitos (no es CUIL)', '4530000800019020349079123', null, null],
];

echo "=== Normalizador::cuil() / dniOrigen() ===" . PHP_EOL . PHP_EOL;
$fallos = 0;
foreach ($casos as [$desc, $crudo, $cuilEsperado, $dniEsperado]) {
    $cuil = Normalizador::cuil($crudo);
    $dni  = Normalizador::dniOrigen($crudo);
    $ok   = ($cuil === $cuilEsperado) && ($dni === $dniEsperado);
    if (!$ok) $fallos++;
    printf(
        "[%s] %-45s crudo=%-45s cuil=%-15s dni=%s%s" . PHP_EOL,
        $ok ? 'OK' : 'FALLO',
        $desc,
        json_encode($crudo, JSON_UNESCAPED_UNICODE),
        $cuil ?? 'null',
        $dni ?? 'null',
        $ok ? '' : " (esperado cuil={$cuilEsperado}, dni={$dniEsperado})"
    );
}

echo PHP_EOL . "=== Normalizador::montoCentavos() ===" . PHP_EOL . PHP_EOL;
$casosMonto = [
    ['$ 153.000,00', 15300000],
    ['153000.00', 15300000],
    ['153000', 15300000],
    ['$ 1.234.567,89', 123456789],
    ['', null],
];
foreach ($casosMonto as [$crudo, $esperado]) {
    $r = Normalizador::montoCentavos($crudo);
    $ok = $r === $esperado;
    if (!$ok) $fallos++;
    printf("[%s] %-20s -> %s%s" . PHP_EOL, $ok ? 'OK' : 'FALLO', json_encode($crudo), $r ?? 'null', $ok ? '' : " (esperado {$esperado})");
}

echo PHP_EOL . "=== Normalizador::nroOperacion() ===" . PHP_EOL . PHP_EOL;
printf("limpia superíndice: %s" . PHP_EOL, Normalizador::nroOperacion('184877173627²'));
printf("limpia zero-width:  %s" . PHP_EOL, Normalizador::nroOperacion($zwsp . '184877173627' . $zwsp));

echo PHP_EOL . ($fallos === 0 ? "=== Todos los casos OK ===" : "=== {$fallos} caso(s) FALLARON — revisar arriba ===") . PHP_EOL;
exit($fallos === 0 ? 0 : 1);
