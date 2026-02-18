<?php

namespace App\Modules\Appointments\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Services\AppointmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class AppointmentController extends Controller
{
    protected $appointmentService;

    public function __construct(AppointmentService $appointmentService)
    {
        $this->appointmentService = $appointmentService;
        
        // RBAC: Doctors and Staff can view, but only Admin/Staff can manage
        $this->middleware('role:admin,staff')->except(['index', 'show', 'updateStatus']);
        $this->middleware('role:admin,staff,doctor')->only(['index', 'show', 'updateStatus']);
    }

    /**
     * List all appointments with status filtering.
     */
    public function index(Request $request)
    {
        $query = Appointment::with(['patient', 'doctor']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->latest()->paginate(15));
    }

    /**
     * Book an Appointment with Conflict Check.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'patient_id'       => 'required|exists:patients,id',
            'doctor_id'        => 'required|exists:users,id',
            'appointment_time' => 'required|date|after:now',
            'reason'           => 'nullable|string'
        ]);

        try {
            DB::beginTransaction();

            // 1. Service Layer logic panni conflict check panrom
            $isConflict = $this->appointmentService->checkSlotConflict(
                $validated['doctor_id'], 
                $validated['appointment_time']
            );

            if ($isConflict) {
                return response()->json([
                    'error' => 'Intha time slot-la vera oru appointment booked-la irukku. Vera time select pannunga.'
                ], 422);
            }

            // 2. Create Appointment
            $appointment = Appointment::create([
                'patient_id'       => $validated['patient_id'],
                'doctor_id'        => $validated['doctor_id'],
                'appointment_time' => $validated['appointment_time'],
                'reason'           => $validated['reason'],
                'status'           => 'scheduled' // Default status
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Appointment booked successfully!',
                'data'    => $appointment
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Booking Error: " . $e->getMessage());
            return response()->json(['error' => 'Booking process-la error.'], 500);
        }
    }

    /**
     * Update Appointment Status (Status Tracking Logic).
     * Usage: scheduled -> arrived -> in-consultation -> completed / cancelled
     */
    public function updateStatus(Request $request, $id)
    {
        $appointment = Appointment::findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|in:scheduled,arrived,in-consultation,completed,cancelled'
        ]);

        try {
            // Logic: Completed aana appointment-ah thirumba cancel panna koodathu
            if ($appointment->status === 'completed') {
                return response()->json(['error' => 'Completed appointment-ah modify panna mudiyathu.'], 422);
            }

            $appointment->update(['status' => $validated['status']]);

            return response()->json([
                'message' => "Appointment status updated to {$validated['status']}",
                'data'    => $appointment
            ]);

        } catch (Exception $e) {
            Log::error("Status Update Error: " . $e->getMessage());
            return response()->json(['error' => 'Status update failed.'], 500);
        }
    }

    /**
     * Cancel Appointment (Soft Delete use pannama, status-ah cancel panrom).
     */
    public function destroy($id)
    {
        $appointment = Appointment::findOrFail($id);
        
        // Logic: Cancel panna 'cancelled' status-ku mathurathu thaan safe
        $appointment->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Appointment cancelled successfully.']);
    }
}