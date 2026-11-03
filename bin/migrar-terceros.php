#!/usr/bin/env php
<?php
/**
 * Migración: pagadores terceros (clientes que pagan desde la cuenta de otro).
 *
 * Crea la tabla pagadores_terceros y agrega dni_efectivo / es_tercero a
 * movimientos y control_items. Es seguro correrlo las veces que haga falta.
 *
 * Ejecutar en el VPS:
 *   /usr/local/lsws/lsphp83/bin/php bin/migrar-terceros.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../app/bootstrap.php';

$pdo = db();

/** Agrega una columna si todavía no está. */
$columna = function (string $tabla, string $nombre, string $definicion) use ($pdo): void {
    if ($pdo->query("SHOW COLUMNS FROM $tabla LIKE '$nombre'")->fetchAll()) {
        echo "· $tabla.$nombre ya existe.\n";
        return;
    }
    $pdo->exec("ALTER TABLE $tabla ADD COLUMN $nombre $definicion");
    echo "✓ $tabla.$nombre agregada.\n";
};

/** Crea un índice si todavía no está. */
$indice = function (string $tabla, string $nombre, string $columnas) use ($pdo): void {
    if ($pdo->query("SHOW INDEX FROM $tabla WHERE Key_name = '$nombre'")->fetchAll()) {
        echo "· Índice $nombre ya existe.\n";
        return;
    }
    $pdo->exec("CREATE INDEX $nombre ON $tabla($columnas)");
    echo "✓ Índice $nombre creado.\n";
};

// 1. Tabla de vínculos
$existia = (bool)$pdo->query("SHOW TABLES LIKE 'pagadores_terceros'")->fetchAll();
$pdo->exec("CREATE TABLE IF NOT EXISTS pagadores_terceros (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dni_tercero     INT UNSIGNED  NOT NULL,
  cuit_tercero    VARCHAR(11)   NULL,
  nombre_tercero  VARCHAR(200)  NULL,
  dni_cliente_sas INT UNSIGNED  NOT NULL,
  es_principal    TINYINT(1)    NOT NULL DEFAULT 0,
  nota            VARCHAR(300)  NULL,
  activo          TINYINT(1)    NULL DEFAULT 1,
  creado_por      VARCHAR(100)  NOT NULL,
  creado_en       DATETIME      NOT NULL,
  desactivado_por VARCHAR(100)  NULL,
  desactivado_en  DATETIME      NULL,
  UNIQUE KEY uq_terc_cli_activo (dni_tercero, dni_cliente_sas, activo),
  INDEX idx_terc_dni (dni_tercero, activo),
  INDEX idx_terc_cliente (dni_cliente_sas, activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo $existia ? "· Tabla pagadores_terceros ya existe.\n" : "✓ Tabla pagadores_terceros creada.\n";

// 2. Columnas nuevas
$columna('movimientos', 'dni_efectivo', 'INT UNSIGNED NULL AFTER dni');
$columna('movimientos', 'es_tercero', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER dni_efectivo');
$indice('movimientos', 'idx_mov_efec', 'dni_efectivo');

$columna('control_items', 'dni_efectivo', 'INT UNSIGNED NULL AFTER dni');
$columna('control_items', 'es_tercero', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER dni_efectivo');

// 3. Backfill: mientras no haya vínculos cargados, el pago es de quien figura en el banco
foreach (['movimientos', 'control_items'] as $tabla) {
    $n = $pdo->exec("UPDATE $tabla SET dni_efectivo = dni WHERE dni_efectivo IS NULL AND dni IS NOT NULL");
    echo "✓ $tabla: $n fila(s) con dni_efectivo completado.\n";
}

echo "\nMigración completada.\n";
