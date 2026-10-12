<?php
declare(strict_types=1);

/**
 * Trae los cupones de la planilla de Google a la base local.
 * La planilla expone sus datos con apps-script/Exportador.gs (protegido con token).
 */
final class Planilla
{
    /** Lee la planilla solo si la última lectura tiene más de N minutos. */
    public static function sincronizarSiHaceFalta(): ?array
    {
        $ultima = ajuste('ultima_sincronizacion');
        $vigencia = (int)config('planilla.minutos_vigencia', 15) * 60;
        if ($ultima && time() - strtotime($ultima) < $vigencia) {
            return null;
        }
        return self::sincronizar();
    }

    /** @return array ['cupones' => int, 'nuevos' => int, 'zonas' => string[]] */
    public static function sincronizar(): array
    {
        $datos = self::descargar();
        $ahora = date('Y-m-d H:i:s');
        $pdo = db();

        $existentes = $pdo->query('SELECT clave FROM cupones')->fetchAll(PDO::FETCH_COLUMN);
        $existentes = array_flip($existentes);

        $sql = 'INSERT INTO cupones (clave, zona, fila_planilla, fecha_recepcion, fecha_cupon, hora, cobrador, titular, monto,
                    billetera, nro_operacion, cbu_alias, cuit, dni, estado_bot, msg_id, en_planilla, sincronizado_en)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
                ON DUPLICATE KEY UPDATE zona = VALUES(zona), fila_planilla = VALUES(fila_planilla),
                    fecha_cupon = VALUES(fecha_cupon), hora = VALUES(hora), titular = VALUES(titular), monto = VALUES(monto),
                    billetera = VALUES(billetera), nro_operacion = VALUES(nro_operacion), cbu_alias = VALUES(cbu_alias),
                    cuit = VALUES(cuit), dni = VALUES(dni), estado_bot = VALUES(estado_bot),
                    en_planilla = 1, sincronizado_en = VALUES(sincronizado_en)';

        $pdo->beginTransaction();
        try {
            $pdo->exec('UPDATE cupones SET en_planilla = 0');
            $st = $pdo->prepare($sql);
            $total = 0;
            $nuevos = 0;
            $zonas = [];
            foreach ($datos['zonas'] ?? [] as $zona) {
                $nombreZona = trim((string)($zona['nombre'] ?? ''));
                if ($nombreZona === '') {
                    continue;
                }
                $zonas[] = $nombreZona;
                $repetidas = [];
                foreach ($zona['filas'] ?? [] as $f) {
                    $fila = self::normalizarFila($f);
                    if ($fila['monto'] <= 0 && $fila['titular'] === '') {
                        continue;
                    }
                    $base = implode('|', [$nombreZona, $fila['fecha_recepcion'], $fila['cobrador'],
                        number_format($fila['monto'], 2, '.', ''), $fila['nro_operacion'], $fila['titular']]);
                    $repetidas[$base] = ($repetidas[$base] ?? 0) + 1;
                    $clave = sha1($base . '#' . $repetidas[$base]);
                    if (!isset($existentes[$clave])) {
                        $nuevos++;
                    }
                    $st->execute([
                        $clave, $nombreZona, $fila['fila'], $fila['fecha_recepcion'], $fila['fecha_cupon'], $fila['hora'],
                        $fila['cobrador'], $fila['titular'], $fila['monto'], $fila['billetera'], $fila['nro_operacion'],
                        $fila['cbu_alias'], $fila['cuit'], $fila['dni'], $fila['estado_bot'], $fila['msg_id'], $ahora,
                    ]);
                    $total++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        ajuste('ultima_sincronizacion', $ahora);
        return ['cupones' => $total, 'nuevos' => $nuevos, 'zonas' => $zonas];
    }

    private static function normalizarFila(array $f): array
    {
        $txt = fn($k, $max = 200) => mb_substr(trim((string)($f[$k] ?? '')), 0, $max);
        $fechaCupon = Texto::fechaTexto($f['fecha_comprobante'] ?? '') ?? Texto::fechaTexto($f['fecha_recepcion'] ?? '');
        $cbu = $txt('cbu', 150);
        $cuit = Texto::extraerCuit($txt('cuil')) ?: Texto::extraerCuit($cbu) ?: Texto::extraerCuit($txt('titular'));

        return [
            'fila' => isset($f['fila']) ? (int)$f['fila'] : null,
            'fecha_recepcion' => $txt('fecha_recepcion', 40),
            'fecha_cupon' => $fechaCupon ? $fechaCupon->format('Y-m-d') : null,
            'hora' => $txt('hora', 10),
            'cobrador' => $txt('remitente', 150),
            'titular' => $txt('titular', 200),
            'monto' => round(Texto::monto($f['monto'] ?? 0), 2),
            'billetera' => $txt('billetera', 100),
            'nro_operacion' => $txt('nro_operacion', 100),
            'cbu_alias' => $cbu,
            'cuit' => $cuit ?: null,
            'dni' => $cuit ? Texto::dniDeCuit($cuit) : null,
            'estado_bot' => $txt('estado', 60),
            'msg_id' => $txt('msg_id', 120),
        ];
    }

    private static function descargar(): array
    {
        $url = (string)config('planilla.url');
        $token = (string)config('planilla.token');
        if ($url === '' || str_contains($url, 'XXXX') || $token === '' || str_starts_with($token, 'CAMBIAR')) {
            throw new RuntimeException('Falta configurar la conexión con la planilla (app/config.php → planilla).');
        }

        $ch = curl_init($url . (str_contains($url, '?') ? '&' : '?') . 'token=' . rawurlencode($token));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,   // Apps Script responde con una redirección
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $cuerpo = curl_exec($ch);
        $error = curl_error($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($cuerpo === false) {
            throw new RuntimeException('No se pudo leer la planilla: ' . $error);
        }
        $datos = json_decode((string)$cuerpo, true);
        if (!is_array($datos)) {
            throw new RuntimeException('La planilla no respondió bien (código ' . $codigo . '). Revisá que el Exportador esté implementado con acceso "Cualquier usuario".');
        }
        if (empty($datos['ok'])) {
            throw new RuntimeException('La planilla rechazó la consulta: ' . ($datos['error'] ?? 'error desconocido'));
        }
        return $datos;
    }
}
