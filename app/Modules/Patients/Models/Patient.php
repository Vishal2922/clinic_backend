<?php

namespace App\Modules\Patients\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;

class Patient extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Mass assignable attributes.
     */
    protected $fillable = [
        'name',
        'phone',
        'email',
        'medical_history',
        'status' // active, inactive, etc.
    ];

    /**
     * AES Encryption Logic & Data Casting.
     * Laravel automatically handles encryption/decryption here.
     */
    protected function casts(): array
    {
        return [
            'medical_history' => 'encrypted', // AES-256 Encryption
            'email_verified_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * 🛡️ Custom Validation Rules (Model Level)
     * Controller-la use panna easy-ah irukkum.
     */
    public static function validationRules($id = null): array
    {
        return [
            'name'  => 'required|string|max:255',
            'phone' => [
                'required',
                'digits:10',
                'regex:/^[6-9]\d{9}$/', // Indian phone number format (Starts with 6-9)
                $id ? "unique:patients,phone,{$id}" : "unique:patients,phone"
            ],
            'email' => $id ? "nullable|email|unique:patients,email,{$id}" : "nullable|email|unique:patients,email",
            'medical_history' => 'required|string|min:10',
        ];
    }

    /**
     * 🔍 Local Scopes (Reusable DB Queries)
     * Usage: Patient::active()->get();
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    public function scopeSearchByPhone(Builder $query, string $phone): void
    {
        $query->where('phone', 'LIKE', "%{$phone}%");
    }

    /**
     * Relationships (If needed in future)
     */
    public function appointments()
    {
        return $this->hasMany(\App\Modules\Appointments\Models\Appointment::class);
    }
}