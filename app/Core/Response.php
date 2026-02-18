<?php

namespace App\Core;

/**
 * Response Class: Git conflict fix panni merge panniyirukaen.
 * Supports static calls for quick JSON matum object methods for advanced headers/CORS.
 */
class Response
{
    private int $statusCode = 200;
    private array $headers = [];
    private $body = null;

    /**
     * STATIC HELPER: Unga pazhaya code break aagaama irukka idhu mukkiam.
     * Response::json() nu direct-aa call pannalaam.
     */
    public static function json($data, $code = 200) {
        $instance = new self();
        $instance->setStatusCode($code);
        $instance->setHeader('Content-Type', 'application/json; charset=utf-8');
        
        // CORS basic headers for React/Postman
        $instance->setCorsHeaders();

        http_response_code($code);
        foreach ($instance->headers as $name => $value) {
            header("$name: $value");
        }
        
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
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
     * CORS CONFIG: React (localhost:3000) kooda work panna idhu mukkiam.
     */
    public function setCorsHeaders(): self
    {
        $this->setHeader('Access-Control-Allow-Origin', '*'); // Development-kaaga '*' pōtturukaen
        $this->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $this->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        return $this;
    }

    /**
     * SUCCESS HELPER: Standard success response format
     */
    public function success(array $data = [], string $message = 'Success', int $code = 200): void
    {
        self::json([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    /**
     * ERROR HELPER: Standard error response format with validation errors
     */
    public function error(string $message = 'Error', int $code = 400, array $errors = []): void
    {
        $payload = [
            'status' => 'error',
            'message' => $message,
        ];

        if (!empty($errors)) {
            $payload['errors'] = $errors;
        }

        self::json($payload, $code);
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
            'expires' => $expires,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
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
            echo $this->body;
        }
    }
}