<?php
/**
 * Application Entry Point: All requests are routed through this file.
 * Conflict resolved between simplified bootstrap and professional engine.
 */

// 1. Error reporting (Development-la matum use pannunga)
ini_set('display_errors', 1); 
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// 2. Define base path
define('BASE_PATH', dirname(__DIR__));

// 3. Merged Autoloader (PSR-4 Style)
// Rendu side logic-ayum standard PSR-4 format-la merge panniyachu.
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

// Security matum CSRF logic-kaaga session start pannurohm
session_start();

[Image of PHP application bootstrap process flow]

// 5. Initialize Core Services
try {
    // Database Singleton Instance (Using merged Database class)
    $dbConfig = file_exists(BASE_PATH . '/config/database.php') ? require BASE_PATH . '/config/database.php' : [];
    $database = \App\Core\Database::getInstance($dbConfig);
    
    $request = new \App\Core\Request();
    $response = new \App\Core\Response();

    // 6. Handle CORS & Preflight (Frontend integration-ku idhu mukkiam)
    $response->setCorsHeaders();
    if ($request->getMethod() === 'options') {
        $response->setStatusCode(200);
        $response->send();
        exit;
    }

    // 7. Initialize & Load Router
    // Teammate logic padi Request matum Response objects-ah inject panroam
    $router = new \App\Core\Router($request, $response);

    // 8. Load Routes (api.php-la irukkura $router-ah use pannum)
    if (file_exists(BASE_PATH . '/routes/api.php')) {
        require_once BASE_PATH . '/routes/api.php';
    }

    // 9. Dispatch (Match routes and execute controllers)
    // resolve() or dispatch() - router merged version-ah poruthu call aagum
    if (method_exists($router, 'dispatch')) {
        $router->dispatch();
    } else {
        $router->resolve();
    }

} catch (\Exception $e) {
    // Global Error Handling: Standardized JSON output
    \App\Core\Response::json([
        "status" => "error",
        "message" => "Application Bootstrap Failed",
        "details" => $e->getMessage()
    ], 500);
}