<?php

namespace App\Modules\Prescriptions\Services;

use App\Modules\Prescriptions\Models\Prescription;

/**
 * PrescriptionService: Fixed Version.
 * Bugs Fixed:
 * 1. createPrescription() returned the result of $this->model->create() which returns
 *    the insert ID (int), but the controller uses it as `$id` and calls Prescription ID {$id} in logs.
 *    This is actually correct — documented for clarity.
 * 2. update() sets $updateData['status'] = $data['status'] ?? 'dispensed' — but if a Provider
 *    updates without sending 'status', it defaults to 'dispensed', potentially overriding
 *    a 'pending' prescription incorrectly. Fixed to only update status if explicitly provided.
 * 3. updateStatus() in model always updates dosage column even when not provided in update payload,
 *    which could overwrite an encrypted dosage with NULL. Fixed by making dosage update conditional.
 */
class PrescriptionService
{
    private Prescription $model;

    public function __construct()
    {
        $this->model = new Prescription();
    }

    /**
     * Create a new prescription.
     * Returns the new prescription ID.
     */
    public function createPrescription(array $data): int
    {
        return $this->model->create([
            'tenant_id'      => $data['tenant_id'],
            'appointment_id' => $data['appointment_id'] ?? null,
            'patient_id'     => $data['patient_id'],
            'provider_id'    => $data['provider_id'],
            'medicine_name'  => $data['medicine_name'],  // AES encrypted by controller
            'dosage'         => $data['dosage'],          // AES encrypted by controller
            'duration_days'  => $data['duration_days'] ?? 7,
            'notes'          => $data['notes'] ?? '',
        ]);
    }

    /**
     * Update a prescription.
     * FIX #2: Status only updated if explicitly provided in request.
     */
    public function update(int $id, int $tenantId, array $data, int $userId, string $userRole): int
    {
        $prescription = $this->model->findById($id, $tenantId);
        if (!$prescription) {
            throw new \RuntimeException('Prescription not found or unauthorized access!');
        }

        $updateData = [];

        if (isset($data['dosage'])) {
            $updateData['dosage'] = $data['dosage'];
        }

        if (isset($data['medicine_name'])) {
            $updateData['medicine_name'] = $data['medicine_name'];
        }

        // FIX #2: Only update status if explicitly provided, not defaulting to 'dispensed'
        if (isset($data['status'])) {
            $updateData['status'] = $data['status'];
        }

        // Role-based tracking: Pharmacist records their own ID
        if ($userRole === 'Pharmacist') {
            $updateData['pharmacist_id'] = $userId;
        } else {
            $updateData['pharmacist_id'] = $prescription['pharmacist_id'] ?? null;
        }

        return $this->model->updatePrescription($id, $tenantId, $updateData);
    }
}