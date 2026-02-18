<?php

namespace App\Modules\Patients\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Patients\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

class PatientController extends Controller
{
    /**
     * RBAC: Constructor moolama middleware apply panrom.
     */
    public function __construct()
    {
        // CRUD panna Admin/Staff venum. View panna Doctor-kum permission undu.
        $this->middleware('role:admin,staff')->except(['index', 'show']);
        $this->middleware('role:admin,staff,doctor')->only(['index', 'show']);
    }

    /**
     * Get All Patients (Soft deletes are hidden by default)
     */
    public function index()
    {
        $patients = Patient::latest()->paginate(10);
        return response()->json([
            'status' => 'success',
            'data' => $patients
        ], 200);
    }

    /**
     * Create New Patient (AES Encryption via Model Casting)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'phone'           => 'required|digits:10|unique:patients,phone',
            'email'           => 'nullable|email|unique:patients,email',
            'medical_history' => 'required|string', 
        ]);

        try {
            DB::beginTransaction();
            $patient = Patient::create($validated);
            DB::commit();

            return response()->json([
                'message' => 'Patient created successfully!',
                'data'    => $patient
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Store Error: " . $e->getMessage());
            return response()->json(['error' => 'Server error during creation.'], 500);
        }
    }

    /**
     * Show Single Patient
     */
    public function show($id)
    {
        $patient = Patient::find($id);
        if (!$patient) return response()->json(['message' => 'Not found'], 404);

        return response()->json(['data' => $patient], 200);
    }

    /**
     * Update Patient Logic (The Core Update)
     */
    public function update(Request $request, $id)
    {
        $patient = Patient::find($id);

        if (!$patient) {
            return response()->json(['message' => 'Patient not found!'], 404);
        }

        // Validation Logic
        $validated = $request->validate([
            'name'            => 'sometimes|string|max:255',
            // Intha unique check romba mukkiyam: 'unique:table,column,except_id'
            'phone'           => 'sometimes|digits:10|unique:patients,phone,' . $id,
            'email'           => 'sometimes|email|unique:patients,email,' . $id,
            'medical_history' => 'sometimes|string',
        ]);

        try {
            DB::beginTransaction();

            // Fill & Save logic: Ethu change aayiruko athu mattum update aagum
            $patient->fill($validated);
            
            // Checking if anything actually changed before saving
            if ($patient->isDirty()) {
                $patient->save();
                $message = 'Patient record updated successfully.';
            } else {
                $message = 'No changes detected.';
            }

            DB::commit();

            return response()->json([
                'message' => $message,
                'data'    => $patient
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Update Error ID {$id}: " . $e->getMessage());
            return response()->json(['error' => 'Update failed. Check logs.'], 500);
        }
    }

    /**
     * Soft Delete Logic
     */
    public function destroy($id)
    {
        $patient = Patient::find($id);

        if (!$patient) return response()->json(['message' => 'Not found'], 404);

        // RBAC: Only admin can delete records
        if (auth()->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized! Admin access required.'], 403);
        }

        $patient->delete();
        return response()->json(['message' => 'Patient moved to trash successfully.'], 200);
    }
}