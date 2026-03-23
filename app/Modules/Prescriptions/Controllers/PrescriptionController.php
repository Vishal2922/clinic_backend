<?php

namespace App\Modules\Prescriptions\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\NotificationHelper;
use App\Core\Security\CryptoService;
use App\Modules\Prescriptions\Services\PrescriptionService;

class PrescriptionController extends Controller
{
    private PrescriptionService $service;
    private CryptoService $crypto;

    public function __construct()
    {
        $this->service = new PrescriptionService();
        $this->crypto  = new CryptoService();
    }

    private function logActivity($userId, $tenantId, $action, $details): void
    {
        if (function_exists('app_log')) {
            app_log("[AUDIT] user_id={$userId} tenant_id={$tenantId} action={$action} details={$details}");
        }
    }

    /**
     * GET /api/prescriptions
     * Role: Provider, Pharmacist, Admin  ($staff middleware)
     * Optional: ?patient_id=123  to filter by patient
     */
    public function index(Request $request): void
    {
        $tenantId  = $this->getTenantId();
        $patientId = $request->getQueryParam('patient_id')
            ? (int) $request->getQueryParam('patient_id')
            : null;

        try {
            $prescriptions = $this->service->listPrescriptions($tenantId, $patientId);

            Response::json([
                'status' => 'success',
                'data'   => $prescriptions,
                'total'  => count($prescriptions),
            ], 200);
        } catch (\Exception $e) {
            Response::json([
                'status'  => 'error',
                'message' => 'Failed to fetch prescriptions: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/prescriptions
     * Role: Provider only  ($providerOnly middleware)
     */
    public function store(Request $request): void
    {
        $authUser = $this->getAuthUser();
        $tenantId = $this->getTenantId();

        if (!$this->checkRole(['Provider'])) {
            Response::json(['status' => 'error', 'message' => 'Access Denied: Only Providers can create prescriptions'], 403);
            return;
        }

        $data = $request->getBody();

        $errors = $this->validate($data, [
            'patient_id'    => 'required|numeric',
            'medicine_name' => 'required|min:3',
            'dosage'        => 'required',
        ]);

        if (!empty($errors)) {
            Response::json(['status' => 'error', 'message' => 'Validation Failed', 'errors' => $errors], 422);
            return;
        }

        // AES-256 encrypt sensitive fields before DB storage
        $data['medicine_name'] = $this->crypto->encrypt($data['medicine_name']);
        $data['dosage']        = $this->crypto->encrypt($data['dosage']);

        if (!empty($data['notes'])) {
            $data['notes'] = $this->crypto->encrypt($data['notes']);
        }

        $data['tenant_id']   = $tenantId;
        $data['provider_id'] = $authUser['id'] ?? $authUser['user_id'];

        try {
            $id = $this->service->createPrescription($data);

            $this->logActivity($data['provider_id'], $tenantId, 'CREATE_PRESCRIPTION', "Prescription ID {$id} created.");

            // Fetch the full decrypted prescription so the frontend can display it immediately
            $prescription = $this->service->getPrescriptionById($id, $tenantId);

            // Notify all Pharmacists about the new prescription
            NotificationHelper::notifyRole(
                $tenantId,
                'Pharmacist',
                'prescription',
                '💊 New Prescription Awaiting Dispense',
                "Prescription #{$id} for Patient #{$data['patient_id']} — please review and dispense",
                'prescription',
                $id
            );

            Response::json([
                'status'       => 'success',
                'id'           => $id,
                'prescription' => $prescription,
                'message'      => 'Prescription created successfully',
            ], 201);
        } catch (\Exception $e) {
            Response::json(['status' => 'error', 'message' => 'Failed to create prescription: ' . $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/prescriptions/{id}
     * Role: Provider, Pharmacist, Admin  ($staff middleware)
     */
    public function update(Request $request, $id): void
    {
        $authUser = $this->getAuthUser();
        $tenantId = $this->getTenantId();

        if (!$this->checkRole(['Provider', 'Pharmacist', 'Admin'])) {
            Response::json(['status' => 'error', 'message' => 'Access Denied: Unauthorized role'], 403);
            return;
        }

        if (empty($id)) {
            Response::json(['status' => 'error', 'message' => 'Prescription ID is required.'], 400);
            return;
        }

        $data = $request->getBody();

        // Encrypt updated sensitive fields
        if (!empty($data['dosage'])) {
            $data['dosage'] = $this->crypto->encrypt($data['dosage']);
        }
        if (!empty($data['medicine_name'])) {
            $data['medicine_name'] = $this->crypto->encrypt($data['medicine_name']);
        }
        if (!empty($data['notes'])) {
            $data['notes'] = $this->crypto->encrypt($data['notes']);
        }

        try {
            $this->service->update(
                (int) $id,
                $tenantId,
                $data,
                $authUser['id'] ?? $authUser['user_id'],
                $authUser['role_name']
            );

            $this->logActivity($authUser['id'] ?? $authUser['user_id'], $tenantId, 'UPDATE_PRESCRIPTION', "Prescription ID {$id} updated.");

            // If status changed to dispensed, notify the original provider
            if (isset($data['status']) && $data['status'] === 'dispensed') {
                $rx = $this->service->getPrescriptionById((int) $id, $tenantId);
                if ($rx && !empty($rx['provider_id'])) {
                    NotificationHelper::notifyUser(
                        $tenantId,
                        (int) $rx['provider_id'],
                        'prescription',
                        '✅ Prescription Dispensed',
                        "Prescription #{$id} has been dispensed by pharmacist",
                        'prescription',
                        (int) $id
                    );
                }
            }

            Response::json(['status' => 'success', 'message' => 'Prescription updated successfully']);
        } catch (\Exception $e) {
            Response::json(['status' => 'error', 'message' => 'Update failed: ' . $e->getMessage()], 500);
        }
    }
}