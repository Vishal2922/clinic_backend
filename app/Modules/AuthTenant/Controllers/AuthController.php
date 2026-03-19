<?php

namespace App\Modules\AuthTenant\Controllers;

use App\Core\Controller;
use App\Modules\AuthTenant\Services\AuthService;
use App\Core\Middleware\CsrfGuard;
use App\Core\Response;

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
            return;
        }

        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $data['password'])) {
            Response::error(
                'Password must contain at least 8 characters, one uppercase, one lowercase, one number, and one special character.',
                422
            );
            return;
        }

        try {
            $result = $this->authService->register($data, $tenantId);
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
            return;
        }

        try {
            // FIX: Removed sanitize() to prevent htmlspecialchars from corrupting valid usernames/emails
            $result = $this->authService->login(
                trim($data['username']), 
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
        //$cookieName   = $_COOKIE['refresh_token'] ?? null;
        $refreshToken = $this->request->getCookie($cookieName);
        $tenantId     = $this->getTenantId();

        if (!$refreshToken) {
            Response::error('Refresh token not found in cookie.', 401);
            return;
        }

        try {
            $result = $this->authService->refreshAccessToken($refreshToken, $tenantId);
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
            return;
        }

        if ($data['current_password'] === $data['new_password']) {
            Response::error('New password must be different from current password.', 422);
            return;
        }

        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $data['new_password'])) {
            Response::error(
                'Password must contain at least 8 characters, one uppercase, one lowercase, one number, and one special character.',
                422
            );
            return;
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