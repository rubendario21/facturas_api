<?php
use App\Portal\View;

$v = $issuerRow ?? [];
$val = fn(string $k, string $default = '') => $e($v[$k] ?? $default);
?>
<header class="page-head">
  <h1>Datos del emisor</h1>
  <p class="muted">Aparecen en todas las facturas y recibos. La app los recibe al sincronizar.</p>
</header>

<?php if ($error): ?><div class="flash flash--error" role="alert"><?= $e($error) ?></div><?php endif; ?>

<form method="post" action="<?= View::url('/emisor') ?>" enctype="multipart/form-data" class="form">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <div class="columns columns--form">
    <section class="panel panel--pad stack">
      <h2>Empresa</h2>
      <label class="field"><span>Nombre o razón social</span><input name="name" value="<?= $val('name') ?>" required maxlength="150"></label>
      <label class="field"><span>Identificación fiscal (NIT, NIF, RFC)</span><input name="tax_id" value="<?= $val('tax_id') ?>" required maxlength="60"></label>
      <label class="field"><span>Dirección</span><input name="address" value="<?= $val('address') ?>" maxlength="255"></label>
      <label class="field"><span>Ciudad</span><input name="city" value="<?= $val('city') ?>" maxlength="120"></label>
      <div class="grid-2">
        <label class="field"><span>Teléfono</span><input name="phone" value="<?= $val('phone') ?>" maxlength="60"></label>
        <label class="field"><span>Email</span><input name="email" type="email" value="<?= $val('email') ?>" maxlength="120"></label>
      </div>
      <label class="field"><span>Cuenta bancaria para transferencias</span><input name="bank_account" value="<?= $val('bank_account') ?>" maxlength="120"></label>
    </section>

    <div class="stack">
      <section class="panel panel--pad stack">
        <h2>Logo</h2>
        <div class="logo-box">
          <?php if (!empty($v['logo'])): ?>
            <img src="data:<?= str_starts_with($v['logo'], "\xFF\xD8") ? 'image/jpeg' : 'image/png' ?>;base64,<?= base64_encode($v['logo']) ?>" alt="Logo actual">
          <?php else: ?>
            <span class="muted">Sin logo</span>
          <?php endif; ?>
        </div>
        <label class="field"><span>Subir logo (PNG o JPG, máximo 1 MB)</span><input type="file" name="logo" accept="image/png,image/jpeg"></label>
        <?php if (!empty($v['logo'])): ?>
          <label class="check"><input type="checkbox" name="remove_logo" value="1"><span>Quitar el logo actual</span></label>
        <?php endif; ?>
      </section>

      <section class="panel panel--pad stack">
        <h2>Facturación</h2>
        <div class="grid-2">
          <label class="field"><span>Prefijo</span><input name="invoice_prefix" value="<?= $val('invoice_prefix', 'F-') ?>" maxlength="20"></label>
          <label class="field"><span>Próximo número</span><input name="next_invoice_number" type="number" min="1" value="<?= $val('next_invoice_number', '1') ?>"></label>
        </div>
        <div class="grid-2">
          <label class="field"><span>IVA por defecto (%)</span><input name="default_tax_rate" inputmode="decimal" value="<?= $e(rtrim(rtrim(number_format((float) ($v['default_tax_rate'] ?? 19), 2, ',', ''), '0'), ',')) ?>"></label>
          <label class="field"><span>Símbolo de moneda</span><input name="currency" value="<?= $val('currency', '$') ?>" maxlength="10" required></label>
        </div>
        <small class="muted">Si el próximo número ya está usado, el servidor salta al siguiente libre.</small>
      </section>
    </div>
  </div>
  <div class="form__actions form__actions--sticky">
    <button class="btn btn--primary" type="submit">Guardar datos del emisor</button>
  </div>
</form>
