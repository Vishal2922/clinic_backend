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
     * Note: Data is encrypted in the Controller before reaching here.
     * This service maps the data and enforces default values.
     */
    public function createPrescription($data) {
        return $this->model->create([
            'tenant_id'      => $data['tenant_id'],           // Clinic isolation
            'appointment_id' => $data['appointment_id'] ?? null,
            'patient_id'     => $data['patient_id'],
            'provider_id'    => $data['provider_id'],         // The Doctor/Provider
            'medicine_name'  => $data['medicine_name'],       // AES Encrypted string
            'dosage'         => $data['dosage'],              // AES Encrypted string
            'duration_days'  => $data['duration_days'] ?? 7,  // Default to 1 week
            'notes'          => $data['notes'] ?? ''
        ]);
    }

    /**
     * UPDATE PRESCRIPTION
     * Handles Status tracking and Pharmacist ID mapping for Audit Logs.
     */
    public function update($id, $tenant_id, $data, $userId, $userRole) {
        // 1. Tenant Isolation Check: Verify prescription belongs to the current clinic
        $prescription = $this->model->findById($id, $tenant_id);
        if (!$prescription) {
            throw new \Exception("Prescription not found or unauthorized access!");
        }

        // 2. Build Update Payload
        $updateData = [];
        
        // If a new encrypted dosage is provided, include it in the update
        if (isset($data['dosage'])) {
            $updateData['dosage'] = $data['dosage'];
        }

        // 3. Status Mapping & Role-Based Tracking
        $updateData['status'] = $data['status'] ?? 'dispensed';
        
        /**
         * ROLE-BASED TRACKING:
         * If the user is a Pharmacist, we record their ID as the person who dispensed it.
         * This is vital for HIPAA/Security auditing.
         */
        if ($userRole === 'Pharmacist') {
            $updateData['pharmacist_id'] = $userId;
        } else {
            // Retain existing pharmacist if update is by an Admin/Provider
            $updateData['pharmacist_id'] = $prescription['pharmacist_id'] ?? null;
        }

        return $this->model->updateStatus($id, $tenant_id, $updateData);
    }
}