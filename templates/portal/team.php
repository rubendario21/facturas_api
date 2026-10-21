<?php use App\Portal\View; ?>
<header class="page-head page-head--split">
  <div>
    <h1>Equipo</h1>
    <p class="muted">Quién trabaja en <?= $e($business['name']) ?>. Los miembros facturan y registran pagos; los administradores además gestionan el equipo y el emisor.</p>
  </div>
  <a class="btn btn--primary" href="<?= View::url('/equipo/nuevo') ?>"><?= View::icon('mas') ?>Añadir usuario</a>
</header>

<section class="panel">
  <table class="table">
    <thead><tr><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Acceso</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr class="row-link<?= $u['active'] ? '' : ' is-muted' ?>" data-href="<?= View::url('/equipo/' . $u['id']) ?>">
        <td>
          <a href="<?= View::url('/equipo/' . $u['id']) ?>"><?= $e($u['name']) ?></a>
          <?php if ((int) $u['id'] === (int) $account['id']): ?><span class="sub">Tú</span>
          <?php elseif ((int) $u['business_count'] > 1): ?><span class="sub">También en otros negocios</span><?php endif; ?>
        </td>
        <td class="num"><?= $e($u['username']) ?></td>
        <td><?= $u['role'] === 'admin' ? 'Administrador' : 'Miembro' ?></td>
        <td><?= $u['web_access'] ? 'App y web' : 'Solo app' ?></td>
        <td><?= $u['active'] ? '<span class="badge badge--paid">Activo</span>' : '<span class="badge">Desactivado</span>' ?></td>
        <td class="right"><a href="<?= View::url('/equipo/' . $u['id']) ?>">Editar</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
