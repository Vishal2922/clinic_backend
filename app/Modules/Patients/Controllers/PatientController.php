<?php

namespace App\Modules\Patients\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Patients\Services\PatientService;

/**
 * PatientController: Fixed Version.
 *
 * CRITICAL BUG: This file was written using Laravel framework classes
 * (Illuminate\Http\Request, Illuminate\Support\Facades\DB, etc.) but the project
 * uses a custom framework (App\Core\Controller, App\Core\Request, etc.).
 *
 * Bugs Fixed:
 * 1. Wrong base class: used `App\Http\Controllers\Controller` (Laravel) instead of `App\Core\Controller`.
 * 2. Wrong imports: used `Illuminate\Http\Request`, `Illuminate\Support\Facades\DB`, `Illuminate\Support\Facades\Log`.
 * 3. Patient model used Laravel Eloquent — replaced with PatientService which uses custom PDO model.
 * 4. `auth()->user()` used in destroy() — doesn't exist in this framework; replaced with getAuthUser().
 * 5. Constructor used Laravel middleware() method — replaced with route-level RBAC middleware.
 * 6. response()->json() helper doesn't exist — replaced with Response::json().
 * 7. $request->validate() doesn't exist — replaced with $this->validate().
 * 8. Patient::latest()->paginate() doesn't exist — replaced with PatientService calls.
 */
class PatientController extends Controller
{
    private PatientService $patientService;

    public function __construct()
    {
        $this->patientService = new PatientService();
    }

    /**
     * GET /api/patients
     */
    public function index(Request $request): void
    {
        $tenantId = $this->getTenantId();
        $page    = (int) $request->getQueryParam('page', 1);
        $perPage = (int) $request->getQueryParam('per_page', 10);

        try {
            $result = $this->patientService->listPatients($tenantId, $page, $perPage);
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
     * FIX #4: auth()->user() replaced with getAuthUser().
     */
    public function destroy(Request $request, string $id): void
    {
        $tenantId = $this->getTenantId();
        $authUser = $this->getAuthUser();

        // RBAC: Only admin can delete records
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