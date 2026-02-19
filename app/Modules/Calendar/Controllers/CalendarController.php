<?php

namespace App\Modules\Calendar\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Calendar\Services\CalendarService;

/**
 * CalendarController
 *
 * Handles all Calendar API endpoints:
 *
 *   GET /api/calendar/events          → events for a single date
 *   GET /api/calendar/range           → events for a date range
 *   GET /api/calendar/tooltip/{id}    → tooltip detail for one appointment
 *   GET /api/calendar/doctor/{id}     → doctor schedule for a date range
 *   GET /api/calendar/monthly         → monthly summary (dot indicators)
 */
class CalendarController extends Controller
{
    private CalendarService $calendarService;

    public function __construct()
    {
        parent::__construct();
        $this->calendarService = new CalendarService();
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  GET /api/calendar/events?date=YYYY-MM-DD[&status=...][&doctor_id=...]
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return all appointments on a specific calendar date.
     *
     * Query params:
     *   date      (required) YYYY-MM-DD
     *   status    (optional) scheduled|arrived|in-consultation|completed|cancelled
     *   doctor_id (optional) integer
     */
    public function getByDate(Request $request): void
    {
        $tenantId = $this->getTenantId();
        $date     = $request->getQueryParam('date');

        if (!$date) {
            Response::error('Query parameter "date" is required (format: YYYY-MM-DD).', 422);
            return;
        }

        $status   = $request->getQueryParam('status')    ?: null;
        $doctorId = $request->getQueryParam('doctor_id') ? (int) $request->getQueryParam('doctor_id') : null;

        try {
            $result = $this->calendarService->getEventsForDate($tenantId, $date, $status, $doctorId);
            Response::json(['message' => 'Calendar events retrieved.', 'data' => $result], 200);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            app_log('CalendarController::getByDate error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to fetch calendar events.', 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  GET /api/calendar/range?start_date=...&end_date=...[&status=...][&doctor_id=...]
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return all appointments within a date range (max 90 days).
     *
     * Query params:
     *   start_date (required) YYYY-MM-DD
     *   end_date   (required) YYYY-MM-DD
     *   status     (optional)
     *   doctor_id  (optional)
     */
    public function getByRange(Request $request): void
    {
        $tenantId  = $this->getTenantId();
        $startDate = $request->getQueryParam('start_date');
        $endDate   = $request->getQueryParam('end_date');

        $errors = [];
        if (!$startDate) {
            $errors[] = 'Query parameter "start_date" is required (format: YYYY-MM-DD).';
        }
        if (!$endDate) {
            $errors[] = 'Query parameter "end_date" is required (format: YYYY-MM-DD).';
        }

        if (!empty($errors)) {
            Response::error(implode(' ', $errors), 422);
            return;
        }

        $status   = $request->getQueryParam('status')    ?: null;
        $doctorId = $request->getQueryParam('doctor_id') ? (int) $request->getQueryParam('doctor_id') : null;

        try {
            $result = $this->calendarService->getEventsForRange(
                $tenantId,
                $startDate,
                $endDate,
                $status,
                $doctorId
            );
            Response::json(['message' => 'Calendar range events retrieved.', 'data' => $result], 200);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            app_log('CalendarController::getByRange error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to fetch range events.', 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  GET /api/calendar/tooltip/{id}
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return rich tooltip data for a single appointment.
     *
     * Route param: id (appointment ID)
     */
    public function getTooltip(Request $request, string $id): void
    {
        $tenantId      = $this->getTenantId();
        $appointmentId = (int) $id;

        if ($appointmentId <= 0) {
            Response::error('Invalid appointment ID.', 422);
            return;
        }

        try {
            $detail = $this->calendarService->getTooltipDetail($appointmentId, $tenantId);

            if (!$detail) {
                Response::error('Appointment not found.', 404);
                return;
            }

            Response::json(['message' => 'Tooltip detail retrieved.', 'data' => $detail], 200);
        } catch (\Exception $e) {
            app_log('CalendarController::getTooltip error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to fetch tooltip detail.', 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  GET /api/calendar/doctor/{id}?start_date=...&end_date=...
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return a specific doctor's schedule grouped by date.
     *
     * Route param:   id (doctor/user ID)
     * Query params:  start_date, end_date (YYYY-MM-DD)
     */
    public function getDoctorSchedule(Request $request, string $id): void
    {
        $tenantId  = $this->getTenantId();
        $doctorId  = (int) $id;
        $startDate = $request->getQueryParam('start_date');
        $endDate   = $request->getQueryParam('end_date');

        if ($doctorId <= 0) {
            Response::error('Invalid doctor ID.', 422);
            return;
        }

        $errors = [];
        if (!$startDate) {
            $errors[] = '"start_date" is required (YYYY-MM-DD).';
        }
        if (!$endDate) {
            $errors[] = '"end_date" is required (YYYY-MM-DD).';
        }

        if (!empty($errors)) {
            Response::error(implode(' ', $errors), 422);
            return;
        }

        try {
            $result = $this->calendarService->getDoctorSchedule($doctorId, $tenantId, $startDate, $endDate);
            Response::json(['message' => "Doctor schedule retrieved.", 'data' => $result], 200);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            app_log('CalendarController::getDoctorSchedule error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to fetch doctor schedule.', 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  GET /api/calendar/monthly?year=YYYY&month=M
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return per-day appointment counts for a full calendar month.
     * Used to render dot/badge indicators on a monthly grid view.
     *
     * Query params: year (YYYY), month (1-12)
     */
    public function getMonthlySummary(Request $request): void
    {
        $tenantId = $this->getTenantId();

        $year  = (int) ($request->getQueryParam('year')  ?: date('Y'));
        $month = (int) ($request->getQueryParam('month') ?: date('n'));

        try {
            $result = $this->calendarService->getMonthlySummary($tenantId, $year, $month);
            Response::json(['message' => 'Monthly summary retrieved.', 'data' => $result], 200);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            app_log('CalendarController::getMonthlySummary error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to fetch monthly summary.', 500);
        }
    }
}