<?php
use App\Portal\View;

$i = $invoice;
$is = $issuerRow ?? [];
$qty = fn($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
$subtotal = array_sum(array_map(fn($it) => $it['quantity'] * $it['unit_price'], $items));
$logoMime = !empty($is['logo']) && str_starts_with($is['logo'], "\xFF\xD8") ? 'image/jpeg' : 'image/png';
?>
<article class="sheet">
  <header class="sheet__head">
    <div class="issuer">
      <?php if (!empty($is['logo'])): ?>
        <img class="issuer__logo" src="data:<?= $logoMime ?>;base64,<?= base64_encode($is['logo']) ?>" alt="">
      <?php endif; ?>
      <strong class="issuer__name"><?= $e($is['name'] ?? '') ?></strong>
      <?php foreach (['tax_id', 'address', 'city', 'phone', 'email'] as $f): if (!empty($is[$f])): ?>
        <span><?= $e($is[$f]) ?></span>
      <?php endif; endforeach; ?>
    </div>
    <div class="doc">
      <h1>FACTURA</h1>
      <strong class="num">Nº <?= $e($i['number']) ?></strong>
      <span class="num">Fecha: <?= $e(View::date($i['issue_date'])) ?></span>
      <?php if ($i['due_date']): ?><span class="num">Vencimiento: <?= $e(View::date($i['due_date'])) ?></span><?php endif; ?>
      <?php if ($i['paid']): ?><span class="stamp">PAGADA <?= $e(View::date($i['paid_date'])) ?></span><?php endif; ?>
    </div>
  </header>

  <section class="client">
    <span class="label">Facturar a</span>
    <strong><?= $e($i['client_name']) ?></strong>
    <?php foreach (['client_tax_id', 'client_address', 'client_email'] as $f): if ($i[$f] !== ''): ?>
      <span><?= $e($i[$f]) ?></span>
    <?php endif; endforeach; ?>
  </section>

  <table class="lines">
    <thead><tr><th>Descripción</th><th class="r">Cant.</th><th class="r">Precio</th><th class="r">Importe</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
      <tr>
        <td><?= $e($it['description']) ?></td>
        <td class="r num"><?= $e($qty($it['quantity'])) ?></td>
        <td class="r num"><?= $e(View::money($it['unit_price'], $currency)) ?></td>
        <td class="r num"><?= $e(View::money($it['quantity'] * $it['unit_price'], $currency)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <dl class="sum">
    <div><dt>Base imponible</dt><dd class="num"><?= $e(View::money($subtotal, $currency)) ?></dd></div>
    <div><dt>IVA (<?= $e($qty($i['tax_rate'])) ?> %)</dt><dd class="num"><?= $e(View::money($i['total'] - $subtotal, $currency)) ?></dd></div>
    <div class="sum__total"><dt>TOTAL</dt><dd class="num"><?= $e(View::money($i['total'], $currency)) ?></dd></div>
    <?php if ($i['payment_count'] > 0): ?>
      <div><dt>Pagado</dt><dd class="num"><?= $e(View::money($i['paid_amount'], $currency)) ?></dd></div>
      <div class="sum__total"><dt>Saldo pendiente</dt><dd class="num"><?= $e(View::money($i['balance'], $currency)) ?></dd></div>
    <?php endif; ?>
  </dl>

  <?php if (!empty($is['bank_account'])): ?>
    <section class="note"><strong>Forma de pago</strong><span>Transferencia bancaria a: <?= $e($is['bank_account']) ?></span></section>
  <?php endif; ?>
  <?php if (trim((string) $i['notes']) !== ''): ?>
    <section class="note"><strong>Observaciones</strong><span><?= nl2br($e($i['notes'])) ?></span></section>
  <?php endif; ?>
</article>
