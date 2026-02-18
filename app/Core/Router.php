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


//-----------------------------------------------------------// 

namespace App\Core;

class Router {
    protected $routes = [];

    // URL path-ah standardize panna (e.g., /patients or /patients/)
    private function standardizePath($path) {
        return '/' . trim($path, '/');
    }

    // GET Method register panna
    public function get($path, $callback) {
        $path = $this->standardizePath($path);
        $this->routes['get'][$path] = $callback;
    }

    // POST Method register panna
    public function post($path, $callback) {
        $path = $this->standardizePath($path);
        $this->routes['post'][$path] = $callback;
    }

    // PUT Method register panna (Update operations-ku mukkiyam)
    public function put($path, $callback) {
        $path = $this->standardizePath($path);
        $this->routes['put'][$path] = $callback;
    }

    // DELETE Method register panna (Soft delete operations-ku)
    public function delete($path, $callback) {
        $path = $this->standardizePath($path);
        $this->routes['delete'][$path] = $callback;
    }

    /**
     * Request-ku yetha controller and method-ah kandu puidchi run pannum
     */
    public function resolve(Request $request) {
        $method = $request->getMethod(); // get, post, put, etc.
        $path = $this->standardizePath($request->getPath()); 
        
        $callback = $this->routes[$method][$path] ?? false;

        // Route illana 404 error
        if (!$callback) {
            Response::json([
                "error" => "Route not found vro!", 
                "received" => ["method" => strtoupper($method), "path" => $path]
            ], 404);
        }

        $controllerName = $callback[0];
        $action = $callback[1];

        // Controller class iruka nu check panroam
        if (class_exists($controllerName)) {
            $controller = new $controllerName();
            
            // Controller method iruka nu check panni run panroam
            if (method_exists($controller, $action)) {
                return $controller->$action($request);
            } else {
                Response::json([
                    "error" => "Method '$action' not found in $controllerName"
                ], 500);
            }
        } else {
            Response::json([
                "error" => "Controller class '$controllerName' not found"
            ], 500);
        }
    }
}