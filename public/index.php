<?php
/**
 * Application Entry Point: All requests are routed through this file.
 * Conflict resolved between simplified bootstrap and professional engine.
 */

// 1. Error reporting (Development-la matum use pannunga)
ini_set('display_errors', 1); // Debugging-kaaga 1-la vachurukaen
ini_set('log_errors', 1);
error_reporting(E_ALL);

// 2. Define base path
define('BASE_PATH', dirname(__DIR__));

// 3. Merged Autoloader (PSR-4 Style)
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

// 4. Environment & Session Setup (Teammate's requirement)
// CSRF matum Encryption keys work aaga idhu kandippa venum.
if (file_exists(BASE_PATH . '/app/Helpers/functions.php')) {
    require_once BASE_PATH . '/app/Helpers/functions.php';
}

if (class_exists('\App\Helpers\EnvLoader')) {
    \App\Helpers\EnvLoader::load(BASE_PATH . '/.env');
}

// Security matum CSRF logic-kaaga session start pannurohm
session_start();

// 5. Initialize Core Services
// Singleton database instance matum Request/Response objects
try {
    $dbConfig = file_exists(BASE_PATH . '/config/database.php') ? require BASE_PATH . '/config/database.php' : [];
    $database = \App\Core\Database::getInstance($dbConfig);
    
    $request = new \App\Core\Request();
    $response = new \App\Core\Response();

    // 6. Handle CORS & Preflight (React frontend integration)
    $response->setCorsHeaders();
    if ($request->getMethod() === 'OPTIONS') {
        $response->setStatusCode(200);
        $response->send();
        exit;
    }

    // 7. Initialize & Load Router
    $router = new \App\Core\Router($request, $response);

    // Routes file-ai load pannurohm ($router object-ai inga pass pannuvom)
    if (file_exists(BASE_PATH . '/routes/api.php')) {
        require_once BASE_PATH . '/routes/api.php';
    }

    // 8. Dispatch (Match routes and execute controllers)
    $router->dispatch();

} catch (\Exception $e) {
    // Global Error Handling
    \App\Core\Response::json([
        "status" => "error",
        "message" => "Application Bootstrap Failed",
        "details" => $e->getMessage()
    ], 500);
}