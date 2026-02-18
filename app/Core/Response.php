<?php

namespace App\Core;

/**
 * Response Class: Git conflict resolved and merged.
 * Supports static calls for quick JSON and object methods for advanced headers/CORS/Cookies.
 */
class Response
{
    private int $statusCode = 200;
    private array $headers = [];
    private $body = null;

    /**
     * STATIC HELPER: 
     * Unga previous code break aagaama irukka direct-aa Response::json() call pannalaam.
     * Merged logic: Automatically adds status (success/error) and CORS.
     */
    public static function json($data, $code = 200): void
    {
        $instance = new self();
        $instance->setStatusCode($code);
        $instance->setHeader('Content-Type', 'application/json; charset=utf-8');
        
        // CORS basic headers added automatically for compatibility (Postman/React/Mobile)
        $instance->setCorsHeaders();

        // Standardize data structure
        $status = ($code >= 200 && $code < 300) ? 'success' : 'error';
        
        // Prepare final payload: if data is already an array, merge; otherwise, wrap it.
        $payload = ['status' => $status];
        if (is_array($data)) {
            $payload = array_merge($payload, $data);
        } else {
            $payload['data'] = $data;
        }

        http_response_code($code);
        foreach ($instance->headers as $name => $value) {
            header("$name: $value");
        }
        
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * SUCCESS HELPER: 
     * Standard success format used across the project.
     */
    public static function success($message, $data = [], $code = 200): void
    {
        self::json([
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    /**
     * ERROR HELPER: 
     * Standard error response with optional validation error details.
     */
    public static function error($message = 'Error', int $code = 400, array $errors = []): void
    {
        $payload = [
            'message' => $message,
        ];

        if (!empty($errors)) {
            $payload['errors'] = $errors;
        }

        self::json($payload, $code);
    }

    public function setStatusCode(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * CORS CONFIG: 
     * Frontend (React/Vue/Angular) kooda connect panna idhu romba mukkiam.
     */
    public function setCorsHeaders(): self
    {
        $this->setHeader('Access-Control-Allow-Origin', '*'); 
        $this->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $this->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        return $this;
    }

    /**
     * COOKIE HELPER: 
     * Secure-ah cookies set panna idhu use aagum.
     */
    public function setCookie(
        string $name,
        string $value,
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httponly = true,
        string $samesite = 'Strict'
    ): self {
        setcookie($name, $value, [
            'expires' => $expires,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httponly,
            'samesite' => $samesite,
        ]);
        return $this;
    }

    /**
     * Standard send method for non-static usage.
     */
    public function send(): void
    {
        http_response_code($this->statusCode);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        if ($this->body !== null) {
            echo is_array($this->body) ? json_encode($this->body) : $this->body;
        }
    }
}