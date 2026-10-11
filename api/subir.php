<?php
require __DIR__ . '/../app/bootstrap.php';
$u = requerir_login(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder_json(['error' => 'Método no permitido.'], 405);
}

$entrada = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($entrada) || !is_string($entrada['csrf'] ?? null) || !hash_equals(csrf_token(), $entrada['csrf'])) {
    responder_json(['error' => 'La página venció. Recargala y volvé a elegir el archivo.'], 400);
}
$filas = $entrada['filas'] ?? null;
if (!is_array($filas) || !$filas) {
    responder_json(['error' => 'El archivo está vacío.'], 400);
}
if (count($filas) > 20000) {
    responder_json(['error' => 'El archivo es demasiado grande (más de 20.000 filas).'], 400);
}

set_time_limit(180);
try {
    $r = Controles::ejecutar($filas, (string)($entrada['archivo'] ?? ''), (int)$u['id']);
    if ($r['aviso']) {
        flash($r['aviso'], 'info');
    }
    responder_json(['id' => $r['id'], 'aviso' => (bool)$r['aviso']]);
} catch (PDOException $e) {
    error_log('[control_cupones] ' . $e);
    responder_json(['error' => 'Error de base de datos al guardar el control. Avisale a Alejandro.'], 500);
} catch (RuntimeException $e) {
    responder_json(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[control_cupones] ' . $e);
    responder_json(['error' => 'Ocurrió un error inesperado al comparar. Avisale a Alejandro.'], 500);
}
