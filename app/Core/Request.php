<?php

namespace App\Core;

/**
 * Request Class: Git conflict fix panni merge panniyirukaen.
 * Supports manual testing, attribute passing, and Bearer token extraction.
 */
class Request
{
    private string $method;
    private string $uri;
    private array $headers;
    private array $queryParams;
    private array $body;
    private array $attributes = [];

    // Unga original requirement: Public properties for ease of use
    public $user; 
    public $tenant_id = 1;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->uri = $this->parseUri();
        $this->headers = $this->parseHeaders();
        $this->queryParams = $_GET;
        $this->body = $this->parseBody();
    }

    private function parseUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = dirname($scriptName);

        if ($basePath !== '/' && $basePath !== '\\') {
            $uri = substr($uri, strlen($basePath));
        }

        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }

        return '/' . ltrim($uri, '/');
    }

    private function parseHeaders(): array
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                $headers[strtolower($name)] = $value;
            }
        } else {
            foreach ($_SERVER as $key => $value) {
                if (strpos($key, 'HTTP_') === 0) {
                    $name = strtolower(str_replace('_', '-', substr($key, 5)));
                    $headers[$name] = $value;
                }
            }
        }
        return $headers;
    }

    private function parseBody(): array
    {
        if (in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            $contentType = $this->getHeader('content-type') ?? '';
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw, true);

            if (strpos($contentType, 'application/json') !== false) {
                return is_array($decoded) ? $decoded : [];
            }
            
            return !empty($_POST) ? $_POST : (is_array($decoded) ? $decoded : []);
        }
        return [];
    }

    public function getCookie(string $name): ?string
    {
        return $_COOKIE[$name] ?? null;
    }

    public function getMethod(): string { return $this->method; }

    public function getPath(): string { return $this->uri; } // Alias for teammate's getUri

    public function getHeader(string $name): ?string {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function getBody(): array { return $this->body; }

    /**
     * Bearer token-ai extract panna idhu romba mukkiam (Manual JWT logic-kaaga)
     */
    public function getBearerToken(): ?string
    {
        $auth = $this->getHeader('authorization');
        if ($auth && preg_match('/Bearer\s+(.+)/i', $auth, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    // Attributes storage (Middleware matum Controllers kooda data share panna)
    public function setAttribute(string $key, $value): void { $this->attributes[$key] = $value; }

    public function getAttribute(string $key, $default = null) {
        return $this->attributes[$key] ?? $default;
    }
}