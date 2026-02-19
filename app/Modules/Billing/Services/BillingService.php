<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Invoice;

/**
 * BillingService
 *
 * Business logic for invoice generation, payment processing,
 * and billing summaries.
 */
class BillingService
{
    private Invoice $invoiceModel;

    // Valid invoice statuses
    public const STATUSES = ['pending', 'paid', 'overdue', 'cancelled'];

    // Status transitions allowed per role
    private const ROLE_TRANSITIONS = [
        'Admin'    => ['pending', 'paid', 'overdue', 'cancelled'],
        'Provider' => ['pending', 'paid', 'overdue'],
        'Patient'  => ['paid'],
    ];

    public function __construct()
    {
        $this->invoiceModel = new Invoice();
    }

    /**
     * Calculate total amount from base amount + tax percentage.
     */
    public function calculateTotal(float $amount, float $taxPercent): array
    {
        $tax   = round($amount * ($taxPercent / 100), 2);
        $total = round($amount + $tax, 2);

        return [
            'amount'       => $amount,
            'tax'          => $tax,
            'total_amount' => $total,
        ];
    }

    /**
     * Check if a role is allowed to set a particular status.
     */
    public function canSetStatus(string $role, string $targetStatus): bool
    {
        $allowed = self::ROLE_TRANSITIONS[$role] ?? [];
        return in_array($targetStatus, $allowed);
    }

    /**
     * Generate a new invoice and persist it.
     * Returns array with id, invoice_number, and amounts.
     */
    public function generateInvoice(array $data, int $tenantId, int $providerId): array
    {
        $amounts = $this->calculateTotal(
            (float) $data['amount'],
            (float) ($data['tax_percent'] ?? 0)
        );

        $invoiceNumber = $this->invoiceModel->generateInvoiceNumber($tenantId);

        $id = $this->invoiceModel->create([
            'tenant_id'      => $tenantId,
            'patient_id'     => (int) $data['patient_id'],
            'provider_id'    => $providerId,
            'appointment_id' => isset($data['appointment_id']) ? (int) $data['appointment_id'] : null,
            'invoice_number' => $invoiceNumber,
            'amount'         => $amounts['amount'],
            'tax'            => $amounts['tax'],
            'total_amount'   => $amounts['total_amount'],
            'status'         => 'pending',
            'due_date'       => $data['due_date'] ?? null,
            'notes'          => $data['notes'] ?? null,
        ]);

        return ['id' => $id, 'invoice_number' => $invoiceNumber, 'amounts' => $amounts];
    }
}