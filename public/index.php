<?php
// 1. Error reporting (Debugging-ku)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 2. Fixed Autoloader
spl_autoload_register(function ($class) {
    // App\Core\Request -> app/Core/Request.php
    $classPath = str_replace(['App\\', '\\'], ['', DIRECTORY_SEPARATOR], $class);
    $file = __DIR__ . '/../app/' . $classPath . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

use App\Core\Request;
use App\Core\Router;
use App\Core\Response;

// 3. Initialize Request & Router
$request = new Request();
$router = new Router(); // Router object-ah ingaye create panradhu safe

// 4. Load Routes (api.php-la irukkura $router-ah use pannum)
if (file_exists(__DIR__ . '/../routes/api.php')) {
    require_once __DIR__ . '/../routes/api.php';
}

// 5. Dispatch (Route matching starts here)
try {
    $router->resolve($request);
} catch (\Exception $e) {
    Response::json(["error" => "Application Error", "message" => $e->getMessage()], 500);
}