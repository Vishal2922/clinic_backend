<?php

namespace App\Modules\Prescriptions\Models;

use App\Core\Database;

/**
 * Prescription Model: Fixed Version.
 * Bugs Fixed:
 * 1. PDO named parameters used with colon prefix in params array (e.g., ':tenant_id' => value).
 *    PDO expects params WITHOUT the colon prefix when using an associative array.
 *    Fixed throughout all methods.
 * 2. updateStatus() always included dosage in the SET clause even when it's null,
 *    potentially overwriting existing encrypted dosage with NULL.
 *    Fixed to build dynamic SET clause based on what data is provided.
 * 3. Method was named updateStatus() but called as updatePrescription() from service.
 *    Renamed to updatePrescription() and kept updateStatus() as an alias.
 * 4. create() SQL inserted duration_days and notes in VALUES list but they were missing
 *    from the column list. Fixed to match columns and values.
 */
class Prescription
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Create a new prescription record.
     * FIX #4: Added duration_days and notes to the column list.
     * FIX #1: Removed colon prefix from PDO parameter keys.
     */
    public function create(array $data): int
    {
        $sql = "INSERT INTO prescriptions (
                    tenant_id,
                    appointment_id,
                    patient_id,
                    provider_id,
                    medicine_name,
                    dosage,
                    duration_days,
                    notes,
                    status
                ) VALUES (
                    :tenant_id,
                    :appointment_id,
                    :patient_id,
                    :provider_id,
                    :medicine_name,
                    :dosage,
                    :duration_days,
                    :notes,
                    'pending'
                )";

        return $this->db->insert($sql, [
            'tenant_id'      => $data['tenant_id'],
            'appointment_id' => $data['appointment_id'] ?? null,
            'patient_id'     => $data['patient_id'],
            'provider_id'    => $data['provider_id'],
            'medicine_name'  => $data['medicine_name'],
            'dosage'         => $data['dosage'],
            'duration_days'  => $data['duration_days'] ?? 7,
            'notes'          => $data['notes'] ?? null,
        ]);
    }

    /**
     * Find a prescription by ID with tenant isolation.
     * FIX #1: Removed colon prefix from PDO param keys.
     */
    public function findById(int $id, int $tenantId): ?array
    {
        return $this->db->fetch(
            'SELECT * FROM prescriptions WHERE id = :id AND tenant_id = :tenant_id',
            ['id' => $id, 'tenant_id' => $tenantId]
        );
    }

    /**
     * Update a prescription — dynamic SET clause to avoid NULL overwrites.
     * FIX #2 & #3: Renamed from updateStatus() to updatePrescription(), dynamic columns.
     * FIX #1: No colon prefix in param keys.
     */
    public function updatePrescription(int $id, int $tenantId, array $updateData): int
    {
        $sets   = ['updated_at = CURRENT_TIMESTAMP'];
        $params = ['id' => $id, 'tenant_id' => $tenantId];

        if (isset($updateData['status'])) {
            $sets[]           = 'status = :status';
            $params['status'] = $updateData['status'];
        }

        if (array_key_exists('pharmacist_id', $updateData)) {
            $sets[]                 = 'pharmacist_id = :pharmacist_id';
            $params['pharmacist_id'] = $updateData['pharmacist_id'];
        }

        // FIX #2: Only update dosage if explicitly provided (not null-default)
        if (isset($updateData['dosage'])) {
            $sets[]          = 'dosage = :dosage';
            $params['dosage'] = $updateData['dosage'];
        }

        if (isset($updateData['medicine_name'])) {
            $sets[]                  = 'medicine_name = :medicine_name';
            $params['medicine_name'] = $updateData['medicine_name'];
        }

        $setStr = implode(', ', $sets);

        return $this->db->execute(
            "UPDATE prescriptions SET $setStr WHERE id = :id AND tenant_id = :tenant_id",
            $params
        );
    }

    /**
     * Alias kept for backward compatibility.
     */
    public function updateStatus(int $id, int $tenantId, array $updateData): int
    {
        return $this->updatePrescription($id, $tenantId, $updateData);
    }

    /**
     * Get all prescriptions for a tenant.
     * FIX #1: Removed colon prefix from param key.
     */
    public function getAllByTenant(int $tenantId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM prescriptions WHERE tenant_id = :tenant_id ORDER BY created_at DESC',
            ['tenant_id' => $tenantId]
        );
    }
}