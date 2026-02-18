<?php

namespace App\Modules\Patients\Services;

use App\Modules\Patients\Models\Patient;

/**
 * PatientService: Fixed Version.
 * Bugs Fixed:
 * 1. Used Laravel facades (DB::transaction, Log::info, auth()->id()) — replaced with
 *    custom framework equivalents (app_log, manual DB calls via model).
 * 2. Patient::findOrFail() — Eloquent method doesn't exist; replaced with findById() + manual check.
 * 3. $patient->appointments()->where()->exists() — Eloquent relation doesn't exist in custom model.
 *    Replaced with a model method call.
 * 4. Patient::create(), $patient->fill(), $patient->isDirty(), $patient->save() — all Eloquent.
 *    Replaced with custom model insert/update methods.
 * 5. Patient::when()->latest()->paginate() — Eloquent. Replaced with custom model search.
 */
class PatientService
{
    private Patient $model;

    public function __construct()
    {
        $this->model = new Patient();
    }

    /**
     * List patients for a tenant with pagination.
     */
    public function listPatients(int $tenantId, int $page = 1, int $perPage = 10): array
    {
        return $this->model->getAllByTenant($tenantId, $page, $perPage);
    }

    /**
     * Get a single patient by ID within a tenant.
     */
    public function getPatient(int $id, int $tenantId): ?array
    {
        return $this->model->findById($id, $tenantId);
    }

    /**
     * Create a patient.
     */
    public function createPatient(array $data, int $tenantId): array
    {
        // Normalize phone number
        $data['phone'] = preg_replace('/\D/', '', $data['phone']);
        $data['tenant_id'] = $tenantId;

        $id = $this->model->create($data);
        app_log("New Patient Registered: ID {$id}");

        return $this->model->findById($id, $tenantId);
    }

    /**
     * Update a patient record.
     */
    public function updatePatient(int $id, array $data, int $tenantId): array
    {
        $patient = $this->model->findById($id, $tenantId);
        if (!$patient) {
            throw new \RuntimeException('Patient not found');
        }

        $this->model->update($id, $data, $tenantId);
        app_log("Patient Data Updated: ID {$id}");

        return $this->model->findById($id, $tenantId);
    }

    /**
     * Soft-delete a patient after checking for active appointments.
     */
    public function deletePatient(int $id, int $tenantId): bool
    {
        $patient = $this->model->findById($id, $tenantId);
        if (!$patient) {
            throw new \RuntimeException('Patient not found');
        }

        // Check for active scheduled appointments before deletion
        if ($this->model->hasScheduledAppointments($id, $tenantId)) {
            throw new \RuntimeException('This patient has scheduled appointments. Cannot delete.');
        }

        return $this->model->softDelete($id, $tenantId);
    }

    /**
     * Search patients by name or phone.
     */
    public function searchPatients(?string $query, int $tenantId, int $page = 1, int $perPage = 15): array
    {
        return $this->model->search($query, $tenantId, $page, $perPage);
    }
}