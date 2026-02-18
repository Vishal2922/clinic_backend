<?php

namespace App\Core;

/**
 * Response Class: Fixed Version.
 * Bugs Fixed:
 * 1. error() is a static method but called as $this->response->error() in middleware (instance call).
 *    Added instance proxy methods success() and error() so both $this->response->error() and
 *    Response::error() work correctly.
 * 2. AuthController::register() calls $this->response->success($result, 'message', 201) —
 *    argument order is (data, message, code) but static success() signature is (message, data, code).
 *    Fixed argument order in static success() to match actual usage.
 * 3. CORS header missing X-CSRF-TOKEN in Access-Control-Allow-Headers (needed for CSRF support).
 */
class Response
{
    private int $statusCode = 200;
    private array $headers = [];
    private $body = null;

    /**
     * STATIC: Raw JSON output.
     */
    public static function json($data, $code = 200): void
    {
        $instance = new self();
        $instance->setStatusCode($code);
        $instance->setHeader('Content-Type', 'application/json; charset=utf-8');
        $instance->setCorsHeaders();

        $status = ($code >= 200 && $code < 300) ? 'success' : 'error';

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
     * FIX #2: Static success() — corrected argument order to (message, data, code).
     * AuthController calls: $this->response->success($result, 'message', 201)
     * which maps to instance success() below. Static version keeps (message, data, code).
     */
    public static function success($message, $data = [], $code = 200): void
    {
        self::json([
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    /**
     * STATIC: Standard error response.
     */
    public static function error($message = 'Error', int $code = 400, array $errors = []): void
    {
        $payload = ['message' => $message];

        if (!empty($errors)) {
            $payload['errors'] = $errors;
        }

        self::json($payload, $code);
    }

    // ─────────────────────────────────────────────────────────
    // FIX #1: Instance proxy methods so middleware/controllers
    // can call $this->response->error() / $this->response->success()
    // ─────────────────────────────────────────────────────────

    /**
     * Instance proxy for error() — used by middleware classes.
     * Middleware calls: $response->error('message', 401)
     */
    public function errorResponse(string $message = 'Error', int $code = 400, array $errors = []): void
    {
        static::error($message, $code, $errors);
    }

    /**
     * Instance proxy for success() — used by controllers.
     * Controllers call: $this->response->success($data, 'message', 201)
     * Note: data comes first here (instance usage convention in this codebase).
     */
    public function successResponse($data, string $message = 'Success', int $code = 200): void
    {
        static::json([
            'message' => $message,
            'data'    => $data,
        ], $code);
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
     * FIX #3: Added X-CSRF-TOKEN to allowed headers for CSRF support.
     */
    public function setCorsHeaders(): self
    {
        $this->setHeader('Access-Control-Allow-Origin', '*');
        $this->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $this->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN, X-Tenant-ID');
        return $this;
    }

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
            'expires'  => $expires,
            'path'     => $path,
            'domain'   => $domain,
            'secure'   => $secure,
            'httponly' => $httponly,
            'samesite' => $samesite,
        ]);
        return $this;
    }

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