<?php
/**
 * Copiá este archivo como app/config.php y completá los datos.
 * app/config.php NO se sube a GitHub (está en .gitignore).
 */
return [
    // Base propia del sistema
    'db' => [
        'host' => 'localhost',
        'name' => 'c2881399_control',
        'user' => 'c2881399_control',
        'pass' => 'CAMBIAR',
    ],

    // Exportador de la planilla de Google (apps-script/Exportador.gs)
    'planilla' => [
        'url'   => 'https://script.google.com/macros/s/XXXXXXXX/exec',
        'token' => 'CAMBIAR_TOKEN_LARGO',
        // Si pasaron más de estos minutos desde la última lectura, se vuelve a leer sola
        'minutos_vigencia' => 15,
    ],

    // SAS Imperio — SOLO LECTURA, para mostrar el nombre del cliente por DNI.
    // Usar un usuario MySQL que solo tenga SELECT (ver README).
    // Dejar 'activo' => false para no usarlo.
    'sas' => [
        'activo' => false,
        'host'   => 'localhost',
        'name'   => 'c2881399_credit',
        'user'   => 'c2881399_sasro',
        'pass'   => 'CAMBIAR',
        // La consulta debe devolver las columnas dni y nombre.
        // {dnis} se reemplaza por la lista de DNI a buscar.
        // (Se quitan puntos y espacios del DNI por si en SAS está cargado como "33.974.259")
        'sql'    => "SELECT REPLACE(REPLACE(dni, '.', ''), ' ', '') AS dni, CONCAT(apellidos, ', ', nombres) AS nombre"
                  . " FROM ic_clientes WHERE REPLACE(REPLACE(dni, '.', ''), ' ', '') IN ({dnis})",
        // Link opcional a la ficha del cliente en SAS ({dni} se reemplaza). Dejar '' si no se usa.
        'link'   => '',
    ],

    // Margen de días entre la fecha del cupón y la fecha en que aparece en el banco
    'tolerancia_dias'      => 3,
    'tolerancia_dias_cuil' => 5,
    // Cupones de los últimos días del resumen que todavía no aparecen quedan "En espera"
    'dias_espera'          => 2,

    'zona_horaria' => 'America/Argentina/Tucuman',
];
