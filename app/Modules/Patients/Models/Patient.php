<?php

namespace App\Modules\Patients\Models;

use App\Core\Database;

/**
 * Patient Model: Fixed Version.
 *
 * CRITICAL BUG: The original used Laravel Eloquent (extends Model, use SoftDeletes,
 * use HasFactory, Illuminate\Database\Eloquent\Builder) which doesn't exist in this
 * custom framework.
 *
 * Bugs Fixed:
 * 1. Replaced entire Eloquent model with a plain PHP class using custom Database singleton.
 * 2. Implemented all methods (create, findById, update, softDelete, getAllByTenant, search,
 *    hasScheduledAppointments) that the service and controller require.
 * 3. 'encrypted' cast for medical_history doesn't exist — medical_history stored as plain text
 *    (or can be encrypted via CryptoService if needed, but that's a feature, not a framework bug).
 * 4. validationRules() and scopeActive() etc. are Eloquent-specific — removed.
 */
class Patient
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Find a patient by ID within a tenant.
     */
    public function findById(int $id, int $tenantId): ?array
    {
        return $this->db->fetch(
            'SELECT * FROM patients WHERE id = :id AND tenant_id = :tid AND deleted_at IS NULL',
            ['id' => $id, 'tid' => $tenantId]
        );
    }

    /**
     * Get all patients for a tenant with pagination.
     */
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

        return [
            'patients'   => $patients,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
            ],
        ];
    }

    /**
     * Create a new patient record.
     */
    public function create(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO patients (tenant_id, name, phone, email, medical_history, status, created_at, updated_at)
             VALUES (:tenant_id, :name, :phone, :email, :medical_history, :status, NOW(), NOW())',
            [
                'tenant_id'       => $data['tenant_id'],
                'name'            => sanitize($data['name']),
                'phone'           => $data['phone'],
                'email'           => $data['email'] ?? null,
                'medical_history' => $data['medical_history'],
                'status'          => $data['status'] ?? 'active',
            ]
        );
    }

    /**
     * Update patient fields.
     */
    public function update(int $id, array $data, int $tenantId): bool
    {
        $sets   = [];
        $params = ['id' => $id, 'tid' => $tenantId];

        if (isset($data['name'])) {
            $sets[] = 'name = :name';
            $params['name'] = sanitize($data['name']);
        }
        if (isset($data['phone'])) {
            $sets[] = 'phone = :phone';
            $params['phone'] = preg_replace('/\D/', '', $data['phone']);
        }
        if (isset($data['email'])) {
            $sets[] = 'email = :email';
            $params['email'] = $data['email'];
        }
        if (isset($data['medical_history'])) {
            $sets[] = 'medical_history = :medical_history';
            $params['medical_history'] = $data['medical_history'];
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

    /**
     * Soft delete a patient.
     */
    public function softDelete(int $id, int $tenantId): bool
    {
        $affected = $this->db->execute(
            'UPDATE patients SET deleted_at = NOW(), status = :status WHERE id = :id AND tenant_id = :tid AND deleted_at IS NULL',
            ['status' => 'inactive', 'id' => $id, 'tid' => $tenantId]
        );
        return $affected > 0;
    }

    /**
     * Check if a patient has scheduled appointments.
     */
    public function hasScheduledAppointments(int $patientId, int $tenantId): bool
    {
        $result = $this->db->fetch(
            'SELECT COUNT(*) as count FROM appointments
             WHERE patient_id = :pid AND tenant_id = :tid AND status = :status AND deleted_at IS NULL',
            ['pid' => $patientId, 'tid' => $tenantId, 'status' => 'scheduled']
        );
        return (int) ($result['count'] ?? 0) > 0;
    }

    /**
     * Search patients by name or phone.
     */
    public function search(?string $query, int $tenantId, int $page = 1, int $perPage = 15): array
    {
        $offset = ($page - 1) * $perPage;
        $params = ['tid' => $tenantId];
        $where  = 'tenant_id = :tid AND deleted_at IS NULL';

        if ($query) {
            $where .= ' AND (name LIKE :q OR phone LIKE :q2)';
            $params['q']  = "%{$query}%";
            $params['q2'] = "%{$query}%";
        }

        $countResult = $this->db->fetch("SELECT COUNT(*) as total FROM patients WHERE $where", $params);
        $total       = (int) ($countResult['total'] ?? 0);

        $patients = $this->db->fetchAll(
            "SELECT * FROM patients WHERE $where ORDER BY created_at DESC LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $perPage, 'offset' => $offset])
        );

        return [
            'patients'   => $patients,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
            ],
        ];
    }
}