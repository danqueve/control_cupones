<?php
/**
 * Crea las tablas que falten (es seguro correrlo las veces que haga falta: no toca los datos).
 * Uso:  php bin/migrar.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../app/bootstrap.php';

$sql = (string)file_get_contents(__DIR__ . '/../sql/schema.sql');
$sql = preg_replace('/^\s*--.*$/m', '', $sql);          // saca los comentarios
$hechas = 0;
foreach (array_filter(array_map('trim', explode(';', $sql))) as $sentencia) {
    if (!preg_match('/^CREATE TABLE IF NOT EXISTS\s/i', $sentencia)) {
        continue;                                        // solo se ejecutan CREATE TABLE IF NOT EXISTS
    }
    db()->exec($sentencia);
    $hechas++;
}
echo "Listo: $hechas tablas verificadas.\n";
