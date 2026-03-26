<?php
// =============================================================================
// public/api.php — Single API entry point
// =============================================================================

declare(strict_types=1);

// ── Output buffer: catches any stray PHP output before headers ────────────────
ob_start();

// ── Global exception/error handler: always return valid JSON ─────────────────
// Registered before anything else so even autoload failures return JSON.
set_exception_handler(function (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error.',
        'debug'   => APP_ENV === 'development' ? $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() : null,
    ]);
    exit;
});

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    // Convert fatal-level errors to exceptions so the exception handler catches them
    if ($errno & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) {
        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    }
    return false; // Let PHP handle non-fatal errors normally
});

require_once __DIR__ . '/app/config/config.php';

spl_autoload_register(function (string $class): void {
    $base = __DIR__ . '/app/src/';
    $file = $base . str_replace(['PBBG\\', '\\'], ['', '/'], $class) . '.php';
    if (file_exists($file)) require_once $file;
});

use PBBG\Middleware\Auth;
use PBBG\Utils\Response;
use PBBG\Controllers\AuthController;
use PBBG\Controllers\PetController;
use PBBG\Controllers\ShopController;
use PBBG\Controllers\AdventureController;

// Discard any buffered startup noise, send clean headers
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---------------------------------------------------------------------------
// Parse body
// ---------------------------------------------------------------------------
$body   = [];
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $body = $decoded ?? [];
        }
    }
    if (empty($body) && !empty($_POST)) {
        $body = $_POST;
    }
}

$params = $_GET;
$action = trim($params['action'] ?? '');

// ---------------------------------------------------------------------------
// Route table
// ---------------------------------------------------------------------------
$routes = [
    'auth.register'            => ['POST', fn() => AuthController::register($body),              false],
    'auth.login'               => ['POST', fn() => AuthController::login($body),                 false],
    'auth.logout'              => ['POST', fn() => AuthController::logout(),                     false],
    'auth.me'                  => ['GET',  fn() => AuthController::me(),                         true],

    'pet.startHatch'           => ['POST', fn() => PetController::startHatch($body),             true],
    'pet.claimPet'             => ['POST', fn() => PetController::claimPet($params),             true],
    'pet.feed'                 => ['POST', fn() => PetController::feed($params, $body),          true],
    'pet.play'                 => ['POST', fn() => PetController::play($params, $body),          true],
    'pet.getMyPet'             => ['GET',  fn() => PetController::getMyPet($params),             true],

    'adventure.getExpeditions' => ['GET',  fn() => AdventureController::getExpeditions(),        true],
    'adventure.getStatus'      => ['GET',  fn() => AdventureController::getStatus($params),      true],
    'adventure.start'          => ['POST', fn() => AdventureController::start($params, $body),   true],
    'adventure.collect'        => ['POST', fn() => AdventureController::collect($params),        true],

    'shop.getItems'            => ['GET',  fn() => ShopController::getItems(),                   true],
    'shop.buy'                 => ['POST', fn() => ShopController::buy($body),                   true],
    'shop.getInventory'        => ['GET',  fn() => ShopController::getInventory(),               true],
];

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------
if (!array_key_exists($action, $routes)) {
    Response::error("Unknown action: {$action}", 404);
}

[$expectedMethod, $handler, $needsAuth] = $routes[$action];

if ($method !== $expectedMethod) {
    Response::error("Method {$method} not allowed.", 405);
}

if ($needsAuth) {
    Auth::require();
}

$handler();
