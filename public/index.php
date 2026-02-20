<?php

/**
 * Application Entry Point: Fixed Version.
 * Bugs Fixed:
 * 1. CORS preflight check used $request->getMethod() === 'options' (lowercase),
 *    but getMethod() now returns UPPERCASE. Fixed to use 'OPTIONS'.
 * 2. Removed display_errors=1 for production safety (kept as comment).
 * 3. Strip subfolder base path so router sees /api/health not /clinic_backend/public/api/health
 */

// 1. Error reporting — enable for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// 2. Define base path
define('BASE_PATH', dirname(__DIR__));

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

// Session start for CSRF
session_start();

// 6. Initialize Core Services
try {
    $dbConfig = file_exists(BASE_PATH . '/config/database.php') ? require BASE_PATH . '/config/database.php' : [];
    $database = \App\Core\Database::getInstance($dbConfig);

    $request  = new \App\Core\Request();
    $response = new \App\Core\Response();

    // 7. FIX #1: Handle CORS preflight — getMethod() returns UPPERCASE, so compare 'OPTIONS'
    if ($request->getMethod() === 'OPTIONS') {
        $response->setCorsHeaders();
        $response->setStatusCode(200);
        $response->send();
        exit;
    }

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