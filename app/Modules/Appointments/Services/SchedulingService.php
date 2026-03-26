<?php

namespace App\Modules\Appointments\Services;

use App\Modules\Appointments\Models\Appointment;

class SchedulingService
{
    protected int $slotDuration = 30;

    private Appointment $appointmentModel;

    public function __construct()
    {
        $this->appointmentModel = new Appointment();
    }

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

    public function generateDoctorSchedule(int $doctorId, string $date): array
    {
        $startTime = new \DateTime("{$date} 09:00:00");
        $endTime   = new \DateTime("{$date} 18:00:00");

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

    public function reschedule(int $appointmentId, int $tenantId, string $newTime): array
    {
        $appointment = $this->appointmentModel->findById($appointmentId, $tenantId);

        if (!$appointment) {
            throw new \RuntimeException('Appointment not found.');
        }

        if (!$this->isSlotAvailable((int) $appointment['doctor_id'], $newTime)) {
            throw new \RuntimeException('The newly requested time slot is already booked.');
        }

        $db = tenant_db();
        $db->execute(
            'UPDATE appointments SET appointment_time = :time, status = :status, updated_at = NOW()
             WHERE id = :id AND tenant_id = :tid',
            ['time' => $newTime, 'status' => 'scheduled', 'id' => $appointmentId, 'tid' => $tenantId]
        );

        app_log("Appointment Rescheduled: ID {$appointmentId} to {$newTime}");

        return $this->appointmentModel->findById($appointmentId, $tenantId);
    }
}