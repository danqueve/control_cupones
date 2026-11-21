<?php
declare(strict_types=1);

/**
 * Revisa si un vínculo "pagador tercero → cliente de SAS" tiene sentido, mirando la evidencia que ya
 * está en el sistema (los cupones y las transferencias), para encontrar los que se cargaron al cliente
 * equivocado.
 *
 * evaluar() es puro (solo arrays, sin base): se prueba igual que el Conciliador. cargar() arma esos
 * arrays con SQL. Las señales nunca "prueban" nada: ayudan a que una persona decida. Por eso hay tres
 * estados y no uno de "está mal":
 *   revisar   hay algo que no cierra (otro cobrador o zona, cupones de otra persona, el pagador también es cliente)
 *   ok        la evidencia fuerte (cobrador, zona, importes) coincide y nada contradice
 *   sin_datos no hay evidencia suficiente para opinar (un vínculo nuevo, por ejemplo)
 */
final class VerificacionTerceros
{
    public const REVISAR = 'revisar';
    public const OK = 'ok';
    public const SIN_DATOS = 'sin_datos';

    /** Tope de transferencias y de cupones que se miran por vínculo. */
    private const TOPE_TRANSFERENCIAS = 200;
    private const TOPE_CUPONES = 300;

    /** Mitad o más de las transferencias tienen que coincidir en importe con un cupón del cliente para dar "ok". */
    private const MIN_PROPORCION_IMPORTES = 0.5;

    public static function etiqueta(string $estado): string
    {
        return ['revisar' => 'Revisar', 'ok' => 'Coincide', 'sin_datos' => 'Sin datos'][$estado] ?? 'Sin datos';
    }

    /**
     * @param array $v [
     *   'dni_tercero' => int, 'dni_cliente' => int, 'otros_clientes' => int[] (otros clientes por los que paga el mismo pagador),
     *   'nombre_pagador' => ?string, 'nombre_cliente' => ?string (el de SAS), 'pagador_es_cliente' => bool,
     *   'transferencias' => [['id', 'monto', 'excluida' => bool, 'cupon' => null|['id', 'dni', 'titular', 'cobrador', 'zona']]],
     *   'cupones_cliente' => [['id', 'monto', 'cobrador', 'zona']]  (cupones del cliente, por su DNI o por su nombre),
     *   'cupones_pagador' => [['id', 'cobrador', 'zona']]           (cupones con el DNI del propio pagador),
     * ]
     * @return array ['estado' => revisar|ok|sin_datos, 'senales' => [...], 'transferencias' => [... + 'clase'], 'resumen' => string]
     */
    public static function evaluar(array $v): array
    {
        $transferencias = [];
        foreach ($v['transferencias'] ?? [] as $t) {
            $t['clase'] = self::clasificar($t, $v);
            $transferencias[] = $t;
        }
        $cuponesCliente = $v['cupones_cliente'] ?? [];
        $cuponesPagador = $v['cupones_pagador'] ?? [];
        $vigentes = array_values(array_filter($transferencias, fn($t) => empty($t['excluida'])));

        // Cupones que dicen algo del pagador: los suyos y los de otras personas que se cruzaron con sus
        // transferencias. Los del cliente no cuentan acá: se cruzaron justamente por el vínculo y servirían
        // de prueba circular (están en la señal de importes).
        $delPagador = $cuponesPagador;
        foreach ($vigentes as $t) {
            if ($t['cupon'] !== null && in_array($t['clase'], ['propio', 'ajeno'], true)) {
                $delPagador[] = $t['cupon'];
            }
        }

        $senales = [
            self::senalCoincidencia('cobrador', 'Cobrador', $delPagador, $cuponesCliente, 'cobrador'),
            self::senalCoincidencia('zona', 'Zona', $delPagador, $cuponesCliente, 'zona'),
            self::senalImportes($vigentes, $cuponesCliente),
            self::senalNombre($v),
            self::senalPagadorCliente($v, $cuponesPagador, $vigentes),
            self::senalAjenos($vigentes),
        ];

        $porClave = array_column($senales, 'estado', 'clave');
        $fuertes = array_map(fn($k) => $porClave[$k], ['cobrador', 'zona', 'importes']);
        if (in_array('mal', [$porClave['cobrador'], $porClave['zona'], $porClave['ajenos'], $porClave['pagador_cliente']], true)) {
            $estado = self::REVISAR;
        } elseif (in_array('ok', $fuertes, true) && !in_array('mal', $fuertes, true)) {
            $estado = self::OK;
        } else {
            $estado = self::SIN_DATOS;
        }

        $resumen = [
            self::REVISAR => 'Hay señales que no coinciden con este cliente: conviene revisar el vínculo.',
            self::OK => 'La evidencia disponible coincide con este cliente.',
            self::SIN_DATOS => 'Todavía no hay evidencia suficiente para opinar (pasa con vínculos nuevos o sin cupones cruzados).',
        ][$estado];

        return ['estado' => $estado, 'senales' => $senales, 'transferencias' => $transferencias, 'resumen' => $resumen];
    }

    /**
     * A quién "parece" corresponder una transferencia del pagador según el cupón con el que se cruzó:
     * coincide (cliente del vínculo u otro cliente del mismo pagador), propio (el cupón es del pagador),
     * ajeno (de una tercera persona), sin_cupon, o excluida (alguien la sacó del vínculo a mano).
     */
    public static function clasificar(array $t, array $v): string
    {
        if (!empty($t['excluida'])) {
            return 'excluida';
        }
        $c = $t['cupon'] ?? null;
        if ($c === null) {
            return 'sin_cupon';
        }
        $dni = (int)($c['dni'] ?? 0);
        $titular = (string)($c['titular'] ?? '');
        if ($dni > 0) {
            if ($dni === (int)$v['dni_cliente'] || in_array($dni, $v['otros_clientes'] ?? [], true)) {
                return 'coincide';
            }
            return $dni === (int)$v['dni_tercero'] ? 'propio' : 'ajeno';
        }
        // Cupón sin CUIL: queda el nombre del titular
        if ($titular !== '' && Texto::nombresCoinciden($titular, (string)($v['nombre_cliente'] ?? ''))) {
            return 'coincide';
        }
        if ($titular !== '' && Texto::nombresCoinciden($titular, (string)($v['nombre_pagador'] ?? ''))) {
            return 'propio';
        }
        return $titular !== '' ? 'ajeno' : 'sin_cupon';
    }

    // ─── Señales ──────────────────────────────────────────────────────

    /** Cobrador o zona: ¿lo que muestran los cupones del pagador es lo mismo que atiende al cliente? */
    private static function senalCoincidencia(string $clave, string $titulo, array $delPagador, array $delCliente, string $campo): array
    {
        $a = self::valores($delPagador, $campo);
        $b = self::valores($delCliente, $campo);
        if (!$a || !$b) {
            return self::senal($clave, $titulo, 'sin_datos', !$a
                ? 'Todavía no hay cupones del pagador para comparar.'
                : 'No encontré cupones del cliente para comparar.');
        }
        $comunes = array_intersect_key($a, $b);
        $lista = fn(array $x) => implode(', ', array_slice(array_values($x), 0, 4));
        if ($comunes) {
            return self::senal($clave, $titulo, 'ok', 'El pagador y el cliente comparten ' . mb_strtolower($titulo) . ': ' . $lista($comunes) . '.');
        }
        return self::senal($clave, $titulo, 'mal', 'Los cupones del pagador son de ' . $lista($a) . ' y los del cliente de ' . $lista($b) . '.');
    }

    /** @return array<string,string> clave normalizada => texto original */
    private static function valores(array $cupones, string $campo): array
    {
        $r = [];
        foreach ($cupones as $c) {
            $texto = trim((string)($c[$campo] ?? ''));
            if ($texto !== '') {
                $r[Texto::normalizar($texto)] = $texto;
            }
        }
        return $r;
    }

    /** ¿Cuántas transferencias del pagador tienen un importe que el cliente también paga en sus cupones? */
    private static function senalImportes(array $vigentes, array $cuponesCliente): array
    {
        $total = count($vigentes);
        if ($total === 0) {
            return self::senal('importes', 'Importes', 'sin_datos', 'El pagador no tiene transferencias vigentes en este vínculo.');
        }
        if (!$cuponesCliente) {
            return self::senal('importes', 'Importes', 'sin_datos', 'No encontré cupones del cliente para comparar los importes.');
        }
        $montos = [];
        foreach ($cuponesCliente as $c) {
            $montos[(int)round((float)$c['monto'] * 100)] = true;
        }
        $coinciden = count(array_filter($vigentes, fn($t) => isset($montos[(int)round((float)$t['monto'] * 100)])));
        $texto = $coinciden . ' de ' . $total . ' transferencias tienen el mismo importe que un cupón del cliente.';
        if ($coinciden > 0 && $coinciden / $total >= self::MIN_PROPORCION_IMPORTES) {
            return self::senal('importes', 'Importes', 'ok', $texto);
        }
        if ($coinciden === 0 && $total >= 2) {
            return self::senal('importes', 'Importes', 'mal', $texto);
        }
        return self::senal('importes', 'Importes', 'sin_datos', $texto);
    }

    /** Pista débil: compartir apellido sugiere familia, no compartirlo no dice nada (un vecino también puede pagar). */
    private static function senalNombre(array $v): array
    {
        $a = Texto::tokens((string)($v['nombre_pagador'] ?? ''), 4);
        $b = Texto::tokens((string)($v['nombre_cliente'] ?? ''), 4);
        if (!$a || !$b) {
            return self::senal('nombre', 'Apellido', 'sin_datos', 'Falta el nombre de uno de los dos para comparar.', true);
        }
        $comunes = [];
        foreach ($a as $x) {
            foreach ($b as $y) {
                if (Texto::tokenParecido($x, $y)) {
                    $comunes[] = $x;
                    break;
                }
            }
        }
        return $comunes
            ? self::senal('nombre', 'Apellido', 'ok', 'Comparten el nombre o apellido: ' . implode(', ', array_unique($comunes)) . '.', true)
            : self::senal('nombre', 'Apellido', 'sin_datos', 'No comparten apellido. No es raro (puede pagar un conocido), pero es una pista más.', true);
    }

    /** Si el pagador también es cliente de SAS, sus pagos propios se pueden estar contando para otro. */
    private static function senalPagadorCliente(array $v, array $cuponesPagador, array $vigentes): array
    {
        if (empty($v['pagador_es_cliente'])) {
            return self::senal('pagador_cliente', 'El pagador y SAS', 'ok', 'El pagador no figura como cliente de SAS: sus pagos solo pueden ser de un tercero.', true);
        }
        $propios = count($cuponesPagador);
        foreach ($vigentes as $t) {
            if ($t['clase'] === 'propio' && !in_array($t['cupon']['id'] ?? null, array_column($cuponesPagador, 'id'), true)) {
                $propios++;
            }
        }
        if ($propios > 0) {
            return self::senal('pagador_cliente', 'El pagador y SAS', 'mal',
                'El pagador también es cliente de SAS y tiene ' . $propios . ' cupón' . ($propios === 1 ? '' : 'es') . ' propio' . ($propios === 1 ? '' : 's')
                . ': sus pagos podrían ser suyos y no del cliente vinculado. Sacá del vínculo los que sean suyos.');
        }
        return self::senal('pagador_cliente', 'El pagador y SAS', 'sin_datos', 'El pagador también es cliente de SAS, pero no tiene cupones propios cargados.', true);
    }

    /** Transferencias del pagador cruzadas con el cupón de una tercera persona (ni el pagador ni el cliente). */
    private static function senalAjenos(array $vigentes): array
    {
        $ajenas = count(array_filter($vigentes, fn($t) => $t['clase'] === 'ajeno'));
        if ($ajenas > 0) {
            return self::senal('ajenos', 'Cruzadas con otras personas', 'mal',
                $ajenas . ' transferencia' . ($ajenas === 1 ? '' : 's') . ' del pagador se ' . ($ajenas === 1 ? 'cruzó con el cupón de otra persona' : 'cruzaron con cupones de otras personas') . ': puede que ese sea el cliente correcto.');
        }
        $conCupon = count(array_filter($vigentes, fn($t) => $t['cupon'] !== null));
        return $conCupon > 0
            ? self::senal('ajenos', 'Cruzadas con otras personas', 'ok', 'Ninguna transferencia se cruzó con el cupón de otra persona.', true)
            : self::senal('ajenos', 'Cruzadas con otras personas', 'sin_datos', 'Ninguna transferencia del pagador está cruzada con un cupón todavía.', true);
    }

    private static function senal(string $clave, string $titulo, string $estado, string $texto, bool $debil = false): array
    {
        return ['clave' => $clave, 'titulo' => $titulo, 'estado' => $estado, 'texto' => $texto, 'debil' => $debil];
    }

    // ─── Armado de los datos desde la base ────────────────────────────

    /**
     * @param array|null $nombres dni => nombre en SAS, si ya se tienen (evita una consulta por vínculo)
     * @return array|null los datos para evaluar(), o null si no existe el vínculo
     */
    public static function cargar(int $vinculoId, ?array $nombres = null): ?array
    {
        $vinculo = Terceros::obtener($vinculoId);
        if (!$vinculo) {
            return null;
        }
        $pdo = db();
        $dniTercero = (int)$vinculo['dni_tercero'];
        $dniCliente = (int)$vinculo['dni_cliente_sas'];
        $nombres ??= Sas::nombres([$dniTercero, $dniCliente]);

        $otros = [];
        foreach (Terceros::porTercero($dniTercero) as $o) {
            if ((int)$o['dni_cliente_sas'] !== $dniCliente) {
                $otros[] = (int)$o['dni_cliente_sas'];
            }
        }

        $st = $pdo->prepare("SELECT m.id, m.fecha, m.monto, m.nombre, m.dni_efectivo, m.es_tercero, m.cupon_id,
                                    a.dni_cliente_sas AS asignado_a,
                                    c.id AS c_id, c.dni AS c_dni, c.titular AS c_titular, c.cobrador AS c_cobrador,
                                    c.zona AS c_zona, c.estado_conc AS c_estado, c.monto AS c_monto, c.fecha_cupon AS c_fecha
                               FROM movimientos m
                               LEFT JOIN cupones c ON c.id = m.cupon_id
                               LEFT JOIN movimientos_cliente a ON a.movimiento_id = m.id
                              WHERE m.dni = ?
                              ORDER BY m.fecha DESC, m.id DESC LIMIT " . self::TOPE_TRANSFERENCIAS);
        $st->execute([$dniTercero]);
        $transferencias = [];
        foreach ($st->fetchAll() as $m) {
            $transferencias[] = [
                'id' => (int)$m['id'], 'fecha' => $m['fecha'], 'monto' => (float)$m['monto'], 'nombre' => $m['nombre'],
                'dni_efectivo' => $m['dni_efectivo'] !== null ? (int)$m['dni_efectivo'] : null,
                'asignado_a' => $m['asignado_a'] !== null ? (int)$m['asignado_a'] : null,
                'excluida' => $m['asignado_a'] !== null && (int)$m['asignado_a'] === $dniTercero,
                'cupon' => $m['c_id'] === null ? null : [
                    'id' => (int)$m['c_id'], 'dni' => $m['c_dni'] !== null ? (int)$m['c_dni'] : null, 'titular' => $m['c_titular'],
                    'cobrador' => $m['c_cobrador'], 'zona' => $m['c_zona'], 'estado' => $m['c_estado'],
                    'monto' => (float)$m['c_monto'], 'fecha' => $m['c_fecha'],
                ],
            ];
        }

        // Cupones del cliente: por su DNI o, si el cupón no trae el CUIL correcto, por el nombre (con el primer
        // filtro en SQL a 4 letras por palabra, igual que Busqueda, porque el banco y el bot cortan los nombres).
        $nombreCliente = $nombres[$dniCliente] ?? null;
        $cuponesCliente = [];
        $st = $pdo->prepare('SELECT id, monto, cobrador, zona FROM cupones WHERE en_planilla = 1 AND dni = ? LIMIT ' . self::TOPE_CUPONES);
        $st->execute([$dniCliente]);
        foreach ($st->fetchAll() as $c) {
            $cuponesCliente[(int)$c['id']] = $c;
        }
        $tokens = Texto::tokens($nombreCliente, 3);
        if (count($tokens) >= 2) {
            $cond = implode(' AND ', array_fill(0, count($tokens), 'titular LIKE ?'));
            $st = $pdo->prepare("SELECT id, monto, cobrador, zona, titular, dni FROM cupones
                                  WHERE en_planilla = 1 AND (dni IS NULL OR (dni <> ? AND dni <> ?)) AND $cond LIMIT " . self::TOPE_CUPONES);
            $st->execute(array_merge([$dniCliente, $dniTercero], array_map(fn($t) => '%' . substr($t, 0, 4) . '%', $tokens)));
            foreach ($st->fetchAll() as $c) {
                if (Texto::nombresCoinciden((string)$c['titular'], (string)$nombreCliente)) {
                    $cuponesCliente[(int)$c['id']] = $c;
                }
            }
        }

        $st = $pdo->prepare('SELECT id, monto, cobrador, zona FROM cupones WHERE en_planilla = 1 AND dni = ? LIMIT ' . self::TOPE_CUPONES);
        $st->execute([$dniTercero]);

        return [
            'vinculo' => $vinculo,
            'dni_tercero' => $dniTercero,
            'dni_cliente' => $dniCliente,
            'otros_clientes' => $otros,
            'nombre_pagador' => $vinculo['nombre_tercero'] ?: ($nombres[$dniTercero] ?? null),
            'nombre_cliente' => $nombreCliente,
            'pagador_es_cliente' => isset($nombres[$dniTercero]),
            'transferencias' => $transferencias,
            'cupones_cliente' => array_values($cuponesCliente),
            'cupones_pagador' => $st->fetchAll(),
        ];
    }

    /**
     * Estado de cada vínculo, para el listado (una pasada, con los nombres de SAS pedidos de una vez).
     * @param array[] $vinculos filas de pagadores_terceros vigentes
     * @return array<int,string> id => revisar|ok|sin_datos
     */
    public static function estados(array $vinculos): array
    {
        $dnis = [];
        foreach ($vinculos as $t) {
            $dnis[] = (int)$t['dni_tercero'];
            $dnis[] = (int)$t['dni_cliente_sas'];
        }
        $nombres = $dnis ? Sas::nombres(array_values(array_unique($dnis))) : [];
        $estados = [];
        foreach ($vinculos as $t) {
            $datos = self::cargar((int)$t['id'], $nombres);
            $estados[(int)$t['id']] = $datos ? self::evaluar($datos)['estado'] : self::SIN_DATOS;
        }
        return $estados;
    }
}
