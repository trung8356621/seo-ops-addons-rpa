<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

use App\Models\User;
use Omnichannel\Addons\SocialAutomation\Contracts\ClientApiKeyAssigneeDirectoryContract;
use Omnichannel\Addons\SocialAutomation\Support\EloquentClientApiKeyAssigneeDirectory;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;

/**
 * Resolves designated key owner vs minting actor for Client API Key creation.
 *
 * - created_by (owner): Staff/Owner who owns the Client association (enrollment slot).
 * - issued_by: authenticated actor who minted the key (audit).
 * Frontend-supplied created_by is never trusted.
 */
final class ClientApiKeyAssignmentPolicy
{
    public const ERROR_ASSIGNMENT_FORBIDDEN = 'assignment_forbidden';

    public const ERROR_ASSIGNED_USER_INVALID = 'assigned_user_invalid';

    public const ERROR_ASSIGNED_USER_INELIGIBLE = 'assigned_user_ineligible';

    private ClientApiKeyAssigneeDirectoryContract $directory;

    public function __construct(
        private readonly SocialAutomationAccess $access,
        ?ClientApiKeyAssigneeDirectoryContract $directory = null,
    ) {
        $this->directory = $directory ?? new EloquentClientApiKeyAssigneeDirectory;
    }

    public function canAssignToOthers(?User $actor): bool
    {
        return $this->access->canAccessAdminPages($actor);
    }

    /**
     * @return array{
     *   ok: bool,
     *   owner_user_id?: int|null,
     *   issued_by_user_id?: int|null,
     *   error?: string,
     *   message?: string
     * }
     */
    public function resolveCreateOwnership(User $actor, ?int $assignedToUserId): array
    {
        $actorId = (int) $actor->id;
        $issuedBy = $actorId > 0 ? $actorId : null;

        if ($assignedToUserId === null) {
            return [
                'ok' => true,
                'owner_user_id' => $issuedBy,
                'issued_by_user_id' => $issuedBy,
            ];
        }

        if ($assignedToUserId <= 0) {
            return [
                'ok' => false,
                'error' => self::ERROR_ASSIGNED_USER_INVALID,
                'message' => 'assigned_to_user_id must be a positive integer.',
            ];
        }

        if (! $this->canAssignToOthers($actor)) {
            return [
                'ok' => false,
                'error' => self::ERROR_ASSIGNMENT_FORBIDDEN,
                'message' => 'Only Owner/Admin may assign a Client API Key to another user.',
            ];
        }

        $assignee = $this->directory->findById($assignedToUserId);
        if (! $assignee instanceof User) {
            return [
                'ok' => false,
                'error' => self::ERROR_ASSIGNED_USER_INVALID,
                'message' => 'Assigned user was not found.',
            ];
        }

        if ($this->directory instanceof EloquentClientApiKeyAssigneeDirectory) {
            $eligible = $this->directory->isEligibleAssignee($actor, $assignee);
        } else {
            $eligible = $this->isInEligibleList($actor, $assignee);
        }

        if (! $eligible) {
            return [
                'ok' => false,
                'error' => self::ERROR_ASSIGNED_USER_INELIGIBLE,
                'message' => 'Assigned user is not an eligible Staff/Owner in this account scope.',
            ];
        }

        return [
            'ok' => true,
            'owner_user_id' => (int) $assignee->id,
            'issued_by_user_id' => $issuedBy,
        ];
    }

    /**
     * @return list<array{id: int, name: string, email: string|null, role: string}>
     */
    public function listAssignableUsers(User $actor): array
    {
        if (! $this->canAssignToOthers($actor)) {
            return [];
        }

        $out = [];
        foreach ($this->directory->listEligibleAssignees($actor) as $user) {
            $out[] = [
                'id' => (int) $user->id,
                'name' => (string) ($user->name ?? ''),
                'email' => isset($user->email) ? (string) $user->email : null,
                'role' => (string) ($user->role ?? ''),
            ];
        }

        return $out;
    }

    private function isInEligibleList(User $actor, User $assignee): bool
    {
        foreach ($this->directory->listEligibleAssignees($actor) as $candidate) {
            if ((int) $candidate->id === (int) $assignee->id) {
                return true;
            }
        }

        return false;
    }
}
