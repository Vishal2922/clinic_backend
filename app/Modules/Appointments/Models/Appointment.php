<?php

namespace App\Modules\Appointments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class Appointment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'patient_id',
        'doctor_id',
        'appointment_time',
        'reason',
        'status' // scheduled, arrived, in-consultation, completed, cancelled
    ];

    /**
     * Date Casting: Appointment time-ah Carbon instance-ah mathiduvom.
     * Ithanal calculations (addMinutes, format) panna romba easy-ah irukkum.
     */
    protected function casts(): array
    {
        return [
            'appointment_time' => 'datetime',
            'deleted_at'       => 'datetime',
        ];
    }

    /**
     * 🔍 Query Scopes: Business logic queries-ah model-laye vechirukom.
     */

    // Innikki ulla appointments mattum edukka: Patient::today()->get();
    public function scopeToday(Builder $query)
    {
        return $query->whereDate('appointment_time', Carbon::today());
    }

    // Particular doctor-oda appointments:
    public function scopeForDoctor(Builder $query, $doctorId)
    {
        return $query->where('doctor_id', $doctorId);
    }

    // Upcoming appointments (future):
    public function scopeUpcoming(Builder $query)
    {
        return $query->where('appointment_time', '>', now())
                     ->where('status', 'scheduled');
    }

    /**
     * 🔗 Relationships
     */

    public function patient()
    {
        // Patient Module-oda Model-ah connect panrom
        return $this->belongsTo(\App\Modules\Patients\Models\Patient::class);
    }

    public function doctor()
    {
        // User model-la 'role' doctor-ah iruntha connect aagum
        return $this->belongsTo(\App\Models\User::class, 'doctor_id');
    }

    /**
     * 🛠️ Helper Logic (Accessor)
     * View-la use panna easy-ah irukkum (e.g., $appointment->time_formatted)
     */
    public function getTimeFormattedAttribute()
    {
        return $this->appointment_time->format('d-M-Y h:i A');
    }
}