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