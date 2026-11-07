<?php use App\Portal\View; ?>
<main class="login">
  <aside class="login__cover">
    <span class="login__brand">
      <span class="brand__mark" aria-hidden="true">
      <svg viewBox="0 0 24 24"><path d="M6 3h12v18l-2-1.5L14 21l-2-1.5L10 21l-2-1.5L6 21z"/><path class="brand__check" d="M9 11l2 2 4-4"/></svg>
    </span>
      Facturas
    </span>
    <p class="login__claim">Factura, registra pagos y revisa lo que te deben desde el navegador.</p>
  </aside>
  <div class="login__panel">
    <h1>Inicia sesión</h1>
    <p class="muted">Usa el mismo usuario y contraseña de la app.</p>

    <?php if ($error): ?>
      <?= View::alert($error) ?>
    <?php endif; ?>

    <form method="post" action="<?= View::url('/login') ?>" class="form">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <label class="field">
        <span>Usuario</span>
        <input name="username" value="<?= $e($username) ?>" autocomplete="username" required autofocus>
      </label>
      <label class="field">
        <span>Contraseña</span>
        <input name="password" type="password" autocomplete="current-password" required>
      </label>
      <button class="btn btn--primary btn--block" type="submit">Entrar</button>
    </form>
  </div>
</main>
