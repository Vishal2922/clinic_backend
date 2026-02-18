<?php

namespace App\Modules\Appointments\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Appointments\Services\SchedulingService;
use App\Modules\Appointments\Models\Appointment;

/**
 * AppointmentController: Fixed Version.
 *
 * Bugs Fixed:
 * 1. CRITICAL: Used Laravel base class `App\Http\Controllers\Controller` and Laravel facades.
 *    Replaced with custom framework equivalents.
 * 2. Constructor dependency-injected `AppointmentService $appointmentService` — custom Router
 *    does not support constructor DI. Replaced with `new SchedulingService()` (actual class name).
 * 3. Called `$this->appointmentService->checkSlotConflict()` but SchedulingService has
 *    `isSlotAvailable()` — method name mismatch. Fixed to call correct method.
 * 4. Constructor used Laravel `$this->middleware()` — doesn't exist. RBAC handled via routes.
 * 5. `Appointment::with(['patient','doctor'])->latest()->paginate()` — Eloquent. Replaced with model/service.
 * 6. `Appointment::findOrFail()` — Eloquent. Replaced with model findById() + manual 404.
 * 7. `$appointment->update()` — Eloquent. Replaced with model update().
 * 8. response()->json() doesn't exist — replaced with Response::json().
 * 9. $request->validate() doesn't exist — replaced with $this->validate().
 * 10. Missing 'tenant_id' when creating appointments (multi-tenancy isolation broken).
 */
class AppointmentController extends Controller
{
    private SchedulingService $schedulingService;
    private Appointment $appointmentModel;

    public function __construct()
    {
        $this->schedulingService = new SchedulingService();
        $this->appointmentModel  = new Appointment();
    }

    /**
     * GET /api/appointments
     */
    public function index(Request $request): void
    {
        $tenantId = $this->getTenantId();
        $status   = $request->getQueryParam('status');
        $page     = (int) $request->getQueryParam('page', 1);
        $perPage  = (int) $request->getQueryParam('per_page', 15);

        try {
            $result = $this->appointmentModel->getAllByTenant($tenantId, $status, $page, $perPage);
            Response::json(['message' => 'Appointments retrieved', 'data' => $result], 200);
        } catch (\Exception $e) {
            app_log('List appointments error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to retrieve appointments.', 500);
        }
    }

    /**
     * POST /api/appointments/book
     * FIX #3: checkSlotConflict() → isSlotAvailable() (correct method name).
     * FIX #10: tenant_id now included when creating appointments.
     */
    public function store(Request $request): void
    {
        $tenantId = $this->getTenantId();
        $data     = $request->getBody();

        $errors = $this->validate($data, [
            'patient_id'       => 'required|numeric',
            'doctor_id'        => 'required|numeric',
            'appointment_time' => 'required',
            'reason'           => '',
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        // Validate appointment_time is in the future
        if (strtotime($data['appointment_time']) <= time()) {
            Response::error('Appointment time must be in the future.', 422);
        }

        try {
            // FIX #3: Correct method name is isSlotAvailable(), not checkSlotConflict()
            $isAvailable = $this->schedulingService->isSlotAvailable(
                (int) $data['doctor_id'],
                $data['appointment_time']
            );

            if (!$isAvailable) {
                Response::error('This time slot is already booked. Please select another time.', 422);
            }

            $id = $this->appointmentModel->create([
                'tenant_id'        => $tenantId, // FIX #10
                'patient_id'       => (int) $data['patient_id'],
                'doctor_id'        => (int) $data['doctor_id'],
                'appointment_time' => $data['appointment_time'],
                'reason'           => $data['reason'] ?? null,
                'status'           => 'scheduled',
            ]);

            $appointment = $this->appointmentModel->findById($id, $tenantId);
            Response::json(['message' => 'Appointment booked successfully!', 'data' => $appointment], 201);

        } catch (\Exception $e) {
            app_log('Booking error: ' . $e->getMessage(), 'ERROR');
            Response::error('Booking process error.', 500);
        }
    }

    /**
     * PATCH /api/appointments/{id}/status
     */
    public function updateStatus(Request $request, string $id): void
    {
        $tenantId = $this->getTenantId();
        $data     = $request->getBody();

        $errors = $this->validate($data, [
            'status' => 'required|in:scheduled,arrived,in-consultation,completed,cancelled',
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        try {
            $appointment = $this->appointmentModel->findById((int) $id, $tenantId);
            if (!$appointment) {
                Response::error('Appointment not found.', 404);
            }

            if ($appointment['status'] === 'completed') {
                Response::error('Cannot modify a completed appointment.', 422);
            }

            $this->appointmentModel->updateStatus((int) $id, $tenantId, $data['status']);
            $updated = $this->appointmentModel->findById((int) $id, $tenantId);

            Response::json([
                'message' => "Appointment status updated to {$data['status']}",
                'data'    => $updated,
            ], 200);

        } catch (\Exception $e) {
            app_log('Status update error: ' . $e->getMessage(), 'ERROR');
            Response::error('Status update failed.', 500);
        }
    }

    /**
     * DELETE /api/appointments/{id}/cancel
     */
    public function destroy(Request $request, string $id): void
    {
        $tenantId = $this->getTenantId();

        try {
            $appointment = $this->appointmentModel->findById((int) $id, $tenantId);
            if (!$appointment) {
                Response::error('Appointment not found.', 404);
            }

            $this->appointmentModel->updateStatus((int) $id, $tenantId, 'cancelled');
            Response::json(['message' => 'Appointment cancelled successfully.'], 200);

        } catch (\Exception $e) {
            app_log('Cancel appointment error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to cancel appointment.', 500);
        }
    }
}