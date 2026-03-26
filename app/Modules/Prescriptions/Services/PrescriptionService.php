<?php

namespace App\Modules\Prescriptions\Services;

use App\Modules\Prescriptions\Models\Prescription;

class PrescriptionService
{
    private Prescription $model;

    public function __construct()
    {
        $this->model = new Prescription();
    }

    // ── List prescriptions for a tenant (optional patient filter + pagination) ───
    public function listPrescriptions(int $tenantId, ?int $patientId = null, int $page = 1, int $perPage = 10): array
    {
        return $this->model->getAllByTenant($tenantId, $patientId, $page, $perPage);
    }

    // ── Get a single prescription by ID (decrypted) ──────────────────────────
    public function getPrescriptionById(int $id, int $tenantId): ?array
    {
        return $this->model->findById($id, $tenantId);
    }

    // ── Create a new prescription. Returns the new prescription ID ───────────
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

    // ── Update a prescription ────────────────────────────────────────────────
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
        if (isset($data['notes'])) {
            $updateData['notes'] = $data['notes'];
        }
        // Only update status if explicitly provided — validate allowed values
        if (isset($data['status'])) {
            if (!in_array($data['status'], ['pending', 'dispensed'], true)) {
                throw new \RuntimeException('Invalid status. Allowed values: pending, dispensed');
            }
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