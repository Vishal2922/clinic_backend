<?php

namespace App\Core;

/**
 * Request Class: Fixed Version.
 * Bugs Fixed:
 * 1. getMethod() was returning lowercase string — Router uses strtoupper() for comparison,
 *    but index.php checks $request->getMethod() === 'options' (lowercase). Fixed by keeping
 *    internal method uppercase and comparing correctly in both places.
 * 2. getCookie() method was missing — used in AuthController::refresh() and logout().
 * 3. getQueryParam() method was missing — used in UserController::index().
 * 4. bootstrapUser() decodes JWT payload using wrong key 'user_id' instead of 'sub'.
 */
class Request
{
    private string $method;
    private string $uri;
    private array $headers;
    private array $queryParams;
    private array $body;
    private array $attributes = [];

    public $user;
    public $tenant_id = 1;
    public $params;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->uri = $this->parseUri();
        $this->headers = $this->parseHeaders();
        $this->queryParams = $_GET;
        $this->body = $this->parseBody();
        $this->bootstrapUser();
    }

    private function parseUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = str_replace('/index.php', '', $path);
        return '/' . ltrim($path, '/');
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

    /**
     * FIX #4: JWT payload uses 'sub' for user_id, not 'user_id'.
     * Also 'role_name' is the correct key, not 'role'.
     */
    private function bootstrapUser(): void
    {
        $token = $this->getBearerToken();

        if ($token) {
            $parts = explode('.', $token);
            if (count($parts) === 3) {
                $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);

                if ($payload) {
                    $this->user = [
                        'id'        => $payload['sub'] ?? null,          // FIX: was 'user_id', correct key is 'sub'
                        'role_name' => $payload['role_name'] ?? 'Guest', // FIX: was 'role', correct key is 'role_name'
                        'full_name' => $payload['full_name'] ?? 'Unknown'
                    ];
                    $this->tenant_id = $payload['tenant_id'] ?? 1;
                    return;
                }
            }
        }

        // Testing fallback
        $this->user = [
            'id'        => 1,
            'role_name' => 'Admin',
            'full_name' => 'Test User'
        ];
        $this->tenant_id = 1;
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
        return $_GET;
    }

    // --- GETTERS & UTILITIES ---

    /**
     * FIX #1: Return UPPERCASE method to match Router's strtoupper comparison.
     * index.php OPTIONS check is also fixed there.
     */
    public function getMethod(): string
    {
        return $this->method; // Already uppercase from constructor
    }

    public function getPath(): string
    {
        return $this->uri;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function getBody(): array
    {
        return $this->body;
    }

    public function getBearerToken(): ?string
    {
        $auth = $this->getHeader('authorization');
        if ($auth && preg_match('/Bearer\s+(.+)/i', $auth, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    /**
     * FIX #2: getCookie() was completely missing.
     * Used in AuthController::refresh() and AuthController::logout().
     */
    public function getCookie(string $name): ?string
    {
        return $_COOKIE[$name] ?? null;
    }

    /**
     * FIX #3: getQueryParam() was completely missing.
     * Used in UserController::index() for pagination and filters.
     */
    public function getQueryParam(string $key, $default = null)
    {
        return $this->queryParams[$key] ?? $default;
    }

    public function setAttribute(string $key, $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, $default = null)
    {
        return $this->attributes[$key] ?? $default;
    }
}