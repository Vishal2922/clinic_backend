<?php

namespace App\Core;

/**
 * Router Class: Git conflict fix panni merge panniyirukaen.
 * Supports Grouping, Middleware, matum Named Parameters.
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
     * Group routes: Common prefix matum middleware sethu register panna.
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

    // Standard Route Registration
    public function get(string $path, $handler, array $middleware = []): void { $this->addRoute('GET', $path, $handler, $middleware); }
    public function post(string $path, $handler, array $middleware = []): void { $this->addRoute('POST', $path, $handler, $middleware); }
    public function put(string $path, $handler, array $middleware = []): void { $this->addRoute('PUT', $path, $handler, $middleware); }
    public function patch(string $path, $handler, array $middleware = []): void { $this->addRoute('PATCH', $path, $handler, $middleware); }
    public function delete(string $path, $handler, array $middleware = []): void { $this->addRoute('DELETE', $path, $handler, $middleware); }

    private function addRoute(string $method, string $path, $handler, array $middleware): void
    {
        // Standardize path (leading slash)
        $fullPath = '/' . ltrim($this->currentPrefix . $path, '/');
        $allMiddleware = array_merge($this->currentGroupMiddleware, $middleware);

        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $fullPath,
            'handler' => $handler,
            'middleware' => $allMiddleware,
        ];
    }

    /**
     * Dispatch: Request-ku match aagura route-ai kandupidi panni execute pannum.
     */
    public function dispatch(): void
    {
        $requestMethod = $this->request->getMethod();
        $requestUri = $this->request->getPath(); // Using our merged Request method

        foreach ($this->routes as $route) {
            if ($route['method'] !== strtoupper($requestMethod)) {
                continue;
            }

            $params = $this->matchRoute($route['path'], $requestUri);

            if ($params !== false) {
                // Run Middleware Chain
                foreach ($route['middleware'] as $middleware) {
                    $this->runMiddleware($middleware);
                }

                // Call Controller Handler
                $this->callHandler($route['handler'], $params);
                return;
            }
        }

        // 404 Error using our merged Response helper
        $this->response->error('Route not found', 404, [
            'received_method' => $requestMethod,
            'received_path' => $requestUri
        ]);
    }

    /**
     * Match route: {id} maadhiri parameters-ai extract pannum.
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
            // Important: Set Request and Response to Controller
            $controller->setRequest($this->request);
            $controller->setResponse($this->response);
            
            $controller->$method($this->request, ...array_values($params));
        } else {
            $this->response->error("Controller $controllerClass not found", 500);
        }
    }
}