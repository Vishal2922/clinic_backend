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
use App\Modules\Staff\Controllers\StaffController;
use App\Modules\SettingsSecurity\Controllers\SettingsController;

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
// MODULE 9: STAFF MANAGEMENT
// Access: Admin only
// ═══════════════════════════════════════════════════════════
$router->group(['prefix' => '/api/staff', 'middleware' => [$tenant, $auth]], function ($router) use ($adminOnly, $csrf) {
    // GET departments must be registered BEFORE /{id} to avoid route conflict
    $router->get('/departments', [StaffController::class, 'departments'], [$adminOnly]);
    $router->get('/',            [StaffController::class, 'index'],       [$adminOnly]);
    $router->get('/{id}',        [StaffController::class, 'show'],        [$adminOnly]);
    $router->post('/',           [StaffController::class, 'store'],       [$adminOnly, $csrf]);
    $router->put('/{id}',        [StaffController::class, 'update'],      [$adminOnly, $csrf]);
    $router->delete('/{id}',     [StaffController::class, 'destroy'],     [$adminOnly]);
});

// ═══════════════════════════════════════════════════════════
// MODULE 11: SETTINGS & SECURITY
// Access: All authenticated users (audit log = Admin only)
// ═══════════════════════════════════════════════════════════
$router->group(['prefix' => '/api/settings', 'middleware' => [$tenant, $auth]], function ($router) use ($allAuthenticated, $adminOnly, $csrf) {

    // Password management — all authenticated users
    $router->post('/change-password', [SettingsController::class, 'changePassword'], [$allAuthenticated, $csrf]);

    // Session management — all authenticated users
    $router->post('/logout',          [SettingsController::class, 'logout'],            [$allAuthenticated, $csrf]);
    $router->post('/logout-all',      [SettingsController::class, 'logoutAll'],         [$allAuthenticated, $csrf]);

    // Token rotation — all authenticated users
    $router->post('/rotate-tokens',   [SettingsController::class, 'rotateTokens'],      [$allAuthenticated]);

    // CSRF regeneration — all authenticated users
    $router->get('/csrf-token',       [SettingsController::class, 'csrfRegenerate'],    [$allAuthenticated]);

    // Session viewing/invalidation — all authenticated users
    $router->get('/sessions',         [SettingsController::class, 'listSessions'],      [$allAuthenticated]);
    $router->delete('/sessions/{id}', [SettingsController::class, 'invalidateSession'], [$allAuthenticated]);

    // Audit log — Admin only
    $router->get('/audit-log',         [SettingsController::class, 'auditLog'],    [$adminOnly]);
    $router->get('/audit-log/actions', [SettingsController::class, 'auditActions'], [$adminOnly]);
});
