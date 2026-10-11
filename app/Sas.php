<?php
declare(strict_types=1);

/**
 * Consulta de SOLO LECTURA a la base de SAS Imperio para saber
 * a qué cliente corresponde un DNI. Si no está configurada o falla,
 * el sistema sigue funcionando sin esos nombres.
 */
final class Sas
{
    private static ?PDO $pdo = null;
    private static bool $fallo = false;

    public static function activo(): bool
    {
        return (bool)config('sas.activo', false) && !self::$fallo;
    }

    /**
     * @param int[] $dnis
     * @return array<int, string> dni => nombre del cliente
     */
    public static function nombres(array $dnis): array
    {
        $dnis = array_values(array_unique(array_filter(array_map('intval', $dnis))));
        if (!$dnis || !self::activo()) {
            return [];
        }
        $resultado = [];
        try {
            $pdo = self::conexion();
            foreach (array_chunk($dnis, 500) as $lote) {
                $marcadores = implode(',', array_fill(0, count($lote), '?'));
                $sql = str_replace('{dnis}', $marcadores, (string)config('sas.sql'));
                if (!preg_match('/^\s*SELECT\s/i', $sql)) {
                    throw new RuntimeException('La consulta de SAS debe ser un SELECT.');
                }
                $st = $pdo->prepare($sql);
                $st->execute($lote);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                    $dni = (int)preg_replace('/\D/', '', (string)($fila['dni'] ?? ''));
                    if ($dni && !empty($fila['nombre'])) {
                        $resultado[$dni] = trim((string)$fila['nombre']);
                    }
                }
            }
        } catch (Throwable $e) {
            self::$fallo = true;
            error_log('[control_cupones] SAS no disponible: ' . $e->getMessage());
        }
        return $resultado;
    }

    public static function link(?int $dni): string
    {
        $plantilla = (string)config('sas.link', '');
        return ($dni && $plantilla !== '') ? str_replace('{dni}', (string)$dni, $plantilla) : '';
    }

    private static function conexion(): PDO
    {
        if (!self::$pdo) {
            self::$pdo = new PDO(
                'mysql:host=' . config('sas.host') . ';dbname=' . config('sas.name') . ';charset=utf8mb4',
                config('sas.user'),
                config('sas.pass'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
            // Refuerzo: la sesión no puede escribir aunque el usuario tuviera permisos
            self::$pdo->exec('SET SESSION TRANSACTION READ ONLY');
        }
        return self::$pdo;
    }
}
