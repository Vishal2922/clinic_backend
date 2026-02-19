<?php

namespace App\Modules\SettingsSecurity\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Modules\SettingsSecurity\Services\SettingsService;

/**
 * SettingsController
 * User account and security management endpoints.
 *
 * Key Features:
 *  - Change password
 *  - Logout (invalidate session & refresh token)
 *  - Token rotation
 *  - CSRF regeneration
 *  - Session management (view/invalidate)
 *  - Audit log (Admin only)
 *
 * Access: All authenticated users (some endpoints Admin-only).
 */
class SettingsController extends Controller
{
    private SettingsService $settingsService;

    public function __construct()
    {
        $this->settingsService = new SettingsService();
    }

    /**
     * POST /api/settings/change-password
     * All authenticated users.
     */
    public function changePassword(Request $request): void
    {
        $authUser = $this->getAuthUser();
        $data     = $request->getBody();

        $errors = $this->validate($data, [
            'current_password' => 'required',
            'new_password'     => 'required|min:8',
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
            return; // FIX: explicit return after error response
        }

        if ($data['current_password'] === $data['new_password']) {
            Response::error('New password must be different from current password.', 422);
            return; // FIX: explicit return after error response
        }

        // Enforce password policy
        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $data['new_password'])) {
            Response::error(
                'Password must contain at least 8 characters, one uppercase, one lowercase, one number, and one special character.',
                422
            );
            return; // FIX: explicit return after error response
        }

        try {
            $this->settingsService->changePassword(
                $authUser['user_id'],
                $data['current_password'],
                $data['new_password'],
                [
                    'tenant_id'  => $authUser['tenant_id'] ?? $this->getTenantId(),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                ]
            );

            Response::json([
                'message' => 'Password changed successfully. Please login again.',
                'data'    => [],
            ], 200);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 400);
        } catch (\Exception $e) {
            app_log('Change password error: ' . $e->getMessage(), 'ERROR');
            Response::error('Password change failed.', 500);
        }
    }

    /**
     * POST /api/settings/logout
     * Logout current session.
     */
    public function logout(Request $request): void
    {
        $authUser     = $this->getAuthUser();
        $cookieName   = env('REFRESH_COOKIE_NAME', 'refresh_token');
        $refreshToken = $request->getCookie($cookieName);

        try {
            $this->settingsService->logout(
                $refreshToken,
                $authUser['user_id'],
                [
                    'tenant_id'  => $authUser['tenant_id'] ?? $this->getTenantId(),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                ]
            );

            Response::json(['message' => 'Logged out successfully', 'data' => []], 200);
        } catch (\Exception $e) {
            app_log('Logout error: ' . $e->getMessage(), 'ERROR');
            Response::error('Logout failed.', 500);
        }
    }

    /**
     * POST /api/settings/logout-all
     * Invalidate all sessions and tokens.
     */
    public function logoutAll(Request $request): void
    {
        $authUser = $this->getAuthUser();

        try {
            $this->settingsService->logoutAll(
                $authUser['user_id'],
                [
                    'tenant_id'  => $authUser['tenant_id'] ?? $this->getTenantId(),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                ]
            );

            Response::json(['message' => 'Logged out from all devices', 'data' => []], 200);
        } catch (\Exception $e) {
            app_log('Logout all error: ' . $e->getMessage(), 'ERROR');
            Response::error('Logout failed.', 500);
        }
    }

    /**
     * POST /api/settings/rotate-tokens
     * Proactive token rotation for enhanced security.
     *
     * FIX: This endpoint does NOT require AuthJWT middleware.
     * The access token may be expired — that is precisely why the client calls this.
     * Authentication is verified via the refresh token cookie itself.
     * user_id and tenant_id are read from the validated refresh token DB record,
     * NOT from $this->getAuthUser() which requires a valid access token.
     */
    public function rotateTokens(Request $request): void
    {
        $cookieName   = env('REFRESH_COOKIE_NAME', 'refresh_token');
        $refreshToken = $request->getCookie($cookieName);

        // FIX: Guard checks both null AND non-string to resolve the nullable string
        // type warning. getCookie() returns ?string, but rotateTokens() expects string.
        // The explicit (string) cast after the guard fully satisfies PHP strict typing.
        if (!$refreshToken || !is_string($refreshToken)) {
            Response::error('Refresh token not found. Please log in again.', 401);
            return;
        }

        try {
            // $refreshToken is guaranteed a non-null non-empty string after the guard above.
            $result = $this->settingsService->rotateTokens((string) $refreshToken);

            Response::json(['message' => 'Tokens rotated successfully', 'data' => $result], 200);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 401);
        } catch (\Exception $e) {
            app_log('Token rotation error: ' . $e->getMessage(), 'ERROR');
            Response::error('Token rotation failed.', 500);
        }
    }

    /**
     * GET /api/settings/csrf-token
     * Regenerate CSRF token.
     */
    public function csrfRegenerate(Request $request): void
    {
        try {
            $result = $this->settingsService->regenerateCsrf();

            Response::json([
                'message' => 'CSRF token regenerated',
                'data'    => $result,
            ], 200);
        } catch (\Exception $e) {
            app_log('CSRF regeneration error: ' . $e->getMessage(), 'ERROR');
            Response::error('CSRF regeneration failed.', 500);
        }
    }

    /**
     * GET /api/settings/sessions
     * View active sessions for the current user.
     */
    public function listSessions(Request $request): void
    {
        $authUser = $this->getAuthUser();

        try {
            $sessions = $this->settingsService->getActiveSessions($authUser['user_id']);

            Response::json([
                'message' => 'Active sessions retrieved',
                'data'    => ['sessions' => $sessions],
            ], 200);
        } catch (\Exception $e) {
            app_log('List sessions error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to retrieve sessions.', 500);
        }
    }

    /**
     * DELETE /api/settings/sessions/{id}
     * Invalidate a specific session.
     */
    public function invalidateSession(Request $request, string $id): void
    {
        $authUser = $this->getAuthUser();

        try {
            $result = $this->settingsService->invalidateSession(
                (int) $id,
                $authUser['user_id'],
                [
                    'tenant_id'  => $authUser['tenant_id'] ?? $this->getTenantId(),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                ]
            );

            if (!$result) {
                Response::error('Session not found or already invalidated.', 404);
                return; // FIX: explicit return after error response
            }

            Response::json(['message' => 'Session invalidated successfully', 'data' => []], 200);
        } catch (\Exception $e) {
            app_log('Invalidate session error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to invalidate session.', 500);
        }
    }

    /**
     * GET /api/settings/audit-log
     * Admin only: View security audit log.
     */
    public function auditLog(Request $request): void
    {
        $tenantId = $this->getTenantId();
        $page     = (int) $request->getQueryParam('page', 1);
        $perPage  = (int) $request->getQueryParam('per_page', 50);

        $filters = [
            'user_id'     => $request->getQueryParam('user_id'),
            'action'      => $request->getQueryParam('action'),
            'entity_type' => $request->getQueryParam('entity_type'),
            'date_from'   => $request->getQueryParam('date_from'),
            'date_to'     => $request->getQueryParam('date_to'),
        ];

        try {
            $result = $this->settingsService->getAuditLog($tenantId, $page, $perPage, $filters);
            Response::json(['message' => 'Audit log retrieved', 'data' => $result], 200);
        } catch (\Exception $e) {
            app_log('Audit log error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to retrieve audit log.', 500);
        }
    }

    /**
     * GET /api/settings/audit-log/actions
     * Admin only: Get distinct action types for filter UI.
     */
    public function auditActions(Request $request): void
    {
        $tenantId = $this->getTenantId();

        try {
            $actions = $this->settingsService->getAuditActions($tenantId);
            Response::json([
                'message' => 'Audit actions retrieved',
                'data'    => ['actions' => array_column($actions, 'action')],
            ], 200);
        } catch (\Exception $e) {
            app_log('Audit actions error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to retrieve audit actions.', 500);
        }
    }
}