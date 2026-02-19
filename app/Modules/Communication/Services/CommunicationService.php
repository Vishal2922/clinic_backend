<?php

namespace App\Modules\Communication\Services;

use App\Core\Security\CryptoService;
use App\Modules\Communication\Models\Note;

/**
 * CommunicationService
 *
 * Handles encryption/decryption of note messages and
 * role-based visibility resolution.
 */
class CommunicationService
{
    private CryptoService $crypto;
    private Note $noteModel;

    // Roles allowed to see provider-only notes
    private const PROVIDER_ROLES = ['Provider', 'Admin'];

    // Roles allowed to see nurse notes
    private const NURSE_ROLES = ['Nurse', 'Provider', 'Admin'];

    public function __construct()
    {
        $this->crypto    = new CryptoService();
        $this->noteModel = new Note();
    }

    /**
     * Encrypt message text before persisting.
     */
    public function encryptMessage(string $plainText): string
    {
        return $this->crypto->encrypt($plainText);
    }

    /**
     * Decrypt a single note's message field.
     * Returns the note array with 'message' field added (decrypted).
     */
    public function decryptNote(array $note): array
    {
        try {
            $note['message'] = $this->crypto->decrypt($note['message_encrypted']);
        } catch (\Exception $e) {
            $note['message'] = '[Unable to decrypt message]';
        }
        // Remove raw encrypted field from output
        unset($note['message_encrypted']);
        return $note;
    }

    /**
     * Decrypt an array of notes.
     */
    public function decryptNotes(array $notes): array
    {
        return array_map([$this, 'decryptNote'], $notes);
    }

    /**
     * Determine if the given role is allowed to see a note
     * based on its visible_to_role value.
     */
    public function canViewNote(string $visibleToRole, string $userRole): bool
    {
        if ($visibleToRole === 'all') {
            return true;
        }
        if ($visibleToRole === 'provider') {
            return in_array($userRole, self::PROVIDER_ROLES);
        }
        if ($visibleToRole === 'nurse') {
            return in_array($userRole, self::NURSE_ROLES);
        }
        // Exact match fallback
        return strcasecmp($visibleToRole, $userRole) === 0;
    }

    /**
     * Resolve visible_to_role based on the author's role.
     * Providers create provider-restricted notes by default;
     * Nurses create nurse-visible notes by default.
     * Can be overridden by passing explicit value.
     */
    public function resolveVisibility(?string $requestedVisibility, string $authorRole): string
    {
        $allowed = ['all', 'provider', 'nurse'];

        if ($requestedVisibility && in_array(strtolower($requestedVisibility), $allowed)) {
            return strtolower($requestedVisibility);
        }

        // Default visibility based on role
        return match (strtolower($authorRole)) {
            'provider' => 'provider',
            'nurse'    => 'nurse',
            default    => 'all',
        };
    }
}