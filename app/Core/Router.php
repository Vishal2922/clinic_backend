<?php

namespace App\Core;

/**
 * Router Class: Merged Version.
 * Supports: Grouping, Middleware, Dynamic Route Parameters {id}, and Standard HTTP Methods.
 */
class Router
{
    private Request $request;
    private Response $response;
    private array $routes = [];
    private array $currentGroupMiddleware = [];
    private string $currentPrefix = '';

    public function __construct(Request $request, Response $response)
    {
        $this->request = $request;
        $this->response = $response;
    }

    /**
     * Group routes: Common prefix matum middleware sethu register panna idhu use aagum.
     */
    public function group(array $options, callable $callback): void
    {
        $previousPrefix = $this->currentPrefix;
        $previousMiddleware = $this->currentGroupMiddleware;

        $this->currentPrefix .= $options['prefix'] ?? '';
        
        if (isset($options['middleware'])) {
            $middlewares = is_array($options['middleware']) ? $options['middleware'] : [$options['middleware']];
            $this->currentGroupMiddleware = array_merge($this->currentGroupMiddleware, $middlewares);
        }

        $callback($this);

        $this->currentPrefix = $previousPrefix;
        $this->currentGroupMiddleware = $previousMiddleware;
    }

    // Standard Route Registration Methods (Merged from all versions)
    public function get(string $path, $handler, array $middleware = []): void { $this->addRoute('GET', $path, $handler, $middleware); }
    public function post(string $path, $handler, array $middleware = []): void { $this->addRoute('POST', $path, $handler, $middleware); }
    public function put(string $path, $handler, array $middleware = []): void { $this->addRoute('PUT', $path, $handler, $middleware); }
    public function patch(string $path, $handler, array $middleware = []): void { $this->addRoute('PATCH', $path, $handler, $middleware); }
    public function delete(string $path, $handler, array $middleware = []): void { $this->addRoute('DELETE', $path, $handler, $middleware); }

    /**
     * Internal helper to standardize and store routes.
     */
    private function addRoute(string $method, string $path, $handler, array $middleware): void
    {
        // Standardize path: prefixes merge panni clean slash set pannum.
        $fullPath = '/' . ltrim($this->currentPrefix . '/' . ltrim($path, '/'), '/');
        // Group middleware + Route level middleware merge pannum.
        $allMiddleware = array_merge($this->currentGroupMiddleware, $middleware);

        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => rtrim($fullPath, '/') ?: '/',
            'handler' => $handler,
            'middleware' => $allMiddleware,
        ];
    }

    /**
     * Dispatch: Request-ku match aagura route-ai kandupidi panni execute pannum.
     */
    public function resolve(): void
    {
        $requestMethod = strtoupper($this->request->getMethod());
        $requestUri = '/' . ltrim($this->request->getPath(), '/');

        foreach ($this->routes as $route) {
            if ($route['method'] !== $requestMethod) {
                continue;
            }

            $params = $this->matchRoute($route['path'], $requestUri);

            if ($params !== false) {
                // 1. Run Middleware Chain
                foreach ($route['middleware'] as $middleware) {
                    $this->runMiddleware($middleware);
                }

                // 2. Call Controller Handler
                $this->callHandler($route['handler'], $params);
                return;
            }
        }

        // 404 Error: Merged response helper use panni standard output kudukkum.
        $this->response->error('Route not found vro!', 404, [
            'method' => $requestMethod,
            'path' => $requestUri
        ]);
    }

    /**
     * Match route: /patients/{id} maadhiri dynamic parameters-ai extract pannum.
     */
    private function matchRoute(string $routePath, string $uri)
    {
        $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $routePath);
        $pattern = '#^' . $pattern . '$#';

        if (preg_match($pattern, $uri, $matches)) {
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) { $params[$key] = $value; }
            }
            return $params;
        }
        return false;
    }

    private function runMiddleware($middleware): void
    {
        if (is_string($middleware)) {
            $parts = explode(':', $middleware, 2);
            $className = $parts[0];
            $params = isset($parts[1]) ? explode(',', $parts[1]) : [];

            if (class_exists($className)) {
                $instance = new $className();
                $instance->handle($this->request, $this->response, $params);
            }
        } elseif (is_callable($middleware)) {
            $middleware($this->request, $this->response);
        }
    }

    private function callHandler($handler, array $params): void
    {
        $controllerClass = '';
        $method = '';

        if (is_array($handler)) {
            [$controllerClass, $method] = $handler;
        } elseif (is_string($handler) && strpos($handler, '@') !== false) {
            [$controllerClass, $method] = explode('@', $handler);
        }

        if (class_exists($controllerClass)) {
            $controller = new $controllerClass();
            
            // Controller-ku merged Request matum Response-ai inject panroam.
            if (method_exists($controller, 'setRequest')) {
                $controller->setRequest($this->request);
            }
            if (method_exists($controller, 'setResponse')) {
                $controller->setResponse($this->response);
            }
            
            // Method exist-ah nu check panni execute panroam.
            if (method_exists($controller, $method)) {
                call_user_func_array([$controller, $method], [$this->request, ...array_values($params)]);
            } else {
                $this->response->error("Method $method not found in $controllerClass", 500);
            }
        } else {
            $this->response->error("Controller $controllerClass not found", 500);
        }
    }
}