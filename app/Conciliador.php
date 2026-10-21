<?php
declare(strict_types=1);

/**
 * Cruza cupones (planilla) contra transferencias del banco.
 * No toca la base de datos: recibe arrays y devuelve el resultado.
 */
final class Conciliador
{
    private int $tolDias;
    private int $tolCuil;
    private int $diasEspera;

    public function __construct(int $tolDias = 3, int $tolCuil = 5, int $diasEspera = 2)
    {
        $this->tolDias = $tolDias;
        $this->tolCuil = $tolCuil;
        $this->diasEspera = $diasEspera;
    }

    /**
     * @param array $cupones [id => ['fecha' => ?DateTimeImmutable, 'monto', 'titular', 'nro_operacion', 'estado_bot', 'dni']]
     * @param array $movs    [id => ['fecha' => DateTimeImmutable, 'monto', 'nombre', 'dni', 'referencia', 'concepto']]
     * @param array $nombresSas [dni => nombre del cliente en SAS]. Se usa solo para las transferencias que el banco
     *                          informa sin nombre (solo con el CUIL), para poder compararlas con el titular del cupón.
     * @return array [
     *   'cupones' => [cuponId => ['estado', 'detalle', 'movimiento_id']],
     *   'movs'    => [movId => cuponId],
     * ]
     */
    public function conciliar(array $cupones, array $movs, array $nombresSas = []): array
    {
        $resultado = [];
        $asignado = [];   // movId => cuponId

        $minBanco = null;
        $maxBanco = null;
        $porMonto = [];
        $conceptoAlfa = [];
        foreach ($movs as $mid => $m) {
            $minBanco = (!$minBanco || $m['fecha'] < $minBanco) ? $m['fecha'] : $minBanco;
            $maxBanco = (!$maxBanco || $m['fecha'] > $maxBanco) ? $m['fecha'] : $maxBanco;
            $porMonto[self::claveMonto($m['monto'])][] = $mid;
            $conceptoAlfa[$mid] = Texto::soloAlfanumerico($m['concepto'] ?? '');
        }

        $candidatos = function (array $c, int $tol) use (&$asignado, $porMonto, $movs): array {
            $lista = [];
            foreach ($porMonto[self::claveMonto($c['monto'])] ?? [] as $mid) {
                if (!isset($asignado[$mid]) && abs(Texto::dias($movs[$mid]['fecha'], $c['fecha'])) <= $tol) {
                    $lista[] = $mid;
                }
            }
            return $lista;
        };
        $masCercano = function (array $c, array $lista) use ($movs): int {
            usort($lista, fn($a, $b) => abs(Texto::dias($movs[$a]['fecha'], $c['fecha'])) <=> abs(Texto::dias($movs[$b]['fecha'], $c['fecha'])));
            return $lista[0];
        };
        $asignar = function ($cid, int $mid, string $estado, string $detalle) use (&$asignado, &$resultado): void {
            $asignado[$mid] = $cid;
            $resultado[$cid] = ['estado' => $estado, 'detalle' => $detalle, 'movimiento_id' => $mid];
        };

        // Nombre con el que se compara cada transferencia: el del banco o, si no vino, el de SAS (según el DNI)
        $nombreMov = function (int $mid) use ($movs, $nombresSas): array {
            $banco = trim((string)($movs[$mid]['nombre'] ?? ''));
            if ($banco !== '') {
                return [$banco, false];
            }
            $dni = (int)($movs[$mid]['dni'] ?? 0);
            return isset($nombresSas[$dni]) ? [(string)$nombresSas[$dni], true] : ['', false];
        };

        // Cupones que no se controlan
        foreach ($cupones as $cid => $c) {
            if (preg_match('/DUPLICADO/i', (string)($c['estado_bot'] ?? ''))) {
                $resultado[$cid] = ['estado' => 'omitido', 'detalle' => 'Cupón duplicado', 'movimiento_id' => null];
            } elseif (($c['monto'] ?? 0) <= 0) {
                $resultado[$cid] = ['estado' => 'omitido', 'detalle' => 'Sin monto leído', 'movimiento_id' => null];
            } elseif (empty($c['fecha'])) {
                $resultado[$cid] = ['estado' => 'omitido', 'detalle' => 'Sin fecha leída', 'movimiento_id' => null];
            }
        }
        $pendientes = function () use (&$resultado, $cupones): array {
            return array_filter($cupones, fn($c, $cid) => !isset($resultado[$cid]), ARRAY_FILTER_USE_BOTH);
        };

        // 1) N° de operación que aparece en el concepto o la referencia del banco
        foreach ($pendientes() as $cid => $c) {
            $op = Texto::soloAlfanumerico($c['nro_operacion'] ?? '');
            if (strlen($op) < 6) {
                continue;
            }
            $lista = array_values(array_filter($candidatos($c, $this->tolCuil), fn($mid) =>
                str_contains($conceptoAlfa[$mid], $op)
                || (($movs[$mid]['referencia'] ?? '') !== '' && Texto::soloAlfanumerico($movs[$mid]['referencia']) === $op)));
            if ($lista) {
                $asignar($cid, $masCercano($c, $lista), 'ok', 'Coincide N° de operación y monto');
            }
        }

        // 2) Mismo DNI/CUIL y mismo monto
        foreach ($pendientes() as $cid => $c) {
            if (empty($c['dni'])) {
                continue;
            }
            $lista = array_values(array_filter($candidatos($c, $this->tolCuil), fn($mid) => (int)$movs[$mid]['dni'] === (int)$c['dni']));
            if ($lista) {
                $asignar($cid, $masCercano($c, $lista), 'ok', 'Coincide CUIL y monto');
            }
        }

        // 3) Mismo nombre y mismo monto (el nombre puede venir del banco o, si el banco no lo informó, de SAS)
        foreach ($pendientes() as $cid => $c) {
            $lista = array_values(array_filter($candidatos($c, $this->tolDias), fn($mid) =>
                Texto::nombresCoinciden($c['titular'] ?? '', $nombreMov($mid)[0])));
            if ($lista) {
                $mid = $masCercano($c, $lista);
                $asignar($cid, $mid, 'ok', $nombreMov($mid)[1] ? 'Coincide nombre (según SAS) y monto' : 'Coincide nombre y monto');
            }
        }

        // 4) Solo monto y fecha → a revisar (primero los casos sin ambigüedad)
        $orden = [];
        foreach ($pendientes() as $cid => $c) {
            $orden[$cid] = count($candidatos($c, $this->tolDias));
        }
        asort($orden);
        foreach (array_keys($orden) as $cid) {
            $c = $cupones[$cid];
            $lista = $candidatos($c, $this->tolDias);
            if (count($lista) === 1) {
                $asignar($cid, $lista[0], 'revisar', 'Coincide monto y fecha, pero no el nombre');
            } elseif (count($lista) > 1) {
                $asignar($cid, $masCercano($c, $lista), 'revisar', 'Había ' . count($lista) . ' transferencias del mismo monto');
            }
        }

        // 5) Sin coincidencia
        foreach ($pendientes() as $cid => $c) {
            if (!$minBanco || Texto::dias($c['fecha'], $minBanco) < -$this->tolDias || Texto::dias($c['fecha'], $maxBanco) > 0) {
                $resultado[$cid] = ['estado' => 'espera', 'detalle' => 'Todavía no se subió el resumen del banco de esa fecha', 'movimiento_id' => null];
            } elseif (Texto::dias($maxBanco, $c['fecha']) < $this->diasEspera) {
                $resultado[$cid] = ['estado' => 'espera', 'detalle' => 'Puede figurar en el próximo resumen', 'movimiento_id' => null];
            } else {
                $resultado[$cid] = ['estado' => 'no', 'detalle' => 'No hay ninguna transferencia de ese monto en esas fechas', 'movimiento_id' => null];
            }
        }

        return ['cupones' => $resultado, 'movs' => $asignado];
    }

    private static function claveMonto($monto): string
    {
        return (string)(int)round((float)$monto * 100);
    }
}
