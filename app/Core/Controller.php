<?php

namespace App\Core;

/**
 * Base Controller: Git conflict fix panni merge panniyirukaen.
 * Intha file-ai appadiyae use pannunga.
 */
class Controller
{
    protected Request $request;
    protected Response $response;

    public function __construct() {
        // Shared logic global-aa thevai-na inga add pannalaam
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
     * Optional Helper: Controller-la $this->json() nu easy-aa use panna
     */
    protected function json($data, $code = 200) {
        Response::json($data, $code);
    }

    /**
     * Authenticated user data-vai JWT middleware attribute-la irundhu edukka
     */
    protected function getAuthUser(): ?array
    {
        return $this->request->getAttribute('auth_user');
    }

    /**
     * Tenant ID-ai middleware attribute-la irundhu edukka
     */
    protected function getTenantId(): ?int
    {
        return $this->request->getAttribute('tenant_id');
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
                    if ($value && strlen($value) < $min) {
                        $errors[$field][] = "{$field} must be at least {$min} characters.";
                    }
                }

                // 4. Maximum Length Check
                if (strpos($rule, 'max:') === 0) {
                    $max = (int) substr($rule, 4);
                    if ($value && strlen($value) > $max) {
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