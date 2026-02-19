<?php
namespace App\Modules\Prescriptions\Models;

use App\Core\Database;

class Prescription
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO prescriptions (
            tenant_id, appointment_id, patient_id, provider_id,
            encrypted_medicine_name, encrypted_dosage,
            duration_days, encrypted_notes, status
        ) VALUES (
            :tenant_id, :appointment_id, :patient_id, :provider_id,
            :medicine_name, :dosage,
            :duration_days, :notes, 'pending'
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

    public function findById(int $id, int $tenantId): ?array
    {
        return $this->db->fetch(
            'SELECT * FROM prescriptions WHERE id = :id AND tenant_id = :tenant_id',
            ['id' => $id, 'tenant_id' => $tenantId]
        );
    }

    public function updatePrescription(int $id, int $tenantId, array $updateData): int
    {
        $sets = ['updated_at = CURRENT_TIMESTAMP'];
        $params = ['id' => $id, 'tenant_id' => $tenantId];

        if (isset($updateData['status'])) {
            $sets[] = 'status = :status';
            $params['status'] = $updateData['status'];
        }
        if (array_key_exists('pharmacist_id', $updateData)) {
            $sets[] = 'pharmacist_id = :pharmacist_id';
            $params['pharmacist_id'] = $updateData['pharmacist_id'];
        }
        if (isset($updateData['dosage'])) {
            $sets[] = 'encrypted_dosage = :dosage';
            $params['dosage'] = $updateData['dosage'];
        }
        if (isset($updateData['medicine_name'])) {
            $sets[] = 'encrypted_medicine_name = :medicine_name';
            $params['medicine_name'] = $updateData['medicine_name'];
        }

        $setStr = implode(', ', $sets);

        return $this->db->execute(
            "UPDATE prescriptions SET $setStr WHERE id = :id AND tenant_id = :tenant_id",
            $params
        );
    }

    public function updateStatus(int $id, int $tenantId, array $updateData): int
    {
        return $this->updatePrescription($id, $tenantId, $updateData);
    }

    public function getAllByTenant(int $tenantId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM prescriptions WHERE tenant_id = :tenant_id ORDER BY created_at DESC',
            ['tenant_id' => $tenantId]
        );
    }
}