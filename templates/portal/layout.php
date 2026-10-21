<?php
use App\Portal\View;

$nav = [];
if ($user) {
    $nav['resumen'] = ['Resumen', '/resumen'];
    $nav['facturas'] = ['Facturas', '/facturas'];
}
if ($isAdmin) {
    $nav['equipo'] = ['Equipo', '/equipo'];
    $nav['emisor'] = ['Datos del emisor', '/emisor'];
}
if ($isSuper) {
    $nav['negocios'] = ['Negocios', '/negocios'];
}
$initials = implode('', array_map(fn($p) => mb_strtoupper(mb_substr($p, 0, 1)),
    array_slice(array_values(array_filter(explode(' ', $account['name']))), 0, 2)));
$roleLabel = $user === null ? 'Plataforma' : ($user['role'] === 'admin' ? 'Administración' : 'Equipo');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($title) ?> · <?= $e($business['name'] ?? 'Facturas') ?></title>
  <script>
    // Tema antes de pintar, para evitar el destello al cargar.
    try { const t = localStorage.getItem('theme'); if (t) document.documentElement.dataset.theme = t; } catch (e) {}
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">
  <link rel="stylesheet" href="<?= View::asset('portal.css') ?>">
</head>
<body>
<div class="shell">
  <aside class="sidebar">
    <div class="brand">
      <span class="brand__mark" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M6 3h12v18l-2-1.5L14 21l-2-1.5L10 21l-2-1.5L6 21z"/><path class="brand__check" d="M9 11l2 2 4-4"/></svg>
      </span>
      <?php if (count($businesses) > 1): ?>
        <form class="switcher" method="post" action="<?= View::url('/negocio-actual') ?>">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <label class="visually-hidden" for="negocio">Negocio</label>
          <select id="negocio" name="business_id" data-autosubmit>
            <?php if (!$user): ?><option value="" selected disabled>Elige un negocio</option><?php endif; ?>
            <?php foreach ($businesses as $b): ?>
              <option value="<?= (int) $b['id'] ?>" <?= $user && (int) $b['id'] === (int) $user['business_id'] ? 'selected' : '' ?>>
                <?= $e($b['name']) ?><?= $b['active'] ? '' : ' (desactivado)' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <span class="switcher__role"><?= $e($roleLabel) ?></span>
          <noscript><button class="btn" type="submit">Cambiar</button></noscript>
        </form>
      <?php else: ?>
        <span class="brand__text">
          <strong><?= $e($business['name'] ?? 'Facturas') ?></strong>
          <span><?= $e($roleLabel) ?></span>
        </span>
      <?php endif; ?>
    </div>
    <nav class="nav" aria-label="Secciones">
      <?php foreach ($nav as $key => [$label, $path]): ?>
        <a href="<?= View::url($path) ?>" class="nav__link<?= $section === $key ? ' is-active' : '' ?>"
           <?= $section === $key ? 'aria-current="page"' : '' ?>>
          <?= View::icon($key) ?><span><?= $e($label) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="me">
      <a class="me__card<?= $section === 'cuenta' ? ' is-active' : '' ?>" href="<?= View::url('/cuenta') ?>">
        <span class="avatar"><?= $e($initials) ?></span>
        <span class="me__text"><strong><?= $e($account['name']) ?></strong><span>Mi cuenta</span></span>
      </a>
      <div class="me__actions">
        <button type="button" class="ghost-btn" data-theme-toggle title="Cambiar tema">
          <?= View::icon('tema') ?><span data-theme-label>Tema</span>
        </button>
        <form method="post" action="<?= View::url('/salir') ?>">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <button class="ghost-btn" type="submit"><?= View::icon('salir') ?><span>Salir</span></button>
        </form>
      </div>
    </div>
  </aside>

  <main class="main" id="contenido">
    <?php if ($flash): ?>
      <div class="flash flash--<?= $e($flash['type']) ?>" role="status"><?= $e($flash['text']) ?></div>
    <?php endif; ?>
    <?= $content ?>
  </main>
</div>
<script src="<?= View::asset('portal.js') ?>"></script>
</body>
</html>
