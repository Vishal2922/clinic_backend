<?php

namespace App\Modules\Communication\Models;

use App\Core\Database;

/**
 * Note Model
 *
 * Handles encrypted appointment-based notes/messages.
 * Message content is stored encrypted via CryptoService.
 * Role-based visibility is enforced at the controller layer.
 */
class Note
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Find a single note by ID within a tenant.
     */
    public function findById(int $id, int $tenantId): ?array
    {
        return $this->db->fetch(
            'SELECT n.*, u.username AS author_name, r.name AS author_role
             FROM appointment_notes n
             LEFT JOIN users u ON n.author_id = u.id
             LEFT JOIN roles r ON u.role_id = r.id
             WHERE n.id = :id AND n.tenant_id = :tid AND n.deleted_at IS NULL',
            ['id' => $id, 'tid' => $tenantId]
        );
    }

    /**
     * Get all notes for a specific appointment.
     * Optionally filter by visible_to_role.
     */
    public function getByAppointment(int $appointmentId, int $tenantId, ?string $roleFilter = null): array
    {
        $where  = 'n.appointment_id = :apt_id AND n.tenant_id = :tid AND n.deleted_at IS NULL';
        $params = ['apt_id' => $appointmentId, 'tid' => $tenantId];

        if ($roleFilter) {
            $where  .= ' AND (n.visible_to_role = :role OR n.visible_to_role = "all")';
            $params['role'] = $roleFilter;
        }

        return $this->db->fetchAll(
            "SELECT n.*, u.username AS author_name, r.name AS author_role
             FROM appointment_notes n
             LEFT JOIN users u ON n.author_id = u.id
             LEFT JOIN roles r ON u.role_id = r.id
             WHERE $where
             ORDER BY n.created_at ASC",
            $params
        );
    }

    /**
     * Get message history for an appointment (paginated).
     */
    public function getHistory(int $appointmentId, int $tenantId, int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;
        $params = ['apt_id' => $appointmentId, 'tid' => $tenantId];

        $countResult = $this->db->fetch(
            'SELECT COUNT(*) as total FROM appointment_notes
             WHERE appointment_id = :apt_id AND tenant_id = :tid AND deleted_at IS NULL',
            $params
        );
        $total = (int) ($countResult['total'] ?? 0);

        $notes = $this->db->fetchAll(
            'SELECT n.*, u.username AS author_name, r.name AS author_role
             FROM appointment_notes n
             LEFT JOIN users u ON n.author_id = u.id
             LEFT JOIN roles r ON u.role_id = r.id
             WHERE n.appointment_id = :apt_id AND n.tenant_id = :tid AND n.deleted_at IS NULL
             ORDER BY n.created_at DESC
             LIMIT :limit OFFSET :offset',
            array_merge($params, ['limit' => $perPage, 'offset' => $offset])
        );

        return [
            'notes'      => $notes,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
            ],
        ];
    }

    /**
     * Create a new note (message content should be pre-encrypted).
     */
    public function create(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO appointment_notes
                (tenant_id, appointment_id, author_id, message_encrypted, note_type, visible_to_role, created_at, updated_at)
             VALUES
                (:tenant_id, :appointment_id, :author_id, :message_encrypted, :note_type, :visible_to_role, NOW(), NOW())',
            [
                'tenant_id'         => $data['tenant_id'],
                'appointment_id'    => $data['appointment_id'],
                'author_id'         => $data['author_id'],
                'message_encrypted' => $data['message_encrypted'],
                'note_type'         => $data['note_type'] ?? 'note',
                'visible_to_role'   => $data['visible_to_role'] ?? 'all',
            ]
        );
    }

    /**
     * Soft delete a note.
     */
    public function softDelete(int $id, int $tenantId, int $authorId): bool
    {
        $affected = $this->db->execute(
            'UPDATE appointment_notes SET deleted_at = NOW()
             WHERE id = :id AND tenant_id = :tid AND author_id = :author_id AND deleted_at IS NULL',
            ['id' => $id, 'tid' => $tenantId, 'author_id' => $authorId]
        );
        return $affected > 0;
    }
}