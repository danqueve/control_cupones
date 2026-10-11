<?php
declare(strict_types=1);

/**
 * Buscador por DNI, CUIL (completos o parciales) o por nombre y apellido.
 * Una búsqueda por nombre encuentra el DNI de esa persona y trae también
 * las transferencias donde el banco no informó el nombre pero sí el CUIL.
 */
final class Busqueda
{
    public static function buscar(string $q): array
    {
        $q = trim($q);
        $compacto = preg_replace('/[\s.\-\/]/', '', $q);

        if (preg_match('/^\d+$/', $compacto)) {
            if (strlen($compacto) < 4) {
                return ['error' => 'Escribí al menos 4 números.'];
            }
            return self::porNumero($compacto);
        }
        $tokens = Texto::tokens($q, 2);
        if (!$tokens) {
            return ['error' => 'Escribí un nombre, apellido, DNI o CUIL.'];
        }
        return self::porNombre($tokens);
    }

    private static function porNumero(string $digitos): array
    {
        $variantes = [$digitos];
        if (strlen($digitos) === 11) {
            $variantes[] = substr($digitos, 2, 8); // CUIL → DNI
        }
        $cond = [];
        $params = [];
        foreach ($variantes as $v) {
            $cond[] = '(CAST(m.dni AS CHAR) LIKE ? OR m.cuit LIKE ?)';
            array_push($params, "%$v%", "%$v%");
        }
        $movs = self::traerMovs(implode(' OR ', $cond), $params);

        $cond = [];
        $params = [];
        foreach ($variantes as $v) {
            $cond[] = '(CAST(c.dni AS CHAR) LIKE ? OR c.cuit LIKE ? OR CAST(m.dni AS CHAR) LIKE ? OR c.nro_operacion LIKE ? OR c.cbu_alias LIKE ?)';
            array_push($params, "%$v%", "%$v%", "%$v%", "%$v%", "%$v%");
        }
        $cupones = self::traerCupones(implode(' OR ', $cond), $params);

        return self::armar($movs, $cupones);
    }

    private static function porNombre(array $tokens): array
    {
        // Primer filtro en SQL (4 letras de cada palabra, porque el banco corta los nombres) y el fino en PHP
        $condMov = [];
        $condCup = [];
        $params = [];
        foreach ($tokens as $t) {
            $condMov[] = 'm.nombre LIKE ?';
            $condCup[] = 'c.titular LIKE ?';
            $params[] = '%' . substr($t, 0, 4) . '%';
        }
        $movs = array_filter(self::traerMovs(implode(' AND ', $condMov), $params), fn($m) => self::coincideNombre($tokens, $m['nombre']));
        $cupones = array_filter(self::traerCupones(implode(' AND ', $condCup), $params), fn($c) => self::coincideNombre($tokens, $c['titular']));

        // Con los DNI encontrados, traer también lo que no tiene nombre pero sí ese CUIL
        $dnis = array_unique(array_filter(array_merge(
            array_column($movs, 'dni'),
            array_column($cupones, 'dni'),
            array_column($cupones, 'mov_dni')
        )));
        if ($dnis) {
            $in = implode(',', array_fill(0, count($dnis), '?'));
            $dnis = array_values(array_map('intval', $dnis));
            foreach (self::traerMovs("m.dni IN ($in)", $dnis) as $m) {
                $movs[$m['id']] = $m;
            }
            foreach (self::traerCupones("c.dni IN ($in) OR m.dni IN ($in)", array_merge($dnis, $dnis)) as $c) {
                $cupones[$c['id']] = $c;
            }
        }
        return self::armar($movs, $cupones);
    }

    private static function coincideNombre(array $tokens, ?string $nombre): bool
    {
        $objetivo = Texto::tokens($nombre, 2);
        if (!$objetivo) {
            return false;
        }
        foreach ($tokens as $q) {
            $ok = false;
            foreach ($objetivo as $t) {
                if ($t === $q || (strlen($q) >= 3 && str_starts_with($t, $q)) || (strlen($t) >= 4 && str_starts_with($q, $t))) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    private static function traerMovs(string $where, array $params): array
    {
        $st = db()->prepare("SELECT m.*, c.zona AS cup_zona, c.titular AS cup_titular, c.cobrador AS cup_cobrador, c.estado_conc AS cup_estado
                             FROM movimientos m LEFT JOIN cupones c ON c.id = m.cupon_id
                             WHERE $where ORDER BY m.fecha DESC, m.id DESC LIMIT 500");
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll() as $m) {
            $out[$m['id']] = $m;
        }
        return $out;
    }

    private static function traerCupones(string $where, array $params): array
    {
        $st = db()->prepare("SELECT c.*, m.fecha AS mov_fecha, m.dni AS mov_dni, m.cuit AS mov_cuit, m.nombre AS mov_nombre,
                                    p.estado AS pend_estado, p.nota AS pend_nota
                             FROM cupones c
                             LEFT JOIN movimientos m ON m.id = c.movimiento_id
                             LEFT JOIN pendientes p ON p.cupon_id = c.id
                             WHERE $where ORDER BY c.fecha_cupon DESC, c.id DESC LIMIT 500");
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll() as $c) {
            $out[$c['id']] = $c;
        }
        return $out;
    }

    /** Ordena resultados y arma el resumen por persona (DNI). */
    private static function armar(array $movs, array $cupones): array
    {
        $movs = array_values($movs);
        $cupones = array_values($cupones);
        usort($movs, fn($a, $b) => strcmp($b['fecha'], $a['fecha']));
        usort($cupones, fn($a, $b) => strcmp((string)$b['fecha_cupon'], (string)$a['fecha_cupon']));

        $personas = [];
        $agregar = function (?int $dni, ?string $cuit, ?string $nombre) use (&$personas) {
            if (!$dni) {
                return;
            }
            $p = $personas[$dni] ?? ['dni' => $dni, 'cuit' => $cuit, 'nombres' => [], 'transferencias' => 0, 'total_banco' => 0.0, 'cupones' => 0, 'sin_cupon' => 0];
            if ($cuit && !$p['cuit']) {
                $p['cuit'] = $cuit;
            }
            if ($nombre) {
                $p['nombres'][Texto::normalizar($nombre)] = $nombre;
            }
            $personas[$dni] = $p;
        };
        foreach ($movs as $m) {
            $agregar($m['dni'] ? (int)$m['dni'] : null, $m['cuit'], $m['nombre']);
            if ($m['dni']) {
                $personas[(int)$m['dni']]['transferencias']++;
                $personas[(int)$m['dni']]['total_banco'] += (float)$m['monto'];
                if (!$m['cupon_id']) {
                    $personas[(int)$m['dni']]['sin_cupon']++;
                }
            }
        }
        foreach ($cupones as $c) {
            $dni = $c['mov_dni'] ? (int)$c['mov_dni'] : ($c['dni'] ? (int)$c['dni'] : null);
            $agregar($dni, $c['mov_cuit'] ?: $c['cuit'], $c['titular']);
            if ($dni) {
                $personas[$dni]['cupones']++;
            }
        }

        $sas = Sas::nombres(array_keys($personas));
        foreach ($personas as $dni => &$p) {
            $p['cliente_sas'] = $sas[$dni] ?? null;
            $p['nombres'] = array_values($p['nombres']);
        }
        unset($p);
        uasort($personas, fn($a, $b) => $b['total_banco'] <=> $a['total_banco']);

        return [
            'personas' => array_values($personas),
            'movs' => $movs,
            'cupones' => $cupones,
            'total_banco' => array_sum(array_map(fn($m) => (float)$m['monto'], $movs)),
            'total_cupones' => array_sum(array_map(fn($c) => (float)$c['monto'], $cupones)),
            'cantidad' => count($movs) + count($cupones),
        ];
    }

    public static function registrar(int $usuarioId, string $texto, int $resultados): void
    {
        db()->prepare('INSERT INTO busquedas (usuario_id, texto, resultados, creado_en) VALUES (?, ?, ?, NOW())')
            ->execute([$usuarioId, mb_substr($texto, 0, 150), $resultados]);
    }

    public static function recientes(int $limite = 50): array
    {
        return db()->query('SELECT b.*, u.nombre AS usuario FROM busquedas b LEFT JOIN usuarios u ON u.id = b.usuario_id
                            ORDER BY b.id DESC LIMIT ' . (int)$limite)->fetchAll();
    }
}
