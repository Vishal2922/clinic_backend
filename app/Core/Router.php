<?php

namespace App\Core;

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
     * Group routes with common prefix and middleware
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

    public function get(string $path, $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $handler, $middleware);
    }

    public function post(string $path, $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $handler, $middleware);
    }

    public function put(string $path, $handler, array $middleware = []): void
    {
        $this->addRoute('PUT', $path, $handler, $middleware);
    }

    public function patch(string $path, $handler, array $middleware = []): void
    {
        $this->addRoute('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, $handler, array $middleware = []): void
    {
        $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    private function addRoute(string $method, string $path, $handler, array $middleware): void
    {
        $fullPath = $this->currentPrefix . $path;
        $allMiddleware = array_merge($this->currentGroupMiddleware, $middleware);

        $this->routes[] = [
            'method' => $method,
            'path' => $fullPath,
            'handler' => $handler,
            'middleware' => $allMiddleware,
        ];
    }

    public function dispatch(): void
    {
        $requestMethod = $this->request->getMethod();
        $requestUri = $this->request->getUri();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $requestMethod) {
                continue;
            }

            $params = $this->matchRoute($route['path'], $requestUri);

            if ($params !== false) {
                // Run middleware chain
                foreach ($route['middleware'] as $middleware) {
                    $this->runMiddleware($middleware);
                }

                // Call handler
                $this->callHandler($route['handler'], $params);
                return;
            }
        }

        // No route matched
        $this->response->error('Route not found', 404);
    }

    /**
     * Match route pattern with URI, return params or false
     */
    private function matchRoute(string $routePath, string $uri)
    {
        // Convert route params like {id} to regex
        $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $routePath);
        $pattern = '#^' . $pattern . '$#';

        if (preg_match($pattern, $uri, $matches)) {
            // Extract named params only
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            return $params;
        }

        return false;
    }

    private function runMiddleware($middleware): void
    {
        if (is_string($middleware)) {
            // Format: "ClassName" or "ClassName:param1,param2"
            $parts = explode(':', $middleware, 2);
            $className = $parts[0];
            $params = isset($parts[1]) ? explode(',', $parts[1]) : [];

            if (class_exists($className)) {
                $instance = new $className();
                $instance->handle($this->request, $this->response, $params);
            } else {
                $this->response->error("Middleware not found: {$className}", 500);
            }
        } elseif (is_callable($middleware)) {
            $middleware($this->request, $this->response);
        }
    }

    private function callHandler($handler, array $params): void
    {
        if (is_array($handler)) {
            // [ControllerClass, method]
            [$controllerClass, $method] = $handler;
            $controller = new $controllerClass();
            $controller->setRequest($this->request);
            $controller->setResponse($this->response);
            $controller->$method(...array_values($params));
        } elseif (is_callable($handler)) {
            $handler($this->request, $this->response, $params);
        } elseif (is_string($handler) && strpos($handler, '@') !== false) {
            // "Controller@method"
            [$controllerClass, $method] = explode('@', $handler);
            $controller = new $controllerClass();
            $controller->setRequest($this->request);
            $controller->setResponse($this->response);
            $controller->$method(...array_values($params));
        }
    }
}