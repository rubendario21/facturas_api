<?php use App\Portal\View; ?>
<header class="page-head">
  <h1>Mi cuenta</h1>
  <p class="muted"><?= $e($user['name']) ?>, usuario <?= $e($user['username']) ?>. Es el mismo usuario con el que entras en la app.</p>
</header>

<?php if ($error): ?><div class="flash flash--error" role="alert"><?= $e($error) ?></div><?php endif; ?>

<form method="post" action="<?= View::url('/cuenta') ?>" class="panel panel--pad form form--narrow">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <h2>Cambiar contraseña</h2>
  <label class="field"><span>Contraseña actual</span><input name="current" type="password" autocomplete="current-password" required></label>
  <label class="field"><span>Nueva contraseña</span><input name="new" type="password" minlength="6" autocomplete="new-password" required></label>
  <label class="field"><span>Repite la nueva contraseña</span><input name="repeat" type="password" minlength="6" autocomplete="new-password" required></label>
  <small class="muted">Al cambiarla se cierra tu sesión en la app y en otros navegadores.</small>
  <div class="form__actions">
    <button class="btn btn--primary" type="submit">Cambiar contraseña</button>
  </div>
</form>
