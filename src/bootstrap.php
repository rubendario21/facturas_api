<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// FACTURAS_CONFIG permite usar otra configuración (p. ej. una base de datos de pruebas).
$configFile = getenv('FACTURAS_CONFIG') ?: dirname(__DIR__) . '/config/config.php';
if (!is_file($configFile)) {
    // En la consola (migraciones, pruebas) se indica el archivo exacto que falta.
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "No se encuentra el archivo de configuración: $configFile\n"
            . "Créalo (ver README) o revisa la variable FACTURAS_CONFIG.\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Falta config/config.php (copia config.example.php).']);
    exit;
}
App\Config::load(require $configFile);
