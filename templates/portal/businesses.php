<?php use App\Portal\View; ?>
<header class="page-head page-head--split">
  <div>
    <h1>Negocios</h1>
    <p class="muted">Tus clientes en la plataforma. Cada negocio tiene sus propias facturas, emisor y equipo, y nadie ve los datos de otro.</p>
  </div>
  <a class="btn btn--primary" href="<?= View::url('/negocios/nuevo') ?>"><?= View::icon('mas') ?>Nuevo negocio</a>
</header>

<?php if (!$list): ?>
  <section class="empty">
    <h2>Aún no hay negocios</h2>
    <p class="muted">Crea el primero para un cliente; podrás darle de alta a su administrador en el mismo paso.</p>
  </section>
<?php else: ?>
  <section class="panel">
    <table class="table">
      <thead><tr><th>Negocio</th><th class="right">Personas</th><th class="right">Facturas</th><th class="right">Con saldo</th><th>Estado</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($list as $b): ?>
        <tr class="row-link<?= $b['active'] ? '' : ' is-muted' ?>" data-href="<?= View::url('/negocios/' . $b['id']) ?>">
          <td><a href="<?= View::url('/negocios/' . $b['id']) ?>"><?= $e($b['name']) ?></a>
            <?php if ($user && (int) $user['business_id'] === (int) $b['id']): ?><span class="sub">Trabajando aquí ahora</span><?php endif; ?></td>
          <td class="right num"><?= (int) $b['members'] ?></td>
          <td class="right num"><?= (int) $b['invoices'] ?></td>
          <td class="right num"><?= (int) $b['open_invoices'] ?></td>
          <td><?= $b['active'] ? '<span class="badge badge--paid">Activo</span>' : '<span class="badge">Desactivado</span>' ?></td>
          <td class="right nowrap">
            <form class="inline" method="post" action="<?= View::url('/negocio-actual') ?>">
              <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
              <input type="hidden" name="business_id" value="<?= (int) $b['id'] ?>">
              <button class="link-btn" type="submit">Entrar</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php endif; ?>
