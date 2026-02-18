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
        $combined = base64_decode($encryptedData);

        if ($combined === false) {
            throw new \RuntimeException('Invalid encrypted data format');
        }

        $ivLength  = openssl_cipher_iv_length($this->cipher);
        $iv        = substr($combined, 0, $ivLength);
        $encrypted = substr($combined, $ivLength);

        $decrypted = openssl_decrypt(
            $encrypted,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($decrypted === false) {
            throw new \RuntimeException('Decryption failed');
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