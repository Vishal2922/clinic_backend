<?php
/**
 * Application Entry Point: Fixed Version.
 * Bugs Fixed:
 * 1. CORS preflight check used $request->getMethod() === 'options' (lowercase),
 *    but getMethod() now returns UPPERCASE. Fixed to use 'OPTIONS'.
 * 2. Removed display_errors=1 for production safety (kept as comment).
 */

// 1. Error reporting — disable display_errors in production
ini_set('display_errors', 0); // FIX: was 1 (leaks stack traces in production)
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// 2. Define base path
define('BASE_PATH', dirname(__DIR__));

// 3. PSR-4 Autoloader
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

// 4. Global Helpers & Environment Setup
if (file_exists(BASE_PATH . '/app/Helpers/functions.php')) {
    require_once BASE_PATH . '/app/Helpers/functions.php';
}

if (class_exists('\App\Helpers\EnvLoader')) {
    \App\Helpers\EnvLoader::load(BASE_PATH . '/.env');
}

// Session start for CSRF
session_start();

// 5. Initialize Core Services
try {
    $dbConfig = file_exists(BASE_PATH . '/config/database.php') ? require BASE_PATH . '/config/database.php' : [];
    $database = \App\Core\Database::getInstance($dbConfig);

    $request  = new \App\Core\Request();
    $response = new \App\Core\Response();

    // 6. FIX #1: Handle CORS preflight — getMethod() returns UPPERCASE, so compare 'OPTIONS'
    if ($request->getMethod() === 'OPTIONS') {
        $response->setCorsHeaders();
        $response->setStatusCode(200);
        $response->send();
        exit;
    }

    // 7. Initialize Router
    $router = new \App\Core\Router($request, $response);

    // 8. Load Routes
    if (file_exists(BASE_PATH . '/routes/api.php')) {
        require_once BASE_PATH . '/routes/api.php';
    }

    // 9. Dispatch
    $router->resolve();

} catch (\Exception $e) {
    \App\Core\Response::json([
        "status"  => "error",
        "message" => "Application Bootstrap Failed",
        "details" => $e->getMessage()
    ], 500);
}