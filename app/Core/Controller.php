<?php

namespace App\Core;

/**
 * Base Controller: Fixed Version.
 * Bugs Fixed:
 * 1. $this->response->error() and $this->response->success() were calling static methods
 *    through an instance — PHP allows this but they call exit immediately which is correct,
 *    HOWEVER AuthController calls $this->response->success($data, $message, $code) with
 *    data as the FIRST argument, while static success() takes (message, data, code).
 *    Added instance-aware success()/error() delegates in Controller to normalize argument order.
 * 2. validate() method: the 'required' check doesn't catch value === '0' correctly 
 *    because '0' is falsy. Fixed to only reject null and empty string.
 */
class Controller
{
    protected Request $request;
    protected Response $response;

    public function __construct()
    {
        // Shared constructor logic
    }

    public function setRequest(Request $request): void
    {
        $this->request = $request;
    }

    public function setResponse(Response $response): void
    {
        $this->response = $response;
    }

    protected function json($data, $code = 200): void
    {
        Response::json($data, $code);
    }

    protected function getAuthUser(): ?array
    {
        return $this->request->getAttribute('auth_user') ?? ($this->request->user ?? null);
    }

    protected function checkRole(array $allowedRoles): bool
    {
        $user = $this->getAuthUser();
        if (!$user || !isset($user['role_name'])) {
            return false;
        }
        return in_array($user['role_name'], $allowedRoles);
    }

    protected function getTenantId(): ?int
    {
        $fromAttr = $this->request->getAttribute('tenant_id');
        if ($fromAttr !== null) {
            return (int) $fromAttr;
        }
        return isset($this->request->tenant_id) ? (int) $this->request->tenant_id : null;
    }

    /**
     * FIX #1: Normalised instance-level response helpers.
     * Controllers call $this->response->success($data, $message, $code)
     * — data first, message second. This proxy corrects the argument order
     * before delegating to the static Response::json().
     */
    protected function respondSuccess($data, string $message = 'Success', int $code = 200): void
    {
        Response::json(['message' => $message, 'data' => $data], $code);
    }

    protected function respondError(string $message = 'Error', int $code = 400, array $errors = []): void
    {
        Response::error($message, $code, $errors);
    }

    /**
     * FIX #2: required check now only rejects null and '' (empty string),
     * not falsy values like '0' or 0.
     */
    protected function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $ruleList = explode('|', $ruleString);
            $value = $data[$field] ?? null;

            foreach ($ruleList as $rule) {
                // Required: reject only null and empty string
                if ($rule === 'required' && ($value === null || $value === '')) {
                    $errors[$field][] = "{$field} is required.";
                }

                if ($rule === 'email' && $value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field][] = "{$field} must be a valid email.";
                }

                if (strpos($rule, 'min:') === 0) {
                    $min = (int) substr($rule, 4);
                    if ($value !== null && $value !== '' && strlen((string)$value) < $min) {
                        $errors[$field][] = "{$field} must be at least {$min} characters.";
                    }
                }

                if (strpos($rule, 'max:') === 0) {
                    $max = (int) substr($rule, 4);
                    if ($value !== null && $value !== '' && strlen((string)$value) > $max) {
                        $errors[$field][] = "{$field} must not exceed {$max} characters.";
                    }
                }

                if ($rule === 'numeric' && $value !== null && $value !== '' && !is_numeric($value)) {
                    $errors[$field][] = "{$field} must be numeric.";
                }

                if (strpos($rule, 'in:') === 0) {
                    $allowed = explode(',', substr($rule, 3));
                    if ($value !== null && $value !== '' && !in_array($value, $allowed)) {
                        $errors[$field][] = "{$field} must be one of: " . implode(', ', $allowed);
                    }
                }
            }
        }

        return $errors;
    }
}