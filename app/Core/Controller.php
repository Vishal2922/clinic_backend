<?php

namespace App\Core;

/**
 * Base Controller: Solved Git Conflicts & Merged Features.
 * Includes: Validation Engine, JWT Auth Helpers, and JSON Response Helpers.
 */
class Controller
{
    protected Request $request;
    protected Response $response;

    /**
     * Constructor: Shared logic global-aa thevai-na inga add pannalaam.
     */
    public function __construct() 
    {
        // Add shared logic here (e.g., base logging or session checks)
    }

    public function setRequest(Request $request): void
    {
        $this->request = $request;
    }

    public function setResponse(Response $response): void
    {
        $this->response = $response;
    }

    /**
     * Optional Helper: Controller-la $this->json() nu easy-aa use panna.
     */
    protected function json($data, $code = 200): void
    {
        Response::json($data, $code);
    }

    /**
     * Authenticated user data-vai JWT payload-la irundhu edukka.
     */
    protected function getAuthUser(): ?array
    {
        // Namma merged request logic padi 'user' property-la data irukkum
        return $this->request->user ?? $this->request->getAttribute('auth_user');
    }

    /**
     * Role-based Access Check (Manual Check inside Controller if needed).
     */
    protected function checkRole(array $allowedRoles): bool
    {
        $user = $this->getAuthUser();
        if (!$user || !isset($user['role_name'])) {
            return false;
        }
        return in_array($user['role_name'], $allowedRoles);
    }

    /**
     * Tenant ID-ai middleware attribute-la irundhu edukka.
     */
    protected function getTenantId(): ?int
    {
        return $this->request->tenant_id ?? $this->request->getAttribute('tenant_id');
    }

    /**
     * VALIDATION ENGINE:
     * Manual-aa ovvoru if-else ezhudhama, rule-padi validate panna idhu best.
     */
    protected function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $ruleList = explode('|', $ruleString);
            $value = $data[$field] ?? null;

            foreach ($ruleList as $rule) {
                // 1. Required Check
                if ($rule === 'required' && (is_null($value) || $value === '')) {
                    $errors[$field][] = "{$field} is required.";
                }

                // 2. Email Validation
                if ($rule === 'email' && $value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field][] = "{$field} must be a valid email.";
                }

                // 3. Minimum Length Check
                if (strpos($rule, 'min:') === 0) {
                    $min = (int) substr($rule, 4);
                    if ($value && strlen((string)$value) < $min) {
                        $errors[$field][] = "{$field} must be at least {$min} characters.";
                    }
                }

                // 4. Maximum Length Check
                if (strpos($rule, 'max:') === 0) {
                    $max = (int) substr($rule, 4);
                    if ($value && strlen((string)$value) > $max) {
                        $errors[$field][] = "{$field} must not exceed {$max} characters.";
                    }
                }

                // 5. Numeric Check
                if ($rule === 'numeric' && $value && !is_numeric($value)) {
                    $errors[$field][] = "{$field} must be numeric.";
                }

                // 6. Allowed Values Check (e.g., in:active,inactive)
                if (strpos($rule, 'in:') === 0) {
                    $allowed = explode(',', substr($rule, 3));
                    if ($value && !in_array($value, $allowed)) {
                        $errors[$field][] = "{$field} must be one of: " . implode(', ', $allowed);
                    }
                }
            }
        }

        return $errors;
    }
}