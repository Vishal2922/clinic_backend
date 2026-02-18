<?php

namespace App\Modules\Prescriptions\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security\CryptoService;
use App\Modules\Prescriptions\Services\PrescriptionService;

class PrescriptionController extends Controller {
    private $service;
    private $crypto;

    public function __construct() {
        // Services-ah initialize panroam
        $this->service = new PrescriptionService();
        $this->crypto  = new CryptoService();
    }

    /**
     * AUDIT LOG HELPER: Security compliance-kaaga ella sensitive actions-aiyum log pannum.
     */
    private function logActivity($userId, $tenantId, $action, $details) {
        // Common helper function (app/Helpers/functions.php-la irukkum)
        if (function_exists('app_log')) {
            app_log("[AUDIT] user_id={$userId} tenant_id={$tenantId} action={$action} details={$details}");
        }
    }

    /**
     * POST /api/prescriptions
     * Role: Provider mattum thaan prescription create panna mudiyum.
     */
    public function store(Request $request) {
        // Base Controller helpers vachi Auth user matum Tenant ID edukkurom
        $authUser = $this->getAuthUser();
        $tenantId = $this->getTenantId();
        $data     = $request->getBody();

        // 1. Validation Logic
        $errors = [];
        if (empty($data['patient_id'])) $errors[] = "Patient ID is required.";
        if (empty($data['medicine_name']) || strlen($data['medicine_name']) < 3) {
            $errors[] = "Valid Medicine Name is required (min 3 chars).";
        }
        if (empty($data['dosage'])) $errors[] = "Dosage instructions are required.";

        if (!empty($errors)) {
            // Merged Response helper use panroam
            return Response::json(['status' => 'error', 'message' => 'Validation Failed', 'errors' => $errors], 422);
        }

        // 2. AES ENCRYPTION: Sensitive fields-ah database-ku poradhukku munnadi encrypt panroam
        // CryptoService openSSL aes-256-cbc logic-ah handle pannum
        $data['medicine_name'] = $this->crypto->encrypt($data['medicine_name']);
        $data['dosage']        = $this->crypto->encrypt($data['dosage']);

        // 3. Inject Metadata
        $data['tenant_id']   = $tenantId;
        $data['provider_id'] = $authUser['user_id'];

        try {
            $id = $this->service->createPrescription($data);

            // Audit log create panroam
            $this->logActivity($authUser['user_id'], $tenantId, 'CREATE_PRESCRIPTION', "Prescription ID {$id} created.");

            Response::json([
                'status' => 'success', 
                'id' => $id, 
                'message' => 'Prescription encrypted and saved successfully'
            ], 201);
        } catch (\Exception $e) {
            Response::json(['status' => 'error', 'message' => 'Failed to create prescription: ' . $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/prescriptions/{id}
     * Role: Provider or Pharmacist can update.
     */
    public function update(Request $request, string $id) {
        $authUser = $this->getAuthUser();
        $tenantId = $this->getTenantId();
        $data     = $request->getBody();

        if (empty($id)) {
            return Response::json(['status' => 'error', 'message' => 'Prescription ID is required.'], 400);
        }

        // Encrypt sensitive fields if they are provided in the update
        if (!empty($data['dosage'])) {
            $data['dosage'] = $this->crypto->encrypt($data['dosage']);
        }
        if (!empty($data['medicine_name'])) {
            $data['medicine_name'] = $this->crypto->encrypt($data['medicine_name']);
        }

        try {
            // Service level update logic with Role-based constraints
            $this->service->update(
                $id,
                $tenantId,
                $data,
                $authUser['user_id'],
                $authUser['role_name']
            );

            $this->logActivity($authUser['user_id'], $tenantId, 'UPDATE_PRESCRIPTION', "Prescription ID {$id} updated.");

            Response::json(['status' => 'success', 'message' => 'Prescription updated successfully']);
        } catch (\Exception $e) {
            Response::json(['status' => 'error', 'message' => 'Update failed: ' . $e->getMessage()], 500);
        }
    }
}