<?php
use App\Portal\View;

$v = $values;
$isEdit = $invoiceRow !== null;
$action = $isEdit ? View::url('/facturas/' . $invoiceRow['id'] . '/editar') : View::url('/facturas/nueva');
$plain = fn($n) => $n === '' || $n === null ? '' : rtrim(rtrim(number_format((float) str_replace(',', '.', (string) $n), 2, ',', ''), '0'), ',');
?>
<a class="back" href="<?= $isEdit ? View::url('/facturas/' . $invoiceRow['id']) : View::url('/facturas') ?>"><?= View::icon('volver') ?><?= $isEdit ? $e($invoiceRow['number']) : 'Facturas' ?></a>
<header class="page-head">
  <h1><?= $isEdit ? 'Editar ' . $e($invoiceRow['number']) : 'Nueva factura' ?></h1>
  <p class="muted"><?= $isEdit ? 'Se puede editar mientras no tenga pagos.' : 'El número se asigna al guardar, sin repetirse con los creados en la app.' ?></p>
</header>

<?php if ($error): ?><div class="flash flash--error" role="alert"><?= $e($error) ?></div><?php endif; ?>

<form method="post" action="<?= $action ?>" class="form" data-invoice-form data-currency="<?= $e($currency) ?>">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <div class="columns columns--form">
    <section class="panel panel--pad stack">
      <h2>Cliente</h2>
      <label class="field"><span>Nombre o razón social</span><input name="client_name" value="<?= $e($v['client_name'] ?? '') ?>" required maxlength="150" autofocus></label>
      <div class="grid-2">
        <label class="field"><span>Identificación fiscal</span><input name="client_tax_id" value="<?= $e($v['client_tax_id'] ?? '') ?>" maxlength="60"></label>
        <label class="field"><span>Email</span><input name="client_email" type="email" value="<?= $e($v['client_email'] ?? '') ?>" maxlength="120"></label>
      </div>
      <label class="field"><span>Dirección</span><input name="client_address" value="<?= $e($v['client_address'] ?? '') ?>" maxlength="255"></label>
    </section>
    <section class="panel panel--pad stack">
      <h2>Fechas e impuesto</h2>
      <div class="grid-2">
        <label class="field"><span>Emisión</span><input type="date" name="issue_date" value="<?= $e(substr((string) ($v['issue_date'] ?? ''), 0, 10)) ?>" required></label>
        <label class="field"><span>Vencimiento</span><input type="date" name="due_date" value="<?= $e(substr((string) ($v['due_date'] ?? ''), 0, 10)) ?>"></label>
      </div>
      <label class="field"><span>IVA o impuesto (%)</span><input name="tax_rate" inputmode="decimal" value="<?= $e($plain($v['tax_rate'] ?? '')) ?>" required data-tax></label>
      <label class="field"><span>Observaciones</span><textarea name="notes" rows="3" maxlength="5000"><?= $e($v['notes'] ?? '') ?></textarea></label>
    </section>
  </div>

  <section class="panel">
    <header class="panel__head"><h2>Conceptos</h2></header>
    <table class="table items-table">
      <thead><tr><th>Descripción</th><th class="right">Cantidad</th><th class="right">Precio unitario</th><th class="right">Importe</th><th></th></tr></thead>
      <tbody data-items>
      <?php foreach ($v['items_list'] as $n => $it): ?>
        <tr data-item>
          <td><input name="items[<?= $n ?>][description]" value="<?= $e($it['description'] ?? '') ?>" aria-label="Descripción" maxlength="500"></td>
          <td class="right"><input class="num right" name="items[<?= $n ?>][quantity]" value="<?= $e($plain($it['quantity'] ?? 1)) ?>" inputmode="decimal" aria-label="Cantidad" data-qty></td>
          <td class="right"><input class="num right" name="items[<?= $n ?>][unit_price]" value="<?= $e($plain($it['unit_price'] ?? '')) ?>" inputmode="decimal" aria-label="Precio unitario" data-price></td>
          <td class="right num" data-line>—</td>
          <td class="right"><button type="button" class="link-btn link-btn--danger" data-remove aria-label="Quitar concepto">Quitar</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="items-footer">
      <button type="button" class="btn" data-add><?= View::icon('mas') ?>Añadir concepto</button>
      <dl class="totals totals--inline">
        <div><dt>Base imponible</dt><dd class="num" data-subtotal>—</dd></div>
        <div><dt>Impuesto</dt><dd class="num" data-taxamount>—</dd></div>
        <div class="totals__grand"><dt>Total</dt><dd class="num" data-total>—</dd></div>
      </dl>
    </div>
  </section>

  <div class="form__actions form__actions--sticky">
    <a class="btn" href="<?= $isEdit ? View::url('/facturas/' . $invoiceRow['id']) : View::url('/facturas') ?>">Cancelar</a>
    <button class="btn btn--primary" type="submit"><?= $isEdit ? 'Guardar cambios' : 'Crear factura' ?></button>
  </div>
</form>
