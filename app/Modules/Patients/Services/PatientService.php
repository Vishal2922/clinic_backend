<?php

namespace App\Modules\Patients\Services;

use App\Modules\Patients\Models\Patient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class PatientService
{
    /**
     * Business Logic: Create Patient with Transaction.
     * Inga thaan neenga extra logics (Welcome SMS/Email) add panna mudiyum.
     */
    public function createPatient(array $data): Patient
    {
        return DB::transaction(function () use ($data) {
            // Logic: Phone number-ah standardize panrom (removing spaces/dashes)
            $data['phone'] = preg_replace('/\D/', '', $data['phone']);
            
            $patient = Patient::create($data);

            Log::info("New Patient Registered: ID {$patient->id}");
            
            // Future-la inga Notification trigger pannalam: 
            // event(new PatientRegistered($patient));

            return $patient;
        });
    }

    /**
     * Business Logic: Update with "Dirty" check and Logging.
     */
    public function updatePatient(int $id, array $data): Patient
    {
        $patient = Patient::findOrFail($id);

        return DB::transaction(function () use ($patient, $data) {
            $patient->fill($data);

            if ($patient->isDirty()) {
                $patient->save();
                Log::info("Patient Data Updated: ID {$patient->id} by User: " . auth()->id());
            }

            return $patient;
        });
    }

    /**
     * Business Logic: Advanced Search Logic.
     * Controller-la simple query illama, complex search-ku ithu useful.
     */
    public function searchPatients(?string $query)
    {
        return Patient::when($query, function ($q) use ($query) {
            $q->where('name', 'LIKE', "%{$query}%")
              ->orWhere('phone', 'LIKE', "%{$query}%");
        })
        ->latest()
        ->paginate(15);
    }

    /**
     * Soft Delete with specific Business Condition.
     */
    public function deletePatient(int $id): bool
    {
        $patient = Patient::findOrFail($id);

        // Example Logic: Active appointments iruntha delete panna koodathu
        $hasAppointments = $patient->appointments()->where('status', 'scheduled')->exists();
        
        if ($hasAppointments) {
            throw new Exception("Intha patient-ku scheduled appointments irukku. Delete panna mudiyathu.");
        }

        return $patient->delete();
    }
}