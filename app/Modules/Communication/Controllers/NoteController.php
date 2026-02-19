<?php

namespace App\Modules\Communication\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Communication\Models\Note;
use App\Modules\Communication\Services\CommunicationService;

/**
 * NoteController
 *
 * Handles appointment-based notes/messages with:
 *  - Encrypted message storage (AES-256-CBC via CryptoService)
 *  - Role-based visibility (provider-only, nurse-visible, or all)
 *  - Full message history pagination per appointment
 *
 * Routes:
 *  GET    /api/communication/appointments/{id}/notes         -> index
 *  POST   /api/communication/appointments/{id}/notes         -> store
 *  GET    /api/communication/appointments/{id}/notes/history  -> history
 *  DELETE /api/communication/notes/{id}                      -> destroy
 */
class NoteController extends Controller
{
    private Note $noteModel;
    private CommunicationService $communicationService;

    public function __construct()
    {
        $this->noteModel            = new Note();
        $this->communicationService = new CommunicationService();
    }

    /**
     * GET /api/communication/appointments/{appointmentId}/notes
     * Returns decrypted notes for the given appointment.
     * Filters by the requesting user's role visibility.
     */
    public function index(Request $request, string $appointmentId): void
    {
        $tenantId = $this->getTenantId();
        $user     = $this->getAuthUser();
        $userRole = $user['role_name'] ?? 'all';

        try {
            $rawNotes = $this->noteModel->getByAppointment((int) $appointmentId, $tenantId);

            // Filter notes by role visibility
            $visibleNotes = array_filter($rawNotes, function ($note) use ($userRole) {
                return $this->communicationService->canViewNote($note['visible_to_role'], $userRole);
            });

            $decrypted = $this->communicationService->decryptNotes(array_values($visibleNotes));

            Response::json([
                'message' => 'Notes retrieved successfully.',
                'data'    => $decrypted,
            ], 200);

        } catch (\Exception $e) {
            app_log('Get notes error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to retrieve notes.', 500);
        }
    }

    /**
     * POST /api/communication/appointments/{appointmentId}/notes
     * Creates a new encrypted note for the appointment.
     *
     * Body params:
     *  - message         (required) plain-text message
     *  - note_type       (optional) 'note'|'clinical'|'provider_only'|'nurse'|'general'  default: 'note'
     *  - visible_to_role (optional) 'all' | 'provider' | 'nurse'   default: role-based
     */
    public function store(Request $request, string $appointmentId): void
    {
        $tenantId = $this->getTenantId();
        $user     = $this->getAuthUser();
        $data     = $request->getBody();

        $errors = $this->validate($data, [
            'message'   => 'required',
            'note_type' => 'in:note,clinical,provider_only,nurse,general',
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
            return; // FIX: explicit return after error response
        }

        try {
            $authorRole   = $user['role_name'] ?? 'provider';
            $visibility   = $this->communicationService->resolveVisibility(
                $data['visible_to_role'] ?? null,
                $authorRole
            );
            $encryptedMsg = $this->communicationService->encryptMessage($data['message']);

            // JWT middleware stores user's primary key as 'user_id', not 'id'
            $authorId = (int) ($user['user_id'] ?? 0);
            if (!$authorId) {
                Response::error('Unable to identify author. Please re-login.', 401);
                return;
            }

            $id = $this->noteModel->create([
                'tenant_id'         => $tenantId,
                'appointment_id'    => (int) $appointmentId,
                'author_id'         => $authorId,
                'message_encrypted' => $encryptedMsg,
                'note_type'         => $data['note_type'] ?? 'note',
                'visible_to_role'   => $visibility,
            ]);

            $note = $this->noteModel->findById($id, $tenantId);
            if ($note) {
                $note = $this->communicationService->decryptNote($note);
            }

            Response::json([
                'message' => 'Note created successfully.',
                'data'    => $note,
            ], 201);

        } catch (\Exception $e) {
            app_log('Create note error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to create note.', 500);
        }
    }

    /**
     * GET /api/communication/appointments/{appointmentId}/notes/history
     * Returns paginated message history for an appointment.
     *
     * Query params: page, per_page
     */
    public function history(Request $request, string $appointmentId): void
    {
        $tenantId = $this->getTenantId();
        $user     = $this->getAuthUser();
        $userRole = $user['role_name'] ?? 'all';
        $page     = (int) $request->getQueryParam('page', 1);
        $perPage  = (int) $request->getQueryParam('per_page', 20);

        try {
            $result = $this->noteModel->getHistory((int) $appointmentId, $tenantId, $page, $perPage);

            // Filter by role visibility
            $result['notes'] = array_values(array_filter($result['notes'], function ($note) use ($userRole) {
                return $this->communicationService->canViewNote($note['visible_to_role'], $userRole);
            }));

            $result['notes'] = $this->communicationService->decryptNotes($result['notes']);

            Response::json([
                'message' => 'Message history retrieved.',
                'data'    => $result,
            ], 200);

        } catch (\Exception $e) {
            app_log('Message history error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to retrieve message history.', 500);
        }
    }

    /**
     * DELETE /api/communication/notes/{id}
     * Soft-deletes a note. Only the original author (or Admin) can delete.
     *
     * FIX: Author ownership check uses $user['user_id'] (set by AuthJWT middleware
     * from JWT 'sub' claim). The old code used $user['id'] which is undefined in
     * the auth_user array, so author_id check always failed -> 403 for everyone.
     *
     * FIX: Model's softDelete() no longer needs authorId param. Ownership is
     * verified here in the controller before calling softDelete().
     */
    public function destroy(Request $request, string $id): void
    {
        $tenantId = $this->getTenantId();
        $user     = $this->getAuthUser();
        // FIX: use 'user_id' key, not 'id'
        $userId   = (int) ($user['user_id'] ?? 0);
        $userRole = $user['role_name'] ?? '';

        try {
            $note = $this->noteModel->findById((int) $id, $tenantId);
            if (!$note) {
                Response::error('Note not found.', 404);
                return;
            }

            // Only the author or an Admin may delete
            $isAuthor = (int) $note['author_id'] === $userId;
            $isAdmin  = $userRole === 'Admin';

            if (!$isAuthor && !$isAdmin) {
                Response::error('You are not authorised to delete this note.', 403);
                return;
            }

            $deleted = $this->noteModel->softDelete((int) $id, $tenantId);

            if (!$deleted) {
                Response::error('Failed to delete note.', 500);
                return;
            }

            Response::json(['message' => 'Note deleted successfully.'], 200);

        } catch (\Exception $e) {
            app_log('Delete note error: ' . $e->getMessage(), 'ERROR');
            Response::error('Failed to delete note.', 500);
        }
    }
}