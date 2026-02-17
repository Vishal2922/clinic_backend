<?php
namespace App\Modules\Prescriptions\Services;

use App\Modules\Prescriptions\Models\Prescription;
use App\Core\Security\CryptoService;

class PrescriptionService {
    private $model;
    private $crypto;

    public function __construct() {
        $this->model = new Prescription();
        $this->crypto = new CryptoService();
    }

    // Module 5: Create logic with Encryption
    public function createPrescription($data) {
        $encryptedMedicines = $this->crypto->encrypt(json_encode($data['medicines']));
        
        return $this->model->create([
            'tenant_id' => $data['tenant_id'], // Multi-tenancy isolation
            'appointment_id' => $data['appointment_id'],
            'patient_id' => $data['patient_id'],
            'provider_id' => $data['provider_id'], // Seeded 'Provider' role user
            'medicines' => $encryptedMedicines,
            'notes' => $data['notes'] ?? ''
        ]);
    }

    /**
     * 🔥 FIX: Intha method thaan Controller-la irundhu call aaganum.
     * Pharmacist dispense panna pharmacist_id-ah track panroam.
     */
    public function update($id, $tenant_id, $data, $userId, $userRole) {
        // 1. Prescription unga clinic-u dhaan-nu verify panroam
        $prescription = $this->model->findById($id, $tenant_id);
        if (!$prescription) {
            throw new \Exception("Prescription not found or unauthorized access");
        }

        // 2. Medicines update panna thirumbavum encrypt pannanum
        if (isset($data['medicines'])) {
            $data['medicines'] = $this->crypto->encrypt(json_encode($data['medicines']));
        }

        // 3. Pharmacist roles-ah base panni status update
        $status = $data['status'] ?? 'dispensed';
        
        // Teammate seed panna 'Pharmacist' role-ah irundha ID-yah track panroam
        $pharmacistId = ($userRole === 'Pharmacist') ? $userId : null;

        return $this->model->updateStatus($id, $tenant_id, $status, $pharmacistId);
    }
}