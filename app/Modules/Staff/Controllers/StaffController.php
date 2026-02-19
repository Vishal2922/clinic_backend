<?php

namespace App\Modules\Staff\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Staff\Services\StaffService;

/**
 * StaffController
 * Manages doctors, nurses, receptionists, pharmacists.
 *
 * Key Features:
 *  - Add/Edit/Delete staff
 *  - Role assignment
 *  - Status management (active/inactive/on_leave)
 *  - Tenant-based staff segregation
 *
 * Access: Admin only (enforced via route middleware).
 */
class StaffController extends Controller
{
    private StaffService $staffService;

    public function __construct()
    {
        $this->staffService = new StaffService();
    }

    /**
     * GET /api/staff
     * List all staff with optional filters: status, role_id, department, search.
     */
    public function index(Request $request): void
    {
        $tenantId = $this->getTenantId();
        $page     = (int) $request->getQueryParam('page', 1);
        $perPage  = (int) $request->getQueryParam('per_page', 20);

        $filters = [
            'status'     => $request->getQueryParam('status'),
            'role_id'    => $request->getQueryParam('role_id'),
            'department' => $request->getQueryParam('department'),
            'search'     => $request->getQueryParam('search'),
        ];

        try {
            $result = $this->staffService->listStaff($tenantId, $page, $perPage, $filters);
            Response::json(['message' => 'Staff retrieved', 'data' => $result], 200);
        } catch (\Exception $e) {
            app_log('List staff error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to retrieve staff.', 500);
        }
    }

    /**
     * GET /api/staff/{id}
     * Get a single staff member by staff ID.
     */
    public function show(Request $request, string $id): void
    {
        $tenantId = $this->getTenantId();

        try {
            $staff = $this->staffService->getStaff((int) $id, $tenantId);
            if (!$staff) {
                Response::error('Staff member not found', 404);
            }
            Response::json(['message' => 'Staff member retrieved', 'data' => $staff], 200);
        } catch (\Exception $e) {
            app_log('Show staff error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to retrieve staff member.', 500);
        }
    }

    /**
     * POST /api/staff
     * Add a new staff member (links to an existing user).
     */
    public function store(Request $request): void
    {
        $tenantId = $this->getTenantId();
        $data     = $request->getBody();

        $errors = $this->validate($data, [
            'user_id' => 'required|numeric',
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        try {
            $staff = $this->staffService->createStaff($data, $tenantId);
            Response::json(['message' => 'Staff member added successfully', 'data' => $staff], 201);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 409);
        } catch (\Exception $e) {
            app_log('Create staff error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to create staff record.', 500);
        }
    }

    /**
     * PUT /api/staff/{id}
     * Update staff record. Can update department, specialization, status,
     * role assignment, etc.
     */
    public function update(Request $request, string $id): void
    {
        $tenantId = $this->getTenantId();
        $data     = $request->getBody();

        if (empty($data)) {
            Response::error('No data provided for update', 422);
        }

        try {
            $staff = $this->staffService->updateStaff((int) $id, $data, $tenantId);
            Response::json(['message' => 'Staff member updated successfully', 'data' => $staff], 200);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 400);
        } catch (\Exception $e) {
            app_log('Update staff error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to update staff record.', 500);
        }
    }

    /**
     * DELETE /api/staff/{id}
     * Soft-delete staff record and deactivate the linked user.
     */
    public function destroy(Request $request, string $id): void
    {
        $tenantId = $this->getTenantId();

        try {
            $this->staffService->deleteStaff((int) $id, $tenantId);
            Response::json(['message' => 'Staff member removed successfully', 'data' => []], 200);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 400);
        } catch (\Exception $e) {
            app_log('Delete staff error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to delete staff record.', 500);
        }
    }

    /**
     * GET /api/staff/departments
     * Get distinct departments for dropdown/filter UI.
     */
    public function departments(Request $request): void
    {
        $tenantId = $this->getTenantId();

        try {
            $departments = $this->staffService->getDepartments($tenantId);
            Response::json([
                'message' => 'Departments retrieved',
                'data'    => ['departments' => array_column($departments, 'department')],
            ], 200);
        } catch (\Exception $e) {
            app_log('Get departments error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to retrieve departments.', 500);
        }
    }
}