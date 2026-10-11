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
        if (!preg_match('/TRANSF:\s*([\p{L} ,.\'´]+?)\s*-\s*\d{11}/iu', (string)$concepto, $m)) {
            return '';
        }
        return preg_match('/\d/', $m[1]) ? '' : trim($m[1]);
    }

    /** Montos como "64.000,00", "45000.50", 45000 o "45.000". */
    public static function monto($v): float
    {
        if (is_int($v) || is_float($v)) {
            return (float)$v;
        }
        $s = preg_replace('/[^0-9,.\-]/', '', (string)$v);
        if ($s === '' || $s === '-') {
            return 0.0;
        }
        if (str_contains($s, ',')) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
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
