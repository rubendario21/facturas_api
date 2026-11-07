<?php
use App\Portal\View;

$cur = $report['currency'];
$presets = [
    'Este mes' => [date('Y-m-01'), date('Y-m-t')],
    'Mes pasado' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
    'Este año' => [date('Y-01-01'), date('Y-12-31')],
];
?>
<a class="back" href="<?= View::url('/informes') ?>"><?= View::icon('volver') ?>Informes</a>
<header class="page-head">
  <h1><?= $e($report['title']) ?></h1>
  <p class="muted"><?= $e($report['description']) ?></p>
</header>

<?php if ($error): ?><?= View::alert($error) ?><?php endif; ?>

<?php if ($report['uses_period']): ?>
  <div class="toolbar">
    <nav class="tabs" aria-label="Periodos frecuentes">
      <?php foreach ($presets as $label => [$from, $to]):
          $active = $report['from'] === $from && $report['to'] === $to; ?>
        <a class="tabs__tab<?= $active ? ' is-active' : '' ?>" <?= $active ? 'aria-current="true"' : '' ?>
           href="<?= View::url('/informes/' . $report['key']) ?>?<?= $e(http_build_query(['desde' => $from, 'hasta' => $to])) ?>"><?= $e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <form class="period" method="get" action="<?= View::url('/informes/' . $report['key']) ?>">
      <label><span>Desde</span><input type="date" name="desde" value="<?= $e($report['from']) ?>" required></label>
      <label><span>Hasta</span><input type="date" name="hasta" value="<?= $e($report['to']) ?>" required></label>
      <button class="btn" type="submit">Aplicar</button>
    </form>
  </div>
<?php endif; ?>

<section class="report-stats" aria-label="Cifras principales">
  <?php foreach ($report['summary'] as $s): ?>
    <div class="report-stat<?= $s['tone'] ? ' is-' . $e($s['tone']) : '' ?>">
      <span class="report-stat__label"><?= $e($s['label']) ?></span>
      <strong class="report-stat__value num"><?= $e(View::reportValue($s['value'], $s['type'], $cur)) ?></strong>
    </div>
  <?php endforeach; ?>
</section>

<?php if (!$report['rows']): ?>
  <section class="empty"><h2><?= $e($report['empty']) ?></h2></section>
<?php else: ?>
  <section class="panel">
    <table class="table table--cards">
      <thead>
        <tr>
          <?php foreach ($report['columns'] as $c): ?>
            <th<?= $c['type'] === 'text' || $c['type'] === 'date' ? '' : ' class="right"' ?>><?= $e($c['label']) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($report['rows'] as $row): ?>
          <tr>
            <?php foreach ($report['columns'] as $n => $c): ?>
              <td data-label="<?= $e($c['label']) ?>" class="<?= $c['type'] === 'text' ? '' : 'num' ?><?= $c['type'] === 'text' || $c['type'] === 'date' ? '' : ' right' ?>"><?= $e(View::reportValue($row[$n], $c['type'], $cur)) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php endif; ?>
<?php if ($report['note']): ?><p class="muted small report-note"><?= $e($report['note']) ?></p><?php endif; ?>
