<?php use App\Portal\View; ?>
<!doctype html>
<html lang="es" data-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($title) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">
  <link rel="stylesheet" href="<?= View::asset('print.css') ?>">
</head>
<body>
  <div class="toolbar no-print">
    <button type="button" onclick="window.print()">Imprimir o guardar como PDF</button>
    <span>En el diálogo de impresión elige «Guardar como PDF» para obtener el archivo.</span>
  </div>
  <?= $content ?>
</body>
</html>
