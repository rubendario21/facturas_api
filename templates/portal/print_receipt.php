<?php
use App\Portal\View;

$p = $payment;
$i = $invoice;
$is = $issuerRow ?? [];
$amount = (float) $p['amount'];
$totalCents = (int) round($i['total'] * 100);
$afterCents = (int) round($previous * 100) + (int) round($amount * 100);
$balanceAfter = max(0, $totalCents - $afterCents) / 100;
$settles = $afterCents >= $totalCents;
$concept = !$settles ? "Abono a la factura Nº {$i['number']}"
    : ($previous == 0 ? "Pago total de la factura Nº {$i['number']}" : "Pago del saldo final de la factura Nº {$i['number']}");
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
      <h1>RECIBO DE PAGO</h1>
      <strong class="num">Nº <?= $e($p['receipt_number']) ?></strong>
      <span class="num">Fecha: <?= $e(View::date($p['date'])) ?></span>
    </div>
  </header>

  <section class="received">
    <span>Valor recibido</span>
    <strong class="num"><?= $e(View::money($amount, $currency)) ?></strong>
  </section>

  <section class="facts-print">
    <div><span class="label">Recibimos de</span><strong><?= $e($i['client_name']) ?></strong><?php if ($i['client_tax_id'] !== ''): ?><span><?= $e($i['client_tax_id']) ?></span><?php endif; ?></div>
    <div><span class="label">Por concepto de</span><span><?= $e($concept) ?></span></div>
    <div><span class="label">Forma de pago</span><span><?= $e($p['method'] ?: '—') ?></span></div>
    <?php if (trim((string) $p['note']) !== ''): ?><div><span class="label">Referencia / nota</span><span><?= $e($p['note']) ?></span></div><?php endif; ?>
  </section>

  <dl class="sum">
    <div><dt>Valor total de la factura</dt><dd class="num"><?= $e(View::money($i['total'], $currency)) ?></dd></div>
    <div><dt>Pagos anteriores</dt><dd class="num"><?= $e(View::money($previous, $currency)) ?></dd></div>
    <div><dt>Este pago</dt><dd class="num"><?= $e(View::money($amount, $currency)) ?></dd></div>
    <div class="sum__total"><dt>Saldo pendiente</dt><dd class="num"><?= $e(View::money($balanceAfter, $currency)) ?></dd></div>
  </dl>
  <?php if ($settles): ?><p class="stamp-wrap"><span class="stamp">FACTURA PAGADA</span></p><?php endif; ?>

  <footer class="signature">
    <span class="signature__line"></span>
    <span>Recibido por</span>
    <strong><?= $e($is['name'] ?? '') ?></strong>
  </footer>
</article>
