<?php use App\Portal\View; ?>
<header class="page-head page-head--split">
  <div>
    <h1>Resumen</h1>
    <p class="muted"><?= $e(View::longDate(new DateTime())) ?></p>
  </div>
  <?php if ($issuerReady): ?>
    <a class="btn btn--primary" href="<?= View::url('/facturas/nueva') ?>"><?= View::icon('mas') ?>Nueva factura</a>
  <?php endif; ?>
</header>

<?php if (!$issuerReady): ?>
  <div class="notice">
    <p><strong>Falta completar los datos del emisor.</strong> Sin nombre e identificación fiscal no se pueden crear facturas.</p>
    <?php if ($isAdmin): ?><a class="btn btn--primary" href="<?= View::url('/emisor') ?>">Completar datos</a><?php endif; ?>
  </div>
<?php endif; ?>

<section class="ledger" aria-label="Cobros">
  <div class="ledger__main">
    <span class="ledger__label">Por cobrar</span>
    <span class="ledger__figure num"><?= str_replace(' ', "\u{00A0}", $e(View::money($receivable, $currency))) ?></span>
    <span class="muted"><?= $openCount ?> factura<?= $openCount === 1 ? '' : 's' ?> con saldo pendiente</span>
    <?php if ($receivable > 0):
        $notDue = $receivable - $overdueTotal;
        $overdueShare = (int) round($overdueTotal / $receivable * 100); ?>
      <div class="ledger__bar" role="img"
           aria-label="<?= $overdueShare ?> % de lo que se debe está vencido y <?= 100 - $overdueShare ?> % está por vencer">
        <?php if ($overdueTotal > 0): ?><span class="seg--overdue" style="flex: <?= $overdueTotal ?> 1 0" title="Vencido: <?= $e(View::money($overdueTotal, $currency)) ?> (<?= $overdueShare ?> %)"></span><?php endif; ?>
        <?php if ($notDue > 0): ?><span class="seg--current" style="flex: <?= $notDue ?> 1 0" title="Por vencer: <?= $e(View::money($notDue, $currency)) ?> (<?= 100 - $overdueShare ?> %)"></span><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <dl class="ledger__parts">
    <div>
      <dt><span class="seg-key seg--overdue"></span>Vencido</dt>
      <dd class="num"><?= $e(View::money($overdueTotal, $currency)) ?></dd>
      <dd class="muted"><?= $overdueCount ?> factura<?= $overdueCount === 1 ? '' : 's' ?></dd>
    </div>
    <div>
      <dt><span class="seg-key seg--current"></span>Por vencer</dt>
      <dd class="num"><?= $e(View::money($receivable - $overdueTotal, $currency)) ?></dd>
      <dd class="muted"><?= $openCount - $overdueCount ?> factura<?= ($openCount - $overdueCount) === 1 ? '' : 's' ?></dd>
    </div>
    <div>
      <dt>Cobrado este mes</dt>
      <dd class="num"><?= $e(View::money($collected, $currency)) ?></dd>
    </div>
  </dl>
</section>

<div class="columns">
  <section class="panel">
    <header class="panel__head">
      <h2>Vencidas</h2>
      <a href="<?= View::url('/facturas?estado=vencidas') ?>">Ver todas</a>
    </header>
    <?php if (!$overdue): ?>
      <p class="panel__empty muted">No hay facturas vencidas.</p>
    <?php else: ?>
      <table class="table table--cards">
        <thead><tr><th>Cliente</th><th>Venció</th><th class="right">Saldo</th></tr></thead>
        <tbody>
        <?php foreach ($overdue as $i):
            $days = (new DateTime($i['due_date']))->diff(new DateTime('today'))->days; ?>
          <tr class="row-link" data-href="<?= View::url('/facturas/' . $i['id']) ?>">
            <td>
              <a href="<?= View::url('/facturas/' . $i['id']) ?>"><?= $e($i['client_name']) ?></a>
              <span class="sub num"><?= $e($i['number']) ?></span>
            </td>
            <td class="overdue-text" data-label="Venció">hace <?= $days ?> día<?= $days === 1 ? '' : 's' ?></td>
            <td class="right num" data-label="Saldo"><?= $e(View::money($i['balance'], $currency)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <section class="panel">
    <header class="panel__head">
      <h2>Últimos pagos</h2>
    </header>
    <?php if (!$recentPayments): ?>
      <p class="panel__empty muted">Aún no se ha registrado ningún pago.</p>
    <?php else: ?>
      <table class="table table--cards">
        <thead><tr><th>Cliente</th><th class="hide-sm">Fecha</th><th class="right">Importe</th></tr></thead>
        <tbody>
        <?php foreach ($recentPayments as $p): ?>
          <tr class="row-link" data-href="<?= View::url('/facturas/' . $p['invoice_id']) ?>">
            <td>
              <a href="<?= View::url('/facturas/' . $p['invoice_id']) ?>"><?= $e($p['client_name']) ?></a>
              <span class="sub num"><?= $e($p['receipt_number']) ?> de <?= $e($p['number']) ?><span class="show-sm">, <?= $e(View::date($p['date'])) ?></span></span>
            </td>
            <td class="num hide-sm"><?= $e(View::date($p['date'])) ?></td>
            <td class="right num paid-text" data-label="Importe"><?= $e(View::money((float) $p['amount'], $currency)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
</div>
