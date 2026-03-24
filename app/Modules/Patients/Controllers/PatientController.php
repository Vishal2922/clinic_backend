<?php

namespace App\Modules\Patients\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Patients\Services\PatientService;

class PatientController extends Controller
{
    private PatientService $patientService;

    public function __construct()
    {
        $this->patientService = new PatientService();
    }

    /**
     * GET /api/patients
     * FIX: now reads and applies search, gender, status query params
     */
    public function index(Request $request): void
    {
        $tenantId = $this->getTenantId();
        $page     = (int) $request->getQueryParam('page', 1);
        $perPage  = (int) $request->getQueryParam('per_page', 10);
        $search   = $request->getQueryParam('search', null);
        $gender   = $request->getQueryParam('gender', null);
        $status   = $request->getQueryParam('status', 'active');

        try {
            if ($search || $gender || ($status && $status !== 'all')) {
                $result = $this->patientService->searchPatients(
                    $search, $gender, $status, $tenantId, $page, $perPage
                );
            } else {
                $result = $this->patientService->listPatients($tenantId, $page, $perPage);
            }
            Response::json(['message' => 'Patients retrieved', 'data' => $result], 200);
        } catch (\Exception $e) {
            app_log('List patients error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to retrieve patients.', 500);
        }
    }

    /**
     * POST /api/patients
     */
    public function store(Request $request): void
    {
        $tenantId = $this->getTenantId();
        $data     = $request->getBody();

        $errors = $this->validate($data, [
            'name'            => 'required|max:255',
            'phone'           => 'required',
            'medical_history' => 'required',
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        try {
            $patient = $this->patientService->createPatient($data, $tenantId);
            Response::json(['message' => 'Patient created successfully!', 'data' => $patient], 201);
        } catch (\Exception $e) {
            app_log('Store patient error: ' . $e->getMessage(), 'ERROR');
            Response::error('Server error during creation.', 500);
        }
    }

    /**
     * GET /api/patients/{id}
     */
    public function show(Request $request, string $id): void
    {
        $tenantId = $this->getTenantId();

        try {
            $patient = $this->patientService->getPatient((int) $id, $tenantId);
            if (!$patient) {
                Response::error('Not found', 404);
            }
            Response::json(['data' => $patient], 200);
        } catch (\Exception $e) {
            app_log('Show patient error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to retrieve patient.', 500);
        }
    }

    /**
     * PUT /api/patients/{id}
     */
    public function update(Request $request, string $id): void
    {
        $tenantId = $this->getTenantId();
        $data     = $request->getBody();

        $errors = $this->validate($data, [
            'name'            => 'max:255',
            'medical_history' => '',
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        try {
            $patient = $this->patientService->updatePatient((int) $id, $data, $tenantId);
            Response::json(['message' => 'Patient record updated successfully.', 'data' => $patient], 200);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 404);
        } catch (\Exception $e) {
            app_log('Update patient error: ' . $e->getMessage(), 'ERROR');
            Response::error('Update failed.', 500);
        }
    }

    /**
     * DELETE /api/patients/{id}
     */
    public function destroy(Request $request, string $id): void
    {
        $tenantId = $this->getTenantId();
        $authUser = $this->getAuthUser();

        if (!$this->checkRole(['Admin'])) {
            Response::error('Unauthorized! Admin access required.', 403);
        }

        try {
            $this->patientService->deletePatient((int) $id, $tenantId);
            Response::json(['message' => 'Patient moved to trash successfully.'], 200);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            app_log('Delete patient error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to delete patient.', 500);
        }
    }
}