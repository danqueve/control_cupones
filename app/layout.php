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

function encabezado(string $titulo, string $activo = ''): void
{
    $u = usuario();
    $pend = $u ? pendientes_abiertos() : 0;
    $nav = [
        'inicio' => ['index.php', 'Subir resumen', '<path d="M12 16V4m0 0-4 4m4-4 4 4M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>'],
        'buscar' => ['buscar.php', 'Buscar', '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>'],
        'pendientes' => ['pendientes.php', 'Pendientes', '<path d="M12 8v4l2.5 2.5"/><circle cx="12" cy="12" r="9"/>'],
        'historial' => ['historial.php', 'Historial', '<path d="M4 6h16M4 12h16M4 18h10"/>'],
    ];
    $f = flash();
    ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= h($titulo) ?> · Control de Cupones</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%23155e4b'/><path d='M9 16.5l4.5 4.5L23 11' stroke='white' stroke-width='3.2' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>">
  <link rel="stylesheet" href="assets/app.css?v=1">
</head>
<body>
<?php if ($u): ?>
  <header class="barra">
    <a class="marca" href="index.php">
      <span class="marca-logo" aria-hidden="true">✓</span>
      <span><strong>Control de Cupones</strong><small>Banco Macro · Imperio Comercial</small></span>
    </a>
    <nav class="nav" aria-label="Secciones">
      <?php foreach ($nav as $clave => [$url, $texto, $icono]): ?>
        <a href="<?= $url ?>" class="<?= $activo === $clave ? 'activo' : '' ?>">
          <svg viewBox="0 0 24 24" aria-hidden="true"><?= $icono ?></svg>
          <span><?= h($texto) ?></span>
          <?php if ($clave === 'pendientes' && $pend > 0): ?><em class="burbuja"><?= $pend ?></em><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="usuario">
      <span><?= h($u['nombre']) ?></span>
      <a href="logout.php">Salir</a>
    </div>
  </header>
<?php endif; ?>
  <main class="contenido">
    <?php if ($f): ?>
      <div class="aviso aviso-<?= h($f['tipo']) ?>" role="status"><?= h($f['mensaje']) ?></div>
    <?php endif; ?>
<?php
}

function pie(): void
{
    ?>
  </main>
</body>
</html>
<?php
}

/** Etiqueta de estado (color + texto, nunca solo color). */
function etiqueta(string $estado): string
{
    $i = estado_info($estado);
    return '<span class="etiqueta e-' . h($i['clase']) . '">' . $i['icono'] . ' ' . h($i['texto']) . '</span>';
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

function link_persona(?int $dni, ?string $texto = null): string
{
    if (!$dni) {
        return h($texto ?? '');
    }
    return '<a class="link-persona" href="buscar.php?q=' . $dni . '">' . h($texto ?? ('DNI ' . number_format($dni, 0, '', '.'))) . '</a>';
}
