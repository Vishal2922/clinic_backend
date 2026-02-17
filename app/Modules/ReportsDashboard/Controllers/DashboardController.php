<?php
namespace App\Modules\ReportsDashboard\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Modules\ReportsDashboard\Models\DashboardStats;

class DashboardController extends Controller {
    private $model;

    public function __construct() {
        $this->model = new DashboardStats();
    }

    /**
     * AES ENCRYPTION HELPERS: Conflict varaama irukka controller-kulla helper methods-aa vachukalaam.
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
     * PRIVATE HELPER: Manual JWT Validation (No Firebase Library Needed)
     */
    private function getValidatedUser($requiredRoles) {
        $headers = getallheaders();
        
        if (!isset($headers['Authorization'])) {
            Response::json(['status' => 'error', 'message' => 'Authorization token not found'], 401);
            exit();
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            Response::json(['status' => 'error', 'message' => 'Invalid token structure'], 401);
            exit();
        }

        list($header, $payload, $signature) = $parts;

        $secretKey = $_ENV['JWT_SECRET'] ?? null;
        if (!$secretKey) {
            Response::json(['status' => 'error', 'message' => 'System configuration error: JWT_SECRET missing'], 500);
            exit();
        }

        $validSignature = hash_hmac('sha256', "$header.$payload", $secretKey, true);
        $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($validSignature));

        if ($signature !== $base64Signature) {
            Response::json(['status' => 'error', 'message' => 'Unauthorized: Signature mismatch'], 401);
            exit();
        }

        $decodedData = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $payload)));

        $roles = is_array($requiredRoles) ? $requiredRoles : [$requiredRoles];
        
        if (!isset($decodedData->role) || !in_array($decodedData->role, $roles)) {
            Response::json([
                'status' => 'error', 
                'message' => 'Access Denied: You do not have permission to view Dashboard'
            ], 403);
            exit();
        }

        return $decodedData;
    }

    public function index(Request $request) {
        // Step 1: Scenario implementation - Allow only Provider or Admin
        $user = $this->getValidatedUser(['Provider', 'Admin']);

        try {
            // Step 2: Tenant isolation using request/token data
            $tenant_id = $request->tenant_id ?? 1;
            
            // Step 3: Fetching aggregate stats through the model
            $stats = $this->model->getCounts($tenant_id);
            
            // Note: Intha stats-la sensitive strings edhavadhu irundha, 
            // neenga $this->decryptData() use panni dashboard-la kaattalaam.

            Response::json([
                'status' => 'success', 
                'data' => $stats,
                'accessed_by' => $user->role
            ]);
        } catch (\Exception $e) {
            Response::json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}