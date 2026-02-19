<?php

use App\Core\Middleware\ResolveTenant;
use App\Core\Middleware\AuthJWT;
use App\Core\Middleware\AuthorizeRole;
use App\Core\Middleware\CsrfGuard;

use App\Modules\AuthTenant\Controllers\AuthController;
use App\Modules\UsersRoles\Controllers\UserController;
use App\Modules\Prescriptions\Controllers\PrescriptionController;
use App\Modules\ReportsDashboard\Controllers\DashboardController;
use App\Modules\Patients\Controllers\PatientController;
use App\Modules\Appointments\Controllers\AppointmentController;

/**
 * 1. Middleware Definitions
 * Registering class names to variables for cleaner group definitions.
 */
$tenant           = ResolveTenant::class;
$auth             = AuthJWT::class;
$csrf             = CsrfGuard::class;

// Role-based Access Control (RBAC) strings
$adminOnly        = AuthorizeRole::class . ':Admin';
$providerOnly     = AuthorizeRole::class . ':Provider';
$staff            = AuthorizeRole::class . ':Provider,Pharmacist,Admin';
$clinicStaff      = AuthorizeRole::class . ':Admin,Provider,Nurse,Receptionist';
$allAuthenticated = AuthorizeRole::class . ':Admin,Provider,Nurse,Patient,Pharmacist,Receptionist';

// ═══════════════════════════════════════════════════════════
//  HEALTH CHECK (Public)
// ═══════════════════════════════════════════════════════════
$router->get('/api/health', function ($request, $response) {
    $response->json([
        'service'   => 'Clinic Management API',
        'status'    => 'running',
        'timestamp' => date('Y-m-d H:i:s'),
    ], 200);
});

// ═══════════════════════════════════════════════════════════
//  MODULE 1 & 2: AUTH & USERS
// ═══════════════════════════════════════════════════════════
$router->group(['prefix' => '/api/auth', 'middleware' => [$tenant]], function ($router) use ($auth, $csrf, $allAuthenticated) {
    // Public routes
    $router->post('/register', [AuthController::class, 'register']);
    $router->post('/login',    [AuthController::class, 'login']);
    $router->post('/refresh',  [AuthController::class, 'refresh']);
    $router->get('/csrf-token', [AuthController::class, 'csrfToken']);

    // Protected routes
    $router->get('/me', [AuthController::class, 'me'], [$auth, $allAuthenticated]);
    $router->post('/logout', [AuthController::class, 'logout'], [$auth, $csrf]);
});

// ═══════════════════════════════════════════════════════════
//  MODULE 3: PATIENT MANAGEMENT
// ═══════════════════════════════════════════════════════════
$router->group(['prefix' => '/api/patients', 'middleware' => [$tenant, $auth]], function ($router) use ($clinicStaff, $adminOnly, $csrf) {
    $router->get('/',       [PatientController::class, 'index'],   [$clinicStaff]);
    $router->get('/{id}',   [PatientController::class, 'show'],    [$clinicStaff]);
    $router->post('/',      [PatientController::class, 'store'],   [$clinicStaff, $csrf]);
    $router->put('/{id}',   [PatientController::class, 'update'],  [$clinicStaff, $csrf]);
    $router->delete('/{id}', [PatientController::class, 'destroy'], [$adminOnly]);
});

// ═══════════════════════════════════════════════════════════
//  MODULE 4: APPOINTMENT MANAGEMENT
// ═══════════════════════════════════════════════════════════
$router->group(['prefix' => '/api/appointments', 'middleware' => [$tenant, $auth]], function ($router) use ($clinicStaff, $csrf) {
    $router->get('/',               [AppointmentController::class, 'index'],   [$clinicStaff]);
    $router->post('/book',          [AppointmentController::class, 'store'],   [$clinicStaff, $csrf]);
    $router->patch('/{id}/status',  [AppointmentController::class, 'updateStatus'], [$clinicStaff, $csrf]);
    $router->delete('/{id}/cancel', [AppointmentController::class, 'destroy'], [$clinicStaff]);
});

// ═══════════════════════════════════════════════════════════
//  MODULE 5: PRESCRIPTION MANAGEMENT
//  Role: Providers create, Pharmacists/Providers can update.
// ═══════════════════════════════════════════════════════════
$router->group(['prefix' => '/api/prescriptions', 'middleware' => [$tenant, $auth]], function ($router) use ($providerOnly, $staff, $csrf) {
    // POST /api/prescriptions -> Uses providerOnly role
    $router->post('/',      [PrescriptionController::class, 'store'], [$providerOnly, $csrf]);
    
    // PUT /api/prescriptions/{id} -> Uses staff role (Provider + Pharmacist)
    $router->put('/{id}',   [PrescriptionController::class, 'update'], [$staff, $csrf]);
});

// ═══════════════════════════════════════════════════════════
//  MODULE 6: DASHBOARD STATISTICS
// ═══════════════════════════════════════════════════════════
$router->group(['prefix' => '/api/dashboard', 'middleware' => [$tenant, $auth]], function ($router) use ($staff) {
    $router->get('/stats',  [DashboardController::class, 'index'], [$staff]);
});

// ═══════════════════════════════════════════════════════════
//  MODULE 7: COMMUNICATION (Notes & Messages)
//  Roles: Provider, Nurse
// ═══════════════════════════════════════════════════════════
use App\Modules\Communication\Controllers\NoteController;
$providerNurse = AuthorizeRole::class . ':Provider,Nurse';

$router->group(['prefix' => '/api/communication', 'middleware' => [$tenant, $auth, $providerNurse]], function ($router) use ($csrf) {
    // Get notes for an appointment (role-visibility filtered + decrypted)
    $router->get('/appointments/{id}/notes',         [NoteController::class, 'index']);

    // Create a new encrypted note for an appointment
    $router->post('/appointments/{id}/notes',        [NoteController::class, 'store'],   [$csrf]);

    // Paginated message history for an appointment
    $router->get('/appointments/{id}/notes/history', [NoteController::class, 'history']);

    // Soft-delete a note (author only)
    $router->delete('/notes/{id}',                   [NoteController::class, 'destroy'], [$csrf]);
});

// ═══════════════════════════════════════════════════════════
//  MODULE 8: BILLING & PAYMENTS
//  Admin: full access | Provider: create+update | Patient: view own+pay
// ═══════════════════════════════════════════════════════════
use App\Modules\Billing\Controllers\InvoiceController;
$billingStaff = AuthorizeRole::class . ':Admin,Provider';
$billingAll   = AuthorizeRole::class . ':Admin,Provider,Patient';

$router->group(['prefix' => '/api/billing', 'middleware' => [$tenant, $auth]], function ($router) use ($billingStaff, $billingAll, $adminOnly, $csrf) {
    // Billing summary totals (Admin + Provider)
    $router->get('/summary',                [InvoiceController::class, 'summary'],      [$billingStaff]);

    // List invoices — patients auto-filtered to own
    $router->get('/invoices',               [InvoiceController::class, 'index'],        [$billingAll]);

    // Single invoice — patients can only view own
    $router->get('/invoices/{id}',          [InvoiceController::class, 'show'],         [$billingAll]);

    // Generate invoice (Admin + Provider)
    $router->post('/invoices',              [InvoiceController::class, 'store'],        [$billingStaff, $csrf]);

    // Update payment status (all roles, restricted by role-transition rules)
    $router->patch('/invoices/{id}/status', [InvoiceController::class, 'updateStatus'], [$billingAll, $csrf]);

    // Delete invoice (Admin only)
    $router->delete('/invoices/{id}',       [InvoiceController::class, 'destroy'],      [$adminOnly]);
});