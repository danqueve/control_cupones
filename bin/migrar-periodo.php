#!/usr/bin/env php
<?php
/**
 * Migración: agrega columna `periodo` a la tabla cupones.
 *
 * Ejecutar una sola vez en el VPS:
 *   /usr/local/lsws/lsphp83/bin/php bin/migrar-periodo.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../app/bootstrap.php';

$pdo = db();

// 1. Agregar columna si no existe
$cols = $pdo->query("SHOW COLUMNS FROM cupones LIKE 'periodo'")->fetchAll();
if (empty($cols)) {
    $pdo->exec("ALTER TABLE cupones ADD COLUMN periodo CHAR(7) NULL AFTER zona");
    echo "✓ Columna 'periodo' agregada.\n";
} else {
    echo "· Columna 'periodo' ya existe.\n";
}

// 2. Agregar índice si no existe
$indices = $pdo->query("SHOW INDEX FROM cupones WHERE Key_name = 'idx_cup_periodo'")->fetchAll();
if (empty($indices)) {
    $pdo->exec("CREATE INDEX idx_cup_periodo ON cupones(periodo)");
    echo "✓ Índice 'idx_cup_periodo' creado.\n";
} else {
    echo "· Índice 'idx_cup_periodo' ya existe.\n";
}

// 3. Backfill: calcular periodo desde fecha_recepcion (formato dd/mm/yyyy)
$sinPeriodo = $pdo->query("SELECT COUNT(*) FROM cupones WHERE periodo IS NULL AND fecha_recepcion IS NOT NULL AND fecha_recepcion != ''")->fetchColumn();
if ($sinPeriodo > 0) {
    // fecha_recepcion es VARCHAR con formato dd/mm/yyyy
    $actualizado = $pdo->exec("
        UPDATE cupones
        SET periodo = CONCAT(
            SUBSTRING(fecha_recepcion, 7, 4), '-',
            SUBSTRING(fecha_recepcion, 4, 2)
        )
        WHERE periodo IS NULL
          AND fecha_recepcion IS NOT NULL
          AND fecha_recepcion != ''
          AND LENGTH(fecha_recepcion) >= 10
    ");
    echo "✓ Backfill: {$actualizado} cupones actualizados con periodo.\n";
} else {
    echo "· No hay cupones sin periodo para actualizar.\n";
}

echo "\nMigración completada.\n";
