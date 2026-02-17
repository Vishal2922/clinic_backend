<?php
namespace App\Core\Security;

class CryptoService {
    // ⚠️ Keep these keys safe. If you change them later, you cannot decrypt old data.
    private $encrypt_method = "AES-256-CBC";
    private $secret_key = "MySuperSecretKeyForHospital123"; // Use a strong, random string
    private $secret_iv = "1234567890123456"; // Must be exactly 16 characters

    public function encrypt($string) {
        $key = hash('sha256', $this->secret_key);
        // IV - encrypt method AES-256-CBC expects 16 bytes
        $iv = substr(hash('sha256', $this->secret_iv), 0, 16);

        $output = openssl_encrypt($string, $this->encrypt_method, $key, 0, $iv);
        return base64_encode($output);
    }

    public function decrypt($string) {
        $key = hash('sha256', $this->secret_key);
        $iv = substr(hash('sha256', $this->secret_iv), 0, 16);

        $output = openssl_decrypt(base64_decode($string), $this->encrypt_method, $key, 0, $iv);
        return $output;
    }
}