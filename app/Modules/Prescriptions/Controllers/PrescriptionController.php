<?php
namespace App\Modules\Prescriptions\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Prescriptions\Services\PrescriptionService;

class PrescriptionController extends Controller {
    private $service;

    public function __construct() {
        $this->service = new PrescriptionService();
    }

    /**
     * AES ENCRYPTION HELPERS
     * Common file use panna conflict aagum-nu neenga sonna maadhiriye 
     * controller-kulla helper methods-aa vachukalaam.
     */
    private function encryptData($data) {
        $encryption_key = $_ENV['APP_KEY'] ?? null; 
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $encryption_key, 0, $iv);
        return base64_encode($encrypted . '::' . $iv);
    }

    private function decryptData($data) {
        $encryption_key = $_ENV['APP_KEY'] ?? null;
        list($encrypted_data, $iv) = explode('::', base64_decode($data), 2);
        return openssl_decrypt($encrypted_data, 'aes-256-cbc', $encryption_key, 0, $iv);
    }

    /**
     * PRIVATE HELPER: Library illama manual-aa token-ai decode panni validate pannum logic.
     */
    private function getValidatedUser($requiredRoles) {
        $headers = getallheaders();
        
        if (!isset($headers['Authorization'])) {
            Response::json(['status' => 'error', 'message' => 'Authorization token missing'], 401);
            exit();
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            Response::json(['status' => 'error', 'message' => 'Invalid token format'], 401);
            exit();
        }

        list($header, $payload, $signature) = $parts;

        $secretKey = $_ENV['JWT_SECRET'] ?? null;
        if (!$secretKey) {
            Response::json(['status' => 'error', 'message' => 'System error: JWT_SECRET not found'], 500);
            exit();
        }

        $validSignature = hash_hmac('sha256', "$header.$payload", $secretKey, true);
        $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($validSignature));

        if ($signature !== $base64Signature) {
            Response::json(['status' => 'error', 'message' => 'Unauthorized: Invalid signature'], 401);
            exit();
        }

        $decodedData = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $payload)));
        $roles = is_array($requiredRoles) ? $requiredRoles : [$requiredRoles];
        
        if (!isset($decodedData->role) || !in_array($decodedData->role, $roles)) {
            Response::json(['status' => 'error', 'message' => 'Access Denied: Required role missing'], 403);
            exit();
        }

        return $decodedData;
    }

    // Prescription Create: Only 'Provider' allowed
    public function store(Request $request) {
        $user = $this->getValidatedUser('Provider'); 
        $data = $request->getBody();

        // BODY DATA VALIDATION
        $errors = [];
        if (empty($data['patient_id'])) $errors[] = "Patient ID is required.";
        if (empty($data['medicine_name']) || strlen($data['medicine_name']) < 3) $errors[] = "Valid Medicine Name is required.";
        if (empty($data['dosage'])) $errors[] = "Dosage instructions are required.";
        if (!isset($data['duration_days']) || !is_numeric($data['duration_days']) || $data['duration_days'] <= 0) $errors[] = "Duration must be a positive number.";

        if (!empty($errors)) {
            Response::json(['status' => 'error', 'message' => 'Validation Failed', 'errors' => $errors], 422);
            exit();
        }

        // --- ENCRYPTION STEP ---
        // Sensitive healthcare data-vai encrypt pannurohm before saving
        $data['medicine_name'] = $this->encryptData($data['medicine_name']);
        $data['dosage'] = $this->encryptData($data['dosage']);

        $data['tenant_id'] = $request->tenant_id; 
        $data['provider_id'] = $user->user_id;

        try {
            $id = $this->service->createPrescription($data);
            Response::json(['status' => 'success', 'id' => $id, 'message' => 'Validated, Encrypted and Saved'], 201);
        } catch (\Exception $e) {
            Response::json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // Prescription Update: Both 'Provider' and 'Pharmacist' allowed
    public function update(Request $request) {
        $user = $this->getValidatedUser(['Provider', 'Pharmacist']);
        $data = $request->getBody();

        // Encrypt if updating sensitive info
        if (!empty($data['dosage'])) {
            $data['dosage'] = $this->encryptData($data['dosage']);
        }

        try {
            $this->service->update($data['id'], $request->tenant_id, $data, $user->user_id, $user->role);
            Response::json(['status' => 'success', 'message' => 'Prescription updated']);
        } catch (\Exception $e) {
            Response::json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}