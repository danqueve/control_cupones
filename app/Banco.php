<?php
declare(strict_types=1);

/**
 * Lee el Excel de "Últimos movimientos" del Banco Macro
 * (las filas llegan ya leídas por el navegador, como arrays).
 */
final class Banco
{
    /**
     * @param array $filas Filas del Excel, cada una un array de celdas (texto o número)
     * @return array ['movs' => [...], 'desde' => DateTimeImmutable, 'hasta' => DateTimeImmutable]
     * @throws RuntimeException si el archivo no es el esperado
     */
    public static function parsear(array $filas): array
    {
        $map = self::mapearEncabezado($filas);
        if (!$map) {
            throw new RuntimeException('No reconozco el archivo. Tiene que ser el Excel de "Últimos movimientos" del Banco Macro (con columnas Fecha, Concepto y Crédito).');
        }

        $movs = [];
        $repetidas = [];
        $anterior = null;
        $desde = null;
        $hasta = null;

        for ($i = $map['fila'] + 1, $n = count($filas); $i < $n; $i++) {
            $f = is_array($filas[$i]) ? array_values($filas[$i]) : [];
            $fecha = self::fecha($f[$map['fecha']] ?? null, $anterior);
            if (!$fecha) {
                continue;
            }
            $anterior = $fecha;
            $desde = (!$desde || $fecha < $desde) ? $fecha : $desde;
            $hasta = (!$hasta || $fecha > $hasta) ? $fecha : $hasta;

            $monto = Texto::monto($f[$map['credito']] ?? '');
            if ($monto <= 0) {
                continue; // solo ingresos
            }

            $concepto = trim((string)($f[$map['concepto']] ?? ''));
            $referencia = $map['referencia'] !== null ? trim((string)($f[$map['referencia']] ?? '')) : '';
            $codigo = $map['codigo'] !== null ? trim((string)($f[$map['codigo']] ?? '')) : '';
            $cuit = Texto::extraerCuit($concepto);

            // Clave estable para no cargar dos veces el mismo movimiento si se suben resúmenes superpuestos
            $base = implode('|', [$fecha->format('Y-m-d'), $referencia, number_format($monto, 2, '.', ''), $codigo, $concepto]);
            $repetidas[$base] = ($repetidas[$base] ?? 0) + 1;

            $movs[] = [
                'clave' => sha1($base . '#' . $repetidas[$base]),
                'fecha' => $fecha,
                'cuit' => $cuit ?: null,
                'dni' => $cuit ? Texto::dniDeCuit($cuit) : null,
                'nombre' => Texto::nombreDelConcepto($concepto) ?: null,
                'monto' => round($monto, 2),
                'referencia' => $referencia,
                'codigo' => $codigo,
                'concepto' => mb_substr($concepto, 0, 255),
            ];
        }

        if (!$movs) {
            throw new RuntimeException('El archivo no tiene transferencias recibidas (créditos).');
        }
        return ['movs' => $movs, 'desde' => $desde, 'hasta' => $hasta];
    }

    private static function mapearEncabezado(array $filas): ?array
    {
        $limite = min(count($filas), 25);
        for ($i = 0; $i < $limite; $i++) {
            if (!is_array($filas[$i])) {
                continue;
            }
            $enc = array_map(fn($v) => Texto::normalizar((string)$v), array_values($filas[$i]));
            $buscar = function (callable $pred) use ($enc): ?int {
                foreach ($enc as $j => $v) {
                    if ($pred($v)) {
                        return $j;
                    }
                }
                return null;
            };
            $fecha = $buscar(fn($v) => $v === 'FECHA');
            $concepto = $buscar(fn($v) => $v === 'CONCEPTO');
            $credito = $buscar(fn($v) => str_starts_with($v, 'CREDITO'));
            if ($fecha !== null && $concepto !== null && $credito !== null) {
                return [
                    'fila' => $i,
                    'fecha' => $fecha,
                    'concepto' => $concepto,
                    'credito' => $credito,
                    'referencia' => $buscar(fn($v) => $v === 'REFERENCIA'),
                    'codigo' => $buscar(fn($v) => str_contains($v, 'CAUSAL')),
                ];
            }
        }
        return null;
    }

    /**
     * El Excel de Macro trae las fechas mezcladas: del 13 al 31 vienen como
     * texto ("13/10/2026"), pero del 1 al 12 Excel las convierte en fecha con
     * día y mes invertidos (el 9/10 aparece como 10 de septiembre).
     * Para esas se prueban las dos lecturas y se elige la más cercana a la
     * fila anterior, porque el resumen viene ordenado por fecha.
     */
    public static function fecha($valor, ?DateTimeImmutable $anterior): ?DateTimeImmutable
    {
        if (is_int($valor) || is_float($valor) || (is_string($valor) && preg_match('/^\d{5}(\.\d+)?$/', trim($valor)))) {
            $n = (float)$valor;
            if ($n < 20000 || $n > 90000) {
                return null;
            }
            $a = Texto::serialExcel($n);
            if ((int)$a->format('j') > 12) {
                return $a;
            }
            $b = Texto::crearFecha((int)$a->format('Y'), (int)$a->format('j'), (int)$a->format('n'));
            if (!$b) {
                return $a;
            }
            $ref = $anterior ?? new DateTimeImmutable('today');
            return abs(Texto::dias($a, $ref)) <= abs(Texto::dias($b, $ref)) ? $a : $b;
        }
        return Texto::fechaTexto($valor);
    }
}
