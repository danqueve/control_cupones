<?php
declare(strict_types=1);

/**
 * Normalización para la API de comprobantes (Rendición de Transferencias
 * v2, sas_imperio). Delega en app/Texto.php para CUIL/DNI/monto — esa
 * clase ya hace exactamente esto para el cruce interno de
 * app/Conciliador.php (extraerCuitAjeno(), dniDeCuit(), monto()), y
 * además excluye el CUIT propio de Imperio Comercial. Reescribir esa
 * lógica acá hubiera significado dos implementaciones del mismo cálculo
 * que con el tiempo pueden divergir — este archivo solo le agrega lo que
 * a Texto.php todavía le faltaba para este uso puntual: limpiar
 * superíndices/espacios de más del número de operación, y devolver
 * centavos (entero) en vez de un float.
 *
 * Pura, sin base de datos — se puede probar con arrays
 * (ver scripts/test_normalizador.php).
 */
final class Normalizador
{
    /**
     * CUIL de origen de un comprobante (acepta texto suelto, con o sin
     * guiones, con un CBU pegado al lado, etc. — ver Texto::extraerCuitAjeno()).
     * Devuelve los 11 dígitos, o null si no hay un CUIL de un tercero.
     */
    public static function cuil(?string $crudo): ?string
    {
        $cuit = Texto::extraerCuitAjeno($crudo);
        return $cuit !== '' ? $cuit : null;
    }

    /** DNI a partir del CUIL ya extraído (dígitos 3 a 10, sin ceros a la izquierda). */
    public static function dniDesdeCuil(?string $cuil11): ?int
    {
        return $cuil11 !== null ? Texto::dniDeCuit($cuil11) : null;
    }

    /** Conveniencia: DNI directo a partir del crudo (cuil() + dniDesdeCuil() encadenados). */
    public static function dniOrigen(?string $crudoCuil): ?int
    {
        return self::dniDesdeCuil(self::cuil($crudoCuil));
    }

    /**
     * Número de operación: le saca caracteres invisibles, espacios de más
     * y superíndices sueltos (ej. "²") que a veces deja el copiado/pegado
     * del comprobante. No valida formato — cada banco/billetera usa el suyo.
     * No hay equivalente en Texto.php porque nro_operacion no participa
     * del cruce interno de este sistema (ahí se usa N° de operación tal
     * cual viene, sin necesitar limpieza para comparar contra el banco).
     */
    public static function nroOperacion(?string $crudo): ?string
    {
        if ($crudo === null) {
            return null;
        }
        $limpio = str_replace("\xE2\x80\x8B", '', $crudo);
        $limpio = preg_replace('/[\x{00B2}\x{00B3}\x{00B9}]/u', '', $limpio) ?? $limpio; // ² ³ ¹
        $limpio = trim(preg_replace('/\s+/', ' ', $limpio) ?? $limpio);
        return $limpio !== '' ? $limpio : null;
    }

    /**
     * Monto en centavos (entero) — Texto::monto() ya interpreta formato
     * argentino/EE.UU./número plano y devuelve float; acá solo se
     * redondea a centavos para el contrato JSON de la API.
     */
    public static function montoCentavos($crudo): ?int
    {
        if ($crudo === null || $crudo === '') {
            return null;
        }
        return (int) round(Texto::monto($crudo) * 100);
    }
}
