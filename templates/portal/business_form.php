<?php use App\Portal\View; $v = $values; ?>
<a class="back" href="<?= View::url('/negocios') ?>"><?= View::icon('volver') ?>Negocios</a>
<header class="page-head">
  <h1>Nuevo negocio</h1>
  <p class="muted">Después de crearlo entrarás en él para completar los datos del emisor (nombre fiscal, logo, numeración).</p>
</header>

<?php if ($error): ?><div class="flash flash--error" role="alert"><?= $e($error) ?></div><?php endif; ?>

<form method="post" action="<?= View::url('/negocios/nuevo') ?>" class="form form--narrow">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <section class="panel panel--pad stack">
    <h2>Negocio</h2>
    <label class="field"><span>Nombre del negocio</span><input name="name" value="<?= $e($v['name'] ?? '') ?>" required maxlength="150" autofocus></label>
    <label class="check">
      <input type="checkbox" name="include_me" value="1" <?= !empty($v['include_me']) ? 'checked' : '' ?>>
      <span><strong>Incluirme como administrador</strong>
        <small>Así lo verás en la app al cambiar de negocio. Como superadministrador, en la web puedes entrar igualmente.</small></span>
    </label>
  </section>

  <section class="panel panel--pad stack">
    <h2>Administrador del cliente</h2>
    <p class="muted">Opcional: la cuenta con la que tu cliente gestionará su negocio y su equipo. Puedes añadirla más tarde.</p>
    <label class="field"><span>Nombre</span><input name="admin_name" value="<?= $e($v['admin_name'] ?? '') ?>" maxlength="120"></label>
    <label class="field"><span>Usuario</span><input name="admin_username" value="<?= $e($v['admin_username'] ?? '') ?>" pattern="[A-Za-z0-9._\-]{3,60}" autocomplete="off" autocapitalize="none"></label>
    <label class="field">
      <span>Contraseña</span>
      <span class="input-row">
        <input name="admin_password" type="text" minlength="6" autocomplete="new-password" data-password value="<?= $e($v['admin_password'] ?? '') ?>">
        <button type="button" class="btn" data-generate>Generar</button>
      </span>
    </label>
  </section>

  <div class="form__actions">
    <a class="btn" href="<?= View::url('/negocios') ?>">Cancelar</a>
    <button class="btn btn--primary" type="submit">Crear negocio</button>
  </div>
</form>
