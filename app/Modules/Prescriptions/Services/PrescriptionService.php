<?php

namespace App\Modules\Prescriptions\Services;

use App\Modules\Prescriptions\Models\Prescription;

/**
 * Prescription Service: Business logic layer.
 * Handles Multi-tenancy verification and Role-specific data mapping.
 */
class PrescriptionService {
    private $model;

    public function __construct() {
        $this->model = new Prescription();
    }

    /**
     * CREATE PRESCRIPTION
     * Controller-laye data encrypt aagi varuvadhala, inga mapping mattum panroam.
     */
    public function createPrescription($data) {
        return $this->model->create([
            'tenant_id'      => $data['tenant_id'],           // Multi-tenancy isolation
            'appointment_id' => $data['appointment_id'] ?? null, // Merged FIX: optional parameter
            'patient_id'     => $data['patient_id'],
            'provider_id'    => $data['provider_id'],         // From getAuthUser()
            'medicine_name'  => $data['medicine_name'],      // Already AES Encrypted in Controller
            'dosage'         => $data['dosage'],             // Already AES Encrypted in Controller
            'duration_days'  => $data['duration_days'] ?? 7,  // Merged FIX: default 7 days
            'notes'          => $data['notes'] ?? ''
        ]);
    }

    /**
     * UPDATE PRESCRIPTION
     * Handles Status tracking and Pharmacist ID mapping.
     */
    public function update($id, $tenant_id, $data, $userId, $userRole) {
        // 1. Tenant Isolation Check: Verify prescription belongs to the current clinic
        $prescription = $this->model->findById($id, $tenant_id);
        if (!$prescription) {
            throw new \Exception("Prescription not found or unauthorized access vro!");
        }

        // 2. Build Update Payload
        $updateData = [];
        
        // Controller-la irundhu encrypted dosage vandhaa mattum update list-la serkiroam
        if (isset($data['dosage'])) {
            $updateData['dosage'] = $data['dosage'];
        }

        // 3. Status mapping
        $updateData['status'] = $data['status'] ?? 'dispensed';
        
        /**
         * ROLE-BASED TRACKING:
         * User role 'Pharmacist'-aa irundha, avanga ID-ai 'pharmacist_id' column-la track panroam.
         * Ithu AUDIT purposes-ku romba mukkiam.
         */
        if ($userRole === 'Pharmacist') {
            $updateData['pharmacist_id'] = $userId;
        } else {
            // Provider or Admin update panna existing pharmacist_id-ai retain panna null handle panroam
            $updateData['pharmacist_id'] = $prescription['pharmacist_id'] ?? null;
        }

        return $this->model->updateStatus($id, $tenant_id, $updateData);
    }
}