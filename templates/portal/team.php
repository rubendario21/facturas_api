<?php use App\Portal\View; ?>
<header class="page-head page-head--split">
  <div>
    <h1>Equipo</h1>
    <p class="muted">Quién trabaja en <?= $e($business['name']) ?>. Los miembros facturan y registran pagos; los administradores además gestionan el equipo y el emisor.</p>
  </div>
  <a class="btn btn--primary" href="<?= View::url('/equipo/nuevo') ?>"><?= View::icon('mas') ?>Añadir usuario</a>
</header>

<section class="panel">
  <table class="table table--cards">
    <thead><tr><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Acceso</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr class="row-link<?= $u['active'] ? '' : ' is-muted' ?>" data-href="<?= View::url('/equipo/' . $u['id']) ?>">
        <td>
          <a href="<?= View::url('/equipo/' . $u['id']) ?>"><?= $e($u['name']) ?></a>
          <?php if ((int) $u['id'] === (int) $account['id']): ?><span class="sub">Tú</span>
          <?php elseif ((int) $u['business_count'] > 1): ?><span class="sub">También en otros negocios</span><?php endif; ?>
        </td>
        <td class="num" data-label="Usuario"><?= $e($u['username']) ?></td>
        <td data-label="Rol"><?= $u['role'] === 'admin' ? 'Administrador' : 'Miembro' ?></td>
        <td data-label="Acceso"><div>
          <?= $u['web_access'] ? 'App y web' : 'Solo app' ?>
          <?php if ($u['role'] !== 'admin' && $u['can_void']): ?><span class="sub">Puede anular</span><?php endif; ?>
          <?php if ($u['role'] !== 'admin' && $u['reports']): ?><span class="sub"><?= count($u['reports']) === 1 ? '1 informe' : count($u['reports']) . ' informes' ?></span><?php endif; ?>
        </div></td>
        <td data-label="Estado"><?= $u['active'] ? '<span class="badge badge--paid">Activo</span>' : '<span class="badge">Desactivado</span>' ?></td>
        <td class="right"><a href="<?= View::url('/equipo/' . $u['id']) ?>">Editar</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
