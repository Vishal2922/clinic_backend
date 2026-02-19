<?php
namespace App\Modules\Appointments\Models;

use App\Core\Database;
use App\Core\Security\CryptoService;

class Appointment
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
        $row = $this->db->fetch(
            'SELECT a.*, p.encrypted_name as enc_patient_name, u.username as doctor_name
             FROM appointments a
             LEFT JOIN patients p ON a.patient_id = p.id
             LEFT JOIN users u ON a.doctor_id = u.id
             WHERE a.id = :id AND a.tenant_id = :tid AND a.deleted_at IS NULL',
            ['id' => $id, 'tid' => $tenantId]
        );
        return $row ? $this->decryptAppointment($row) : null;
    }

    public function getAllByTenant(int $tenantId, ?string $status = null, int $page = 1, int $perPage = 15): array
    {
        $offset = ($page - 1) * $perPage;
        $where = 'a.tenant_id = :tid AND a.deleted_at IS NULL';
        $params = ['tid' => $tenantId];

        if ($status) {
            $where .= ' AND a.status = :status';
            $params['status'] = $status;
        }

        $countResult = $this->db->fetch(
            "SELECT COUNT(*) as total FROM appointments a WHERE $where",
            $params
        );
        $total = (int) ($countResult['total'] ?? 0);

        $appointments = $this->db->fetchAll(
            "SELECT a.*, p.encrypted_name as enc_patient_name, u.username as doctor_name
             FROM appointments a
             LEFT JOIN patients p ON a.patient_id = p.id
             LEFT JOIN users u ON a.doctor_id = u.id
             WHERE $where
             ORDER BY a.appointment_time DESC
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $perPage, 'offset' => $offset])
        );

        $appointments = array_map([$this, 'decryptAppointment'], $appointments);

        return [
            'appointments' => $appointments,
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
            'INSERT INTO appointments (tenant_id, patient_id, doctor_id, appointment_time, encrypted_reason, status, created_at, updated_at)
             VALUES (:tenant_id, :patient_id, :doctor_id, :appointment_time, :enc_reason, :status, NOW(), NOW())',
            [
                'tenant_id'        => $data['tenant_id'],
                'patient_id'       => $data['patient_id'],
                'doctor_id'        => $data['doctor_id'],
                'appointment_time' => $data['appointment_time'],
                'enc_reason'       => isset($data['reason']) ? $this->crypto->encrypt($data['reason']) : null,
                'status'           => $data['status'] ?? 'scheduled',
            ]
        );
    }

    public function updateStatus(int $id, int $tenantId, string $status): bool
    {
        $affected = $this->db->execute(
            'UPDATE appointments SET status = :status, updated_at = NOW()
             WHERE id = :id AND tenant_id = :tid AND deleted_at IS NULL',
            ['status' => $status, 'id' => $id, 'tid' => $tenantId]
        );
        return $affected > 0;
    }

    public function softDelete(int $id, int $tenantId): bool
    {
        $affected = $this->db->execute(
            'UPDATE appointments SET deleted_at = NOW() WHERE id = :id AND tenant_id = :tid AND deleted_at IS NULL',
            ['id' => $id, 'tid' => $tenantId]
        );
        return $affected > 0;
    }

    public function getBookedSlotsForDoctor(int $doctorId, string $date): array
    {
        return $this->db->fetchAll(
            "SELECT appointment_time FROM appointments
             WHERE doctor_id = :did AND DATE(appointment_time) = :date
             AND status IN ('scheduled', 'arrived') AND deleted_at IS NULL",
            ['did' => $doctorId, 'date' => $date]
        );
    }

    public function hasConflict(int $doctorId, string $startTime, string $endTime): bool
    {
        $result = $this->db->fetch(
            "SELECT COUNT(*) as count FROM appointments
             WHERE doctor_id = :did
             AND status IN ('scheduled', 'arrived', 'in-consultation')
             AND deleted_at IS NULL
             AND (
                 (appointment_time >= :start AND appointment_time < :end)
                 OR (appointment_time <= :start2 AND DATE_ADD(appointment_time, INTERVAL 30 MINUTE) > :start3)
             )",
            ['did' => $doctorId, 'start' => $startTime, 'end' => $endTime,
             'start2' => $startTime, 'start3' => $startTime]
        );
        return (int) ($result['count'] ?? 0) > 0;
    }

    private function decryptAppointment(array $row): array
    {
        try {
            if (!empty($row['encrypted_reason'])) {
                $row['reason'] = $this->crypto->decrypt($row['encrypted_reason']);
            } else {
                $row['reason'] = null;
            }
            if (!empty($row['enc_patient_name'])) {
                $row['patient_name'] = $this->crypto->decrypt($row['enc_patient_name']);
            }
        } catch (\Exception $e) {
            $row['reason'] = '[encrypted]';
            app_log('Appointment decrypt error: ' . $e->getMessage(), 'ERROR');
        }
        unset($row['encrypted_reason'], $row['enc_patient_name']);
        return $row;
    }
}