<?php

namespace App\Modules\Calendar\Services;

use App\Modules\Calendar\Models\CalendarEvent;

/**
 * CalendarService
 *
 * Orchestrates calendar data queries and applies business rules:
 *   - Validates date formats and ranges
 *   - Guards against overly-wide ranges (performance safety)
 *   - Normalises output for consistent API responses
 */
class CalendarService
{
    /** Maximum allowed range in days for a single fetch (prevents table scans) */
    private const MAX_RANGE_DAYS = 90;

    private CalendarEvent $model;

    public function __construct()
    {
        $this->model = new CalendarEvent();
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PUBLIC API METHODS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return events for a single date.
     *
     * @throws \InvalidArgumentException on bad date
     */
    public function getEventsForDate(
        int $tenantId,
        string $date,
        ?string $status = null,
        ?int $doctorId = null
    ): array {
        $this->assertValidDate($date);

        $events = $this->model->getByDate($tenantId, $date, $status, $doctorId);

        return [
            'date'   => $date,
            'count'  => count($events),
            'events' => $events,
        ];
    }

    /**
     * Return events for a date range.
     *
     * @throws \InvalidArgumentException on bad dates or excessive range
     */
    public function getEventsForRange(
        int $tenantId,
        string $startDate,
        string $endDate,
        ?string $status = null,
        ?int $doctorId = null
    ): array {
        $this->assertValidDate($startDate);
        $this->assertValidDate($endDate);
        $this->assertRangeOrder($startDate, $endDate);
        $this->assertRangeWidth($startDate, $endDate);

        $events = $this->model->getByRange($tenantId, $startDate, $endDate, $status, $doctorId);

        // Group by date for frontend convenience
        $grouped = [];
        foreach ($events as $event) {
            $day = substr($event['start'], 0, 10);
            $grouped[$day][] = $event;
        }

        return [
            'start_date'   => $startDate,
            'end_date'     => $endDate,
            'total_events' => count($events),
            'events'       => $events,
            'by_date'      => $grouped,
        ];
    }

    /**
     * Return full tooltip detail for a single appointment.
     */
    public function getTooltipDetail(int $appointmentId, int $tenantId): ?array
    {
        return $this->model->getTooltipDetail($appointmentId, $tenantId);
    }

    /**
     * Return a doctor's schedule grouped by date.
     *
     * @throws \InvalidArgumentException on bad dates / excessive range
     */
    public function getDoctorSchedule(
        int $doctorId,
        int $tenantId,
        string $startDate,
        string $endDate
    ): array {
        $this->assertValidDate($startDate);
        $this->assertValidDate($endDate);
        $this->assertRangeOrder($startDate, $endDate);
        $this->assertRangeWidth($startDate, $endDate);

        $grouped = $this->model->getByDoctor($doctorId, $tenantId, $startDate, $endDate);

        return [
            'doctor_id'  => $doctorId,
            'start_date' => $startDate,
            'end_date'   => $endDate,
            'schedule'   => $grouped,
        ];
    }

    /**
     * Return per-day aggregated counts for a calendar month.
     *
     * @throws \InvalidArgumentException on bad year/month
     */
    public function getMonthlySummary(int $tenantId, int $year, int $month): array
    {
        if ($year < 2000 || $year > 2100) {
            throw new \InvalidArgumentException("Year must be between 2000 and 2100.");
        }
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException("Month must be between 1 and 12.");
        }

        $summary = $this->model->getMonthlySummary($tenantId, $year, $month);

        return [
            'year'    => $year,
            'month'   => $month,
            'summary' => $summary,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  VALIDATION HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function assertValidDate(string $date): void
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException("Invalid date format: '{$date}'. Expected YYYY-MM-DD.");
        }
    }

    private function assertRangeOrder(string $startDate, string $endDate): void
    {
        if (strtotime($startDate) > strtotime($endDate)) {
            throw new \InvalidArgumentException("start_date must not be after end_date.");
        }
    }

    private function assertRangeWidth(string $startDate, string $endDate): void
    {
        $diffDays = (int) round(
            (strtotime($endDate) - strtotime($startDate)) / 86400
        );

        if ($diffDays > self::MAX_RANGE_DAYS) {
            throw new \InvalidArgumentException(
                "Date range too wide. Maximum allowed: " . self::MAX_RANGE_DAYS . " days."
            );
        }
    }
}