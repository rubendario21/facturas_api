<?php
use App\Portal\View;

$i = $invoice;
$qty = fn($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
$editable = !$i['paid'] && $i['payment_count'] === 0;
$pv = $paymentValues ?? [];
?>
<a class="back" href="<?= View::url('/facturas') ?>"><?= View::icon('volver') ?>Facturas</a>

<header class="page-head page-head--split">
  <div>
    <h1 class="num"><?= $e($i['number']) ?></h1>
    <p class="muted"><?= $e($i['client_name']) ?></p>
  </div>
  <div class="page-head__side">
    <?= View::status($i['status']) ?>
    <span class="figure num"><?= $e(View::money($i['total'], $currency)) ?></span>
  </div>
</header>

<div class="actions-bar">
  <a class="btn" href="<?= View::url('/facturas/' . $i['id'] . '/imprimir') ?>" target="_blank" rel="noopener">
    <?= View::icon('imprimir') ?>Imprimir o guardar PDF
  </a>
  <?php if ($editable): ?>
    <a class="btn" href="<?= View::url('/facturas/' . $i['id'] . '/editar') ?>"><?= View::icon('editar') ?>Editar</a>
  <?php endif; ?>
  <?php if ($lastReceipt): ?>
    <a class="btn btn--primary" href="<?= View::url('/pagos/' . $lastReceipt . '/recibo') ?>" target="_blank" rel="noopener">
      <?= View::icon('imprimir') ?>Recibo del pago registrado
    </a>
  <?php endif; ?>
</div>

<div class="columns columns--detail">
  <div class="stack">
    <section class="panel">
      <header class="panel__head"><h2>Conceptos</h2></header>
      <table class="table">
        <thead><tr><th>Descripción</th><th class="right">Cant.</th><th class="right">Precio</th><th class="right">Importe</th></tr></thead>
        <tbody>
        <?php $subtotal = 0; foreach ($items as $it): $line = $it['quantity'] * $it['unit_price']; $subtotal += $line; ?>
          <tr>
            <td><?= $e($it['description']) ?></td>
            <td class="right num"><?= $e($qty($it['quantity'])) ?></td>
            <td class="right num"><?= $e(View::money($it['unit_price'], $currency)) ?></td>
            <td class="right num"><?= $e(View::money($line, $currency)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <dl class="totals">
        <div><dt>Base imponible</dt><dd class="num"><?= $e(View::money($subtotal, $currency)) ?></dd></div>
        <div><dt>IVA (<?= $e($qty($i['tax_rate'])) ?> %)</dt>
          <dd class="num"><?= $e(View::money($i['total'] - $subtotal, $currency)) ?></dd></div>
        <div class="totals__grand"><dt>Total</dt><dd class="num"><?= $e(View::money($i['total'], $currency)) ?></dd></div>
      </dl>
    </section>

    <section class="panel">
      <header class="panel__head">
        <h2>Pagos</h2>
        <span class="pair num"><span>Pagado <?= $e(View::money($i['paid_amount'], $currency)) ?></span><span>Saldo <?= $e(View::money($i['balance'], $currency)) ?></span></span>
      </header>
      <div class="meter" role="img" aria-label="Pagado <?= (int) round($i['total'] > 0 ? $i['paid_amount'] / $i['total'] * 100 : 0) ?> %">
        <span style="width: <?= $i['total'] > 0 ? min(100, round($i['paid_amount'] / $i['total'] * 100, 1)) : 0 ?>%"></span>
      </div>
      <?php if (!$payments): ?>
        <p class="panel__empty muted">Aún no se ha registrado ningún pago.</p>
      <?php else: ?>
        <table class="table">
          <thead><tr><th>Recibo <span class="sub-inline">(imprimir)</span></th><th>Fecha</th><th class="hide-sm">Forma de pago</th><th class="right">Importe</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($payments as $p): ?>
            <tr>
              <td class="num"><a class="link" href="<?= View::url('/pagos/' . $p['id'] . '/recibo') ?>" target="_blank" rel="noopener" title="Ver recibo para imprimir"><?= $e($p['receipt_number']) ?></a></td>
              <td class="num"><?= $e(View::date($p['date'])) ?></td>
              <td class="hide-sm"><?= $e($p['method'] ?: '—') ?><?php if ($p['note']): ?><span class="sub"><?= $e($p['note']) ?></span><?php endif; ?>
                <?php if ($p['created_by_name']): ?><span class="sub">Registrado por <?= $e($p['created_by_name']) ?></span><?php endif; ?></td>
              <td class="right num paid-text"><?= $e(View::money((float) $p['amount'], $currency)) ?></td>
              <td class="right nowrap">
                <?php if ($canDeletePayments): ?>
                  <form class="inline" method="post" action="<?= View::url('/pagos/' . $p['id'] . '/eliminar') ?>"
                        data-confirm="¿Eliminar el pago <?= $e($p['receipt_number']) ?> de <?= $e(View::money((float) $p['amount'], $currency)) ?>? El saldo de la factura aumentará en ese importe.">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="link-btn link-btn--danger" type="submit">Eliminar</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <?php if (!$i['paid']): ?>
        <form method="post" action="<?= View::url('/facturas/' . $i['id'] . '/pagos') ?>" class="payment-form" id="registrar-pago">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <h3>Registrar pago o abono</h3>
          <?php if ($paymentError): ?><div class="flash flash--error" role="alert"><?= $e($paymentError) ?></div><?php endif; ?>
          <div class="payment-form__grid">
            <label class="field">
              <span>Importe</span>
              <span class="input-prefix"><span><?= $e($currency) ?></span>
                <input name="amount" inputmode="decimal" required
                       value="<?= $e($pv['amount'] ?? number_format($i['balance'], 2, ',', '')) ?>">
              </span>
              <small>Saldo pendiente: <?= $e(View::money($i['balance'], $currency)) ?>. Si pones menos, queda como abono.</small>
            </label>
            <label class="field"><span>Fecha</span><input type="date" name="date" required value="<?= $e($pv['date'] ?? date('Y-m-d')) ?>"></label>
            <label class="field">
              <span>Forma de pago</span>
              <select name="method">
                <?php foreach ($methods as $m): ?>
                  <option <?= ($pv['method'] ?? 'Efectivo') === $m ? 'selected' : '' ?>><?= $e($m) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="field"><span>Nota (opcional)</span><input name="note" maxlength="255" value="<?= $e($pv['note'] ?? '') ?>" placeholder="Nº de transferencia, etc."></label>
          </div>
          <div class="form__actions"><button class="btn btn--paid" type="submit">Registrar pago</button></div>
        </form>
      <?php endif; ?>
    </section>
  </div>

  <aside class="stack">
    <section class="panel panel--pad">
      <h2>Cliente</h2>
      <p><strong><?= $e($i['client_name']) ?></strong></p>
      <?php foreach (['client_tax_id', 'client_address', 'client_email'] as $f): if ($i[$f] !== ''): ?>
        <p class="muted"><?= $e($i[$f]) ?></p>
      <?php endif; endforeach; ?>
    </section>
    <section class="panel panel--pad">
      <h2>Fechas</h2>
      <dl class="facts">
        <div><dt>Emisión</dt><dd class="num"><?= $e(View::date($i['issue_date'])) ?></dd></div>
        <div><dt>Vencimiento</dt><dd class="num<?= $i['overdue'] ? ' overdue-text' : '' ?>"><?= $e(View::date($i['due_date'])) ?></dd></div>
        <?php if ($i['paid']): ?><div><dt>Pagada el</dt><dd class="num"><?= $e(View::date($i['paid_date'])) ?></dd></div><?php endif; ?>
        <?php if ($creator): ?><div><dt>Creada por</dt><dd><?= $e($creator) ?></dd></div><?php endif; ?>
      </dl>
    </section>
    <?php if (trim((string) $i['notes']) !== ''): ?>
      <section class="panel panel--pad"><h2>Observaciones</h2><p><?= nl2br($e($i['notes'])) ?></p></section>
    <?php endif; ?>
    <?php if ($editable): ?>
      <form method="post" action="<?= View::url('/facturas/' . $i['id'] . '/eliminar') ?>"
            data-confirm="¿Eliminar la factura <?= $e($i['number']) ?>? Desaparecerá también de la app de todo el equipo.">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button class="btn btn--danger btn--block" type="submit">Eliminar factura</button>
      </form>
    <?php else: ?>
      <p class="muted small">Las facturas con pagos no se pueden editar ni eliminar<?= $canDeletePayments ? '; para corregirla, elimina antes sus pagos.' : '.' ?></p>
    <?php endif; ?>
  </aside>
</div>
