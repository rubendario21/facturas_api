<?php use App\Portal\View; ?>
<section class="empty">
  <h1><?= $e($title) ?></h1>
  <p class="muted"><?= $e($message) ?></p>
  <a class="btn" href="<?= View::url('/resumen') ?>">Ir al resumen</a>
</section>
