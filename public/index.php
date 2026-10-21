<?php

declare(strict_types=1);

use App\ApiException;
use App\Controllers\AuthController;
use App\Controllers\BusinessController;
use App\Controllers\SyncController;
use App\Controllers\UserController;
use App\Portal\Portal;
use App\Request;
use App\Router;

// Servidor de desarrollo de PHP: los archivos (estilos del portal) se sirven tal cual.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__) . '/src/bootstrap.php';

// Portal web de administración.
[$routePath, $routeRoot] = Request::route();
if ($routePath === '/admin' || str_starts_with($routePath, '/admin/')) {
    try {
        Portal::handle(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), $routePath, $routeRoot);
    } catch (Throwable $e) {
        error_log('[facturas_portal] ' . $e);
        http_response_code(500);
        echo '<!doctype html><meta charset="utf-8"><title>Error</title><p>Error interno del portal. Revisa el log del servidor.</p>';
    }
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$router = new Router();
$router->add('GET', '/', [AuthController::class, 'status']);
$router->add('GET', '/status', [AuthController::class, 'status']);
$router->add('POST', '/auth/setup', [AuthController::class, 'setup']);
$router->add('POST', '/auth/login', [AuthController::class, 'login']);
$router->add('POST', '/auth/refresh', [AuthController::class, 'refresh']);
$router->add('GET', '/auth/me', [AuthController::class, 'me']);
$router->add('POST', '/auth/password', [AuthController::class, 'changePassword']);
$router->add('GET', '/auth/businesses', [AuthController::class, 'businesses']);
$router->add('POST', '/auth/switch', [AuthController::class, 'switchBusiness']);
$router->add('GET', '/users', [UserController::class, 'list']);
$router->add('POST', '/users', [UserController::class, 'create']);
$router->add('PATCH', '/users/{id}', [UserController::class, 'update']);
$router->add('GET', '/businesses', [BusinessController::class, 'list']);
$router->add('POST', '/businesses', [BusinessController::class, 'create']);
$router->add('PATCH', '/businesses/{id}', [BusinessController::class, 'update']);
$router->add('POST', '/businesses/{id}/members', [BusinessController::class, 'addMember']);
$router->add('POST', '/sync', [SyncController::class, 'sync']);

try {
    $response = $router->dispatch(Request::fromGlobals());
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
} catch (ApiException $e) {
    http_response_code($e->status);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    // El detalle va al log del servidor, nunca al cliente.
    error_log('[facturas_api] ' . $e);
    http_response_code(500);
    echo json_encode(['error' => 'Error interno del servidor.'], JSON_UNESCAPED_UNICODE);
}
