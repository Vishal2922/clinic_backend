<?php

namespace App\Modules\Prescriptions\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security\CryptoService;
use App\Modules\Prescriptions\Services\PrescriptionService;

class PrescriptionController extends Controller 
{
    private PrescriptionService $service;
    private CryptoService $crypto;

    public function __construct() 
    {
        // Services-ah initialize panroam
        $this->service = new PrescriptionService();
        $this->crypto  = new CryptoService();
    }

    /**
     * AUDIT LOG HELPER: Security compliance-kaaga ella sensitive actions-aiyum log pannum.
     */
    private function logActivity($userId, $tenantId, $action, $details): void 
    {
        // Common helper function use panni audit trial maintain panroam
        if (function_exists('app_log')) {
            app_log("[AUDIT] user_id={$userId} tenant_id={$tenantId} action={$action} details={$details}");
        }
    }

    /**
     * POST /api/prescriptions
     * Role: Provider mattum thaan prescription create panna mudiyum.
     */
    public function store(Request $request): void 
    {
        // 1. AUTH & ROLE CHECK
        $authUser = $this->getAuthUser();
        $tenantId = $this->getTenantId();

        if (!$this->checkRole(['Provider'])) {
            Response::json(['status' => 'error', 'message' => 'Access Denied: Only Providers can create prescriptions'], 403);
            return;
        }

        $data = $request->getBody();

        // 2. VALIDATION LOGIC
        $errors = $this->validate($data, [
            'patient_id'    => 'required|numeric',
            'medicine_name' => 'required|min:3',
            'dosage'        => 'required'
        ]);

        if (!empty($errors)) {
            Response::json(['status' => 'error', 'message' => 'Validation Failed', 'errors' => $errors], 422);
            return;
        }

        // 3. AES ENCRYPTION: Sensitive fields-ah database-ku poradhukku munnadi encrypt panroam
        // CryptoService openSSL aes-256-cbc logic-ah handle pannum
        $data['medicine_name'] = $this->crypto->encrypt($data['medicine_name']);
        $data['dosage']        = $this->crypto->encrypt($data['dosage']);

        // 4. INJECT METADATA
        $data['tenant_id']   = $tenantId;
        $data['provider_id'] = $authUser['id'] ?? $authUser['user_id'];

        try {
            $id = $this->service->createPrescription($data);

            // Audit log create panroam
            $this->logActivity($data['provider_id'], $tenantId, 'CREATE_PRESCRIPTION', "Prescription ID {$id} created.");

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
    public function update(Request $request, $id): void 
    {
        $authUser = $this->getAuthUser();
        $tenantId = $this->getTenantId();
        
        // 1. AUTH & ROLE CHECK
        if (!$this->checkRole(['Provider', 'Pharmacist'])) {
            Response::json(['status' => 'error', 'message' => 'Access Denied: Unauthorized role'], 403);
            return;
        }

        if (empty($id)) {
            Response::json(['status' => 'error', 'message' => 'Prescription ID is required.'], 400);
            return;
        }

        $data = $request->getBody();

        // 2. ENCRYPT UPDATED FIELDS
        // Update-la sensitive fields vandha adhai encrypt panni service-ku anupuvom
        if (!empty($data['dosage'])) {
            $data['dosage'] = $this->crypto->encrypt($data['dosage']);
        }
        if (!empty($data['medicine_name'])) {
            $data['medicine_name'] = $this->crypto->encrypt($data['medicine_name']);
        }

        try {
            // 3. SERVICE CALL
            // Service level update logic with Role-based constraints
            $this->service->update(
                $id,
                $tenantId,
                $data,
                $authUser['id'] ?? $authUser['user_id'],
                $authUser['role_name']
            );

            $this->logActivity($authUser['id'] ?? $authUser['user_id'], $tenantId, 'UPDATE_PRESCRIPTION', "Prescription ID {$id} updated.");

            Response::json(['status' => 'success', 'message' => 'Prescription updated successfully']);
        } catch (\Exception $e) {
            Response::json(['status' => 'error', 'message' => 'Update failed: ' . $e->getMessage()], 500);
        }
    }
}