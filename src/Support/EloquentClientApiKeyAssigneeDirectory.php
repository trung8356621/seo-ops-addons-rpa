<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Support;

use App\Models\User;
use Omnichannel\Addons\SocialAutomation\Contracts\ClientApiKeyAssigneeDirectoryContract;

/**
 * Eligible assignees are non-blocked, non-system users in the actor's account scope.
 * Owner/Admin: self + Staff with parent_id = account owner id.
 */
final class EloquentClientApiKeyAssigneeDirectory implements ClientApiKeyAssigneeDirectoryContract
{
    public function findById(int $userId): ?User
    {
        if ($userId <= 0 || ! class_exists(User::class)) {
            return null;
        }

        try {
            $user = User::query()->find($userId);
        } catch (\Throwable) {
            return null;
        }

        return $user instanceof User ? $user : null;
    }

    public function listEligibleAssignees(User $actor): array
    {
        try {
            $query = User::query()
                ->whereIn('role', [User::ROLE_OWNER, User::ROLE_ADMIN, User::ROLE_STAFF])
                ->where(function ($q): void {
                    $q->whereNull('is_system')->orWhere('is_system', false);
                })
                ->where('status', '!=', User::STATUS_BLOCK)
                ->orderBy('id');

            $role = (string) $actor->role;
            if ($role === User::ROLE_OWNER) {
                $ownerId = (int) $actor->id;
                $query->where(function ($q) use ($ownerId): void {
                    $q->where('id', $ownerId)
                        ->orWhere(function ($q2) use ($ownerId): void {
                            $q2->where('role', User::ROLE_STAFF)
                                ->where('parent_id', $ownerId);
                        });
                });
            } elseif ($role !== User::ROLE_ADMIN) {
                return [];
            }
            // Admin: installation-scoped (single-tenant users table).

            $rows = $query->get();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if ($row instanceof User && $this->isEligibleCandidate($row) && $this->isEligibleAssignee($actor, $row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    public function isEligibleAssignee(User $actor, User $assignee): bool
    {
        if (! $this->isEligibleCandidate($assignee)) {
            return false;
        }

        $actorRole = (string) $actor->role;
        if ($actorRole === User::ROLE_ADMIN) {
            return true;
        }

        if ($actorRole !== User::ROLE_OWNER) {
            return false;
        }

        $ownerId = (int) $actor->id;
        if ((int) $assignee->id === $ownerId) {
            return true;
        }

        return (string) $assignee->role === User::ROLE_STAFF
            && (int) ($assignee->parent_id ?? 0) === $ownerId;
    }

    private function isEligibleCandidate(User $user): bool
    {
        if ($user->isSystemUser()) {
            return false;
        }
        if ((string) ($user->status ?? '') === User::STATUS_BLOCK) {
            return false;
        }

        return in_array((string) $user->role, [User::ROLE_OWNER, User::ROLE_ADMIN, User::ROLE_STAFF], true);
    }
}
