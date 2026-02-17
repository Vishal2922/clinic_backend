<?php

namespace App\Core;

class Controller
{
    protected Request $request;
    protected Response $response;

    public function setRequest(Request $request): void
    {
        $this->request = $request;
    }

    public function setResponse(Response $response): void
    {
        $this->response = $response;
    }

    /**
     * Get authenticated user data from JWT middleware
     */
    protected function getAuthUser(): ?array
    {
        return $this->request->getAttribute('auth_user');
    }

    /**
     * Get tenant ID from middleware
     */
    protected function getTenantId(): ?int
    {
        return $this->request->getAttribute('tenant_id');
    }

    /**
     * Validate required fields
     */
    protected function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $ruleList = explode('|', $ruleString);
            $value = $data[$field] ?? null;

            foreach ($ruleList as $rule) {
                if ($rule === 'required' && (is_null($value) || $value === '')) {
                    $errors[$field][] = "{$field} is required.";
                }

                if ($rule === 'email' && $value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field][] = "{$field} must be a valid email.";
                }

                if (strpos($rule, 'min:') === 0) {
                    $min = (int) substr($rule, 4);
                    if ($value && strlen($value) < $min) {
                        $errors[$field][] = "{$field} must be at least {$min} characters.";
                    }
                }

                if (strpos($rule, 'max:') === 0) {
                    $max = (int) substr($rule, 4);
                    if ($value && strlen($value) > $max) {
                        $errors[$field][] = "{$field} must not exceed {$max} characters.";
                    }
                }

                if ($rule === 'numeric' && $value && !is_numeric($value)) {
                    $errors[$field][] = "{$field} must be numeric.";
                }

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