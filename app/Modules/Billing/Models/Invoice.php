<?php
namespace App\Modules\Billing\Models;

use App\Core\Database;
use App\Core\Security\CryptoService;

class Invoice
{
    private Database $db;
    private CryptoService $crypto;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->crypto = new CryptoService();
    }

    public function findById(int $id, int $tenantId): ?array
    {
        $invoice = $this->db->fetch(
            'SELECT i.*, p.encrypted_name AS enc_patient_name, u.username AS provider_name
             FROM invoices i
             LEFT JOIN patients p ON i.patient_id = p.id
             LEFT JOIN users u ON i.provider_id = u.id
             WHERE i.id = :id AND i.tenant_id = :tid AND i.deleted_at IS NULL',
            ['id' => $id, 'tid' => $tenantId]
        );
        return $invoice ? $this->decryptInvoice($invoice) : null;
    }

    public function getAllByTenant(
        int $tenantId,
        ?int $patientId = null,
        ?string $status = null,
        int $page = 1,
        int $perPage = 15
    ): array {
        $offset = ($page - 1) * $perPage;
        $where = 'i.tenant_id = :tid AND i.deleted_at IS NULL';
        $params = ['tid' => $tenantId];

        if ($patientId) {
            $where .= ' AND i.patient_id = :patient_id';
            $params['patient_id'] = $patientId;
        }
        if ($status) {
            $where .= ' AND i.status = :status';
            $params['status'] = $status;
        }

        $countResult = $this->db->fetch(
            "SELECT COUNT(*) as total FROM invoices i WHERE $where",
            $params
        );
        $total = (int) ($countResult['total'] ?? 0);

        $invoices = $this->db->fetchAll(
            "SELECT i.*, p.encrypted_name AS enc_patient_name, u.username AS provider_name
             FROM invoices i
             LEFT JOIN patients p ON i.patient_id = p.id
             LEFT JOIN users u ON i.provider_id = u.id
             WHERE $where
             ORDER BY i.created_at DESC
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $perPage, 'offset' => $offset])
        );

        $invoices = array_map([$this, 'decryptInvoice'], $invoices);

        return [
            'invoices' => $invoices,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
            ],
        ];
    }

    public function create(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO invoices
             (tenant_id, patient_id, provider_id, appointment_id, invoice_number,
              amount, tax, total_amount, status, due_date, encrypted_notes, created_at, updated_at)
             VALUES
             (:tenant_id, :patient_id, :provider_id, :appointment_id, :invoice_number,
              :amount, :tax, :total_amount, :status, :due_date, :enc_notes, NOW(), NOW())',
            [
                'tenant_id'      => $data['tenant_id'],
                'patient_id'     => $data['patient_id'],
                'provider_id'    => $data['provider_id'],
                'appointment_id' => $data['appointment_id'] ?? null,
                'invoice_number' => $data['invoice_number'],
                'amount'         => $data['amount'],
                'tax'            => $data['tax'] ?? 0.00,
                'total_amount'   => $data['total_amount'],
                'status'         => $data['status'] ?? 'pending',
                'due_date'       => $data['due_date'] ?? null,
                'enc_notes'      => isset($data['notes']) ? $this->crypto->encrypt($data['notes']) : null,
            ]
        );
    }

    public function updateStatus(int $id, int $tenantId, string $status, ?string $paidAt = null, ?string $paymentMethod = null): bool
    {
        // BUG FIX: Original only updated status + paid_at but never payment_method.
        // Body contains payment_method but it was silently ignored, so it stayed NULL forever.
        $affected = $this->db->execute(
            'UPDATE invoices
             SET status = :status, paid_at = :paid_at, payment_method = :payment_method, updated_at = NOW()
             WHERE id = :id AND tenant_id = :tid AND deleted_at IS NULL',
            [
                'status'         => $status,
                'paid_at'        => $paidAt,
                'payment_method' => $paymentMethod,
                'id'             => $id,
                'tid'            => $tenantId,
            ]
        );
        return $affected > 0;
    }

    public function softDelete(int $id, int $tenantId): bool
    {
        $affected = $this->db->execute(
            'UPDATE invoices SET deleted_at = NOW() WHERE id = :id AND tenant_id = :tid AND deleted_at IS NULL',
            ['id' => $id, 'tid' => $tenantId]
        );
        return $affected > 0;
    }

    public function getSummary(int $tenantId): array
    {
        $result = $this->db->fetch(
            "SELECT
                COUNT(*) AS total_invoices,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
                SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) AS overdue_count,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
                COALESCE(SUM(total_amount), 0) AS total_billed,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END), 0) AS total_collected,
                COALESCE(SUM(CASE WHEN status IN ('pending','overdue') THEN total_amount ELSE 0 END), 0) AS total_outstanding
             FROM invoices
             WHERE tenant_id = :tid AND deleted_at IS NULL",
            ['tid' => $tenantId]
        );
        return $result ?? [];
    }

    /**
     * FIX: Safe invoice number generation using fetch() instead of fetchColumn()
     */
    public function generateInvoiceNumber(int $tenantId): string
    {
        $date = date('Ymd');
        $prefix = "INV-{$tenantId}-{$date}-";

        $result = $this->db->fetch(
            "SELECT COUNT(*) AS seq FROM invoices
             WHERE tenant_id = :tid AND invoice_number LIKE :prefix",
            ['tid' => $tenantId, 'prefix' => $prefix . '%']
        );

        $seq = (int) ($result['seq'] ?? 0) + 1;
        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    private function decryptInvoice(array $invoice): array
    {
        try {
            if (!empty($invoice['encrypted_notes'])) {
                $invoice['notes'] = $this->crypto->decrypt($invoice['encrypted_notes']);
            }
            if (!empty($invoice['enc_patient_name'])) {
                $invoice['patient_name'] = $this->crypto->decrypt($invoice['enc_patient_name']);
            }
        } catch (\Exception $e) {
            $invoice['notes'] = '[encrypted]';
            app_log('Invoice decrypt error: ' . $e->getMessage(), 'ERROR');
        }
        unset($invoice['encrypted_notes'], $invoice['enc_patient_name']);
        return $invoice;
    }
}