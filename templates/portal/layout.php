<?php
use App\Portal\View;

$nav = [];
if ($user) {
    $nav['resumen'] = ['Resumen', '/resumen'];
    $nav['facturas'] = ['Facturas', '/facturas'];
    if (!empty($user['reports'])) {
        $nav['informes'] = ['Informes', '/informes'];
    }
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
  <link rel="stylesheet" href="<?= View::asset('vendor/bootstrap-portal.min.css') ?>">
  <link rel="stylesheet" href="<?= View::asset('portal.css') ?>">
</head>
<body>
<div class="shell">
  <header class="topbar">
    <button type="button" class="topbar__menu" data-bs-toggle="offcanvas" data-bs-target="#menu" aria-controls="menu" aria-label="Abrir menú">
      <?= View::icon('menu') ?>
    </button>
    <strong class="topbar__title"><?= $e($business['name'] ?? 'Facturas') ?></strong>
  </header>

  <aside class="sidebar offcanvas-start" id="menu" tabindex="-1" aria-label="Menú">
    <button type="button" class="btn-close sidebar__close" data-bs-dismiss="offcanvas" aria-label="Cerrar menú"></button>
    <div class="brand">
      <span class="brand__mark" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M6 3h12v18l-2-1.5L14 21l-2-1.5L10 21l-2-1.5L6 21z"/><path class="brand__check" d="M9 11l2 2 4-4"/></svg>
      </span>
      <?php if (count($businesses) > 1): ?>
        <div class="switcher">
          <details class="switcher__box" data-switcher>
            <summary class="switcher__current" aria-label="Cambiar de negocio">
              <span title="<?= $e($user ? $business['name'] : 'Elige un negocio') ?>"><?= $e($user ? $business['name'] : 'Elige un negocio') ?></span>
              <?= View::icon('abajo') ?>
            </summary>
            <form class="switcher__menu" method="post" action="<?= View::url('/negocio-actual') ?>">
              <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
              <?php foreach ($businesses as $b):
                  $isCurrent = $user && (int) $b['id'] === (int) $user['business_id']; ?>
                <button class="switcher__option<?= $isCurrent ? ' is-current' : '' ?>" type="submit" name="business_id" value="<?= (int) $b['id'] ?>"
                        <?= $isCurrent ? 'aria-current="true"' : '' ?>>
                  <span><?= $e($b['name']) ?><?= $b['active'] ? '' : ' (desactivado)' ?></span>
                  <?php if ($isCurrent): ?><?= View::icon('marca') ?><?php endif; ?>
                </button>
              <?php endforeach; ?>
            </form>
          </details>
          <span class="switcher__role"><?= $e($roleLabel) ?></span>
        </div>
      <?php else: ?>
        <span class="brand__text">
          <strong title="<?= $e($business['name'] ?? 'Facturas') ?>"><?= $e($business['name'] ?? 'Facturas') ?></strong>
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
    <?= $content ?>
  </main>
</div>

<?php if ($flash):
    $isError = $flash['type'] === 'error'; ?>
  <div class="toast-container portal-toasts">
    <div class="toast show toast--<?= $isError ? 'error' : 'ok' ?>" role="<?= $isError ? 'alert' : 'status' ?>" aria-atomic="true"
         data-bs-autohide="<?= $isError ? 'false' : 'true' ?>" data-bs-delay="6000">
      <div class="toast-body">
        <?= View::icon($isError ? 'aviso' : 'hecho') ?>
        <span><?= $e($flash['text']) ?></span>
        <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Cerrar"></button>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="modal fade" id="confirmar" tabindex="-1" aria-labelledby="confirmar-titulo" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body confirm">
        <span class="confirm__icon"><?= View::icon('aviso') ?></span>
        <div>
          <h2 class="modal-title" id="confirmar-titulo" data-confirm-title></h2>
          <p class="muted" data-confirm-text></p>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn" data-bs-dismiss="modal" data-confirm-cancel>Cancelar</button>
        <button type="button" class="btn btn--danger-solid" data-confirm-ok>Confirmar</button>
      </div>
    </div>
  </div>
</div>

<script src="<?= View::asset('vendor/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= View::asset('portal.js') ?>"></script>
</body>
</html>
