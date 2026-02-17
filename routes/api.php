<?php

/**
 * API Routes
 * 
 * CSRF Token Flow:
 * ─────────────────
 * CSRF is generated during LOGIN and REFRESH.
 * It is NOT regenerated on every CRUD operation.
 * It is only required for sensitive state-changing operations:
 *   - logout, logout-all, change-password
 *   - user create/update/delete (admin operations)
 *   - role create/update/delete
 *   - profile update
 * 
 * CSRF is regenerated ONLY when:
 *   1. User logs in → AuthService::login()
 *   2. Token rotation → TokenService::rotateRefreshToken()
 *   3. Explicit call → GET /api/auth/csrf-token
 */

use App\Modules\AuthTenant\Controllers\AuthController;
use App\Modules\UsersRoles\Controllers\UserController;
use App\Core\Middleware\ResolveTenant;
use App\Core\Middleware\AuthJWT;
use App\Core\Middleware\AuthorizeRole;
use App\Core\Middleware\CsrfGuard;

// Middleware references
$tenant          = ResolveTenant::class;
$auth            = AuthJWT::class;
$csrf            = CsrfGuard::class;
$adminOnly       = AuthorizeRole::class . ':Admin';
$allAuthenticated = AuthorizeRole::class . ':Admin,Provider,Nurse,Patient,Pharmacist,Receptionist';

// ═══════════════════════════════════════════════════════════
//  HEALTH CHECK (no middleware)
// ═══════════════════════════════════════════════════════════
$router->get('/api/health', function ($request, $response) {
    $response->success([
        'service'   => 'Clinic Management API',
        'version'   => '1.0.0',
        'status'    => 'running',
        'timestamp' => date('Y-m-d H:i:s'),
    ], 'API is healthy');
});

// ═══════════════════════════════════════════════════════════
//  MODULE 1: AUTHENTICATION & TENANT
// ═══════════════════════════════════════════════════════════
$router->group(['prefix' => '/api/auth', 'middleware' => [$tenant]], function ($router) use ($auth, $csrf, $allAuthenticated) {

    // ─── Public (no JWT) ──────────────────────────────
    $router->post('/register', [AuthController::class, 'register']);
    $router->post('/login', [AuthController::class, 'login']);
    $router->post('/refresh', [AuthController::class, 'refresh']);
    $router->get('/csrf-token', [AuthController::class, 'csrfToken']);

    // ─── Protected (JWT required) ─────────────────────
    $router->get('/me', [AuthController::class, 'me'], [$auth, $allAuthenticated]);

    // ─── Protected + CSRF (sensitive actions) ─────────
    $router->post('/logout', [AuthController::class, 'logout'], [$auth, $csrf]);
    $router->post('/logout-all', [AuthController::class, 'logoutAll'], [$auth, $csrf]);
    $router->post('/change-password', [AuthController::class, 'changePassword'], [$auth, $csrf]);
});

// ═══════════════════════════════════════════════════════════
//  MODULE 2: USERS, ROLES & PERMISSIONS
// ═══════════════════════════════════════════════════════════
$router->group(['prefix' => '/api', 'middleware' => [$tenant, $auth]], function ($router) use ($adminOnly, $csrf, $allAuthenticated) {

    // ─── User CRUD (Admin only + CSRF for writes) ─────
    $router->get('/users', [UserController::class, 'index'], [$adminOnly]);
    $router->get('/users/{id}', [UserController::class, 'show'], [$adminOnly]);
    $router->post('/users', [UserController::class, 'store'], [$adminOnly, $csrf]);
    $router->put('/users/{id}', [UserController::class, 'update'], [$adminOnly, $csrf]);
    $router->delete('/users/{id}', [UserController::class, 'destroy'], [$adminOnly, $csrf]);

    // ─── Role Management (Admin only + CSRF for writes)
    $router->get('/roles', [UserController::class, 'listRoles'], [$adminOnly]);
    $router->get('/roles/{id}', [UserController::class, 'showRole'], [$adminOnly]);
    $router->post('/roles', [UserController::class, 'createRole'], [$adminOnly, $csrf]);
    $router->put('/roles/{id}', [UserController::class, 'updateRole'], [$adminOnly, $csrf]);
    $router->delete('/roles/{id}', [UserController::class, 'deleteRole'], [$adminOnly, $csrf]);
    $router->put('/roles/{id}/permissions', [UserController::class, 'assignPermissions'], [$adminOnly, $csrf]);

    // ─── Permissions List (Admin, read-only) ──────────
    $router->get('/permissions', [UserController::class, 'listPermissions'], [$adminOnly]);

    // ─── Profile (Any authenticated user) ─────────────
    $router->get('/profile', [UserController::class, 'getProfile'], [$allAuthenticated]);
    $router->put('/profile', [UserController::class, 'updateProfile'], [$allAuthenticated, $csrf]);
});