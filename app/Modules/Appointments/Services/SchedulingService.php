<?php

namespace App\Modules\Appointments\Services;

use App\Modules\Appointments\Models\Appointment;

/**
 * SchedulingService: Fixed Version.
 *
 * Bugs Fixed:
 * 1. Used Carbon (Laravel date library) — not available in this framework.
 *    Replaced with PHP's native DateTime.
 * 2. Used Eloquent query builder (Appointment::where()->whereIn()->where()->exists()).
 *    Replaced with custom Appointment model methods.
 * 3. Used Illuminate\Support\Facades\Log — replaced with app_log().
 * 4. pluck()->map()->toArray() — Eloquent collection methods. Replaced with array_column + array_map.
 */
class SchedulingService
{
    protected int $slotDuration = 30; // minutes

    private Appointment $appointmentModel;

    public function __construct()
    {
        $this->appointmentModel = new Appointment();
    }

    /**
     * Check if a time slot is available for a doctor.
     * Returns true if available, false if there's a conflict.
     */
    public function isSlotAvailable(int $doctorId, string $requestedTime): bool
    {
        $startTime = new \DateTime($requestedTime);
        $endTime   = clone $startTime;
        $endTime->modify("+{$this->slotDuration} minutes");

        return !$this->appointmentModel->hasConflict(
            $doctorId,
            $startTime->format('Y-m-d H:i:s'),
            $endTime->format('Y-m-d H:i:s')
        );
    }

    /**
     * Generate available time slots for a doctor on a given date.
     */
    public function generateDoctorSchedule(int $doctorId, string $date): array
    {
        $startTime = new \DateTime("{$date} 09:00:00");
        $endTime   = new \DateTime("{$date} 18:00:00");

        // Get already booked slots
        $bookedRows  = $this->appointmentModel->getBookedSlotsForDoctor($doctorId, $date);
        $bookedTimes = array_map(function ($row) {
            return (new \DateTime($row['appointment_time']))->format('H:i');
        }, $bookedRows);

        $schedule = [];
        $current  = clone $startTime;

        while ($current < $endTime) {
            $slotTime = $current->format('H:i');

            $schedule[] = [
                'time'      => $slotTime,
                'available' => !in_array($slotTime, $bookedTimes),
                'meridiem'  => $current->format('A'),
            ];

            $current->modify("+{$this->slotDuration} minutes");
        }

        return $schedule;
    }

    /**
     * Reschedule an existing appointment to a new time slot.
     */
    public function reschedule(int $appointmentId, int $tenantId, string $newTime): array
    {
        $appointment = $this->appointmentModel->findById($appointmentId, $tenantId);

        if (!$appointment) {
            throw new \RuntimeException('Appointment not found.');
        }

        if (!$this->isSlotAvailable((int) $appointment['doctor_id'], $newTime)) {
            throw new \RuntimeException('The newly requested time slot is already booked.');
        }

        // Update to new time and reset status
        $this->appointmentModel->updateStatus($appointmentId, $tenantId, 'scheduled');

        // Update appointment time via direct DB call
        $db = \App\Core\Database::getInstance();
        $db->execute(
            'UPDATE appointments SET appointment_time = :time, status = :status, updated_at = NOW()
             WHERE id = :id AND tenant_id = :tid',
            ['time' => $newTime, 'status' => 'scheduled', 'id' => $appointmentId, 'tid' => $tenantId]
        );

        app_log("Appointment Rescheduled: ID {$appointmentId} to {$newTime}");

        return $this->appointmentModel->findById($appointmentId, $tenantId);
    }
}