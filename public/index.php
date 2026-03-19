<?php

/**
 * Application Entry Point: Fixed Version.
 * Bugs Fixed:
 * 1. CORS preflight check used $request->getMethod() === 'options' (lowercase),
 *    but getMethod() now returns UPPERCASE. Fixed to use 'OPTIONS'.
 * 2. Removed display_errors=1 for production safety (kept as comment).
 * 3. Strip subfolder base path so router sees /api/health not /clinic_backend/public/api/health
 */

// 1. Error reporting — log them, but don't display (prevents JSON corruption)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (file_exists(dirname(__DIR__) . '/app/Helpers/functions.php')) {
    require_once dirname(__DIR__) . '/app/Helpers/functions.php';
    if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__));
    app_log("[TOP] Incoming: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI']);
}

// 2. Define base path
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

// 3. FIX #3: Strip subfolder prefix from REQUEST_URI
// Router expects /api/health but WAMP serves from /clinic_backend/public/api/health
$basePath = '/clinic_backend/public';
if (strpos($_SERVER['REQUEST_URI'], $basePath) === 0) {
    $_SERVER['REQUEST_URI'] = substr($_SERVER['REQUEST_URI'], strlen($basePath));
}
// Ensure it always starts with /
if (empty($_SERVER['REQUEST_URI'])) {
    $_SERVER['REQUEST_URI'] = '/';
}

// 4. PSR-4 Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = BASE_PATH . '/app/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// 5. Global Helpers & Environment Setup
if (file_exists(BASE_PATH . '/app/Helpers/functions.php')) {
    require_once BASE_PATH . '/app/Helpers/functions.php';
}

if (class_exists('\App\Helpers\EnvLoader')) {
    \App\Helpers\EnvLoader::load(BASE_PATH . '/.env');
}

// 6. FIX: Handle CORS preflight BEFORE session_start() and DB init.
// OPTIONS preflight must never fail due to DB/session issues —
// browsers require a 200 with correct CORS headers or they block ALL subsequent requests,
// causing the frontend to see "network error" with error.response = undefined.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token, X-Tenant-ID");
    header("Access-Control-Max-Age: 86400");
    http_response_code(200);
    exit;
}

// Session start for CSRF
session_start();

// 7. Initialize Core Services
try {
    app_log("[bootstrap] Incoming: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI']);
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $val) {
            app_log("[bootstrap] Header: $name = $val");
        }
    }

    $dbConfig = file_exists(BASE_PATH . '/config/database.php') ? require BASE_PATH . '/config/database.php' : [];
    $database = \App\Core\Database::getInstance($dbConfig);

    $request  = new \App\Core\Request();
    $response = new \App\Core\Response();

    // 8. Initialize Router
    $router = new \App\Core\Router($request, $response);

    // 9. Load Routes
    if (file_exists(BASE_PATH . '/routes/api.php')) {
        require_once BASE_PATH . '/routes/api.php';
    }

    // 10. Dispatch
    $router->resolve();
} catch (\Exception $e) {
    \App\Core\Response::json([
        "status"  => "error",
        "message" => "Application Bootstrap Failed",
        "details" => $e->getMessage()
    ], 500);
}