<?php
use App\Portal\View;

$i = $invoice;
$qty = fn($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
$voided = $i['voided'];
$editable = !$voided && !$i['paid'] && $i['payment_count'] === 0;
$pv = $paymentValues ?? [];
$dateTime = fn(?string $dt) => $dt ? View::date(substr($dt, 0, 10)) . ' a las ' . substr($dt, 11, 5) : '';
?>
<a class="back" href="<?= View::url('/facturas') ?>"><?= View::icon('volver') ?>Facturas</a>

<header class="page-head page-head--split">
  <div>
    <h1 class="num<?= $voided ? ' is-voided' : '' ?>"><?= $e($i['number']) ?></h1>
    <p class="muted"><?= $e($i['client_name']) ?></p>
  </div>
  <div class="page-head__side">
    <?= View::status($i['status']) ?>
    <span class="figure num<?= $voided ? ' is-voided' : '' ?>"><?= $e(View::money($i['total'], $currency)) ?></span>
  </div>
</header>

<?php if ($voided): ?>
  <div class="void-notice" role="note">
    <strong>Factura anulada</strong>
    <p>Motivo: <?= $e($i['void_reason']) ?></p>
    <p class="muted small">Anulada <?= $voider ? 'por ' . $e($voider) . ' ' : '' ?>el <?= $e($dateTime($i['voided_at'])) ?>. Sus pagos también quedaron anulados. Se conserva en el historial y su número no se reutiliza.</p>
  </div>
<?php endif; ?>

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
        <?php if (!$voided): ?>
          <span class="pair num"><span>Pagado <?= $e(View::money($i['paid_amount'], $currency)) ?></span><span>Saldo <?= $e(View::money($i['balance'], $currency)) ?></span></span>
        <?php endif; ?>
      </header>
      <?php if (!$voided): ?>
        <div class="meter" role="img" aria-label="Pagado <?= (int) round($i['total'] > 0 ? $i['paid_amount'] / $i['total'] * 100 : 0) ?> %">
          <span style="width: <?= $i['total'] > 0 ? min(100, round($i['paid_amount'] / $i['total'] * 100, 1)) : 0 ?>%"></span>
        </div>
      <?php endif; ?>
      <?php if (!$payments): ?>
        <p class="panel__empty muted">Aún no se ha registrado ningún pago.</p>
      <?php else: ?>
        <table class="table">
          <thead><tr><th>Recibo <span class="sub-inline">(imprimir)</span></th><th>Fecha</th><th class="hide-sm">Forma de pago</th><th class="right">Importe</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($payments as $p): $pVoided = $p['voided_at'] !== null; ?>
            <tr class="<?= $pVoided ? 'is-voided-row' : '' ?>">
              <td class="num"><a class="link" href="<?= View::url('/pagos/' . $p['id'] . '/recibo') ?>" target="_blank" rel="noopener" title="Ver recibo para imprimir"><?= $e($p['receipt_number']) ?></a>
                <?php if ($pVoided): ?><span class="badge badge--voided">Anulado</span><?php endif; ?></td>
              <td class="num"><?= $e(View::date($p['date'])) ?></td>
              <td class="hide-sm"><?= $e($p['method'] ?: '—') ?><?php if ($p['note']): ?><span class="sub"><?= $e($p['note']) ?></span><?php endif; ?>
                <?php if ($p['created_by_name']): ?><span class="sub">Registrado por <?= $e($p['created_by_name']) ?></span><?php endif; ?></td>
              <td class="right num <?= $pVoided ? 'strike' : 'paid-text' ?>"><?= $e(View::money((float) $p['amount'], $currency)) ?></td>
              <td class="right nowrap">
                <?php if ($canVoid && !$pVoided && !$voided): ?>
                  <details class="void-box">
                    <summary class="link-btn link-btn--danger">Anular</summary>
                    <form method="post" action="<?= View::url('/pagos/' . $p['id'] . '/anular') ?>" class="void-form"
                          data-confirm="¿Anular el pago <?= $e($p['receipt_number']) ?> de <?= $e(View::money((float) $p['amount'], $currency)) ?>? El saldo de la factura aumentará en ese importe.">
                      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                      <input type="hidden" name="invoice_id" value="<?= $e($i['id']) ?>">
                      <label class="field"><span>Motivo de la anulación</span>
                        <input name="reason" required minlength="3" maxlength="255" placeholder="Ej.: transferencia devuelta"></label>
                      <button class="btn btn--danger" type="submit">Anular pago</button>
                    </form>
                  </details>
                <?php endif; ?>
              </td>
            </tr>
            <?php if ($pVoided): ?>
              <tr class="void-reason-row">
                <td colspan="5"><span class="sub">Anulado<?= $p['voided_by_name'] ? ' por ' . $e($p['voided_by_name']) : '' ?> el <?= $e($dateTime($p['voided_at'])) ?>. Motivo: <?= $e($p['void_reason']) ?></span></td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <?php if (!$i['paid'] && !$voided): ?>
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
        <?php if ($voided): ?><div><dt>Anulada el</dt><dd class="num"><?= $e(View::date(substr((string) $i['voided_at'], 0, 10))) ?></dd></div><?php endif; ?>
        <?php if ($creator): ?><div><dt>Creada por</dt><dd><?= $e($creator) ?></dd></div><?php endif; ?>
      </dl>
    </section>
    <?php if (trim((string) $i['notes']) !== ''): ?>
      <section class="panel panel--pad"><h2>Observaciones</h2><p><?= nl2br($e($i['notes'])) ?></p></section>
    <?php endif; ?>
    <?php if ($canVoid && !$voided): ?>
      <details class="panel panel--pad void-box void-box--panel">
        <summary class="btn btn--danger btn--block">Anular factura</summary>
        <form method="post" action="<?= View::url('/facturas/' . $i['id'] . '/anular') ?>" class="void-form"
              data-confirm="¿Anular la factura <?= $e($i['number']) ?>? También se anularán todos sus pagos. No se puede deshacer.">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <p class="small muted">Se anulan también todos sus pagos. La factura queda en el historial con el motivo y su número no se reutiliza.</p>
          <label class="field"><span>Motivo de la anulación</span>
            <textarea name="reason" rows="3" required minlength="3" maxlength="255" placeholder="Ej.: el cliente canceló el servicio"></textarea></label>
          <button class="btn btn--danger btn--block" type="submit">Confirmar anulación</button>
        </form>
      </details>
    <?php elseif (!$voided && !$editable): ?>
      <p class="muted small">Las facturas con pagos no se pueden editar.<?= $canVoid ? ' Si hay un error, anula el pago o la factura indicando el motivo.' : '' ?></p>
    <?php endif; ?>
  </aside>
</div>
