<?php
/**
 * Application Entry Point
 * All requests are routed through this file.
 */

// Error reporting for development
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Define base path
define('BASE_PATH', dirname(__DIR__));

// Autoload
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = BASE_PATH . '/app/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Load helpers FIRST (env() function needed by configs)
require_once BASE_PATH . '/app/Helpers/functions.php';

// Load .env BEFORE configs
\App\Helpers\EnvLoader::load(BASE_PATH . '/.env');

// Start session for CSRF
session_start();

// Load config (now env() is available)
$appConfig = require BASE_PATH . '/config/app.php';
$dbConfig = require BASE_PATH . '/config/database.php';
$securityConfig = require BASE_PATH . '/config/security.php';

// Set timezone
date_default_timezone_set($appConfig['timezone']);

// Initialize core services
$database = \App\Core\Database::getInstance($dbConfig);
$request = new \App\Core\Request();
$response = new \App\Core\Response();

// Set CORS headers for API
$response->setCorsHeaders();

// Handle preflight
if ($request->getMethod() === 'OPTIONS') {
    $response->setStatusCode(200);
    $response->send();
    exit;
}

// Initialize Router
$router = new \App\Core\Router($request, $response);

// Load routes
require BASE_PATH . '/routes/api.php';

// Dispatch
$router->dispatch();