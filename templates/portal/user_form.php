<?php
use App\Portal\View;
use App\ReportService;

$isNew = $member === null;
$action = $isNew ? View::url('/equipo/nuevo') : View::url('/equipo/' . $member['id']);
$isAdminRole = ($values['role'] ?? 'member') === 'admin';
$grantedReports = is_array($values['reports'] ?? null) ? $values['reports'] : [];
?>
<a class="back" href="<?= View::url('/equipo') ?>"><?= View::icon('volver') ?>Equipo</a>
<header class="page-head">
  <h1><?= $isNew ? 'Nuevo usuario' : $e($member['name']) ?></h1>
  <p class="muted"><?= $isNew
      ? 'Comparte el usuario y la contraseña con la persona; podrá cambiar la contraseña después.'
      : 'Usuario ' . $e($member['username']) ?></p>
</header>

<?php if ($error): ?><?= View::alert($error) ?><?php endif; ?>

<form method="post" action="<?= $action ?>" class="panel panel--pad form form--narrow">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <?php if ($accountLocked): ?>
    <div class="notice notice--quiet">
      <p>Esta persona también trabaja en otros negocios. Aquí puedes cambiar su rol y su acceso en <?= $e($business['name']) ?>; su nombre y contraseña solo los cambia el superadministrador.</p>
    </div>
  <?php else: ?>
    <label class="field">
      <span>Nombre</span>
      <input name="name" value="<?= $e($values['name'] ?? '') ?>" required maxlength="120">
    </label>
  <?php endif; ?>
  <?php if ($isNew): ?>
    <label class="field">
      <span>Usuario para iniciar sesión</span>
      <input name="username" value="<?= $e($values['username'] ?? '') ?>" required pattern="[A-Za-z0-9._\-]{3,60}"
             autocomplete="off" autocapitalize="none">
      <small>Entre 3 y 60 caracteres: letras, números, punto o guion. Es único en toda la plataforma.</small>
    </label>
  <?php endif; ?>
  <label class="field">
    <span>Rol</span>
    <select name="role" data-role>
      <option value="member" <?= !$isAdminRole ? 'selected' : '' ?>>Miembro: factura y registra pagos</option>
      <option value="admin" <?= $isAdminRole ? 'selected' : '' ?>>Administrador: además gestiona equipo y emisor</option>
    </select>
  </label>
  <label class="check">
    <input type="checkbox" name="web_access" value="1" data-web <?= !empty($values['web_access']) || $isAdminRole ? 'checked' : '' ?> <?= $isAdminRole ? 'disabled' : '' ?>>
    <span>
      <strong>Puede entrar desde la web</strong>
      <small data-web-help><?= $isAdminRole
          ? 'Los administradores siempre pueden usar la web.'
          : 'Para quien usa iPhone o prefiere el navegador: podrá facturar y registrar pagos igual que en la app.' ?></small>
    </span>
  </label>
  <label class="check">
    <input type="checkbox" name="can_void" value="1" data-void <?= !empty($values['can_void']) || $isAdminRole ? 'checked' : '' ?> <?= $isAdminRole ? 'disabled' : '' ?>>
    <span>
      <strong>Puede anular facturas y pagos</strong>
      <small data-void-help><?= $isAdminRole
          ? 'Los administradores siempre pueden anular.'
          : 'En la app y en la web, indicando siempre el motivo. Anular una factura anula también todos sus pagos.' ?></small>
    </span>
  </label>
  <fieldset class="check-group">
    <legend>Informes que puede ver</legend>
    <small data-reports-help><?= $isAdminRole
        ? 'Los administradores siempre ven todos los informes.'
        : 'En la app y en la web. Sin ninguno marcado, no verá la sección Informes.' ?></small>
    <?php foreach (ReportService::CATALOG as $key => [$label]): ?>
      <label class="check">
        <input type="checkbox" name="reports[]" value="<?= $e($key) ?>" data-report <?= in_array($key, $grantedReports, true) || $isAdminRole ? 'checked' : '' ?> <?= $isAdminRole ? 'disabled' : '' ?>>
        <span><?= $e($label) ?></span>
      </label>
    <?php endforeach; ?>
  </fieldset>
  <?php if (!$accountLocked): ?>
    <label class="field">
      <span><?= $isNew ? 'Contraseña' : 'Restablecer contraseña' ?></span>
      <span class="input-row">
        <input name="password" type="text" minlength="6" <?= $isNew ? 'required' : '' ?> autocomplete="new-password"
               placeholder="<?= $isNew ? '' : 'Déjala vacía para no cambiarla' ?>" data-password>
        <button type="button" class="btn" data-generate>Generar</button>
      </span>
      <?php if (!$isNew): ?><small>Al cambiarla se cierran sus sesiones abiertas en la app y en la web.</small><?php endif; ?>
    </label>
  <?php endif; ?>
  <?php if (!$isNew): ?>
    <label class="check">
      <input type="checkbox" name="active" value="1" <?= !empty($values['active']) ? 'checked' : '' ?> <?= $isSelf ? 'disabled checked' : '' ?>>
      <span>
        <strong>Activo en este negocio</strong>
        <small><?= $isSelf ? 'No puedes desactivar tu propio usuario.' : 'Si lo desactivas, deja de poder entrar en este negocio y se cierra su sesión.' ?></small>
      </span>
    </label>
  <?php endif; ?>
  <div class="form__actions">
    <a class="btn" href="<?= View::url('/equipo') ?>">Cancelar</a>
    <button class="btn btn--primary" type="submit"><?= $isNew ? 'Crear usuario' : 'Guardar cambios' ?></button>
  </div>
</form>

<?php if (!$isNew && !$isSelf && (!$member['is_superadmin'] || $isSuper)): ?>
  <form method="post" action="<?= View::url('/equipo/' . $member['id'] . '/quitar') ?>" class="panel panel--pad form form--narrow mt"
        data-confirm-title="¿Quitar a <?= $e($member['name']) ?> de <?= $e($business['name']) ?>?" data-confirm-ok="Sí, quitar"
        data-confirm="Perderá el acceso y sus permisos en este negocio. Sus facturas y pagos se conservan.">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <div>
      <h2>Quitar del negocio</h2>
      <p class="muted small">Deja de pertenecer a <?= $e($business['name']) ?> y se cierra su sesión aquí. Las facturas y los pagos que registró se conservan.
        <?php if ((int) $member['business_count'] > 1): ?>Sigue trabajando en sus otros negocios.<?php else: ?>Su cuenta queda sin negocio hasta que el superadministrador la añada a uno.<?php endif; ?>
        Si solo es algo temporal, mejor desmarca «Activo en este negocio».</p>
    </div>
    <div class="form__actions"><button class="btn btn--danger" type="submit">Quitar del negocio</button></div>
  </form>
<?php endif; ?>
