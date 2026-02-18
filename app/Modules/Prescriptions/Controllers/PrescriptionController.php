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
        $this->service = new PrescriptionService();
        $this->crypto  = new CryptoService();
    }

    /**
     * AUDIT LOG HELPER
     */
    private function logActivity($userId, $tenantId, $action, $details) {
        app_log("[AUDIT] user_id={$userId} tenant_id={$tenantId} action={$action} details={$details}");
    }

    /**
     * POST /api/prescriptions
     * Only Provider can create. AuthJWT + AuthorizeRole middleware already ran.
     */
    public function store(Request $request) {
        // Auth user comes from JWT middleware — no manual JWT needed
        $authUser = $this->getAuthUser();
        $tenantId = $this->getTenantId();
        $data     = $request->getBody();

        // Validation
        $errors = [];
        if (empty($data['patient_id']))
            $errors[] = "Patient ID is required.";
        if (empty($data['medicine_name']) || strlen($data['medicine_name']) < 3)
            $errors[] = "Valid Medicine Name is required (min 3 chars).";
        if (empty($data['dosage']))
            $errors[] = "Dosage instructions are required.";

        if (!empty($errors)) {
            $this->response->error('Validation Failed', 422, $errors);
            return;
        }

        // AES Encrypt sensitive fields before saving
        $data['medicine_name'] = $this->crypto->encrypt($data['medicine_name']);
        $data['dosage']        = $this->crypto->encrypt($data['dosage']);

        // Inject auth data
        $data['tenant_id']   = $tenantId;
        $data['provider_id'] = $authUser['user_id']; // FIX: was $user->user_id (wrong)

        try {
            $id = $this->service->createPrescription($data);

            $this->logActivity($authUser['user_id'], $tenantId, 'CREATE_PRESCRIPTION', "Prescription ID {$id} created.");

            $this->response->success(['id' => $id], 'Prescription created successfully', 201);
        } catch (\Exception $e) {
            app_log('Prescription create error: ' . $e->getMessage(), 'ERROR');
            $this->response->error('Failed to create prescription: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/prescriptions/{id}
     * Provider or Pharmacist can update. Route param {id} from api.php.
     */
    public function update(Request $request, string $id) {
        $authUser = $this->getAuthUser();
        $tenantId = $this->getTenantId(); // FIX: was $request->tenant_id (wrong)
        $data     = $request->getBody();

        if (empty($id)) {
            $this->response->error('Prescription ID is required.', 400);
            return;
        }

        // Encrypt dosage if being updated
        if (!empty($data['dosage'])) {
            $data['dosage'] = $this->crypto->encrypt($data['dosage']);
        }

        try {
            $this->service->update(
                $id,
                $tenantId,
                $data,
                $authUser['user_id'],
                $authUser['role_name'] // FIX: was $user->role (wrong field)
            );

            $this->logActivity($authUser['user_id'], $tenantId, 'UPDATE_PRESCRIPTION', "Prescription ID {$id} updated.");

            $this->response->success([], 'Prescription updated successfully');
        } catch (\Exception $e) {
            app_log('Prescription update error: ' . $e->getMessage(), 'ERROR');
            $this->response->error('Update failed: ' . $e->getMessage(), 500);
        }
    }
}