<?php
use App\Portal\View;

$tabs = ['todas' => 'Todas', 'por-cobrar' => 'Por cobrar', 'vencidas' => 'Vencidas', 'pagadas' => 'Pagadas', 'anuladas' => 'Anuladas'];
$link = fn(array $over) => View::url('/facturas') . '?' . http_build_query(array_filter(
    $over + ['estado' => $status, 'q' => $q], fn($v) => $v !== '' && $v !== 'todas' && $v !== 1));
?>
<header class="page-head page-head--split">
  <div>
    <h1>Facturas</h1>
    <p class="muted">Lo que crees o cobres aquí aparece en la app de todo el equipo al sincronizar.</p>
  </div>
  <a class="btn btn--primary" href="<?= View::url('/facturas/nueva') ?>"><?= View::icon('mas') ?>Nueva factura</a>
</header>

<div class="toolbar">
  <nav class="tabs" aria-label="Filtrar por estado">
    <?php foreach ($tabs as $key => $label): ?>
      <a href="<?= $e($link(['estado' => $key, 'pagina' => 1])) ?>" class="tabs__tab<?= $status === $key ? ' is-active' : '' ?>"
         <?= $status === $key ? 'aria-current="page"' : '' ?>><?= $e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <form class="search" method="get" action="<?= View::url('/facturas') ?>" role="search">
    <?php if ($status !== 'todas'): ?><input type="hidden" name="estado" value="<?= $e($status) ?>"><?php endif; ?>
    <?= View::icon('buscar') ?>
    <input type="search" name="q" value="<?= $e($q) ?>" placeholder="Cliente, número o identificación" aria-label="Buscar facturas">
  </form>
</div>

<?php if (!$invoices): ?>
  <section class="empty">
    <h2><?= $q !== '' ? 'Sin resultados' : 'No hay facturas en este filtro' ?></h2>
    <p class="muted"><?= $q !== '' ? 'Prueba con otro nombre o número.' : 'Crea una aquí o desde la app; aparecerá en los dos sitios.' ?></p>
  </section>
<?php else: ?>
  <section class="panel">
    <table class="table table--invoices">
      <thead>
        <tr><th>Número</th><th>Cliente</th><th>Emisión</th><th>Vence</th><th>Estado</th><th class="right">Total</th><th class="right">Saldo</th></tr>
      </thead>
      <tbody>
      <?php foreach ($invoices as $i): ?>
        <tr class="row-link" data-href="<?= View::url('/facturas/' . $i['id']) ?>">
          <td class="num"><a href="<?= View::url('/facturas/' . $i['id']) ?>"><?= $e($i['number']) ?></a></td>
          <td><?= $e($i['client_name']) ?></td>
          <td class="num"><?= $e(View::date($i['issue_date'])) ?></td>
          <td class="num<?= $i['overdue'] ? ' overdue-text' : '' ?>"><?= $e(View::date($i['due_date'])) ?></td>
          <td><?= View::status($i['status']) ?></td>
          <td class="right num<?= $i['voided'] ? ' strike' : '' ?>"><?= $e(View::money($i['total'], $currency)) ?></td>
          <td class="right num"><?= $i['paid'] || $i['voided'] ? '<span class="muted">—</span>' : $e(View::money($i['balance'], $currency)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <footer class="pager">
    <span class="muted"><?= $total ?> factura<?= $total === 1 ? '' : 's' ?></span>
    <?php if ($pages > 1): ?>
      <span>
        <?php if ($page > 1): ?><a class="btn" href="<?= $e($link(['pagina' => $page - 1])) ?>">Anterior</a><?php endif; ?>
        <span class="muted">Página <?= $page ?> de <?= $pages ?></span>
        <?php if ($page < $pages): ?><a class="btn" href="<?= $e($link(['pagina' => $page + 1])) ?>">Siguiente</a><?php endif; ?>
      </span>
    <?php endif; ?>
  </footer>
<?php endif; ?>
