<?php
namespace App\Modules\Prescriptions\Models;

use App\Core\Security\CryptoService;

class Prescription
{
    private CryptoService $crypto;

    public function __construct()
    {
        $this->crypto = new CryptoService();
    }

    private function db(): \App\Core\TenantDatabase
    {
        return tenant_db();
    }

    // ── Safely decrypt — guards against null AND non-string values ────────────
    private function safeDecrypt($value): ?string
    {
        if ($value === null || $value === '' || !is_string($value)) {
            return null;
        }
        try {
            return $this->crypto->decrypt($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── Decrypt all encrypted fields on a prescription row ───────────────────
    private function decrypt(array $rx): array
    {
        $rx['medicine_name_plain'] = $this->safeDecrypt($rx['encrypted_medicine_name'] ?? null);
        $rx['dosage_plain']        = $this->safeDecrypt($rx['encrypted_dosage']        ?? null);
        $rx['notes_plain']         = $this->safeDecrypt($rx['encrypted_notes']         ?? null);

        $rx['patient_name']  = $this->safeDecrypt($rx['patient_enc_name']  ?? null)
                               ?? "Patient #{$rx['patient_id']}";
        $rx['provider_name'] = $this->safeDecrypt($rx['provider_enc_name'] ?? null)
                               ?? "Provider #{$rx['provider_id']}";

        unset($rx['patient_enc_name'], $rx['provider_enc_name']);

        return $rx;
    }

    // ── INSERT ────────────────────────────────────────────────────────────────
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

        return $this->db()->insert($sql, [
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

    // ── FIND BY ID ────────────────────────────────────────────────────────────
    public function findById(int $id, int $tenantId): ?array
    {
        $rx = $this->db()->fetch(
            'SELECT p.*,
                    pt.encrypted_name     AS patient_enc_name,
                    u.encrypted_full_name AS provider_enc_name
             FROM prescriptions p
             LEFT JOIN patients pt ON pt.id = p.patient_id AND pt.tenant_id = p.tenant_id
             LEFT JOIN users u     ON u.id  = p.provider_id
             WHERE p.id = :id AND p.tenant_id = :tenant_id',
            ['id' => $id, 'tenant_id' => $tenantId]
        );
        return $rx ? $this->decrypt($rx) : null;
    }

    // ── UPDATE ────────────────────────────────────────────────────────────────
    public function updatePrescription(int $id, int $tenantId, array $updateData): int
    {
        $sets   = ['updated_at = CURRENT_TIMESTAMP'];
        $params = ['id' => $id, 'tenant_id' => $tenantId];

        if (isset($updateData['status'])) {
            $sets[]           = 'status = :status';
            $params['status'] = $updateData['status'];
        }
        if (array_key_exists('pharmacist_id', $updateData)) {
            $sets[]                  = 'pharmacist_id = :pharmacist_id';
            $params['pharmacist_id'] = $updateData['pharmacist_id'];
        }
        if (isset($updateData['dosage'])) {
            $sets[]           = 'encrypted_dosage = :dosage';
            $params['dosage'] = $updateData['dosage'];
        }
        if (isset($updateData['medicine_name'])) {
            $sets[]                  = 'encrypted_medicine_name = :medicine_name';
            $params['medicine_name'] = $updateData['medicine_name'];
        }
        if (isset($updateData['notes'])) {
            $sets[]          = 'encrypted_notes = :notes';
            $params['notes'] = $updateData['notes'];
        }

        $setStr = implode(', ', $sets);

        return $this->db()->execute(
            "UPDATE prescriptions SET $setStr WHERE id = :id AND tenant_id = :tenant_id",
            $params
        );
    }

    // ── LIST for tenant (optional patient filter + pagination) ───────────────
    public function getAllByTenant(int $tenantId, ?int $patientId = null, int $page = 1, int $perPage = 10): array
    {
        $where  = 'p.tenant_id = :tenant_id';
        $params = ['tenant_id' => $tenantId];

        if ($patientId !== null) {
            $where               .= ' AND p.patient_id = :patient_id';
            $params['patient_id'] = $patientId;
        }

        $offset = ($page - 1) * $perPage;

        $countResult = $this->db()->fetch(
            "SELECT COUNT(*) as total FROM prescriptions p WHERE {$where}",
            $params
        );
        $total = (int) ($countResult['total'] ?? 0);

        $rows = $this->db()->fetchAll(
            "SELECT p.*,
                    pt.encrypted_name     AS patient_enc_name,
                    u.encrypted_full_name AS provider_enc_name
             FROM prescriptions p
             LEFT JOIN patients pt ON pt.id = p.patient_id AND pt.tenant_id = p.tenant_id
             LEFT JOIN users u     ON u.id  = p.provider_id
             WHERE {$where}
             GROUP BY p.id
             ORDER BY p.created_at DESC
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $perPage, 'offset' => $offset])
        );

        $rows = array_map([$this, 'decrypt'], $rows);

        return [
            'prescriptions' => $rows,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
            ],
        ];
    }
}