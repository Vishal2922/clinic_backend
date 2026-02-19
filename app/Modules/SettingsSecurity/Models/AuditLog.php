<?php
namespace App\Modules\SettingsSecurity\Models;

use App\Core\Database;
use App\Core\Security\CryptoService;

class AuditLog
{
    private Database $db;
    private CryptoService $crypto;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->crypto = new CryptoService();
    }

    public function record(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO audit_log (user_id, tenant_id, action, entity_type, entity_id,
                    ip_address, encrypted_user_agent, encrypted_details)
             VALUES (:user_id, :tenant_id, :action, :entity_type, :entity_id,
                    :ip_address, :enc_ua, :enc_details)',
            [
                'user_id'     => $data['user_id'] ?? null,
                'tenant_id'   => $data['tenant_id'] ?? null,
                'action'      => $data['action'],
                'entity_type' => $data['entity_type'] ?? null,
                'entity_id'   => $data['entity_id'] ?? null,
                'ip_address'  => $data['ip_address'] ?? null,
                'enc_ua'      => isset($data['user_agent']) ? $this->crypto->encrypt($data['user_agent']) : null,
                'enc_details' => isset($data['details']) ? $this->crypto->encrypt($data['details']) : null,
            ]
        );
    }

    public function getByTenant(int $tenantId, int $page = 1, int $perPage = 50, array $filters = []): array
    {
        $offset = ($page - 1) * $perPage;
        $where = 'a.tenant_id = :tid';
        $params = ['tid' => $tenantId];

        if (!empty($filters['user_id'])) {
            $where .= ' AND a.user_id = :user_id';
            $params['user_id'] = (int) $filters['user_id'];
        }
        if (!empty($filters['action'])) {
            $where .= ' AND a.action = :action';
            $params['action'] = $filters['action'];
        }
        if (!empty($filters['entity_type'])) {
            $where .= ' AND a.entity_type = :entity_type';
            $params['entity_type'] = $filters['entity_type'];
        }
        if (!empty($filters['date_from'])) {
            $where .= ' AND a.created_at >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where .= ' AND a.created_at <= :date_to';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $countResult = $this->db->fetch(
            "SELECT COUNT(*) AS total FROM audit_log a WHERE $where",
            $params
        );
        $total = (int) ($countResult['total'] ?? 0);

        $logs = $this->db->fetchAll(
            "SELECT a.*, u.username
             FROM audit_log a
             LEFT JOIN users u ON a.user_id = u.id
             WHERE $where
             ORDER BY a.created_at DESC
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $perPage, 'offset' => $offset])
        );

        // Decrypt sensitive fields in results
        $logs = array_map(function($log) {
            try {
                if (!empty($log['encrypted_user_agent'])) {
                    $log['user_agent'] = $this->crypto->decrypt($log['encrypted_user_agent']);
                }
                if (!empty($log['encrypted_details'])) {
                    $log['details'] = $this->crypto->decrypt($log['encrypted_details']);
                }
            } catch (\Exception $e) {
                $log['user_agent'] = '[encrypted]';
                $log['details'] = '[encrypted]';
            }
            unset($log['encrypted_user_agent'], $log['encrypted_details']);
            return $log;
        }, $logs);

        return [
            'logs' => $logs,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
            ],
        ];
    }

    public function getDistinctActions(int $tenantId): array
    {
        return $this->db->fetchAll(
            'SELECT DISTINCT action FROM audit_log WHERE tenant_id = :tid ORDER BY action',
            ['tid' => $tenantId]
        );
    }
}