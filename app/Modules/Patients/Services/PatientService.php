<?php

namespace App\Modules\Patients\Services;

use App\Modules\Patients\Models\Patient;

class PatientService
{
    private Patient $model;

    public function __construct()
    {
        $this->model = new Patient();
    }

    public function listPatients(int $tenantId, int $page = 1, int $perPage = 10): array
    {
        return $this->model->getAllByTenant($tenantId, $page, $perPage);
    }

    public function getPatient(int $id, int $tenantId): ?array
    {
        return $this->model->findById($id, $tenantId);
    }

    public function createPatient(array $data, int $tenantId): array
    {
        $data['phone']     = preg_replace('/\D/', '', $data['phone']);
        $data['tenant_id'] = $tenantId;

        $id = $this->model->create($data);
        app_log("New Patient Registered: ID {$id}");

        return $this->model->findById($id, $tenantId);
    }

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

    public function deletePatient(int $id, int $tenantId): bool
    {
        $patient = $this->model->findById($id, $tenantId);
        if (!$patient) {
            throw new \RuntimeException('Patient not found');
        }

        if ($this->model->hasScheduledAppointments($id, $tenantId)) {
            throw new \RuntimeException('This patient has scheduled appointments. Cannot delete.');
        }

        return $this->model->softDelete($id, $tenantId);
    }

    /**
     * FIX: now accepts gender and status params and passes them to the model
     */
    public function searchPatients(
        ?string $query,
        ?string $gender,
        ?string $status,
        int $tenantId,
        int $page = 1,
        int $perPage = 10
    ): array {
        return $this->model->search($query, $gender, $status, $tenantId, $page, $perPage);
    }
}