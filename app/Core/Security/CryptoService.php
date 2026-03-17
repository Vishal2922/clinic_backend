<?php

namespace App\Core\Security;

class CryptoService
{
    private string $key;
    private string $cipher;

    public function __construct()
    {
        $this->key    = env('ENCRYPTION_KEY', 'change-this-key-in-env-file-32ch');
        $this->cipher = 'aes-256-cbc';

        // Validate key length (AES-256 requires 32 bytes)
        if (strlen($this->key) !== 32) {
            throw new \RuntimeException(
                'ENCRYPTION_KEY must be exactly 32 characters for AES-256-CBC. Current length: ' . strlen($this->key)
            );
        }
    }

    /**
     * Encrypt data using AES-256-CBC
     */
    public function encrypt(string $plainText): string
    {
        $ivLength = openssl_cipher_iv_length($this->cipher);
        $iv = openssl_random_pseudo_bytes($ivLength);

        $encrypted = openssl_encrypt(
            $plainText,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($encrypted === false) {
            throw new \RuntimeException('Encryption failed');
        }

        // Combine IV + encrypted data and base64 encode
        $combined = $iv . $encrypted;
        return base64_encode($combined);
    }

    /**
     * Decrypt data using AES-256-CBC
     */
    public function decrypt(string $encryptedData): string
    {
        // 1. Handle empty strings immediately to avoid unnecessary processing
        if ($encryptedData === '') {
            return $encryptedData;
        }

        // 2. Use strict base64 decoding. If it fails, it's plaintext.
        $combined = base64_decode($encryptedData, true);

        if ($combined === false) {
            // Return original instead of throwing an exception
            return $encryptedData;
        }

        $ivLength  = openssl_cipher_iv_length($this->cipher);

        // 3. Ensure the string is actually long enough to contain an IV + data
        if (strlen($combined) <= $ivLength) {
            // Return original instead of throwing an exception
            return $encryptedData;
        }

        $iv        = substr($combined, 0, $ivLength);
        $encrypted = substr($combined, $ivLength);

        // 4. Use the @ operator to suppress PHP warnings if the IV is mangled
        $decrypted = @openssl_decrypt(
            $encrypted,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        // 5. Fallback to plaintext if decryption fails, rather than crashing the API
        if ($decrypted === false) {
            return $encryptedData;
        }

        return $decrypted;
    }

    /**
     * Create a deterministic hash for lookups (e.g., email lookup without decrypting)
     */
    public function hash(string $data): string
    {
        return hash('sha256', strtolower(trim($data)));
    }
}
