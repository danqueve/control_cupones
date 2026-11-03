<?php
declare(strict_types=1);

/**
 * Clientes que pagan desde la cuenta de un tercero (un familiar, alguien que
 * les hace el trámite): el CUIL que informa el banco no es el del cliente.
 *
 * Una persona carga el mapeo a mano una vez y desde ahí el cruce lo aplica
 * solo, igual que las confirmaciones de cupones "a revisar".
 *
 * Un tercero puede pagar por varios clientes y un cliente puede recibir pagos
 * de varios terceros. Cuando un tercero tiene más de un cliente, para saber a
 * quién va cada transferencia se usa, en este orden: el cupón con el que se
 * cruzó, el mapeo marcado como principal, o nada (queda para decidir a mano).
 */
final class Terceros
{
    /** Prefijos de CUIT de persona física: una empresa no tiene DNI que mapear. */
    private const PREFIJOS_PERSONA = ['20', '23', '24', '25', '26', '27'];

    /**
     * Vuelve a cruzar todo para que el cambio valga también para los pagos que
     * ya estaban cargados. Si justo hay un control en curso no se puede tomar el
     * lock: el vínculo ya quedó guardado igual, así que se avisa y se aplica solo
     * en el próximo control en lugar de cortar con un error.
     */
    private static function recruzar(): ?string
    {
        try {
            Controles::reconciliarTodo();
            return null;
        } catch (Throwable $e) {
            error_log('[control_cupones] recruce tras cambiar un pagador tercero: ' . $e->getMessage());
            return 'El vínculo quedó guardado, pero no se pudieron recalcular los pagos ya cargados ('
                . $e->getMessage() . ') Se va a aplicar solo en el próximo control.';
        }
    }

    /**
     * Mapeos vigentes, agrupados por el DNI del tercero.
     * @return array<int, array<int, array>> dni_tercero => [dni_cliente_sas => fila]
     */
    public static function mapaActivo(): array
    {
        $mapa = [];
        foreach (db()->query('SELECT * FROM pagadores_terceros WHERE activo = 1 ORDER BY id') as $f) {
            $mapa[(int)$f['dni_tercero']][(int)$f['dni_cliente_sas']] = $f;
        }
        return $mapa;
    }

    /**
     * La misma información que mapaActivo() pero solo con los DNI, que es lo
     * que necesita el Conciliador para cruzar.
     * @return array<int, int[]> dni_tercero => [dni_cliente_sas, ...]
     */
    public static function mapaDnis(): array
    {
        $mapa = [];
        foreach (self::mapaActivo() as $dniTercero => $clientes) {
            $mapa[$dniTercero] = array_keys($clientes);
        }
        return $mapa;
    }

    /** @return array[] mapeos vigentes de ese tercero (puede pagar por varios clientes) */
    public static function porTercero(int $dniTercero): array
    {
        $st = db()->prepare('SELECT * FROM pagadores_terceros WHERE dni_tercero = ? AND activo = 1 ORDER BY es_principal DESC, id');
        $st->execute([$dniTercero]);
        return $st->fetchAll();
    }

    /** @return array[] terceros que pagan por ese cliente */
    public static function porCliente(int $dniClienteSas): array
    {
        $st = db()->prepare('SELECT * FROM pagadores_terceros WHERE dni_cliente_sas = ? AND activo = 1 ORDER BY id');
        $st->execute([$dniClienteSas]);
        return $st->fetchAll();
    }

    public static function obtener(int $id): ?array
    {
        $st = db()->prepare('SELECT * FROM pagadores_terceros WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * Cliente al que se le acredita un pago de ese tercero cuando no hay un
     * cupón que lo aclare: el único que tenga, o el marcado como principal.
     * Si tiene varios y ninguno es principal, devuelve null (decisión a mano).
     */
    public static function resolverPrincipal(int $dniTercero, ?array $mapa = null): ?int
    {
        $clientes = $mapa === null ? self::porTercero($dniTercero) : array_values($mapa[$dniTercero] ?? []);
        if (count($clientes) === 1) {
            return (int)$clientes[0]['dni_cliente_sas'];
        }
        foreach ($clientes as $c) {
            if ((int)$c['es_principal'] === 1) {
                return (int)$c['dni_cliente_sas'];
            }
        }
        return null;
    }

    /**
     * Listado para la pantalla de administración.
     * @param bool|null $soloActivos true = vigentes, false = historial, null = todos
     */
    public static function listar(?bool $soloActivos = true): array
    {
        $where = '';
        if ($soloActivos === true) {
            $where = 'WHERE t.activo = 1';
        } elseif ($soloActivos === false) {
            $where = 'WHERE t.activo IS NULL';
        }
        return db()->query("SELECT t.*,
                                   (SELECT COUNT(*)   FROM movimientos m WHERE m.dni = t.dni_tercero) AS movs,
                                   (SELECT COALESCE(SUM(m.monto), 0) FROM movimientos m WHERE m.dni = t.dni_tercero) AS total_movs
                            FROM pagadores_terceros t
                            $where
                            ORDER BY t.activo DESC, t.dni_cliente_sas, t.es_principal DESC, t.id DESC")->fetchAll();
    }

    /**
     * Carga un vínculo nuevo. Valida que el cliente exista en SAS para no
     * vincular contra un DNI que no es de nadie.
     * @return array ['id' => int, 'aviso' => ?string] o ['error' => string]
     */
    public static function crear(int $dniTercero, int $dniClienteSas, bool $esPrincipal,
        ?string $cuitTercero, ?string $nombreTercero, string $nota, string $usuario): array
    {
        if ($dniTercero <= 0 || $dniClienteSas <= 0) {
            return ['error' => 'Faltan el DNI de quien pagó y el del cliente.'];
        }
        if ($dniTercero === $dniClienteSas) {
            return ['error' => 'Son el mismo DNI: no hace falta vincular nada.'];
        }
        $cuitTercero = $cuitTercero !== null ? preg_replace('/\D/', '', $cuitTercero) : null;
        if ($cuitTercero === '') {
            $cuitTercero = null;
        }
        if ($cuitTercero !== null) {
            if (strlen($cuitTercero) !== 11) {
                return ['error' => 'El CUIL tiene que tener 11 números.'];
            }
            if (!in_array(substr($cuitTercero, 0, 2), self::PREFIJOS_PERSONA, true)) {
                return ['error' => 'Ese CUIT es de una empresa, no de una persona: no se puede vincular como pagador.'];
            }
        }
        if (!Sas::activo()) {
            return ['error' => 'SAS Imperio no está disponible ahora, así que no se puede comprobar el DNI del cliente. Probá más tarde.'];
        }
        if (!isset(Sas::nombres([$dniClienteSas])[$dniClienteSas])) {
            return ['error' => 'No encontré ese DNI entre los clientes de SAS Imperio.'];
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT id FROM pagadores_terceros WHERE dni_tercero = ? AND activo = 1 FOR UPDATE');
            $st->execute([$dniTercero]);
            $existentes = $st->fetchAll(PDO::FETCH_COLUMN);

            $st = $pdo->prepare('SELECT id FROM pagadores_terceros WHERE dni_tercero = ? AND dni_cliente_sas = ? AND activo = 1');
            $st->execute([$dniTercero, $dniClienteSas]);
            if ($st->fetchColumn()) {
                $pdo->rollBack();
                return ['error' => 'Ese pagador ya está vinculado a ese cliente.'];
            }
            // El primer mapeo de un tercero es siempre el principal: si paga por
            // uno solo, no hay nada que decidir después.
            if (!$existentes) {
                $esPrincipal = true;
            }
            if ($esPrincipal && $existentes) {
                $pdo->prepare('UPDATE pagadores_terceros SET es_principal = 0 WHERE dni_tercero = ? AND activo = 1')
                    ->execute([$dniTercero]);
            }
            $pdo->prepare('INSERT INTO pagadores_terceros (dni_tercero, cuit_tercero, nombre_tercero, dni_cliente_sas,
                               es_principal, nota, activo, creado_por, creado_en)
                           VALUES (?, ?, ?, ?, ?, ?, 1, ?, NOW())')
                ->execute([$dniTercero, $cuitTercero, $nombreTercero !== null ? mb_substr($nombreTercero, 0, 200) : null,
                    $dniClienteSas, $esPrincipal ? 1 : 0, mb_substr(trim($nota), 0, 300), mb_substr($usuario, 0, 100)]);
            $id = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['id' => $id, 'aviso' => self::recruzar()];
    }

    /**
     * @return array ['aviso' => ?string] o ['error' => string]
     */
    public static function actualizar(int $id, bool $esPrincipal, string $nota, string $usuario): array
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT * FROM pagadores_terceros WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $t = $st->fetch();
            if (!$t) {
                $pdo->rollBack();
                return ['error' => 'No encontré ese vínculo.'];
            }
            if ($t['activo'] === null) {
                $pdo->rollBack();
                return ['error' => 'Ese vínculo está desactivado: creá uno nuevo.'];
            }
            if ($esPrincipal) {
                $pdo->prepare('UPDATE pagadores_terceros SET es_principal = 0 WHERE dni_tercero = ? AND activo = 1')
                    ->execute([(int)$t['dni_tercero']]);
            }
            $pdo->prepare('UPDATE pagadores_terceros SET es_principal = ?, nota = ? WHERE id = ?')
                ->execute([$esPrincipal ? 1 : 0, mb_substr(trim($nota), 0, 300), $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['aviso' => self::recruzar()];
    }

    /**
     * Desactiva el vínculo sin borrarlo (queda el historial de quién lo cargó).
     * @return array ['aviso' => ?string] o ['error' => string]
     */
    public static function desactivar(int $id, string $usuario): array
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT * FROM pagadores_terceros WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $t = $st->fetch();
            if (!$t) {
                $pdo->rollBack();
                return ['error' => 'No encontré ese vínculo.'];
            }
            if ($t['activo'] === null) {
                $pdo->rollBack();
                return ['error' => 'Ese vínculo ya estaba desactivado.'];
            }
            $pdo->prepare('UPDATE pagadores_terceros SET activo = NULL, es_principal = 0,
                               desactivado_por = ?, desactivado_en = NOW() WHERE id = ?')
                ->execute([mb_substr($usuario, 0, 100), $id]);

            // Si quedó un solo vínculo vigente para ese tercero, pasa a ser el principal
            $dniTercero = (int)$t['dni_tercero'];
            $st = $pdo->prepare('SELECT id FROM pagadores_terceros WHERE dni_tercero = ? AND activo = 1');
            $st->execute([$dniTercero]);
            $quedan = $st->fetchAll(PDO::FETCH_COLUMN);
            if (count($quedan) === 1) {
                $pdo->prepare('UPDATE pagadores_terceros SET es_principal = 1 WHERE id = ?')->execute([$quedan[0]]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['aviso' => self::recruzar()];
    }
}
