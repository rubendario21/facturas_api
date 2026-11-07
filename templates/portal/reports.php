<?php use App\Portal\View; ?>
<header class="page-head">
  <h1>Informes</h1>
  <p class="muted">Calculados con la información ya sincronizada. Las facturas y los pagos anulados solo cuentan en el informe de anulaciones.</p>
</header>

<section class="report-list">
  <?php foreach ($reports as $r): ?>
    <a class="report-card" href="<?= View::url('/informes/' . $r['key']) ?>">
      <span class="report-card__text">
        <strong><?= $e($r['title']) ?></strong>
        <span class="muted small"><?= $e($r['description']) ?></span>
      </span>
      <?= View::icon('seguir') ?>
    </a>
  <?php endforeach; ?>
</section>
