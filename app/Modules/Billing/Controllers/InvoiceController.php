<?php

namespace App\Modules\Billing\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\BillingService;

/**
 * InvoiceController
 *
 * Manages invoices and payments within tenant scope.
 *
 * Role access:
 *  Admin    -> full access (list, create, update status, delete, summary)
 *  Provider -> list, create, update status (pending/paid/overdue only)
 *  Patient  -> view own invoices, mark as paid only
 *
 * Routes:
 *  GET    /api/billing/invoices             -> index
 *  POST   /api/billing/invoices             -> store
 *  GET    /api/billing/invoices/{id}        -> show
 *  PATCH  /api/billing/invoices/{id}/status -> updateStatus
 *  DELETE /api/billing/invoices/{id}        -> destroy
 *  GET    /api/billing/summary              -> summary
 */
class InvoiceController extends Controller
{
    private Invoice $invoiceModel;
    private BillingService $billingService;

    public function __construct()
    {
        $this->invoiceModel   = new Invoice();
        $this->billingService = new BillingService();
    }

    /**
     * GET /api/billing/invoices
     * List all invoices for the tenant.
     * Patients automatically see only their own invoices.
     *
     * Query params: patient_id, status, page, per_page
     */
    public function index(Request $request, $id = null): void
    {
        $tenantId = $this->getTenantId();
        $user     = $this->getAuthUser();
        $userRole = $user['role_name'] ?? '';

        $patientId = $request->getQueryParam('patient_id');
        $status    = $request->getQueryParam('status');
        $page      = (int) $request->getQueryParam('page', 1);
        $perPage   = (int) $request->getQueryParam('per_page', 15);

        // Patients can only see their own invoices
        if ($userRole === 'Patient') {
            $patientId = $user['patient_id'] ?? null;
        }

        if ($status && !in_array($status, BillingService::STATUSES)) {
            Response::error('Invalid status filter.', 422);
        }

        try {
            $result = $this->invoiceModel->getAllByTenant(
                $tenantId,
                $patientId ? (int) $patientId : null,
                $status,
                $page,
                $perPage
            );

            Response::json([
                'message' => 'Invoices retrieved successfully.',
                'data'    => $result,
            ], 200);

        } catch (\Exception $e) {
            app_log('List invoices error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to retrieve invoices.', 500);
        }
    }

    /**
     * POST /api/billing/invoices
     * Generate a new invoice. Roles: Admin, Provider.
     *
     * Body params:
     *  - patient_id     (required)
     *  - amount         (required) numeric, base amount before tax
     *  - tax_percent    (optional) default 0
     *  - appointment_id (optional)
     *  - due_date       (optional) YYYY-MM-DD
     *  - notes          (optional)
     */
    public function store(Request $request, $id = null): void
    {
        $tenantId = $this->getTenantId();
        $user     = $this->getAuthUser();
        $data     = $request->getBody();

        $errors = $this->validate($data, [
            'patient_id'  => 'required|numeric',
            'amount'      => 'required|numeric',
            'tax_percent' => 'numeric',
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }

        if ((float) $data['amount'] <= 0) {
            Response::error('Amount must be greater than zero.', 422);
        }

        // FIX: Use the correct variable $user instead of undefined $authUser
        // FIX: Use 'user_id' key which is what getAuthUser() returns
        $providerId = $user['user_id'] ?? null;
        
        if (!$providerId) {
            Response::error('Unable to identify provider. Please re-login.', 401);
        }

        // Verify the provider exists in users table
        $db = \App\Core\Database::getInstance();
        $providerExists = $db->fetch(
            'SELECT id FROM users WHERE id = :id AND tenant_id = :tid AND deleted_at IS NULL',
            ['id' => $providerId, 'tid' => $tenantId]
        );

        if (!$providerExists) {
            Response::error('Provider not found. Please contact administrator.', 400);
        }

        try {
            $result  = $this->billingService->generateInvoice($data, $tenantId, (int) $providerId);
            $invoice = $this->invoiceModel->findById($result['id'], $tenantId);

            Response::json([
                'message' => 'Invoice generated successfully.',
                'data'    => $invoice,
            ], 201);

        } catch (\Exception $e) {
            app_log('Generate invoice error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to generate invoice: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/billing/invoices/{id}
     * Retrieve a single invoice by ID.
     */
    public function show(Request $request, string $id): void
    {
        $tenantId = $this->getTenantId();
        $user     = $this->getAuthUser();
        $userRole = $user['role_name'] ?? '';

        try {
            $invoice = $this->invoiceModel->findById((int) $id, $tenantId);

            if (!$invoice) {
                Response::error('Invoice not found.', 404);
            }

            // Patients can only view their own invoices
            if ($userRole === 'Patient' && (int) $invoice['patient_id'] !== (int) ($user['patient_id'] ?? 0)) {
                Response::error('Access denied.', 403);
            }

            Response::json([
                'message' => 'Invoice retrieved.',
                'data'    => $invoice,
            ], 200);

        } catch (\Exception $e) {
            app_log('Show invoice error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to retrieve invoice.', 500);
        }
    }

    /**
     * PATCH /api/billing/invoices/{id}/status
     * Update invoice payment status.
     *
     * Body params:
     *  - status (required) pending | paid | overdue | cancelled
     */
    public function updateStatus(Request $request, string $id): void
    {
        $tenantId = $this->getTenantId();
        $user     = $this->getAuthUser();
        $userRole = $user['role_name'] ?? '';
        $data     = $request->getBody();

        $errors = $this->validate($data, [
            'status' => 'required|in:pending,paid,overdue,cancelled',
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }

        try {
            $invoice = $this->invoiceModel->findById((int) $id, $tenantId);
            if (!$invoice) {
                Response::error('Invoice not found.', 404);
            }

            if (!$this->billingService->canSetStatus($userRole, $data['status'])) {
                Response::error("Your role is not permitted to set status to '{$data['status']}'.", 403);
            }

            // Patients can only update their own invoice
            if ($userRole === 'Patient' && (int) $invoice['patient_id'] !== (int) ($user['patient_id'] ?? 0)) {
                Response::error('Access denied.', 403);
            }

            if ($invoice['status'] === 'cancelled') {
                Response::error('Cannot modify a cancelled invoice.', 422);
            }

            $paidAt = ($data['status'] === 'paid') ? date('Y-m-d H:i:s') : null;
            $this->invoiceModel->updateStatus((int) $id, $tenantId, $data['status'], $paidAt);

            $updated = $this->invoiceModel->findById((int) $id, $tenantId);

            Response::json([
                'message' => "Invoice status updated to '{$data['status']}'.",
                'data'    => $updated,
            ], 200);

        } catch (\Exception $e) {
            app_log('Update invoice status error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to update invoice status.', 500);
        }
    }

    /**
     * DELETE /api/billing/invoices/{id}
     * Soft delete an invoice. Role: Admin only.
     */
    public function destroy(Request $request, string $id): void
    {
        $tenantId = $this->getTenantId();

        try {
            $invoice = $this->invoiceModel->findById((int) $id, $tenantId);
            if (!$invoice) {
                Response::error('Invoice not found.', 404);
            }

            $this->invoiceModel->softDelete((int) $id, $tenantId);

            Response::json(['message' => 'Invoice deleted successfully.'], 200);

        } catch (\Exception $e) {
            app_log('Delete invoice error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to delete invoice.', 500);
        }
    }

    /**
     * GET /api/billing/summary
     * Pending/paid/overdue totals for the tenant.
     * Roles: Admin, Provider.
     */
    public function summary(Request $request, $id = null): void
    {
        $tenantId = $this->getTenantId();

        try {
            $summary = $this->invoiceModel->getSummary($tenantId);

            Response::json([
                'message' => 'Billing summary retrieved.',
                'data'    => $summary,
            ], 200);

        } catch (\Exception $e) {
            app_log('Billing summary error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to retrieve billing summary.', 500);
        }
    }
}