<?php

namespace App\Core;

/**
 * Request Class: Merged Version.
 * Supports manual testing, attribute passing, and Bearer token extraction for JWT.
 */
class Request
{
    private string $method;
    private string $uri;
    private array $headers;
    private array $queryParams;
    private array $body;
    private array $attributes = [];

    // Public properties for ease of use (As per teammate's original requirement)
    public $user; 
    public $tenant_id = 1;
    public $params; // Placeholder for route params

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->uri = $this->parseUri();
        $this->headers = $this->parseHeaders();
        $this->queryParams = $_GET;
        $this->body = $this->parseBody();
        
        // Logic merged from both versions:
        // First, try to extract user from JWT. If not, set default/testing user.
        $this->bootstrapUser();
    }

    /**
     * Standardize Path logic merged from both versions
     */
    private function parseUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        
        // Clean the path for built-in server or sub-directories
        $path = str_replace('/index.php', '', $path);

        return '/' . ltrim($path, '/');
    }

    /**
     * Parse all Request Headers
     */
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

    /**
     * Role and User Data-vai JWT-la irunthu extract panra logic
     */
    private function bootstrapUser()
    {
        $token = $this->getBearerToken();
        
        if ($token) {
            $parts = explode('.', $token);
            if (count($parts) === 3) {
                // Payload-ah decode panroam (2nd part of JWT)
                $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
                
                if ($payload) {
                    $this->user = [
                        'id' => $payload['user_id'] ?? null,
                        'role_name' => $payload['role'] ?? 'Guest',
                        'full_name' => $payload['full_name'] ?? 'Unknown'
                    ];
                    $this->tenant_id = $payload['tenant_id'] ?? 1;
                    return;
                }
            }
        }

        // Fallback for TESTING/GUEST: (Merged from HEAD requirement)
        $this->user = [
            'id' => 1,
            'role_name' => 'Pharmacist', // 👈 'Provider' or 'Pharmacist' nu mathi test pannunga
            'full_name' => 'Test User'
        ];
        $this->tenant_id = 1;
    }

    /**
     * Body parsing logic (Supports JSON and Form Data)
     */
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
        return $_GET;
    }

    // --- GETTERS ---

    public function getMethod(): string { 
        return strtolower($this->method); 
    }

    public function getPath(): string { 
        return $this->uri; 
    }

    public function getHeader(string $name): ?string {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function getBody(): array { 
        return $this->body; 
    }

    /**
     * Bearer token-ai extract panna idhu romba mukkiam
     */
    public function getBearerToken(): ?string
    {
        $auth = $this->getHeader('authorization');
        if ($auth && preg_match('/Bearer\s+(.+)/i', $auth, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    // Attributes storage (Middleware/Controllers kooda data share panna)
    public function setAttribute(string $key, $value): void { 
        $this->attributes[$key] = $value; 
    }

    public function getAttribute(string $key, $default = null) {
        return $this->attributes[$key] ?? $default;
    }
}