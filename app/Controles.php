<?php
declare(strict_types=1);

/**
 * Un "control" = subir un resumen del banco, cruzarlo con los cupones
 * y guardar la foto del resultado en el historial.
 */
final class Controles
{
    /**
     * @return array ['id' => int, 'aviso' => ?string]
     */
    public static function ejecutar(array $filas, string $archivo, int $usuarioId): array
    {
        $banco = Banco::parsear($filas);   // valida el archivo antes de tocar nada

        $aviso = null;
        try {
            Planilla::sincronizar();
        } catch (Throwable $e) {
            $aviso = 'No se pudo leer la planilla en este momento; se usaron los cupones de la última lectura ('
                . fecha_ar(ajuste('ultima_sincronizacion')) . '). Detalle: ' . $e->getMessage();
        }

        $pdo = db();
        if ((int)$pdo->query("SELECT GET_LOCK('control_cupones', 60)")->fetchColumn() !== 1) {
            throw new RuntimeException('Hay otro control en curso. Probá de nuevo en un minuto.');
        }

        try {
            $pdo->beginTransaction();

            $pdo->prepare('INSERT INTO controles (creado_en, usuario_id, archivo, desde, hasta, mov_archivo) VALUES (NOW(), ?, ?, ?, ?, ?)')
                ->execute([$usuarioId, mb_substr($archivo, 0, 200), $banco['desde']->format('Y-m-d'), $banco['hasta']->format('Y-m-d'), count($banco['movs'])]);
            $controlId = (int)$pdo->lastInsertId();

            // 1) Guardar transferencias nuevas (las repetidas de resúmenes anteriores se ignoran)
            $ins = $pdo->prepare('INSERT IGNORE INTO movimientos (clave, fecha, cuit, dni, nombre, monto, referencia, codigo, concepto, control_id)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $nuevos = 0;
            foreach ($banco['movs'] as $m) {
                $ins->execute([$m['clave'], $m['fecha']->format('Y-m-d'), $m['cuit'], $m['dni'], $m['nombre'], $m['monto'],
                    $m['referencia'], $m['codigo'], $m['concepto'], $controlId]);
                $nuevos += $ins->rowCount();
            }

            // 2) Cruzar TODOS los cupones contra TODO el historial del banco
            $cupones = [];
            foreach ($pdo->query('SELECT id, fecha_cupon, monto, titular, nro_operacion, estado_bot, dni FROM cupones WHERE en_planilla = 1') as $c) {
                $c['fecha'] = $c['fecha_cupon'] ? new DateTimeImmutable($c['fecha_cupon']) : null;
                $c['monto'] = (float)$c['monto'];
                $cupones[(int)$c['id']] = $c;
            }
            $movs = [];
            foreach ($pdo->query('SELECT id, fecha, monto, nombre, dni, referencia, concepto FROM movimientos') as $m) {
                $m['fecha'] = new DateTimeImmutable($m['fecha']);
                $m['monto'] = (float)$m['monto'];
                $movs[(int)$m['id']] = $m;
            }

            $conc = new Conciliador(
                (int)config('tolerancia_dias', 3),
                (int)config('tolerancia_dias_cuil', 5),
                (int)config('dias_espera', 2)
            );
            $res = $conc->conciliar($cupones, $movs);

            // 3) Guardar el resultado en cada cupón y movimiento
            $pdo->exec('UPDATE movimientos SET cupon_id = NULL');
            $upMov = $pdo->prepare('UPDATE movimientos SET cupon_id = ? WHERE id = ?');
            foreach ($res['movs'] as $movId => $cuponId) {
                $upMov->execute([$cuponId, $movId]);
            }
            $upCup = $pdo->prepare('UPDATE cupones SET estado_conc = ?, detalle_conc = ?, movimiento_id = ? WHERE id = ?');
            foreach ($res['cupones'] as $cuponId => $r) {
                $upCup->execute([$r['estado'], $r['detalle'], $r['movimiento_id'], $cuponId]);
            }

            // 4) Seguimiento de cupones que no figuran en el banco
            self::actualizarPendientes($res['cupones'], $controlId);

            // 5) Foto del resultado para el período del archivo subido
            $resumen = self::guardarItems($controlId, $banco['desde'], $banco['hasta']);

            $pdo->prepare('UPDATE controles SET mov_nuevos = ?, total_archivo = ?, ok = ?, revisar = ?, no_figuran = ?, espera = ?,
                           sin_cupon = ?, total_sin_cupon = ? WHERE id = ?')
                ->execute([$nuevos, array_sum(array_column($banco['movs'], 'monto')), $resumen['ok'], $resumen['revisar'],
                    $resumen['no'], $resumen['espera'], $resumen['sin_cupon'], $resumen['total_sin_cupon'], $controlId]);

            $pdo->commit();
            return ['id' => $controlId, 'aviso' => $aviso];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('control_cupones')");
        }
    }

    private static function actualizarPendientes(array $resultados, int $controlId): void
    {
        $pdo = db();
        $abiertos = $pdo->query("SELECT cupon_id FROM pendientes WHERE estado = 'pendiente'")->fetchAll(PDO::FETCH_COLUMN);
        $abiertos = array_flip(array_map('intval', $abiertos));

        $nuevo = $pdo->prepare("INSERT IGNORE INTO pendientes (cupon_id, estado, detectado_en, control_id) VALUES (?, 'pendiente', NOW(), ?)");
        $cerrar = $pdo->prepare("UPDATE pendientes SET estado = 'resuelto', resuelto_en = NOW(), resuelto_por = 'Automático',
                                 nota = ? WHERE cupon_id = ? AND estado = 'pendiente'");
        $fechaMov = $pdo->prepare('SELECT fecha FROM movimientos WHERE id = ?');

        foreach ($resultados as $cuponId => $r) {
            if ($r['estado'] === 'no') {
                $nuevo->execute([$cuponId, $controlId]);
            } elseif (isset($abiertos[$cuponId]) && in_array($r['estado'], ['ok', 'revisar'], true)) {
                $fechaMov->execute([$r['movimiento_id']]);
                $nota = 'Apareció en el banco el ' . fecha_ar((string)$fechaMov->fetchColumn()) . ' (control #' . $controlId . ')';
                $cerrar->execute([$nota, $cuponId]);
            }
        }
    }

    private static function guardarItems(int $controlId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $pdo = db();
        $d = $desde->format('Y-m-d');
        $h = $hasta->format('Y-m-d');

        // Cupones del período (con el movimiento del banco con el que se cruzaron)
        $pdo->prepare("INSERT INTO control_items (control_id, tipo, estado, detalle, cupon_id, movimiento_id, zona, fecha_cupon, cobrador,
                           titular, monto, billetera, nro_operacion, fecha_banco, nombre_banco, cuit_banco, dni)
                       SELECT ?, 'cupon', COALESCE(c.estado_conc, 'omitido'), c.detalle_conc, c.id, m.id, c.zona, c.fecha_cupon, c.cobrador,
                           c.titular, c.monto, c.billetera, c.nro_operacion, m.fecha, m.nombre, m.cuit, COALESCE(m.dni, c.dni)
                       FROM cupones c LEFT JOIN movimientos m ON m.id = c.movimiento_id
                       WHERE c.en_planilla = 1 AND c.fecha_cupon BETWEEN ? AND ?")
            ->execute([$controlId, $d, $h]);

        // Plata que entró sin cupón (solo transferencias de personas: traen CUIL)
        $sinCupon = $pdo->prepare('SELECT id, fecha, nombre, cuit, dni, monto FROM movimientos
                                   WHERE cupon_id IS NULL AND dni IS NOT NULL AND fecha BETWEEN ? AND ?');
        $sinCupon->execute([$d, $h]);
        $lista = $sinCupon->fetchAll();

        $conocidos = self::nombresConocidos(array_column($lista, 'dni'));
        $ins = $pdo->prepare("INSERT INTO control_items (control_id, tipo, estado, detalle, movimiento_id, monto, fecha_banco, nombre_banco, cuit_banco, dni)
                              VALUES (?, 'sin_cupon', 'sin_cupon', ?, ?, ?, ?, ?, ?, ?)");
        $total = 0.0;
        foreach ($lista as $m) {
            $nombre = $m['nombre'] ?: ($conocidos[(int)$m['dni']] ?? null);
            $detalle = $m['nombre'] ? null : ($nombre ? 'Nombre tomado de otras transferencias' : 'El banco no informa el nombre');
            $ins->execute([$controlId, $detalle, $m['id'], $m['monto'], $m['fecha'], $nombre, $m['cuit'], $m['dni']]);
            $total += (float)$m['monto'];
        }

        $conteo = $pdo->prepare("SELECT estado, COUNT(*) FROM control_items WHERE control_id = ? AND tipo = 'cupon' GROUP BY estado");
        $conteo->execute([$controlId]);
        $porEstado = $conteo->fetchAll(PDO::FETCH_KEY_PAIR);

        return [
            'ok' => (int)($porEstado['ok'] ?? 0),
            'revisar' => (int)($porEstado['revisar'] ?? 0),
            'no' => (int)($porEstado['no'] ?? 0),
            'espera' => (int)($porEstado['espera'] ?? 0),
            'sin_cupon' => count($lista),
            'total_sin_cupon' => $total,
        ];
    }

    /**
     * Nombre conocido de cada DNI: el que trae el banco en otras transferencias,
     * o el titular de un cupón ya acreditado con ese CUIL.
     * @return array<int, string>
     */
    public static function nombresConocidos(array $dnis): array
    {
        $dnis = array_values(array_unique(array_filter(array_map('intval', $dnis))));
        if (!$dnis) {
            return [];
        }
        $nombres = [];
        foreach (array_chunk($dnis, 500) as $lote) {
            $in = implode(',', array_fill(0, count($lote), '?'));
            $st = db()->prepare("SELECT dni, nombre FROM movimientos WHERE dni IN ($in) AND nombre IS NOT NULL AND nombre <> ''
                                 UNION ALL
                                 SELECT m.dni, c.titular FROM cupones c JOIN movimientos m ON m.id = c.movimiento_id
                                 WHERE m.dni IN ($in) AND c.estado_conc = 'ok' AND c.titular <> ''");
            $st->execute(array_merge($lote, $lote));
            foreach ($st->fetchAll(PDO::FETCH_NUM) as [$dni, $nombre]) {
                $dni = (int)$dni;
                if (!isset($nombres[$dni]) || mb_strlen($nombre) > mb_strlen($nombres[$dni])) {
                    $nombres[$dni] = $nombre;
                }
            }
        }
        return $nombres;
    }

    public static function obtener(int $id): ?array
    {
        $st = db()->prepare('SELECT c.*, u.nombre AS usuario FROM controles c LEFT JOIN usuarios u ON u.id = c.usuario_id WHERE c.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function items(int $controlId, string $tipo, ?string $estado = null): array
    {
        $sql = 'SELECT * FROM control_items WHERE control_id = ? AND tipo = ?';
        $params = [$controlId, $tipo];
        if ($estado) {
            $sql .= ' AND estado = ?';
            $params[] = $estado;
        }
        $sql .= " ORDER BY FIELD(estado, 'no', 'revisar', 'espera', 'ok', 'omitido', 'sin_cupon'), COALESCE(fecha_cupon, fecha_banco) DESC, monto DESC";
        $st = db()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function listar(int $limite = 100): array
    {
        return db()->query('SELECT c.*, u.nombre AS usuario FROM controles c LEFT JOIN usuarios u ON u.id = c.usuario_id
                            ORDER BY c.id DESC LIMIT ' . (int)$limite)->fetchAll();
    }
}
