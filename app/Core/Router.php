<?php
namespace App\Core;

class Router {
    protected $routes = [];

    // Standardize paths (ensures leading slash and removes trailing slash)
    private function standardizePath($path) {
        return '/' . trim($path, '/');
    }

    public function get($path, $callback) {
        $path = $this->standardizePath($path);
        $this->routes['get'][$path] = $callback;
    }

    public function post($path, $callback) {
        $path = $this->standardizePath($path);
        $this->routes['post'][$path] = $callback;
    }

    /**
     * 🔥 FIX: PUT method-ah register panna intha function kandippa venum.
     * Ippo dhaan /prescriptions (PUT) route work aagum.
     */
    public function put($path, $callback) {
        $path = $this->standardizePath($path);
        $this->routes['put'][$path] = $callback;
    }

    public function resolve(Request $request) {
        $method = $request->getMethod(); // GET, POST, or PUT
        $path = $this->standardizePath($request->getPath()); 
        
        $callback = $this->routes[$method][$path] ?? false;

        if (!$callback) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode([
                "error" => "Route not found", 
                "received" => [
                    "method" => $method, 
                    "path" => $path
                ],
                // Ippo registered_routes-la PUT routes-um kaattum
                "registered_routes" => array_keys($this->routes[$method] ?? [])
            ]);
            exit;
        }

        $controllerName = $callback[0];
        $action = $callback[1];

        if (class_exists($controllerName)) {
            $controller = new $controllerName();
            return $controller->$action($request);
        } else {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                "error" => "Controller class not found",
                "target_class" => $controllerName
            ]);
            exit;
        }
    }
}