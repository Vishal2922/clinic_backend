<?php

namespace App\Core\Security;

class JwtService
{
    private string $secret;
    private string $algorithm;
    private int $accessTtl;
    private string $issuer;

    public function __construct()
    {
        $this->secret    = env('JWT_SECRET', 'fallback-secret-key');
        $this->algorithm = 'HS256';
        $this->accessTtl = (int) env('JWT_ACCESS_TTL', 900);
        $this->issuer    = env('JWT_ISSUER', 'clinic-api');
    }

    /**
     * Generate JWT access token
     * 
     * Payload MUST include:
     *   - sub (user_id)
     *   - tenant_id
     *   - role_id
     *   - role_name
     *   - username
     *   - permissions (array of permission keys)
     */
    public function generateAccessToken(array $payload): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => $this->algorithm,
        ];

        $now = time();

        // Build the full token payload with role information
        $tokenPayload = [
            'iss'         => $this->issuer,
            'iat'         => $now,
            'exp'         => $now + $this->accessTtl,
            'jti'         => bin2hex(random_bytes(16)),
            'sub'         => $payload['sub'],           // user_id
            'tenant_id'   => $payload['tenant_id'],
            'role_id'     => $payload['role_id'],
            'role_name'   => $payload['role_name'],
            'username'    => $payload['username'],
            'permissions' => $payload['permissions'] ?? [],
        ];

        $headerEncoded  = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($tokenPayload));

        $signature        = $this->sign("$headerEncoded.$payloadEncoded");
        $signatureEncoded = $this->base64UrlEncode($signature);

        return "$headerEncoded.$payloadEncoded.$signatureEncoded";
    }

    /**
     * Verify and decode JWT token
     * Returns full payload including role info or null if invalid
     */
    public function verifyToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        // Verify signature
        $expectedSignature = $this->sign("$headerEncoded.$payloadEncoded");
        $signature = $this->base64UrlDecode($signatureEncoded);

        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        // Decode payload
        $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);

        if (!$payload) {
            return null;
        }

        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }

        // Check issuer
        if (isset($payload['iss']) && $payload['iss'] !== $this->issuer) {
            return null;
        }

        // Validate required fields exist
        $requiredFields = ['sub', 'tenant_id', 'role_id', 'role_name'];
        foreach ($requiredFields as $field) {
            if (!isset($payload[$field])) {
                return null;
            }
        }

        return $payload;
    }

    /**
     * Get the access token TTL in seconds
     */
    public function getAccessTtl(): int
    {
        return $this->accessTtl;
    }

    private function sign(string $data): string
    {
        return hash_hmac('sha256', $data, $this->secret, true);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $padding = 4 - (strlen($data) % 4);
        if ($padding !== 4) {
            $data .= str_repeat('=', $padding);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
}