<?php

namespace App\Modules\Appointments\Services;

use App\Modules\Appointments\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SchedulingService
{
    /**
     * SLOT DURATION: Standard-ah 30 mins vachukalam.
     */
    protected $slotDuration = 30;

    /**
     * Logic: Slot Conflict Check
     * Doctor-ku vera appointment request panna time-la overlap aagutha nu check panrom.
     */
    public function isSlotAvailable(int $doctorId, $requestedTime): bool
    {
        $startTime = Carbon::parse($requestedTime);
        $endTime = (clone $startTime)->addMinutes($this->slotDuration);

        // Conflict check: overlaps with existing appointments
        $conflict = Appointment::where('doctor_id', $doctorId)
            ->whereIn('status', ['scheduled', 'arrived', 'in-consultation'])
            ->where(function ($query) use ($startTime, $endTime) {
                $query->where(function ($q) use ($startTime, $endTime) {
                    $q->where('appointment_time', '>=', $startTime)
                      ->where('appointment_time', '<', $endTime);
                })
                ->orWhere(function ($q) use ($startTime) {
                    $q->where('appointment_time', '<=', $startTime)
                      ->whereRaw('DATE_ADD(appointment_time, INTERVAL ? MINUTE) > ?', [$this->slotDuration, $startTime]);
                });
            })
            ->exists();

        return !$conflict;
    }

    /**
     * Logic: Daily Schedule Generator
     * Oru specific date-ku doctor-oda available slots list pannum.
     */
    public function generateDoctorSchedule(int $doctorId, string $date)
    {
        $startTime = Carbon::parse($date)->setHour(9)->setMinute(0); // Clinic opens at 9 AM
        $endTime = Carbon::parse($date)->setHour(18)->setMinute(0);  // Clinic closes at 6 PM
        
        $schedule = [];

        // Get already booked slots for that day
        $bookedSlots = Appointment::where('doctor_id', $doctorId)
            ->whereDate('appointment_time', $date)
            ->whereIn('status', ['scheduled', 'arrived'])
            ->pluck('appointment_time')
            ->map(fn($t) => $t->format('H:i'))
            ->toArray();

        while ($startTime < $endTime) {
            $slotTime = $startTime->format('H:i');
            
            $schedule[] = [
                'time' => $slotTime,
                'available' => !in_array($slotTime, $bookedSlots),
                'meridiem' => $startTime->format('A')
            ];

            $startTime->addMinutes($this->slotDuration);
        }

        return $schedule;
    }

    /**
     * Logic: Reschedule Appointment
     * Existing appointment-ah vera slot-ku mathurathu.
     */
    public function reschedule(int $appointmentId, string $newTime)
    {
        $appointment = Appointment::findOrFail($appointmentId);

        if (!$this->isSlotAvailable($appointment->doctor_id, $newTime)) {
            throw new \Exception("The newly requested time slot is already booked.");
        }

        $appointment->update([
            'appointment_time' => $newTime,
            'status' => 'scheduled' // Reset status if it was cancelled before
        ]);

        Log::info("Appointment Rescheduled: ID {$appointmentId} to {$newTime}");

        return $appointment;
    }
}