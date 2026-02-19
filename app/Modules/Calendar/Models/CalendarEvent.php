<?php
namespace App\Modules\Calendar\Models;

use App\Core\Database;
use App\Core\Security\CryptoService;

class CalendarEvent
{
    private Database $db;
    private CryptoService $crypto;

    private const STATUS_COLORS = [
        'scheduled'       => '#3B82F6',
        'arrived'         => '#F59E0B',
        'in-consultation' => '#8B5CF6',
        'completed'       => '#10B981',
        'cancelled'       => '#EF4444',
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->crypto = new CryptoService();
    }

    private function baseSelect(): string
    {
        return "SELECT
            a.id, a.tenant_id, a.patient_id, a.doctor_id,
            a.appointment_time, a.encrypted_reason, a.status,
            a.created_at, a.updated_at,
            p.encrypted_name AS enc_patient_name,
            p.encrypted_phone AS enc_patient_phone,
            p.encrypted_email AS enc_patient_email,
            u.username AS doctor_name,
            u.encrypted_email AS enc_doctor_email
        FROM appointments a
        LEFT JOIN patients p ON a.patient_id = p.id
        LEFT JOIN users u ON a.doctor_id = u.id";
    }

    public function getByDate(int $tenantId, string $date, ?string $status = null, ?int $doctorId = null): array
    {
        $where = 'a.tenant_id = :tid AND DATE(a.appointment_time) = :date AND a.deleted_at IS NULL';
        $params = ['tid' => $tenantId, 'date' => $date];

        if ($status) {
            $where .= ' AND a.status = :status';
            $params['status'] = $status;
        }
        if ($doctorId) {
            $where .= ' AND a.doctor_id = :doctor_id';
            $params['doctor_id'] = $doctorId;
        }

        $rows = $this->db->fetchAll(
            $this->baseSelect() . " WHERE {$where} ORDER BY a.appointment_time ASC",
            $params
        );
        return array_map([$this, 'formatEvent'], $rows);
    }

    public function getByRange(int $tenantId, string $startDate, string $endDate, ?string $status = null, ?int $doctorId = null): array
    {
        $where = 'a.tenant_id = :tid AND DATE(a.appointment_time) >= :start AND DATE(a.appointment_time) <= :end AND a.deleted_at IS NULL';
        $params = ['tid' => $tenantId, 'start' => $startDate, 'end' => $endDate];

        if ($status) { $where .= ' AND a.status = :status'; $params['status'] = $status; }
        if ($doctorId) { $where .= ' AND a.doctor_id = :doctor_id'; $params['doctor_id'] = $doctorId; }

        $rows = $this->db->fetchAll(
            $this->baseSelect() . " WHERE {$where} ORDER BY a.appointment_time ASC",
            $params
        );
        return array_map([$this, 'formatEvent'], $rows);
    }

    public function getTooltipDetail(int $appointmentId, int $tenantId): ?array
    {
        $row = $this->db->fetch(
            $this->baseSelect() . " WHERE a.id = :id AND a.tenant_id = :tid AND a.deleted_at IS NULL",
            ['id' => $appointmentId, 'tid' => $tenantId]
        );
        if (!$row) return null;

        $rxCount = $this->db->fetch(
            'SELECT COUNT(*) AS total FROM prescriptions WHERE appointment_id = :aid AND tenant_id = :tid',
            ['aid' => $appointmentId, 'tid' => $tenantId]
        );

        $event = $this->formatEvent($row);
        $event['prescription_count'] = (int) ($rxCount['total'] ?? 0);
        return $event;
    }

    public function getByDoctor(int $doctorId, int $tenantId, string $startDate, string $endDate): array
    {
        $events = $this->getByRange($tenantId, $startDate, $endDate, null, $doctorId);
        $grouped = [];
        foreach ($events as $event) {
            $date = substr($event['start'], 0, 10);
            $grouped[$date][] = $event;
        }
        return $grouped;
    }

    public function getMonthlySummary(int $tenantId, int $year, int $month): array
    {
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));

        $rows = $this->db->fetchAll(
            'SELECT DATE(appointment_time) AS day, status, COUNT(*) AS total
             FROM appointments
             WHERE tenant_id = :tid AND DATE(appointment_time) >= :start
             AND DATE(appointment_time) <= :end AND deleted_at IS NULL
             GROUP BY DATE(appointment_time), status ORDER BY day ASC',
            ['tid' => $tenantId, 'start' => $startDate, 'end' => $endDate]
        );

        $summary = [];
        foreach ($rows as $row) {
            $day = $row['day'];
            if (!isset($summary[$day])) {
                $summary[$day] = ['total' => 0, 'by_status' => []];
            }
            $summary[$day]['total'] += (int)$row['total'];
            $summary[$day]['by_status'][$row['status']] = (int)$row['total'];
        }
        return $summary;
    }

    private function formatEvent(array $row): array
    {
        $startDt = new \DateTime($row['appointment_time']);
        $endDt = clone $startDt;
        $endDt->modify('+30 minutes');

        // Decrypt fields
        $patientName = 'Unknown Patient';
        $reason = null;
        $patientPhone = null;
        $patientEmail = null;
        $doctorEmail = null;

        try {
            if (!empty($row['enc_patient_name'])) {
                $patientName = $this->crypto->decrypt($row['enc_patient_name']);
            }
            if (!empty($row['encrypted_reason'])) {
                $reason = $this->crypto->decrypt($row['encrypted_reason']);
            }
            if (!empty($row['enc_patient_phone'])) {
                $patientPhone = $this->crypto->decrypt($row['enc_patient_phone']);
            }
            if (!empty($row['enc_patient_email'])) {
                $patientEmail = $this->crypto->decrypt($row['enc_patient_email']);
            }
            if (!empty($row['enc_doctor_email'])) {
                $doctorEmail = $this->crypto->decrypt($row['enc_doctor_email']);
            }
        } catch (\Exception $e) {
            app_log('Calendar event decrypt error: ' . $e->getMessage(), 'ERROR');
        }

        return [
            'id'            => (int) $row['id'],
            'title'         => $patientName,
            'start'         => $startDt->format('Y-m-d\TH:i:s'),
            'end'           => $endDt->format('Y-m-d\TH:i:s'),
            'color'         => self::STATUS_COLORS[$row['status']] ?? '#6B7280',
            'status'        => $row['status'],
            'reason'        => $reason,
            'patient_id'    => (int) $row['patient_id'],
            'patient_name'  => $patientName,
            'patient_phone' => $patientPhone,
            'patient_email' => $patientEmail,
            'doctor_id'     => (int) $row['doctor_id'],
            'doctor_name'   => $row['doctor_name'],
            'doctor_email'  => $doctorEmail,
        ];
    }
}