<?php

namespace App\Modules\AuthTenant\Controllers;

use App\Core\Controller;
use App\Modules\AuthTenant\Services\AuthService;
use App\Core\Middleware\CsrfGuard;

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

        // Validate input
        $errors = $this->validate($data, [
            'username'  => 'required|min:3|max:50',
            'email'     => 'required|email',
            'password'  => 'required|min:8',
            'full_name' => 'required|min:2|max:100',
        ]);

        if (!empty($errors)) {
            $this->response->error('Validation failed', 422, $errors);
        }

        // Password strength check
        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $data['password'])) {
            $this->response->error(
                'Password must contain at least 8 characters, one uppercase, one lowercase, one number, and one special character.',
                422
            );
        }

        try {
            $result = $this->authService->register($data, $tenantId);
            $this->response->success($result, 'User registered successfully', 201);
        } catch (\RuntimeException $e) {
            $this->response->error($e->getMessage(), 409);
        } catch (\Exception $e) {
            app_log('Registration error: ' . $e->getMessage(), 'ERROR');
            $this->response->error('Registration failed. Please try again.', 500);
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
            $this->response->error('Validation failed', 422, $errors);
        }

        try {
            $result = $this->authService->login(
                sanitize($data['username']),
                $data['password'],
                $tenantId
            );

            $this->response->success($result, 'Login successful');
        } catch (\RuntimeException $e) {
            $this->response->error($e->getMessage(), 401);
        } catch (\Exception $e) {
            app_log('Login error: ' . $e->getMessage(), 'ERROR');
            $this->response->error('Login failed. Please try again.', 500);
        }
    }

    /**
     * POST /api/auth/refresh
     * Uses refresh token from HttpOnly cookie
     * Returns new access token + rotated refresh cookie + new CSRF
     */
    public function refresh(): void
    {
        $cookieName   = env('REFRESH_COOKIE_NAME', 'refresh_token');
        $refreshToken = $this->request->getCookie($cookieName);

        if (!$refreshToken) {
            $this->response->error('Refresh token not found in cookie.', 401);
        }

        try {
            $result = $this->authService->refreshAccessToken($refreshToken);
            $this->response->success($result, 'Token refreshed successfully');
        } catch (\RuntimeException $e) {
            $this->response->error($e->getMessage(), 401);
        } catch (\Exception $e) {
            app_log('Token refresh error: ' . $e->getMessage(), 'ERROR');
            $this->response->error('Token refresh failed.', 500);
        }
    }

    /**
     * POST /api/auth/logout
     * Requires: Bearer token + CSRF
     */
    public function logout(): void
    {
        $authUser     = $this->getAuthUser();
        $cookieName   = env('REFRESH_COOKIE_NAME', 'refresh_token');
        $refreshToken = $this->request->getCookie($cookieName);

        try {
            $this->authService->logout($refreshToken, $authUser['user_id']);
            $this->response->success([], 'Logged out successfully');
        } catch (\Exception $e) {
            app_log('Logout error: ' . $e->getMessage(), 'ERROR');
            $this->response->error('Logout failed.', 500);
        }
    }

    /**
     * POST /api/auth/logout-all
     * Logout from all devices
     */
    public function logoutAll(): void
    {
        $authUser = $this->getAuthUser();

        try {
            $this->authService->logoutAll($authUser['user_id']);
            $this->response->success([], 'Logged out from all devices');
        } catch (\Exception $e) {
            app_log('Logout all error: ' . $e->getMessage(), 'ERROR');
            $this->response->error('Logout failed.', 500);
        }
    }

    /**
     * POST /api/auth/change-password
     * Requires: Bearer token + CSRF
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
            $this->response->error('Validation failed', 422, $errors);
        }

        if ($data['current_password'] === $data['new_password']) {
            $this->response->error('New password must be different from current password.', 422);
        }

        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $data['new_password'])) {
            $this->response->error(
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
            $this->response->success([], 'Password changed successfully. Please login again.');
        } catch (\RuntimeException $e) {
            $this->response->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            app_log('Change password error: ' . $e->getMessage(), 'ERROR');
            $this->response->error('Password change failed.', 500);
        }
    }

    /**
     * GET /api/auth/csrf-token
     * Generate and return a CSRF token
     */
    public function csrfToken(): void
    {
        $token = CsrfGuard::generate();

        $this->response->success([
            'csrf_token' => $token,
            'expires_in' => (int) env('CSRF_TTL', 3600),
        ], 'CSRF token generated');
    }

    /**
     * GET /api/auth/me
     * Get current authenticated user info from JWT payload
     */
    public function me(): void
    {
        $authUser = $this->getAuthUser();
        $this->response->success($authUser, 'Authenticated user');
    }
}