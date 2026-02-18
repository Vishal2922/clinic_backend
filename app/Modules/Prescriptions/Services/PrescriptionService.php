<?php
namespace App\Modules\Prescriptions\Services;

use App\Modules\Prescriptions\Models\Prescription;

class PrescriptionService {
    private $model;

    public function __construct() {
        $this->model = new Prescription();
        // CryptoService dependency-ai remove pannitom, conflict varaama irukka.
    }

    /**
     * Module 5: Create logic
     * Note: Controller-laye data encrypt aagi varuvadhala, inga direct-aa save pannalaam.
     */
    public function createPrescription($data) {
        return $this->model->create([
            'tenant_id'      => $data['tenant_id'],    // Multi-tenancy isolation
            'appointment_id' => $data['appointment_id'],
            'patient_id'     => $data['patient_id'],
            'provider_id'    => $data['provider_id'],  // From getValidatedUser()
            'medicine_name'  => $data['medicine_name'], // Already AES Encrypted in Controller
            'dosage'         => $data['dosage'],        // Already AES Encrypted in Controller
            'duration_days'  => $data['duration_days'],
            'notes'          => $data['notes'] ?? ''
        ]);
    }

    /**
     * Update method for Status and Pharmacist tracking
     */
    public function update($id, $tenant_id, $data, $userId, $userRole) {
        // 1. Prescription unga clinic-u dhaan-nu verify panroam (Tenant Isolation)
        $prescription = $this->model->findById($id, $tenant_id);
        if (!$prescription) {
            throw new \Exception("Prescription not found or unauthorized access");
        }

        // 2. Data update logic
        $updateData = [];
        
        // Controller-la irundhu encrypted dosage vandhaa mattum edukkurohm
        if (isset($data['dosage'])) {
            $updateData['dosage'] = $data['dosage'];
        }

        // 3. Status matum Pharmacist ID tracking
        // Pharmacist dispense panna avanga ID-ai pharmacist_id column-la track panroam
        $updateData['status'] = $data['status'] ?? 'dispensed';
        
        if ($userRole === 'Pharmacist') {
            $updateData['pharmacist_id'] = $userId;
        }

        return $this->model->updateStatus($id, $tenant_id, $updateData);
    }
}