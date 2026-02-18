<?php
// Inga 'class Router' nu thirumbavum ezhudha koodathu!
use App\Modules\Prescriptions\Controllers\PrescriptionController;
use App\Modules\ReportsDashboard\Controllers\DashboardController;

/**
 * Module 5: Prescription Management
 * Inga dhaan encryption logic trigger aagum.
 */
$router->post('/prescriptions', [PrescriptionController::class, 'store']);

// 💡 Indha line irukka-nu paarunga. Module 5 update-ku idhu mukkiyam.
$router->put('/prescriptions', [\App\Modules\Prescriptions\Controllers\PrescriptionController::class, 'update']);

/**
 * Module 6: Dashboard Statistics
 * Inga dhaan aggregated data (counts) kidaikum.
 */
$router->get('/dashboard/stats', [DashboardController::class, 'index']);

//-----------------------------------------------------------// 

// routes/api.php

use App\Modules\Patients\Controllers\PatientController;
use App\Modules\Appointments\Controllers\AppointmentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {

    // --- Patient Module Routes ---
    // Admin and Staff can manage. Doctors can only view.
    Route::prefix('patients')->group(function () {
        Route::get('/', [PatientController::class, 'index'])->middleware('role:admin,staff,doctor');
        Route::get('/{id}', [PatientController::class, 'show'])->middleware('role:admin,staff,doctor');
        Route::post('/', [PatientController::class, 'store'])->middleware('role:admin,staff');
        Route::put('/{id}', [PatientController::class, 'update'])->middleware('role:admin,staff');
        Route::delete('/{id}', [PatientController::class, 'destroy'])->middleware('role:admin'); // Only Admin
    });

    // --- Appointment Module Routes ---
    Route::prefix('appointments')->group(function () {
        Route::get('/', [AppointmentController::class, 'index'])->middleware('role:admin,staff,doctor');
        Route::post('/book', [AppointmentController::class, 'store'])->middleware('role:admin,staff');
        Route::patch('/{id}/status', [AppointmentController::class, 'updateStatus'])->middleware('role:admin,staff,doctor');
        Route::delete('/{id}/cancel', [AppointmentController::class, 'destroy'])->middleware('role:admin,staff');
    });

});