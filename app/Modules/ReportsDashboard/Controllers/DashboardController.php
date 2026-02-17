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
     * AUDIT LOG HELPER
     */
    private function logActivity($userId, $tenantId, $action, $details) {
        $logData = [
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'action' => $action,
            'details' => $this->encryptData($details),
            'timestamp' => date('Y-m-d H:i:s')
        ];
        // $this->model->saveAuditLog($logData);
    }

    /**
     * AES ENCRYPTION HELPERS
     */
    private function encryptData($data) {
        $encryption_key = $_ENV['APP_KEY'] ?? null; 
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $encryption_key, 0, $iv);
        return base64_encode($encrypted . '::' . $iv);
    }

    /**
     * PRIVATE HELPER: Manual JWT Validation with Robust Error Handling
     */
    private function getValidatedUser($requiredRoles) {
        $headers = getallheaders();
        
        // 1. SCENARIO: Header-la token-ae illa-naal (Unga requirement)
        if (!isset($headers['Authorization']) || empty($headers['Authorization'])) {
            Response::json([
                'status' => 'error', 
                'message' => 'Authorization header is missing. Access denied.' 
            ], 401);
            exit();
        }

        $authHeader = $headers['Authorization'];
        
        // 2. Format check (Bearer token structure check)
        if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            Response::json([
                'status' => 'error', 
                'message' => 'Invalid Authorization format. Use: Bearer <token>'
            ], 401);
            exit();
        }

        $token = $matches[1];
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

        // 3. Signature Verification
        $validSignature = hash_hmac('sha256', "$header.$payload", $secretKey, true);
        $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($validSignature));

        if ($signature !== $base64Signature) {
            Response::json(['status' => 'error', 'message' => 'Unauthorized: Signature mismatch'], 401);
            exit();
        }

        $decodedData = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $payload)));

        // 4. EXPIRATION CHECK
        if (isset($decodedData->exp) && $decodedData->exp < time()) {
            Response::json([
                'status' => 'error', 
                'message' => 'Token Expired: Please login again to continue'
            ], 401);
            exit();
        }

        // 5. Role Match Logic
        $roles = is_array($requiredRoles) ? $requiredRoles : [$requiredRoles];
        
        if (!isset($decodedData->role) || !in_array($decodedData->role, $roles)) {
            Response::json([
                'status' => 'error', 
                'message' => 'Access Denied: You do not have permission for this resource'
            ], 403);
            exit();
        }

        return $decodedData;
    }

    public function index(Request $request) {
        // Step 1: Validate User (Header + Expiry + Role)
        $user = $this->getValidatedUser(['Provider', 'Admin']);

        try {
            $tenant_id = $request->tenant_id ?? 1;
            $stats = $this->model->getCounts($tenant_id);
            
            // --- AUDIT LOGGING ---
            $this->logActivity($user->user_id, $tenant_id, 'VIEW_DASHBOARD', "Dashboard accessed by " . $user->role);

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