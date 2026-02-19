<?php

namespace App\Modules\Calendar\Models;

use App\Core\Database;

/**
 * CalendarEvent Model
 *
 * Fetches appointment-based calendar events for a tenant.
 * Supports:
 *   - Single date fetch  (getByDate)
 *   - Date-range fetch   (getByRange)
 *   - Tooltip detail     (getTooltipDetail)
 *   - Doctor-specific    (getByDoctor)
 *   - Monthly summary    (getMonthlySummary)
 */
class CalendarEvent
{
    private Database $db;

    /** Colour map: appointment status → hex colour for frontend calendar rendering */
    private const STATUS_COLORS = [
        'scheduled'       => '#3B82F6', // blue
        'arrived'         => '#F59E0B', // amber
        'in-consultation' => '#8B5CF6', // purple
        'completed'       => '#10B981', // green
        'cancelled'       => '#EF4444', // red
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  CORE SELECT FRAGMENT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Base SELECT used by every calendar query.
     * Returns columns needed both for list views and tooltip details.
     */
    private function baseSelect(): string
    {
        return "SELECT
                    a.id,
                    a.tenant_id,
                    a.patient_id,
                    a.doctor_id,
                    a.appointment_time,
                    a.reason,
                    a.status,
                    a.created_at,
                    a.updated_at,
                    p.name        AS patient_name,
                    p.phone       AS patient_phone,
                    p.email       AS patient_email,
                    u.username    AS doctor_name,
                    u.email       AS doctor_email
                FROM appointments a
                LEFT JOIN patients p ON a.patient_id = p.id
                LEFT JOIN users    u ON a.doctor_id  = u.id";
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  DATE / RANGE FETCHING
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get all calendar events for a single date.
     *
     * @param  int         $tenantId
     * @param  string      $date      Format: YYYY-MM-DD
     * @param  string|null $status    Optional status filter
     * @param  int|null    $doctorId  Optional doctor filter
     * @return array
     */
    public function getByDate(int $tenantId, string $date, ?string $status = null, ?int $doctorId = null): array
    {
        $where  = 'a.tenant_id = :tid AND DATE(a.appointment_time) = :date AND a.deleted_at IS NULL';
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

    /**
     * Get all calendar events within an inclusive date range.
     *
     * @param  int         $tenantId
     * @param  string      $startDate  Format: YYYY-MM-DD
     * @param  string      $endDate    Format: YYYY-MM-DD
     * @param  string|null $status     Optional status filter
     * @param  int|null    $doctorId   Optional doctor filter
     * @return array
     */
    public function getByRange(
        int $tenantId,
        string $startDate,
        string $endDate,
        ?string $status = null,
        ?int $doctorId = null
    ): array {
        $where  = 'a.tenant_id = :tid
                   AND DATE(a.appointment_time) >= :start
                   AND DATE(a.appointment_time) <= :end
                   AND a.deleted_at IS NULL';
        $params = ['tid' => $tenantId, 'start' => $startDate, 'end' => $endDate];

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

    // ─────────────────────────────────────────────────────────────────────────
    //  TOOLTIP DETAIL
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get the full tooltip detail for a single appointment.
     * Returns richer data than the list view (prescription count, notes, etc.).
     *
     * @param  int $appointmentId
     * @param  int $tenantId
     * @return array|null
     */
    public function getTooltipDetail(int $appointmentId, int $tenantId): ?array
    {
        $row = $this->db->fetch(
            $this->baseSelect() . "
            WHERE a.id = :id AND a.tenant_id = :tid AND a.deleted_at IS NULL",
            ['id' => $appointmentId, 'tid' => $tenantId]
        );

        if (!$row) {
            return null;
        }

        // Enrich with prescription count for this appointment
        $rxCount = $this->db->fetch(
            'SELECT COUNT(*) AS total FROM prescriptions
             WHERE appointment_id = :aid AND tenant_id = :tid',
            ['aid' => $appointmentId, 'tid' => $tenantId]
        );

        $event                       = $this->formatEvent($row);
        $event['prescription_count'] = (int) ($rxCount['total'] ?? 0);
        $event['tooltip']            = $this->buildTooltip($event);

        return $event;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  DOCTOR SCHEDULE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get a doctor's full schedule for a date range, grouped by date.
     *
     * @param  int    $doctorId
     * @param  int    $tenantId
     * @param  string $startDate
     * @param  string $endDate
     * @return array  Keyed by date string (YYYY-MM-DD)
     */
    public function getByDoctor(int $doctorId, int $tenantId, string $startDate, string $endDate): array
    {
        $events = $this->getByRange($tenantId, $startDate, $endDate, null, $doctorId);

        // Group by date for easy calendar consumption
        $grouped = [];
        foreach ($events as $event) {
            $date = substr($event['start'], 0, 10); // YYYY-MM-DD
            $grouped[$date][] = $event;
        }

        return $grouped;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  MONTHLY SUMMARY
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Aggregate appointment counts per day for a whole month.
     * Used for dot/badge indicators on monthly calendar grids.
     *
     * @param  int    $tenantId
     * @param  int    $year
     * @param  int    $month  1–12
     * @return array  [ 'YYYY-MM-DD' => [ 'total' => n, 'by_status' => [...] ], ... ]
     */
    public function getMonthlySummary(int $tenantId, int $year, int $month): array
    {
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate   = date('Y-m-t', strtotime($startDate)); // last day of month

        $rows = $this->db->fetchAll(
            'SELECT
                DATE(appointment_time)   AS day,
                status,
                COUNT(*)                 AS total
             FROM appointments
             WHERE tenant_id = :tid
               AND DATE(appointment_time) >= :start
               AND DATE(appointment_time) <= :end
               AND deleted_at IS NULL
             GROUP BY DATE(appointment_time), status
             ORDER BY day ASC, status ASC',
            ['tid' => $tenantId, 'start' => $startDate, 'end' => $endDate]
        );

        $summary = [];
        foreach ($rows as $row) {
            $day    = $row['day'];
            $status = $row['status'];
            $count  = (int) $row['total'];

            if (!isset($summary[$day])) {
                $summary[$day] = ['total' => 0, 'by_status' => []];
            }

            $summary[$day]['total']              += $count;
            $summary[$day]['by_status'][$status]  = $count;
        }

        return $summary;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Format a raw DB row into a FullCalendar-compatible event object.
     */
    private function formatEvent(array $row): array
    {
        $startDt = new \DateTime($row['appointment_time']);
        $endDt   = clone $startDt;
        $endDt->modify('+30 minutes');

        return [
            'id'             => (int) $row['id'],
            'title'          => $row['patient_name'] ?? 'Unknown Patient',
            'start'          => $startDt->format('Y-m-d\TH:i:s'),
            'end'            => $endDt->format('Y-m-d\TH:i:s'),
            'color'          => self::STATUS_COLORS[$row['status']] ?? '#6B7280',
            'status'         => $row['status'],
            'reason'         => $row['reason'],
            'patient_id'     => (int) $row['patient_id'],
            'patient_name'   => $row['patient_name'],
            'patient_phone'  => $row['patient_phone'] ?? null,
            'patient_email'  => $row['patient_email'] ?? null,
            'doctor_id'      => (int) $row['doctor_id'],
            'doctor_name'    => $row['doctor_name'],
            'doctor_email'   => $row['doctor_email'] ?? null,
            'extendedProps'  => [
                'reason'      => $row['reason'],
                'status'      => $row['status'],
                'doctor_name' => $row['doctor_name'],
                'created_at'  => $row['created_at'],
            ],
        ];
    }

    /**
     * Build a human-readable tooltip string for an event.
     */
    private function buildTooltip(array $event): string
    {
        $time   = (new \DateTime($event['start']))->format('h:i A');
        $lines  = [
            "🕐 {$time}",
            "👤 Patient: {$event['patient_name']}",
            "🩺 Doctor: {$event['doctor_name']}",
            "📋 Reason: " . ($event['reason'] ?? 'N/A'),
            "📌 Status: " . ucfirst($event['status']),
        ];

        if ($event['patient_phone']) {
            $lines[] = "📞 Phone: {$event['patient_phone']}";
        }

        if (isset($event['prescription_count']) && $event['prescription_count'] > 0) {
            $lines[] = "💊 Prescriptions: {$event['prescription_count']}";
        }

        return implode("\n", $lines);
    }
}