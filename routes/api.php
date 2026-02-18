<?php

use App\Core\Middleware\ResolveTenant;
use App\Core\Middleware\AuthJWT;
use App\Core\Middleware\AuthorizeRole;
use App\Core\Middleware\CsrfGuard;

use App\Modules\AuthTenant\Controllers\AuthController;
use App\Modules\UsersRoles\Controllers\UserController;
use App\Modules\Prescriptions\Controllers\PrescriptionController;
use App\Modules\ReportsDashboard\Controllers\DashboardController;

// 1. Middleware references
$tenant           = ResolveTenant::class;
$auth             = AuthJWT::class;
$csrf             = CsrfGuard::class;
$adminOnly        = AuthorizeRole::class . ':Admin';
$providerOnly     = AuthorizeRole::class . ':Provider';
$staff            = AuthorizeRole::class . ':Provider,Pharmacist,Admin';
$allAuthenticated = AuthorizeRole::class . ':Admin,Provider,Nurse,Patient,Pharmacist,Receptionist';

// ═══════════════════════════════════════════════════════════
//  HEALTH CHECK (no middleware)
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

    // Public routes (no JWT needed)
    $router->post('/register', [AuthController::class, 'register']);
    $router->post('/login',    [AuthController::class, 'login']);
    $router->post('/refresh',  [AuthController::class, 'refresh']);

    // FIX: Was missing — returns CSRF token for POST/PUT requests
    $router->get('/csrf-token', [AuthController::class, 'csrfToken']);

    // Protected routes (JWT required)
    $router->get('/me', [AuthController::class, 'me'], [$auth, $allAuthenticated]);

    // FIX: These routes were missing — 404 varuthu
    $router->post('/logout',          [AuthController::class, 'logout'],         [$auth, $csrf]);
    $router->post('/logout-all',      [AuthController::class, 'logoutAll'],      [$auth]);
    $router->post('/change-password', [AuthController::class, 'changePassword'], [$auth, $csrf]);
});

// ═══════════════════════════════════════════════════════════
//  MODULE 5: PRESCRIPTION MANAGEMENT
// ═══════════════════════════════════════════════════════════
$router->group(['prefix' => '/api', 'middleware' => [$tenant, $auth]], function ($router) use ($providerOnly, $staff, $csrf) {

    // Create: Only Provider + CSRF check
    $router->post('/prescriptions', [PrescriptionController::class, 'store'], [$providerOnly, $csrf]);

    // FIX: Was PUT /prescriptions — service needs {id} from URL, not body
    $router->put('/prescriptions/{id}', [PrescriptionController::class, 'update'], [$staff, $csrf]);
});

// ═══════════════════════════════════════════════════════════
//  MODULE 6: DASHBOARD STATISTICS
// ═══════════════════════════════════════════════════════════
$router->group(['prefix' => '/api/dashboard', 'middleware' => [$tenant, $auth]], function ($router) use ($staff) {

    // Stats: Accessible by Admin, Provider, Pharmacist
    $router->get('/stats', [DashboardController::class, 'index'], [$staff]);
});

// ═══════════════════════════════════════════════════════════
//  ADMIN ROUTES
// ═══════════════════════════════════════════════════════════
$router->group(['prefix' => '/api/admin', 'middleware' => [$tenant, $auth, $adminOnly]], function ($router) use ($csrf) {
    $router->get('/users',  [UserController::class, 'index']);
    $router->post('/users', [UserController::class, 'store'], [$csrf]);
});