<?php
/**
 * Lee la planilla de Google y actualiza los cupones (opcional, para cron).
 * Uso:  php bin/sincronizar.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../app/bootstrap.php';

try {
    $r = Planilla::sincronizar();
    echo date('Y-m-d H:i') . " OK: {$r['cupones']} cupones ({$r['nuevos']} nuevos) en " . count($r['zonas']) . " zonas\n";
} catch (Throwable $e) {
    fwrite(STDERR, date('Y-m-d H:i') . ' ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
