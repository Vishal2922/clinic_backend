<?php
namespace App\Modules\Patients\Models;

use App\Core\Database;
use App\Core\Security\CryptoService;

class Patient
{
    private Database $db;
    private CryptoService $crypto;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->crypto = new CryptoService();
    }

    public function findById(int $id, int $tenantId): ?array
    {
        $patient = $this->db->fetch(
            'SELECT * FROM patients WHERE id = :id AND tenant_id = :tid AND deleted_at IS NULL',
            ['id' => $id, 'tid' => $tenantId]
        );
        return $patient ? $this->decryptPatient($patient) : null;
    }

    public function getAllByTenant(int $tenantId, int $page = 1, int $perPage = 10): array
    {
        $offset = ($page - 1) * $perPage;

        $countResult = $this->db->fetch(
            'SELECT COUNT(*) as total FROM patients WHERE tenant_id = :tid AND deleted_at IS NULL',
            ['tid' => $tenantId]
        );
        $total = (int) ($countResult['total'] ?? 0);

        $patients = $this->db->fetchAll(
            'SELECT * FROM patients WHERE tenant_id = :tid AND deleted_at IS NULL
             ORDER BY created_at DESC LIMIT :limit OFFSET :offset',
            ['tid' => $tenantId, 'limit' => $perPage, 'offset' => $offset]
        );

        $patients = array_map([$this, 'decryptPatient'], $patients);

        return [
            'patients' => $patients,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
            ],
        ];
    }

    public function create(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO patients (tenant_id, encrypted_name, name_hash, encrypted_phone, phone_hash,
             encrypted_email, email_hash, encrypted_medical_history, status, created_at, updated_at)
             VALUES (:tenant_id, :enc_name, :name_hash, :enc_phone, :phone_hash,
             :enc_email, :email_hash, :enc_history, :status, NOW(), NOW())',
            [
                'tenant_id'   => $data['tenant_id'],
                'enc_name'    => $this->crypto->encrypt($data['name']),
                'name_hash'   => $this->crypto->hash($data['name']),
                'enc_phone'   => $this->crypto->encrypt($data['phone']),
                'phone_hash'  => $this->crypto->hash($data['phone']),
                'enc_email'   => isset($data['email']) ? $this->crypto->encrypt($data['email']) : null,
                'email_hash'  => isset($data['email']) ? $this->crypto->hash($data['email']) : null,
                'enc_history' => $this->crypto->encrypt($data['medical_history']),
                'status'      => $data['status'] ?? 'active',
            ]
        );
    }

    public function update(int $id, array $data, int $tenantId): bool
    {
        $sets = [];
        $params = ['id' => $id, 'tid' => $tenantId];

        if (isset($data['name'])) {
            $sets[] = 'encrypted_name = :enc_name';
            $sets[] = 'name_hash = :name_hash';
            $params['enc_name']  = $this->crypto->encrypt($data['name']);
            $params['name_hash'] = $this->crypto->hash($data['name']);
        }
        if (isset($data['phone'])) {
            $phone = preg_replace('/\D/', '', $data['phone']);
            $sets[] = 'encrypted_phone = :enc_phone';
            $sets[] = 'phone_hash = :phone_hash';
            $params['enc_phone']  = $this->crypto->encrypt($phone);
            $params['phone_hash'] = $this->crypto->hash($phone);
        }
        if (isset($data['email'])) {
            $sets[] = 'encrypted_email = :enc_email';
            $sets[] = 'email_hash = :email_hash';
            $params['enc_email']  = $this->crypto->encrypt($data['email']);
            $params['email_hash'] = $this->crypto->hash($data['email']);
        }
        if (isset($data['medical_history'])) {
            $sets[] = 'encrypted_medical_history = :enc_history';
            $params['enc_history'] = $this->crypto->encrypt($data['medical_history']);
        }
        if (isset($data['status'])) {
            $sets[] = 'status = :status';
            $params['status'] = $data['status'];
        }

        if (empty($sets)) {
            return false;
        }

        $sets[] = 'updated_at = NOW()';
        $setStr = implode(', ', $sets);

        $affected = $this->db->execute(
            "UPDATE patients SET $setStr WHERE id = :id AND tenant_id = :tid AND deleted_at IS NULL",
            $params
        );
        return $affected > 0;
    }

    public function softDelete(int $id, int $tenantId): bool
    {
        $affected = $this->db->execute(
            'UPDATE patients SET deleted_at = NOW(), status = :status
             WHERE id = :id AND tenant_id = :tid AND deleted_at IS NULL',
            ['status' => 'inactive', 'id' => $id, 'tid' => $tenantId]
        );
        return $affected > 0;
    }

    public function hasScheduledAppointments(int $patientId, int $tenantId): bool
    {
        $result = $this->db->fetch(
            'SELECT COUNT(*) as count FROM appointments
             WHERE patient_id = :pid AND tenant_id = :tid AND status = :status AND deleted_at IS NULL',
            ['pid' => $patientId, 'tid' => $tenantId, 'status' => 'scheduled']
        );
        return (int) ($result['count'] ?? 0) > 0;
    }

    public function search(?string $query, int $tenantId, int $page = 1, int $perPage = 15): array
    {
        $offset = ($page - 1) * $perPage;
        $params = ['tid' => $tenantId];
        $where = 'tenant_id = :tid AND deleted_at IS NULL';

        if ($query) {
            $queryHash = $this->crypto->hash($query);
            $where .= ' AND (name_hash = :q_hash OR phone_hash = :p_hash)';
            $params['q_hash'] = $queryHash;
            $params['p_hash'] = $queryHash;
        }

        $countResult = $this->db->fetch(
            "SELECT COUNT(*) as total FROM patients WHERE $where",
            $params
        );
        $total = (int) ($countResult['total'] ?? 0);

        $patients = $this->db->fetchAll(
            "SELECT * FROM patients WHERE $where ORDER BY created_at DESC LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $perPage, 'offset' => $offset])
        );

        $patients = array_map([$this, 'decryptPatient'], $patients);

        return [
            'patients' => $patients,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
            ],
        ];
    }

    private function decryptPatient(array $patient): array
    {
        try {
            if (!empty($patient['encrypted_name'])) {
                $patient['name'] = $this->crypto->decrypt($patient['encrypted_name']);
            }
            if (!empty($patient['encrypted_phone'])) {
                $patient['phone'] = $this->crypto->decrypt($patient['encrypted_phone']);
            }
            if (!empty($patient['encrypted_email'])) {
                $patient['email'] = $this->crypto->decrypt($patient['encrypted_email']);
            }
            if (!empty($patient['encrypted_medical_history'])) {
                $patient['medical_history'] = $this->crypto->decrypt($patient['encrypted_medical_history']);
            }
            if (!empty($patient['encrypted_dob'])) {
                $patient['dob'] = $this->crypto->decrypt($patient['encrypted_dob']);
            }
            if (!empty($patient['encrypted_address'])) {
                $patient['address'] = $this->crypto->decrypt($patient['encrypted_address']);
            }
            if (!empty($patient['encrypted_emergency_contact'])) {
                $patient['emergency_contact'] = $this->crypto->decrypt($patient['encrypted_emergency_contact']);
            }
        } catch (\Exception $e) {
            app_log('Patient decryption error ID ' . ($patient['id'] ?? '?') . ': ' . $e->getMessage(), 'ERROR');
        }

        unset(
            $patient['encrypted_name'],
            $patient['encrypted_phone'],
            $patient['encrypted_email'],
            $patient['encrypted_medical_history'],
            $patient['encrypted_dob'],
            $patient['encrypted_address'],
            $patient['encrypted_emergency_contact'],
            $patient['name_hash'],
            $patient['phone_hash'],
            $patient['email_hash']
        );

        return $patient;
    }
}