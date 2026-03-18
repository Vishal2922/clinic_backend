<?php

/**
 * Application Entry Point: Fixed Version.
 */

// 1. Error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// ─────────────────────────────────────────────────────────────
// 2. FIX: Send CORS headers on EVERY request, as early as possible.
//    This must happen before ANY logic that could fail/exit,
//    otherwise the browser never sees the headers and blocks the response.
// ─────────────────────────────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token, X-Tenant-ID");
header("Access-Control-Max-Age: 86400");

// Handle OPTIONS preflight immediately after sending headers
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ─────────────────────────────────────────────────────────────
// 3. FIX: Define BASE_PATH only once.
//    Previously defined inside an if-block AND again outside it — 
//    the second define() caused a fatal error which suppressed all output
//    including the CORS headers above (since headers weren't sent yet).
// ─────────────────────────────────────────────────────────────
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

// 4. Load helpers early (needed for app_log)
if (file_exists(BASE_PATH . '/app/Helpers/functions.php')) {
    require_once BASE_PATH . '/app/Helpers/functions.php';
}

if (function_exists('app_log')) {
    app_log("[TOP] Incoming: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI']);
}

// 5. Strip subfolder prefix from REQUEST_URI
//    Router expects /api/health but WAMP serves /clinic_backend/public/api/health
$basePath = '/clinic_backend/public';
if (strpos($_SERVER['REQUEST_URI'], $basePath) === 0) {
    $_SERVER['REQUEST_URI'] = substr($_SERVER['REQUEST_URI'], strlen($basePath));
}
if (empty($_SERVER['REQUEST_URI'])) {
    $_SERVER['REQUEST_URI'] = '/';
}

// 6. PSR-4 Autoloader
spl_autoload_register(function ($class) {
    $prefix  = 'App\\';
    $baseDir = BASE_PATH . '/app/';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// 7. Load Environment
if (class_exists('\App\Helpers\EnvLoader')) {
    \App\Helpers\EnvLoader::load(BASE_PATH . '/.env');
}

// 8. Session start for CSRF
session_start();

// 9. Bootstrap Core Services
//    FIX: catch block now always responds with JSON — CORS headers were already
//    sent above, so the browser can actually read this error response.
try {
    if (function_exists('app_log')) {
        app_log("[bootstrap] Incoming: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI']);
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $val) {
                app_log("[bootstrap] Header: $name = $val");
            }
        }
    }

    // NOTE: Database::getInstance() removed — multi-tenant architecture uses
    // MasterDatabase and TenantDatabase (resolved per-request by middleware).
    $request  = new \App\Core\Request();
    $response = new \App\Core\Response();
    $router   = new \App\Core\Router($request, $response);

    if (file_exists(BASE_PATH . '/routes/api.php')) {
        require_once BASE_PATH . '/routes/api.php';
    }

    $router->resolve();

} catch (\Exception $e) {
    // CORS headers are already sent — browser can read this 500 response
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        "status"  => "error",
        "message" => "Application Bootstrap Failed",
        "details" => $e->getMessage()
    ]);
}