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

<?php if ($error): ?><?= View::alert($error) ?><?php endif; ?>

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
    <table class="table table--cards">
      <thead><tr><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Acceso</th><th>Estado</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($members as $u): ?>
        <tr class="<?= $u['active'] ? '' : 'is-muted' ?>">
          <td><?= $e($u['name']) ?><?php if ($u['is_superadmin']): ?><span class="sub">Superadministrador</span><?php endif; ?></td>
          <td class="num" data-label="Usuario"><?= $e($u['username']) ?></td>
          <td data-label="Rol"><?= $u['role'] === 'admin' ? 'Administrador' : 'Miembro' ?></td>
          <td data-label="Acceso"><?= $u['web_access'] ? 'App y web' : 'Solo app' ?></td>
          <td data-label="Estado"><?= $u['active'] ? '<span class="badge badge--paid">Activo</span>' : '<span class="badge">Desactivado</span>' ?></td>
          <td class="right">
            <form method="post" action="<?= View::url('/negocios/' . $b['id'] . '/miembros/' . $u['id'] . '/quitar') ?>"
                  data-confirm-title="¿Quitar a <?= $e($u['name']) ?> de <?= $e($b['name']) ?>?" data-confirm-ok="Sí, quitar"
                  data-confirm="Perderá el acceso y sus permisos en este negocio. Sus facturas y pagos se conservan.">
              <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
              <button class="btn btn--danger" type="submit">Quitar</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<div class="columns mt">
  <section class="panel panel--pad stack">
    <div>
      <h2>Copia para el cliente</h2>
      <p class="muted small">Un archivo de Excel con el resumen, las facturas con sus valores y estados, el detalle de cada una y los pagos, incluidos los anulados con su motivo.</p>
    </div>
    <div class="form__actions">
      <a class="btn" href="<?= View::url('/negocios/' . $b['id'] . '/copia') ?>">Descargar Excel</a>
    </div>
  </section>

  <form method="post" action="<?= View::url('/negocios/' . $b['id'] . '/eliminar') ?>" class="panel panel--pad stack panel--danger"
        data-confirm-title="¿Eliminar <?= $e($b['name']) ?> definitivamente?" data-confirm-ok="Sí, eliminar todo"
        data-confirm="Se borrarán sus facturas, pagos, datos del emisor y equipo. No se puede deshacer.">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <div>
      <h2>Eliminar negocio</h2>
      <p class="muted small">Borra sus facturas, pagos, datos del emisor y equipo, y las cuentas que solo trabajaban aquí. No se puede deshacer: descarga antes la copia en Excel. Para pausarlo sin perder nada, desmarca «Activo».</p>
    </div>
    <label class="field">
      <span>Escribe <strong><?= $e($b['name']) ?></strong> para confirmar</span>
      <input name="confirm_name" required autocomplete="off" autocapitalize="off" spellcheck="false">
    </label>
    <div class="form__actions"><button class="btn btn--danger" type="submit">Eliminar negocio</button></div>
  </form>
</div>
