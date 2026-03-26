<?php

use App\Core\Middleware\ResolveTenant;
use App\Core\Middleware\AuthJWT;
use App\Core\Middleware\AuthorizeRole;
use App\Core\Middleware\CsrfGuard;

// Controllers Import
use App\Modules\AuthTenant\Controllers\AuthController;
use App\Modules\UsersRoles\Controllers\UserController;
use App\Modules\Prescriptions\Controllers\PrescriptionController;
use App\Modules\ReportsDashboard\Controllers\DashboardController;
use App\Modules\Patients\Controllers\PatientController;
use App\Modules\Appointments\Controllers\AppointmentController;
use App\Modules\Calendar\Controllers\CalendarController;
use App\Modules\Communication\Controllers\NoteController;
use App\Modules\Notifications\Controllers\NotificationController;
use App\Modules\Billing\Controllers\InvoiceController;
use App\Modules\Staff\Controllers\StaffController;
use App\Modules\SettingsSecurity\Controllers\SettingsController;

/**
 * 1. Middleware Definitions
 */
$tenant           = ResolveTenant::class;
$auth             = AuthJWT::class;
$csrf             = CsrfGuard::class;

// Role-based Access Control (RBAC)
$adminOnly        = AuthorizeRole::class . ':Admin';
$providerOnly     = AuthorizeRole::class . ':Provider';
$providerNurse    = AuthorizeRole::class . ':Provider,Nurse';
$staff            = AuthorizeRole::class . ':Provider,Pharmacist,Admin';
$clinicStaff      = AuthorizeRole::class . ':Admin,Provider,Nurse,Receptionist';
$billingStaff     = AuthorizeRole::class . ':Receptionist,Provider';
$billingAll       = AuthorizeRole::class . ':Receptionist,Patient,Provider';
$allAuthenticated = AuthorizeRole::class . ':Admin,Provider,Nurse,Patient,Pharmacist,Receptionist';


// ═══════════════════════════════════════════════════════════
// HEALTH CHECK (Public)
// ═══════════════════════════════════════════════════════════
$router->get('/api/health', function ($request, $response) {
    \App\Core\Response::json([
        'service'   => 'Clinic Management API',
        'status'    => 'running',
        'timestamp' => date('Y-m-d H:i:s'),
        'hipaa'     => 'AES-256-CBC encryption enabled',
    ], 200);
});


// ═══════════════════════════════════════════════════════════
// MODULE 1 & 2: AUTH & USERS
// ═══════════════════════════════════════════════════════════
$router->group(['prefix' => '/api/auth', 'middleware' => [$tenant]], function ($router) use ($auth, $csrf, $allAuthenticated) {

    $router->post('/register', [AuthController::class, 'register']);
    $router->post('/login',    [AuthController::class, 'login']);
    $router->post('/refresh',  [AuthController::class, 'refresh']);
    $router->get('/csrf-token', [AuthController::class, 'csrfToken']);

    // Protected
    $router->get('/me',      [AuthController::class, 'me'],     [$auth, $allAuthenticated]);
    $router->post('/logout', [AuthController::class, 'logout'], [$auth, $csrf]);
});


// ═══════════════════════════════════════════════════════════
// MODULE 3: PATIENT MANAGEMENT
// ═══════════════════════════════════════════════════════════
$router->group(['prefix' => '/api/patients', 'middleware' => [$tenant, $auth]], function ($router) use ($clinicStaff, $adminOnly, $csrf) {

    $router->get('/',        [PatientController::class, 'index'],   [$clinicStaff]);
    $router->get('/{id}',    [PatientController::class, 'show'],    [$clinicStaff]);
    $router->post('/',       [PatientController::class, 'store'],   [$clinicStaff, $csrf]);
    $router->put('/{id}',    [PatientController::class, 'update'],  [$clinicStaff, $csrf]);
    $router->delete('/{id}', [PatientController::class, 'destroy'], [$adminOnly]);
});


// ═══════════════════════════════════════════════════════════
// MODULE 4: APPOINTMENT MANAGEMENT
// ═══════════════════════════════════════════════════════════
$router->group(['prefix' => '/api/appointments', 'middleware' => [$tenant, $auth]], function ($router) use ($clinicStaff, $allAuthenticated, $csrf) {

    $router->get('/',               [AppointmentController::class, 'index'], [$allAuthenticated]);
    $router->post('/book',          [AppointmentController::class, 'store'], [$allAuthenticated, $csrf]);
    $router->patch('/{id}/status',  [AppointmentController::class, 'updateStatus'], [$clinicStaff, $csrf]);
    $router->delete('/{id}/cancel', [AppointmentController::class, 'destroy'], [$allAuthenticated]);
});


// ═══════════════════════════════════════════════════════════
// MODULE 5: PRESCRIPTION MANAGEMENT
// ═══════════════════════════════════════════════════════════
$router->group(['prefix' => '/api/prescriptions', 'middleware' => [$tenant, $auth]], function ($router) use ($providerOnly, $staff, $csrf) {

    $prescriptionRead = \App\Core\Middleware\AuthorizeRole::class . ':Provider,Pharmacist,Admin,Patient';

    $router->get('/',              [PrescriptionController::class, 'index'],    [$prescriptionRead]);
    $router->get('/{id}',          [PrescriptionController::class, 'show'],     [$prescriptionRead]);
    $router->get('/{id}/download', [PrescriptionController::class, 'download'], [$prescriptionRead]);
    $router->post('/',             [PrescriptionController::class, 'store'],    [$providerOnly, $csrf]); // CREATE (Provider only)
    $router->put('/{id}',          [PrescriptionController::class, 'update'],   [$staff, $csrf]);        // UPDATE (Provider, Pharmacist, Admin)
});


// ═══════════════════════════════════════════════════════════
// MODULE 6: DASHBOARD STATISTICS
// ═══════════════════════════════════════════════════════════
$router->group(['prefix' => '/api/dashboard', 'middleware' => [$tenant, $auth]], function ($router) use ($allAuthenticated) {

    $router->get('/stats', [DashboardController::class, 'index'], [$allAuthenticated]);
});


// ═══════════════════════════════════════════════════════════
// MODULE 7: COMMUNICATION (Notes)
// ═══════════════════════════════════════════════════════════
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
// MODULE 13: NOTIFICATIONS
// ═══════════════════════════════════════════════════════════
$router->group(['prefix' => '/api/notifications', 'middleware' => [$tenant, $auth, $allAuthenticated]], function ($router) use ($csrf) {

    // List notifications (paginated) + unread count
    $router->get('/',              [NotificationController::class, 'index']);

    // Get unread count only (for badge polling)
    $router->get('/unread-count',  [NotificationController::class, 'unreadCount']);

    // Send a system broadcast to all active users (Admin only)
    $router->post('/broadcast',    [NotificationController::class, 'broadcast'],     [$csrf, $adminOnly]);

    // Mark all as read
    $router->post('/mark-all-read', [NotificationController::class, 'markAllAsRead'], [$csrf]);

    // Mark single as read
    $router->patch('/{id}/read',   [NotificationController::class, 'markAsRead'],    [$csrf]);

    // Delete single notification
    $router->delete('/{id}',       [NotificationController::class, 'destroy'],       [$csrf]);
});


// ═══════════════════════════════════════════════════════════
// MODULE 8: BILLING & PAYMENTS
// ═══════════════════════════════════════════════════════════
$router->group(['prefix' => '/api/billing', 'middleware' => [$tenant, $auth]], function ($router) use ($billingStaff, $billingAll, $adminOnly, $csrf) {

    $router->get('/summary',                  [InvoiceController::class, 'summary'],      [$billingAll]);
    $router->get('/invoices',                 [InvoiceController::class, 'index'],        [$billingAll]);
    $router->get('/invoices/{id}',            [InvoiceController::class, 'show'],         [$billingAll]);
    $router->post('/invoices',                [InvoiceController::class, 'store'],        [$billingStaff, $csrf]);
    $router->patch('/invoices/{id}/status',   [InvoiceController::class, 'updateStatus'], [$billingAll, $csrf]);
    $router->delete('/invoices/{id}',         [InvoiceController::class, 'destroy'],      [$adminOnly]);
});


// ═══════════════════════════════════════════════════════════
// MODULE 9: STAFF MANAGEMENT (Admin Only)
// ═══════════════════════════════════════════════════════════
$router->group(['prefix' => '/api/staff', 'middleware' => [$tenant, $auth]], function ($router) use ($adminOnly, $csrf) {

    $router->get('/departments', [StaffController::class, 'departments'], [$adminOnly]);
    $router->get('/',            [StaffController::class, 'index'],       [$adminOnly]);
    $router->get('/{id}',        [StaffController::class, 'show'],        [$adminOnly]);
    $router->post('/',           [StaffController::class, 'store'],       [$adminOnly, $csrf]);
    $router->put('/{id}',        [StaffController::class, 'update'],      [$adminOnly, $csrf]);
    $router->delete('/{id}',     [StaffController::class, 'destroy'],     [$adminOnly]);
});


// ═══════════════════════════════════════════════════════════
// MODULE 10: CALENDAR API
// ═══════════════════════════════════════════════════════════
$router->group(['prefix' => '/api/calendar', 'middleware' => [$tenant, $auth]], function ($router) use ($clinicStaff) {

    $router->get('/events',       [CalendarController::class, 'getByDate'],         [$clinicStaff]);
    $router->get('/range',        [CalendarController::class, 'getByRange'],        [$clinicStaff]);
    $router->get('/tooltip/{id}', [CalendarController::class, 'getTooltip'],        [$clinicStaff]);
    $router->get('/doctor/{id}',  [CalendarController::class, 'getDoctorSchedule'], [$clinicStaff]);
    $router->get('/monthly',      [CalendarController::class, 'getMonthlySummary'], [$clinicStaff]);
});


// ═══════════════════════════════════════════════════════════
// MODULE 11: SETTINGS & SECURITY
// ═══════════════════════════════════════════════════════════

// Public settings (Tenant scoped only, no Auth required)
$router->group(['prefix' => '/api/settings', 'middleware' => [$tenant]], function ($router) {
    $router->get('/theme', [SettingsController::class, 'getTheme']);
});

$router->group(['prefix' => '/api/settings', 'middleware' => [$tenant, $auth]], function ($router) use ($allAuthenticated, $adminOnly, $csrf) {

    $router->post('/change-password', [SettingsController::class, 'changePassword'], [$allAuthenticated, $csrf]);
    $router->post('/logout',          [SettingsController::class, 'logout'],         [$allAuthenticated, $csrf]);
    $router->post('/logout-all',      [SettingsController::class, 'logoutAll'],      [$allAuthenticated, $csrf]);
  
    $router->post('/theme',           [SettingsController::class, 'updateTheme'],    [$adminOnly, $csrf]);

    $router->get('/csrf-token',       [SettingsController::class, 'csrfRegenerate'], [$allAuthenticated]);

    $router->get('/sessions',         [SettingsController::class, 'listSessions'],      [$allAuthenticated]);
    $router->delete('/sessions/{id}', [SettingsController::class, 'invalidateSession'], [$allAuthenticated]);

    $router->get('/audit-log',         [SettingsController::class, 'auditLog'],     [$adminOnly]);
    $router->get('/audit-log/actions', [SettingsController::class, 'auditActions'], [$adminOnly]);

});

// rotate-tokens uses only $tenant middleware (no $auth) because the access token
// may be expired — that is precisely WHY the client is calling this endpoint.
// Authentication is instead verified via the refresh token cookie itself.
$router->post('/api/settings/rotate-tokens', [SettingsController::class, 'rotateTokens'], [$tenant]);


$router->get('/api/users/roles',     [UserController::class, 'listRoles'], [$tenant, $auth, $clinicStaff]);
$router->get('/api/users',           [UserController::class, 'index'],     [$tenant, $auth, $clinicStaff]);
$router->get('/api/users/providers', [UserController::class, 'providers'], [$tenant, $auth, $allAuthenticated]);

// ═══════════════════════════════════════════════════════════
// SUPER ADMIN MODULE — Platform Control Plane
// No X-Tenant-ID required. Uses separate JWT scope.
// ═══════════════════════════════════════════════════════════
use App\Modules\SuperAdmin\Controllers\SuperAdminAuthController;
use App\Modules\SuperAdmin\Controllers\TenantController;
use App\Modules\SuperAdmin\Controllers\SuperAdminDashboardController;
use App\Core\Middleware\AuthSuperAdmin;

$superAuth = AuthSuperAdmin::class;

// Public: Super Admin Login
$router->post('/api/super-admin/auth/login', [SuperAdminAuthController::class, 'login']);

// Protected super admin routes
$router->group(['prefix' => '/api/super-admin', 'middleware' => [$superAuth]], function ($router) {

    $router->get('/auth/me',            [SuperAdminAuthController::class, 'me']);
    $router->post('/auth/create-admin', [SuperAdminAuthController::class, 'createAdmin']);

    $router->get('/dashboard',  [SuperAdminDashboardController::class, 'index']);
    $router->get('/audit-log',  [SuperAdminDashboardController::class, 'auditLog']);

    $router->get('/tenants',                     [TenantController::class, 'index']);
    $router->post('/tenants',                    [TenantController::class, 'store']);
    $router->get('/tenants/{code}',              [TenantController::class, 'show']);
    $router->put('/tenants/{code}',              [TenantController::class, 'update']);
    $router->post('/tenants/{code}/suspend',     [TenantController::class, 'suspend']);
    $router->post('/tenants/{code}/reactivate',  [TenantController::class, 'reactivate']);
    $router->post('/tenants/{code}/change-plan', [TenantController::class, 'changePlan']);
    $router->get('/tenants/{code}/stats',        [TenantController::class, 'stats']);
    $router->delete('/tenants/{code}',           [TenantController::class, 'destroy']);
});