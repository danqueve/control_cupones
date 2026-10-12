<?php
declare(strict_types=1);

function flash(?string $mensaje = null, string $tipo = 'ok'): ?array
{
    iniciar_sesion();
    if ($mensaje !== null) {
        $_SESSION['flash'] = ['mensaje' => $mensaje, 'tipo' => $tipo];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function pendientes_abiertos(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM pendientes WHERE estado = 'pendiente'")->fetchColumn();
}

/** Íconos de trazo (24×24). Siempre decorativos: el texto va al lado. */
function icono(string $nombre, string $clase = 'ico'): string
{
    $trazos = [
        'subir'      => '<path d="M12 16V4m0 0-4 4m4-4 4 4M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
        'control'    => '<path d="M4 7h10M4 12h16M4 17h7"/><path d="M17 4l3 3-3 3"/>',
        'reloj'      => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'buscar'     => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'lista'      => '<path d="M4 6h16M4 12h16M4 18h10"/>',
        'check'      => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'alerta'     => '<circle cx="12" cy="12" r="8.5"/><path d="M12 8v4.5M12 16v.01"/>',
        'cruz'       => '<path d="M7 7l10 10M17 7 7 17"/>',
        'billete'    => '<rect x="3" y="6.5" width="18" height="11" rx="2"/><circle cx="12" cy="12" r="2.5"/>',
        'menos'      => '<circle cx="12" cy="12" r="8.5"/><path d="M8.5 12h7"/>',
        'calendario' => '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
        'archivo'    => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M12 18v-6m0 0-2.5 2.5M12 12l2.5 2.5"/>',
        'copiar'     => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
        'externo'    => '<path d="M14 4h6v6M20 4l-9 9M18 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4"/>',
        'actualizar' => '<path d="M20 11a8 8 0 0 0-14.3-4.9L4 8M4 4v4h4M4 13a8 8 0 0 0 14.3 4.9L20 16M20 20v-4h-4"/>',
        'salir'      => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l5-5-5-5M15 12H4"/>',
    ];
    return '<svg class="' . h($clase) . '" viewBox="0 0 24 24" aria-hidden="true">' . ($trazos[$nombre] ?? '') . '</svg>';
}

/** "hace 12 min", "hace 3 h", "el 04/10" */
function hace_cuanto(?string $fecha): string
{
    $t = $fecha ? strtotime($fecha) : false;
    if (!$t) {
        return '';
    }
    $seg = time() - $t;
    if ($seg < 60) {
        return 'recién';
    }
    if ($seg < 3600) {
        return 'hace ' . intdiv($seg, 60) . ' min';
    }
    if ($seg < 86400) {
        return 'hace ' . intdiv($seg, 3600) . ' h';
    }
    return 'el ' . date('d/m', $t);
}

/** dd/mm (sin año), para períodos y listas. */
function fecha_corta(?string $fecha): string
{
    $t = $fecha ? strtotime($fecha) : false;
    return $t ? date('d/m', $t) : '';
}

const DIAS_SEMANA = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
const MESES = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

/** "Domingo 11 de octubre" */
function fecha_larga(?int $t = null): string
{
    $t ??= time();
    return ucfirst(DIAS_SEMANA[(int)date('w', $t)]) . ' ' . date('j', $t) . ' de ' . MESES[(int)date('n', $t)];
}

/** "sáb 04/10" */
function dia_corto(?string $fecha): string
{
    $t = $fecha ? strtotime($fecha) : false;
    return $t ? mb_substr(DIAS_SEMANA[(int)date('w', $t)], 0, 3) . ' ' . date('d/m', $t) : '';
}

function iniciales(?string $nombre): string
{
    $partes = preg_split('/[\s,.]+/u', trim((string)$nombre), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
    $ini = mb_substr($partes[0], 0, 1) . (isset($partes[1]) ? mb_substr($partes[1], 0, 1) : '');
    return mb_strtoupper($ini);
}

/** Barra apilada con la proporción de cada estado. $partes: [clase => cantidad] */
function barra_estados(array $partes, string $extra = ''): string
{
    $nombres = ['ok' => 'acreditados', 'revisar' => 'a revisar', 'no' => 'no figuran', 'espera' => 'en espera', 'sincupon' => 'sin cupón'];
    $aria = [];
    $html = '';
    foreach ($partes as $clase => $n) {
        if ($n > 0) {
            $html .= '<span class="b-' . h($clase) . '" style="flex:' . (int)$n . '"></span>';
            $aria[] = (int)$n . ' ' . ($nombres[$clase] ?? $clase);
        }
    }
    return '<div class="barra-estados ' . h($extra) . '" role="img" aria-label="' . h(implode(', ', $aria) ?: 'Sin datos') . '">' . $html . '</div>';
}

/** Días desde una fecha y su clase de antigüedad (para pendientes). */
function antiguedad(?string $fecha): array
{
    $t = $fecha ? strtotime($fecha) : false;
    $dias = $t ? max(0, (int)floor((strtotime('today') - $t) / 86400)) : 0;
    $clase = $dias > 21 ? 'vieja' : ($dias > 14 ? 'media' : 'nueva');
    return ['dias' => $dias, 'clase' => $clase];
}

function encabezado(string $titulo, string $activo = ''): void
{
    $u = usuario();
    $f = flash();
    $pend = 0;
    $ultimoId = null;
    $planilla = null;
    if ($u) {
        $pend = pendientes_abiertos();
        $ultimoId = db()->query('SELECT MAX(id) FROM controles')->fetchColumn() ?: null;
        $planilla = [
            'cupones' => (int)db()->query('SELECT COUNT(*) FROM cupones WHERE en_planilla = 1')->fetchColumn(),
            'lectura' => ajuste('ultima_sincronizacion'),
        ];
    }
    $volver = basename((string)($_SERVER['REQUEST_URI'] ?? 'index.php'));
    $nav = [
        'inicio' => ['index.php', 'Subir resumen', 'Subir', 'subir', ''],
        'control' => [$ultimoId ? 'control.php?id=' . (int)$ultimoId : null, 'Último control', 'Control', 'control', 'solo-pc'],
        'pendientes' => ['pendientes.php', 'Pendientes', 'Pendientes', 'reloj', ''],
        'buscar' => ['buscar.php', 'Buscar persona', 'Buscar', 'buscar', ''],
        'historial' => ['historial.php', 'Historial', 'Historial', 'lista', ''],
    ];
    ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="theme-color" content="#0F1B24">
  <title><?= h($titulo) ?> · Control de Cupones</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%232FC48D'/><path d='M9 16.5l4.5 4.5L23 11' stroke='%230F1B24' stroke-width='3.2' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@400;500;600&display=swap">
  <link rel="stylesheet" href="assets/app.css?v=2">
</head>
<body>
<?php if ($u): ?>
<div class="app">
  <aside class="lateral">
    <a class="marca" href="index.php">
      <span class="marca-logo" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
      <span><strong>Control de Cupones</strong><small>Imperio Comercial · Macro</small></span>
    </a>
    <form class="solo-cel" method="post" action="index.php">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="sincronizar"><input type="hidden" name="volver" value="<?= h($volver) ?>">
      <button class="boton-icono" type="submit" aria-label="Actualizar planilla"><?= icono('actualizar') ?></button>
    </form>
    <a class="boton-icono solo-cel" href="logout.php" aria-label="Salir"><?= icono('salir') ?></a>

    <nav class="nav" aria-label="Secciones">
      <?php foreach ($nav as $clave => [$url, $largo, $corto, $ico, $clase]): if (!$url) continue; ?>
        <a href="<?= h($url) ?>" class="<?= trim('n-' . $clave . ' ' . ($activo === $clave ? 'activo ' : '') . $clase) ?>"<?= $activo === $clave ? ' aria-current="page"' : '' ?>>
          <?= icono($ico) ?>
          <span class="nav-texto"><span class="largo"><?= h($largo) ?></span><span class="corto"><?= h($corto) ?></span></span>
          <?php if ($clave === 'pendientes' && $pend > 0): ?><em class="burbuja" aria-label="<?= $pend ?> abiertos"><?= $pend ?></em><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="lateral-pie">
      <div class="planilla">
        <div class="planilla-titulo"><span class="punto<?= $planilla['lectura'] ? '' : ' apagado' ?>"></span><span>Planilla de cupones</span></div>
        <div class="planilla-dato"><span class="mono"><?= number_format($planilla['cupones'], 0, '', '.') ?></span> cupones ·
          <?= $planilla['lectura'] ? 'leída ' . h(hace_cuanto($planilla['lectura'])) : 'todavía no se leyó' ?></div>
        <form method="post" action="index.php">
          <?= csrf_campo() ?><input type="hidden" name="accion" value="sincronizar"><input type="hidden" name="volver" value="<?= h($volver) ?>">
          <button class="boton-oscuro" type="submit">Actualizar ahora</button>
        </form>
      </div>
      <div class="quien">
        <span class="avatar" aria-hidden="true"><?= h(iniciales($u['nombre'])) ?></span>
        <span class="quien-nombre"><?= h($u['nombre']) ?></span>
        <a href="logout.php">Salir</a>
      </div>
    </div>
  </aside>
  <main class="contenido">
    <div class="ancho">
<?php else: ?>
  <main class="contenido-sin-menu">
    <div>
<?php endif; ?>
    <?php if ($f): ?>
      <div class="aviso aviso-<?= h($f['tipo']) ?>" role="status"><?= icono($f['tipo'] === 'error' ? 'alerta' : ($f['tipo'] === 'info' ? 'calendario' : 'check')) ?><span><?= h($f['mensaje']) ?></span></div>
    <?php endif; ?>
<?php
}

function pie(): void
{
    $u = usuario();
    ?>
    </div>
  </main>
<?php if ($u): ?>
</div>
<?php endif; ?>
</body>
</html>
<?php
}

/** Etiqueta de estado (ícono + texto, nunca solo color). */
function etiqueta(string $estado, ?string $texto = null): string
{
    $i = estado_info($estado);
    return '<span class="etiqueta e-' . h($i['clase']) . '">' . icono($i['icono'], '') . h($texto ?? $i['texto']) . '</span>';
}

function cliente_sas(?int $dni, array $nombresSas): string
{
    if (!$dni || empty($nombresSas[$dni])) {
        return '';
    }
    $link = Sas::link($dni);
    $texto = 'Cliente SAS: ' . h($nombresSas[$dni]);
    return '<div class="sas">' . ($link ? '<a href="' . h($link) . '" target="_blank" rel="noopener">' . $texto . '</a>' : $texto) . '</div>';
}

function formato_cuit(?string $cuit): string
{
    $c = preg_replace('/\D/', '', (string)$cuit);
    return strlen($c) === 11 ? substr($c, 0, 2) . '-' . substr($c, 2, 8) . '-' . substr($c, 10) : (string)$cuit;
}

function formato_dni($dni): string
{
    return number_format((int)$dni, 0, '', '.');
}

function link_persona(?int $dni, ?string $texto = null, string $clase = 'titular'): string
{
    if (!$dni) {
        return '<span class="' . h($clase) . '">' . h($texto ?? '') . '</span>';
    }
    return '<a class="' . h($clase) . '" href="buscar.php?q=' . $dni . '">' . h($texto ?? ('DNI ' . formato_dni($dni))) . '</a>';
}
