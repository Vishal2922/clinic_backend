<?php

namespace App\Modules\Billing\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\NotificationHelper;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\BillingService;

class InvoiceController extends Controller
{
    private Invoice $invoiceModel;
    private BillingService $billingService;

    public function __construct()
    {
        $this->invoiceModel   = new Invoice();
        $this->billingService = new BillingService();
    }

    public function index(Request $request, $id = null): void
    {
        $tenantId  = $this->getTenantId();
        $user      = $this->getAuthUser();
        $userRole  = $user['role_name'] ?? '';

        $patientId = $request->getQueryParam('patient_id');
        $status    = $request->getQueryParam('status');
        $page      = (int) $request->getQueryParam('page', 1);
        $perPage   = (int) $request->getQueryParam('per_page', 15);

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

    public function store(Request $request, $id = null): void
    {
        $tenantId = $this->getTenantId();
        $user     = $this->getAuthUser();
        $data     = $request->getBody();

        // FIX: frontend sends `amount` (subtotal from line items).
        // Accept `amount` directly, or fall back to `total_amount` if
        // the client only sent the grand total.
        if (!isset($data['amount']) && isset($data['total_amount'])) {
            $data['amount'] = $data['total_amount'];
        }

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

        $providerId = $user['user_id'] ?? null;

        if (!$providerId) {
            Response::error('Unable to identify provider. Please re-login.', 401);
        }

        $db = tenant_db();
        $providerExists = $db->fetch(
            'SELECT id FROM users WHERE id = :id AND deleted_at IS NULL',
            ['id' => $providerId]
        );

        if (!$providerExists) {
            Response::error('Provider not found. Please contact administrator.', 400);
        }

        try {
            $result  = $this->billingService->generateInvoice($data, $tenantId, (int) $providerId);
            $invoice = $this->invoiceModel->findById($result['id'], $tenantId);

            // Notify all Admins about the new invoice
            $invoiceNum = $invoice['invoice_number'] ?? "INV-" . str_pad($result['id'], 4, '0', STR_PAD_LEFT);
            $amount = number_format((float) ($invoice['total_amount'] ?? $data['amount']), 2);
            NotificationHelper::notifyRole(
                $tenantId,
                'Admin',
                'billing',
                '🧾 New Invoice Generated',
                "Invoice {$invoiceNum} created for ₹{$amount}",
                'invoice',
                (int) $result['id']
            );
            
            // Notify the Patient about the new invoice
            NotificationHelper::notifyPatient(
                $tenantId,
                (int) $data['patient_id'],
                'billing',
                '🧾 New Invoice Generated',
                "A new invoice ({$invoiceNum}) for ₹{$amount} has been generated for your record.",
                'invoice',
                (int) $result['id']
            );

            Response::json([
                'message' => 'Invoice generated successfully.',
                'data'    => $invoice,
            ], 201);

        } catch (\Exception $e) {
            app_log('Generate invoice error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to generate invoice: ' . $e->getMessage(), 500);
        }
    }

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

    public function updateStatus(Request $request, string $id): void
    {
        $tenantId = $this->getTenantId();
        $user     = $this->getAuthUser();
        $userRole = $user['role_name'] ?? '';
        $data     = $request->getBody();

        $errors = $this->validate($data, [
            'status' => 'required|in:pending,paid,partially_paid,overdue,cancelled,refunded',
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

            if ($userRole === 'Patient' && (int) $invoice['patient_id'] !== (int) ($user['patient_id'] ?? 0)) {
                Response::error('Access denied.', 403);
            }

            if ($invoice['status'] === 'cancelled') {
                Response::error('Cannot modify a cancelled invoice.', 422);
            }

            if ($invoice['status'] === 'paid') {
                Response::error('Cannot modify a paid invoice.', 422);
            }

            // Require payment_method when Patient marks as paid
            if ($userRole === 'Patient' && $data['status'] === 'paid') {
                if (empty($data['payment_method'])) {
                    Response::error('Payment method is required when marking as paid.', 422);
                }
            }

            $paidAt        = ($data['status'] === 'paid') ? date('Y-m-d H:i:s') : null;
            $paymentMethod = $data['payment_method'] ?? null;
            $this->invoiceModel->updateStatus((int) $id, $tenantId, $data['status'], $paidAt, $paymentMethod);

            $updated = $this->invoiceModel->findById((int) $id, $tenantId);

            // Notify Admins when invoice is paid
            if ($data['status'] === 'paid') {
                $invoiceNum = $updated['invoice_number'] ?? "INV-" . str_pad($id, 4, '0', STR_PAD_LEFT);
                $amount = number_format((float) ($updated['total_amount'] ?? 0), 2);
                NotificationHelper::notifyRole(
                    $tenantId,
                    'Admin',
                    'billing',
                    '💰 Invoice Payment Received',
                    "Invoice {$invoiceNum} has been paid — ₹{$amount}",
                    'invoice',
                    (int) $id
                );
            }

            Response::json([
                'message' => "Invoice status updated to '{$data['status']}'.",
                'data'    => $updated,
            ], 200);

        } catch (\Exception $e) {
            app_log('Update invoice status error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to update invoice status.', 500);
        }
    }

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

    public function summary(Request $request, $id = null): void
    {
        $tenantId = $this->getTenantId();
        $user     = $this->getAuthUser();
        $userRole = $user['role_name'] ?? '';

        try {
            $patientId = ($userRole === 'Patient') ? ($user['patient_id'] ?? 0) : null;
            $summary   = $this->invoiceModel->getSummary($tenantId, $patientId ? (int) $patientId : null);

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