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




<?php
// ═══════════════════════════════════════════════════════════
//  MODULE 10: CALENDAR API
//  Supports: single date, date range, tooltip detail,
//            doctor schedule, monthly summary.
//  Roles: All clinic staff (Admin, Provider, Nurse, Receptionist)
// ═══════════════════════════════════════════════════════════

// ADD THIS USE STATEMENT at the top of routes/api.php
// with the other use statements:
//
//   use App\Modules\Calendar\Controllers\CalendarController;

$router->group(['prefix' => '/api/calendar', 'middleware' => [$tenant, $auth]], function ($router) use ($clinicStaff) {

    /**
     * GET /api/calendar/events?date=YYYY-MM-DD[&status=...][&doctor_id=...]
     * Returns all appointments on a specific calendar date.
     * Supports status and doctor_id filters.
     */
    $router->get('/events', [CalendarController::class, 'getByDate'], [$clinicStaff]);

    /**
     * GET /api/calendar/range?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD[&status=...][&doctor_id=...]
     * Returns events within a date range (max 90 days).
     * Response includes both a flat list and a by_date grouped map.
     */
    $router->get('/range', [CalendarController::class, 'getByRange'], [$clinicStaff]);

    /**
     * GET /api/calendar/tooltip/{id}
     * Returns rich tooltip detail for a single appointment:
     * patient contact info, doctor info, reason, status, prescription count.
     */
    $router->get('/tooltip/{id}', [CalendarController::class, 'getTooltip'], [$clinicStaff]);

    /**
     * GET /api/calendar/doctor/{id}?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
     * Returns a specific doctor's appointments grouped by date.
     */
    $router->get('/doctor/{id}', [CalendarController::class, 'getDoctorSchedule'], [$clinicStaff]);

    /**
     * GET /api/calendar/monthly?year=YYYY&month=M
     * Returns per-day appointment counts for a full month.
     * Used for dot/badge indicators on monthly calendar grid views.
     */
    $router->get('/monthly', [CalendarController::class, 'getMonthlySummary'], [$clinicStaff]);
});