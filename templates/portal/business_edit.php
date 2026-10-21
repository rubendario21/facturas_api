<?php use App\Portal\View; ?>
<a class="back" href="<?= View::url('/negocios') ?>"><?= View::icon('volver') ?>Negocios</a>
<header class="page-head page-head--split">
  <div>
    <h1><?= $e($b['name']) ?></h1>
    <p class="muted">Creado el <?= $e(View::date($b['created_at'])) ?></p>
  </div>
  <form class="inline" method="post" action="<?= View::url('/negocio-actual') ?>">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="business_id" value="<?= (int) $b['id'] ?>">
    <button class="btn btn--primary" type="submit">Entrar a gestionarlo</button>
  </form>
</header>

<?php if ($error): ?><div class="flash flash--error" role="alert"><?= $e($error) ?></div><?php endif; ?>

<div class="columns">
  <form method="post" action="<?= View::url('/negocios/' . $b['id']) ?>" class="panel panel--pad stack">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <h2>Datos</h2>
    <label class="field"><span>Nombre</span><input name="name" value="<?= $e($b['name']) ?>" required maxlength="150"></label>
    <label class="check">
      <input type="checkbox" name="active" value="1" <?= $b['active'] ? 'checked' : '' ?>>
      <span><strong>Activo</strong>
        <small>Si lo desactivas, nadie de su equipo puede entrar (ni en la app ni en la web) hasta que lo reactives. Sus datos se conservan.</small></span>
    </label>
    <div class="form__actions"><button class="btn btn--primary" type="submit">Guardar</button></div>
  </form>

  <form method="post" action="<?= View::url('/negocios/' . $b['id'] . '/miembros') ?>" class="panel panel--pad stack">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <h2>Añadir a alguien que ya tiene cuenta</h2>
    <p class="muted">Para una persona que ya trabaja en otro negocio (por ejemplo, tú o un contador). Las cuentas nuevas se crean desde Equipo, dentro del negocio.</p>
    <label class="field"><span>Usuario</span><input name="username" required autocomplete="off" autocapitalize="none"></label>
    <label class="field">
      <span>Rol</span>
      <select name="role">
        <option value="member">Miembro</option>
        <option value="admin">Administrador</option>
      </select>
    </label>
    <div class="form__actions"><button class="btn" type="submit">Añadir al negocio</button></div>
  </form>
</div>

<section class="panel mt">
  <header class="panel__head"><h2>Personas</h2><span class="muted"><?= count($members) ?></span></header>
  <?php if (!$members): ?>
    <p class="panel__empty muted">Nadie pertenece todavía a este negocio.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Acceso</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($members as $u): ?>
        <tr class="<?= $u['active'] ? '' : 'is-muted' ?>">
          <td><?= $e($u['name']) ?><?php if ($u['is_superadmin']): ?><span class="sub">Superadministrador</span><?php endif; ?></td>
          <td class="num"><?= $e($u['username']) ?></td>
          <td><?= $u['role'] === 'admin' ? 'Administrador' : 'Miembro' ?></td>
          <td><?= $u['web_access'] ? 'App y web' : 'Solo app' ?></td>
          <td><?= $u['active'] ? '<span class="badge badge--paid">Activo</span>' : '<span class="badge">Desactivado</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
