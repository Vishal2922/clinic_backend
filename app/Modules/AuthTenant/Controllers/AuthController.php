<?php

namespace App\Modules\AuthTenant\Controllers;

use App\Core\Controller;
use App\Modules\AuthTenant\Services\AuthService;
use App\Core\Middleware\CsrfGuard;
use App\Core\Response;

/**
 * AuthController: Fixed Version.
 * Bugs Fixed:
 * 1. $this->response->success($result, 'message', 201) — data-first argument order.
 *    The static Response::success() takes (message, data, code).
 *    Fixed by calling Response::json() directly with correct structure.
 * 2. After $this->response->error(...) calls, code continued to execute because
 *    static error() calls exit, BUT $this->response->error() was calling a non-existent
 *    instance method. Now all response calls go through static Response:: methods.
 * 3. csrfToken() called $this->response->success([...], 'message') — same argument order bug.
 */
class AuthController extends Controller
{
    private AuthService $authService;

    public function __construct()
    {
        $this->authService = new AuthService();
    }

    /**
     * POST /api/auth/register
     */
    public function register(): void
    {
        $data     = $this->request->getBody();
        $tenantId = $this->getTenantId();

        $errors = $this->validate($data, [
            'username'  => 'required|min:3|max:50',
            'email'     => 'required|email',
            'password'  => 'required|min:8',
            'full_name' => 'required|min:2|max:100',
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $data['password'])) {
            Response::error(
                'Password must contain at least 8 characters, one uppercase, one lowercase, one number, and one special character.',
                422
            );
        }

        try {
            $result = $this->authService->register($data, $tenantId);
            // FIX #1: Use Response::json() directly to avoid argument-order confusion
            Response::json(['message' => 'User registered successfully', 'data' => $result], 201);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 409);
        } catch (\Exception $e) {
            app_log('Registration error: ' . $e->getMessage(), 'ERROR');
            Response::error('Registration failed. Please try again.', 500);
        }
    }

    /**
     * POST /api/auth/login
     */
    public function login(): void
    {
        $data     = $this->request->getBody();
        $tenantId = $this->getTenantId();

        $errors = $this->validate($data, [
            'username' => 'required',
            'password' => 'required',
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        try {
            $result = $this->authService->login(
                sanitize($data['username']),
                $data['password'],
                $tenantId
            );
            Response::json(['message' => 'Login successful', 'data' => $result], 200);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 401);
        } catch (\Exception $e) {
            app_log('Login error: ' . $e->getMessage(), 'ERROR');
            Response::error('Login failed. Please try again.', 500);
        }
    }

    /**
     * POST /api/auth/refresh
     */
    public function refresh(): void
    {
        $cookieName   = env('REFRESH_COOKIE_NAME', 'refresh_token');
        $refreshToken = $this->request->getCookie($cookieName);

        if (!$refreshToken) {
            Response::error('Refresh token not found in cookie.', 401);
        }

        try {
            $result = $this->authService->refreshAccessToken($refreshToken);
            Response::json(['message' => 'Token refreshed successfully', 'data' => $result], 200);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 401);
        } catch (\Exception $e) {
            app_log('Token refresh error: ' . $e->getMessage(), 'ERROR');
            Response::error('Token refresh failed.', 500);
        }
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(): void
    {
        $authUser     = $this->getAuthUser();
        $cookieName   = env('REFRESH_COOKIE_NAME', 'refresh_token');
        $refreshToken = $this->request->getCookie($cookieName);

        try {
            $this->authService->logout($refreshToken, $authUser['user_id']);
            Response::json(['message' => 'Logged out successfully', 'data' => []], 200);
        } catch (\Exception $e) {
            app_log('Logout error: ' . $e->getMessage(), 'ERROR');
            Response::error('Logout failed.', 500);
        }
    }

    /**
     * POST /api/auth/logout-all
     */
    public function logoutAll(): void
    {
        $authUser = $this->getAuthUser();

        try {
            $this->authService->logoutAll($authUser['user_id']);
            Response::json(['message' => 'Logged out from all devices', 'data' => []], 200);
        } catch (\Exception $e) {
            app_log('Logout all error: ' . $e->getMessage(), 'ERROR');
            Response::error('Logout failed.', 500);
        }
    }

    /**
     * POST /api/auth/change-password
     */
    public function changePassword(): void
    {
        $authUser = $this->getAuthUser();
        $data     = $this->request->getBody();

        $errors = $this->validate($data, [
            'current_password' => 'required',
            'new_password'     => 'required|min:8',
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        if ($data['current_password'] === $data['new_password']) {
            Response::error('New password must be different from current password.', 422);
        }

        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $data['new_password'])) {
            Response::error(
                'Password must contain at least 8 characters, one uppercase, one lowercase, one number, and one special character.',
                422
            );
        }

        try {
            $this->authService->changePassword(
                $authUser['user_id'],
                $data['current_password'],
                $data['new_password']
            );
            Response::json(['message' => 'Password changed successfully. Please login again.', 'data' => []], 200);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 400);
        } catch (\Exception $e) {
            app_log('Change password error: ' . $e->getMessage(), 'ERROR');
            Response::error('Password change failed.', 500);
        }
    }

    /**
     * GET /api/auth/csrf-token
     * FIX #3: Argument order corrected.
     */
    public function csrfToken(): void
    {
        $token = CsrfGuard::generate();
        Response::json([
            'message' => 'CSRF token generated',
            'data'    => [
                'csrf_token' => $token,
                'expires_in' => (int) env('CSRF_TTL', 3600),
            ],
        ], 200);
    }

    /**
     * GET /api/auth/me
     */
    public function me(): void
    {
        $authUser = $this->getAuthUser();
        Response::json(['message' => 'Authenticated user', 'data' => $authUser], 200);
    }
}