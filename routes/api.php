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

// 1. Middleware references (Custom Router-ku mukkiam)
$tenant           = ResolveTenant::class;
$auth             = AuthJWT::class;
$csrf             = CsrfGuard::class;

// Role-based Access Control (RBAC) definitions
$adminOnly        = AuthorizeRole::class . ':Admin';
$providerOnly     = AuthorizeRole::class . ':Provider';
$staff            = AuthorizeRole::class . ':Provider,Pharmacist,Admin';
$clinicStaff      = AuthorizeRole::class . ':Admin,Provider,Nurse,Receptionist';
$allAuthenticated = AuthorizeRole::class . ':Admin,Provider,Nurse,Patient,Pharmacist,Receptionist';

// ═══════════════════════════════════════════════════════════
//  HEALTH CHECK (Public)
// ═══════════════════════════════════════════════════════════
$router->get('/api/health', function ($request, $response) {
    $response->success([
        'service'   => 'Clinic Management API',
        'status'    => 'running',
        'timestamp' => date('Y-m-d H:i:s'),
    ], 'API is healthy');
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
//  MODULE 3: PATIENT MANAGEMENT (Merged from HEAD)
// ═══════════════════════════════════════════════════════════
$router->group(['prefix' => '/api/patients', 'middleware' => [$tenant, $auth]], function ($router) use ($clinicStaff, $adminOnly, $csrf) {
    $router->get('/',    [PatientController::class, 'index'],   [$clinicStaff]);
    $router->get('/{id}', [PatientController::class, 'show'],    [$clinicStaff]);
    $router->post('/',   [PatientController::class, 'store'],   [$clinicStaff, $csrf]);
    $router->put('/{id}', [PatientController::class, 'update'],  [$clinicStaff, $csrf]);
    $router->delete('/{id}', [PatientController::class, 'destroy'], [$adminOnly]);
});

// ═══════════════════════════════════════════════════════════
//  MODULE 4: APPOINTMENT MANAGEMENT (Merged from HEAD)
// ═══════════════════════════════════════════════════════════
$router->group(['prefix' => '/api/appointments', 'middleware' => [$tenant, $auth]], function ($router) use ($clinicStaff, $csrf) {
    $router->get('/',        [AppointmentController::class, 'index'],   [$clinicStaff]);
    $router->post('/book',   [AppointmentController::class, 'store'],   [$clinicStaff, $csrf]);
    $router->patch('/{id}/status', [AppointmentController::class, 'updateStatus'], [$clinicStaff, $csrf]);
    $router->delete('/{id}/cancel', [AppointmentController::class, 'destroy'], [$clinicStaff]);
});

// ═══════════════════════════════════════════════════════════
//  MODULE 5: PRESCRIPTION MANAGEMENT
// ═══════════════════════════════════════════════════════════
$router->group(['prefix' => '/api/prescriptions', 'middleware' => [$tenant, $auth]], function ($router) use ($providerOnly, $staff, $csrf) {
    $router->post('/',      [PrescriptionController::class, 'store'], [$providerOnly, $csrf]);
    $router->put('/{id}',   [PrescriptionController::class, 'update'], [$staff, $csrf]);
});

// ═══════════════════════════════════════════════════════════
//  MODULE 6: DASHBOARD STATISTICS
// ═══════════════════════════════════════════════════════════
$router->group(['prefix' => '/api/dashboard', 'middleware' => [$tenant, $auth]], function ($router) use ($staff) {
    $router->get('/stats', [DashboardController::class, 'index'], [$staff]);
});