<?php
declare(strict_types=1);

/**
 * Funciones puras para interpretar textos de cupones y del banco:
 * nombres, CUIL/DNI, montos y fechas en formato argentino.
 */
final class Texto
{
    private const PREFIJOS_CUIT = '(?:20|23|24|25|26|27|30|33|34)';

    public static function normalizar(?string $s): string
    {
        $s = (string)$s;
        if (class_exists('Normalizer')) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_D) ?: $s;
            $s = preg_replace('/\p{Mn}/u', '', $s);
        } else {
            $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
                'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
        }
        return trim(mb_strtoupper($s, 'UTF-8'));
    }

    public static function soloAlfanumerico(?string $s): string
    {
        return preg_replace('/[^A-Z0-9]/', '', self::normalizar($s));
    }

    /** @return string[] */
    public static function tokens(?string $s, int $minimo = 3): array
    {
        $limpio = preg_replace('/[^A-Z ]/', ' ', self::normalizar($s));
        return array_values(array_filter(preg_split('/\s+/', $limpio), fn($t) => strlen($t) >= $minimo));
    }

    /** "DELGADILL" (nombre cortado por el banco) coincide con "DELGADILLO". */
    public static function tokenParecido(string $x, string $y): bool
    {
        if ($x === $y) {
            return true;
        }
        return min(strlen($x), strlen($y)) >= 4 && (str_starts_with($x, $y) || str_starts_with($y, $x));
    }

    public static function nombresCoinciden(?string $a, ?string $b): bool
    {
        $ta = self::tokens($a);
        $tb = self::tokens($b);
        if (!$ta || !$tb) {
            return false;
        }
        $coinciden = 0;
        foreach ($ta as $x) {
            foreach ($tb as $y) {
                if (self::tokenParecido($x, $y)) {
                    $coinciden++;
                    break;
                }
            }
        }
        return $coinciden >= 2 || ($coinciden === 1 && min(count($ta), count($tb)) === 1);
    }

    public static function extraerCuit(?string $texto): string
    {
        $t = (string)$texto;
        if (preg_match('/(?:^|\D)(' . self::PREFIJOS_CUIT . '\d{9})(?!\d)/', $t, $m)) {
            return $m[1];
        }
        if (preg_match('/(?:^|\D)(' . self::PREFIJOS_CUIT . ')-(\d{8})-(\d)(?!\d)/', $t, $m)) {
            return $m[1] . $m[2] . $m[3];
        }
        return '';
    }

    public static function dniDeCuit(?string $cuit): ?int
    {
        $dni = (int)substr((string)$cuit, 2, 8);
        return $dni > 0 ? $dni : null;
    }

    /** "ING TRANSF:YAMILA BELEN DELGADILL-27432251360" → "YAMILA BELEN DELGADILL" */
    public static function nombreDelConcepto(?string $concepto): string
    {
        $c = (string)$concepto;
        // "ING TRANSF:YAMILA BELEN DELGADILL-27432251360"
        if (preg_match('/TRANSF:\s*([\p{L} ,.\'´]+?)\s*-\s*\d{11}/iu', $c, $m)) {
            return trim($m[1]);
        }
        // "TRANSF FERNANDO 20339742597 VAR" o "TRANSF CORDOBA/F 20339742597 VAR"
        if (preg_match('/TRANSF\s+(\p{L}[\p{L} .\/\'´]*?)\s+' . self::PREFIJOS_CUIT . '\d{9}(?!\d)/iu', $c, $m)) {
            $nombre = trim(preg_replace('/\s+/', ' ', str_replace('/', ' ', $m[1])));
            return self::tokens($nombre) ? $nombre : '';
        }
        return '';
    }

    /**
     * Montos en cualquier formato habitual:
     * "64.000,00" (Argentina), "64,000.00" (EE.UU.), "64000.50", "64000,50", "64.000", "64,000" o 64000.
     */
    public static function monto($v): float
    {
        if (is_int($v) || is_float($v)) {
            return (float)$v;
        }
        $s = preg_replace('/[^0-9,.\-]/', '', (string)$v);
        if ($s === '' || $s === '-') {
            return 0.0;
        }
        $coma = strrpos($s, ',');
        $punto = strrpos($s, '.');
        if ($coma !== false && $punto !== false) {
            // El separador que aparece último es el de los decimales
            $s = $coma > $punto ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
        } elseif ($coma !== false) {
            // Solo comas: "64,000" / "1,250,000" son miles; "64000,50" es decimal
            $s = preg_match('/^-?\d{1,3}(,\d{3})+$/', $s) ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        } elseif ($punto !== false && preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) {
            // Solo puntos de miles: "64.000"
            $s = str_replace('.', '', $s);
        }
        return (float)$s;
    }

    /** "13/10/2026", "13/10/2026, 15:02:33" o "2026-10-13" → DateTimeImmutable (sin hora). */
    public static function fechaTexto($v): ?\DateTimeImmutable
    {
        $s = trim((string)$v);
        if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{2,4})/', $s, $m)) {
            $anio = (int)$m[3] < 100 ? 2000 + (int)$m[3] : (int)$m[3];
            return self::crearFecha($anio, (int)$m[2], (int)$m[1]);
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
            return self::crearFecha((int)$m[1], (int)$m[2], (int)$m[3]);
        }
        return null;
    }

    public static function crearFecha(int $anio, int $mes, int $dia): ?\DateTimeImmutable
    {
        if (!checkdate($mes, $dia, $anio)) {
            return null;
        }
        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $anio, $mes, $dia));
    }

    /** Número de serie de Excel (46275) → fecha. */
    public static function serialExcel(float $n): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('1899-12-30'))->modify('+' . (int)floor($n) . ' days');
    }

    public static function dias(\DateTimeInterface $a, \DateTimeInterface $b): int
    {
        return (int)round((strtotime($a->format('Y-m-d')) - strtotime($b->format('Y-m-d'))) / 86400);
    }
}
