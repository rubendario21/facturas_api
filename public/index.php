<?php

declare(strict_types=1);

use App\ApiException;
use App\Controllers\AuthController;
use App\Controllers\SyncController;
use App\Controllers\UserController;
use App\Request;
use App\Router;

require dirname(__DIR__) . '/src/bootstrap.php';

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
$router->add('GET', '/users', [UserController::class, 'list']);
$router->add('POST', '/users', [UserController::class, 'create']);
$router->add('PATCH', '/users/{id}', [UserController::class, 'update']);
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
