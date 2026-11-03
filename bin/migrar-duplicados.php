#!/usr/bin/env php
<?php
/**
 * Migración: transferencias cargadas dos veces.
 *
 * El banco reescribe el concepto de una misma transferencia entre resúmenes
 * (primero sin nombre, después con nombre) y la clave anterior incluía el
 * concepto, así que se cargaba de nuevo. La copia de más terminaba en
 * "Plata sin cupón" o, peor, acreditando el cupón de otra persona del mismo
 * monto. Este script junta cada transferencia repetida en una sola, pasa todas
 * las filas a la clave nueva (Banco::baseClave) y vuelve a cruzar todo.
 *
 * Por defecto es un simulacro: solo muestra lo que haría.
 *   /opt/php8-3/bin/php bin/migrar-duplicados.php
 *   /opt/php8-3/bin/php bin/migrar-duplicados.php --aplicar
 *
 * Antes de --aplicar, hacé un backup de la base y guardalo FUERA de la carpeta
 * del sistema (trae datos personales de clientes).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../app/bootstrap.php';

$aplicar = in_array('--aplicar', $argv, true);
$pdo = db();

$grupos = $pdo->query("SELECT referencia, fecha, monto, codigo, COUNT(*) n, GROUP_CONCAT(id ORDER BY id) ids
                       FROM movimientos WHERE referencia <> ''
                       GROUP BY referencia, fecha, monto, codigo HAVING COUNT(*) > 1")->fetchAll();
$copias = 0;
$plata = 0.0;
foreach ($grupos as $g) {
    $copias += (int)$g['n'] - 1;
    $plata += ((int)$g['n'] - 1) * (float)$g['monto'];
}

echo ($aplicar ? "=== APLICANDO ===\n" : "=== SIMULACRO (no se cambia nada; para hacerlo: --aplicar) ===\n") . "\n";
echo count($grupos) . " transferencia(s) cargada(s) más de una vez: " . $copias . " copia(s) de más por " . dinero($plata) . ".\n";

// Cupones cruzados hoy con una copia cuya gemela también acredita otro cupón:
// al juntarlas, uno de los dos se queda sin esa plata.
$enRiesgo = $pdo->query("SELECT c.id, c.fecha_cupon, c.monto, c.zona, c.titular, c.estado_conc
                         FROM cupones c JOIN movimientos m ON m.id = c.movimiento_id
                         WHERE EXISTS (SELECT 1 FROM movimientos o
                                       WHERE o.referencia = m.referencia AND o.fecha = m.fecha AND o.monto = m.monto
                                         AND o.codigo = m.codigo AND o.id <> m.id AND o.cupon_id IS NOT NULL AND o.cupon_id <> c.id)
                           AND m.referencia <> ''
                         ORDER BY c.fecha_cupon, c.id")->fetchAll();
if ($enRiesgo) {
    echo "\nCupones que hoy usan la misma transferencia que otro cupón (" . count($enRiesgo) . "):\n";
    foreach ($enRiesgo as $c) {
        printf("  · cupón #%d  %s  %s  %s  %s  [%s]\n", $c['id'], fecha_ar($c['fecha_cupon']), dinero($c['monto']),
            $c['zona'], $c['titular'] ?: '(sin titular)', $c['estado_conc']);
    }
    echo "  Al juntar las copias, la transferencia queda para uno solo (el que mejor coincide).\n";
}

if (!$aplicar) {
    echo "\nTambién se pasan " . (int)$pdo->query('SELECT COUNT(*) FROM movimientos')->fetchColumn()
        . " transferencias a la clave nueva y se vuelve a cruzar todo.\n";
    echo "\nSimulacro terminado. Para hacerlo: agregá --aplicar\n";
    exit(0);
}

$estadoAntes = $pdo->query('SELECT id, estado_conc FROM cupones')->fetchAll(PDO::FETCH_KEY_PAIR);

if ((int)$pdo->query("SELECT GET_LOCK('control_cupones', 60)")->fetchColumn() !== 1) {
    fwrite(STDERR, "Hay un control en curso. Probá de nuevo en un minuto.\n");
    exit(1);
}
try {
    $pdo->beginTransaction();

    $filasGrupo = $pdo->prepare('SELECT id, nombre, concepto, cuit, dni FROM movimientos WHERE FIND_IN_SET(id, ?) ORDER BY id');
    $completar = $pdo->prepare('UPDATE movimientos SET concepto = ?, nombre = ?, cuit = COALESCE(cuit, ?), dni = COALESCE(dni, ?) WHERE id = ?');
    foreach ($grupos as $g) {
        $filasGrupo->execute([$g['ids']]);
        $filas = $filasGrupo->fetchAll();
        $sobreviviente = (int)$filas[0]['id'];

        // El texto más completo: el que trae nombre y, entre esos, el concepto más largo
        $mejor = $filas[0];
        foreach ($filas as $f) {
            $tiene = trim((string)$f['nombre']) !== '';
            $mejorTiene = trim((string)$mejor['nombre']) !== '';
            if (($tiene && !$mejorTiene) || ($tiene === $mejorTiene && mb_strlen($f['concepto']) > mb_strlen($mejor['concepto']))) {
                $mejor = $f;
            }
        }
        $cuit = null;
        $dni = null;
        foreach ($filas as $f) {
            $cuit ??= $f['cuit'];
            $dni ??= $f['dni'];
        }
        $completar->execute([$mejor['concepto'], $mejor['nombre'], $cuit, $dni, $sobreviviente]);

        $otras = array_map(fn($f) => (int)$f['id'], array_slice($filas, 1));
        $in = implode(',', $otras);
        $pdo->exec("UPDATE cupones SET movimiento_id = $sobreviviente WHERE movimiento_id IN ($in)");
        $pdo->exec("UPDATE control_items SET movimiento_id = $sobreviviente WHERE movimiento_id IN ($in)");
        $pdo->exec("UPDATE confirmaciones SET movimiento_id = $sobreviviente WHERE movimiento_id IN ($in)");
        // Asignación a mano (una por transferencia): si la tenía una copia y no el sobreviviente, se pasa
        if (!$pdo->query("SELECT 1 FROM movimientos_cliente WHERE movimiento_id = $sobreviviente")->fetchColumn()) {
            $pdo->exec("UPDATE movimientos_cliente SET movimiento_id = $sobreviviente WHERE movimiento_id IN ($in) ORDER BY movimiento_id LIMIT 1");
        }
        $pdo->exec("DELETE FROM movimientos_cliente WHERE movimiento_id IN ($in)");
        $pdo->exec("DELETE FROM movimientos WHERE id IN ($in)");
    }
    echo "✓ " . count($grupos) . " transferencia(s) juntada(s), " . $copias . " copia(s) borrada(s).\n";

    // Clave nueva para todas. Primero una provisoria por id, para que ningún paso intermedio choque con el UNIQUE.
    $pdo->exec("UPDATE movimientos SET clave = CONCAT('tmp-', id)");
    $repetidas = [];
    $ponerClave = $pdo->prepare('UPDATE movimientos SET clave = ? WHERE id = ?');
    $todas = $pdo->query('SELECT id, fecha, referencia, monto, codigo, concepto FROM movimientos ORDER BY id')->fetchAll();
    foreach ($todas as $m) {
        $base = Banco::baseClave(new DateTimeImmutable($m['fecha']), (string)$m['referencia'], (float)$m['monto'],
            (string)$m['codigo'], (string)$m['concepto']);
        $repetidas[$base] = ($repetidas[$base] ?? 0) + 1;
        $ponerClave->execute([Banco::clave($base, $repetidas[$base]), (int)$m['id']]);
    }
    echo "✓ " . count($todas) . " transferencia(s) con la clave nueva.\n";

    ajuste('movimientos_clave_v2', date('Y-m-d H:i:s'));
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "No se cambió nada: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    $pdo->query("SELECT RELEASE_LOCK('control_cupones')");
}

echo "· Volviendo a cruzar todo y rehaciendo la foto de los controles...\n";
Controles::reconciliarTodo();
echo "✓ Listo.\n";

$estadoDespues = $pdo->query('SELECT id, estado_conc FROM cupones')->fetchAll(PDO::FETCH_KEY_PAIR);
$cambiaron = array_keys(array_filter($estadoDespues, fn($e, $id) => ($estadoAntes[$id] ?? null) !== $e, ARRAY_FILTER_USE_BOTH));
// Los que salieron de la planilla no son para revisar: solo dejan de arrastrar un cruce viejo
$fuera = $cambiaron ? (int)$pdo->query('SELECT COUNT(*) FROM cupones WHERE en_planilla = 0 AND id IN ('
    . implode(',', array_map('intval', $cambiaron)) . ')')->fetchColumn() : 0;
if ($fuera) {
    echo "\n· " . $fuera . " cupón(es) que ya no están en la planilla dejaron de arrastrar el resultado de un cruce viejo.\n";
}
$cambiaron = $cambiaron ? $pdo->query('SELECT id FROM cupones WHERE en_planilla = 1 AND id IN ('
    . implode(',', array_map('intval', $cambiaron)) . ')')->fetchAll(PDO::FETCH_COLUMN) : [];
if ($cambiaron) {
    $st = $pdo->query('SELECT id, fecha_cupon, monto, zona, titular, estado_conc, detalle_conc FROM cupones WHERE id IN ('
        . implode(',', array_map('intval', $cambiaron)) . ') ORDER BY fecha_cupon, id');
    echo "\nCupones que cambiaron de estado (" . count($cambiaron) . ") — conviene mirarlos:\n";
    foreach ($st as $c) {
        printf("  · cupón #%d  %s  %s  %s  %s  %s → %s  (%s)\n", $c['id'], fecha_ar($c['fecha_cupon']), dinero($c['monto']),
            $c['zona'], $c['titular'] ?: '(sin titular)', $estadoAntes[(int)$c['id']] ?? '-', $c['estado_conc'], $c['detalle_conc']);
    }
} else {
    echo "\nNingún cupón cambió de estado.\n";
}

$sinAplicar = $pdo->query("SELECT c.id, c.titular, c.monto, c.estado_conc FROM confirmaciones cf JOIN cupones c ON c.id = cf.cupon_id
                           WHERE c.estado_conc <> 'ok' ORDER BY c.id")->fetchAll();
if ($sinAplicar) {
    echo "\nConfirmaciones a mano que ya no aplican (" . count($sinAplicar) . "): la transferencia confirmada era una copia"
        . " o la reclamó otro cupón con más evidencia. Revisalos:\n";
    foreach ($sinAplicar as $c) {
        printf("  · cupón #%d  %s  %s  [%s]\n", $c['id'], $c['titular'] ?: '(sin titular)', dinero($c['monto']), $c['estado_conc']);
    }
}

echo "\nMigración completada.\n";
