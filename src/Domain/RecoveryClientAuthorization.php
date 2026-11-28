<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

use App\Models\User;
use Omnichannel\Addons\SocialAutomation\Enums\AgentAvailability;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;

/**
 * Resolves the authoritative set of Recovery owner_user_ids a Client API Key may access.
 *
 * Rules:
 * - Key must be active (not revoked) with a valid owner (created_by > 0) and a non-disabled
 *   registered Agent in the same installation_id.
 * - Key owner always has access to their own Recovery records.
 * - An active Staff key owner (users.role = staff) is additionally delegated access to their
 *   assigned Manager/Owner (users.parent_id) when that parent is an active owner|admin.
 * - Sharing an installation_id alone or having issued_by set never grants cross-owner access.
 */
final class RecoveryClientAuthorization
{
    /**
     * @return list<int>
     */
    public function resolvePermittedOwnerIds(SaClientApiKey $key): array
    {
        if ($key->revoked_at !== null) {
            return [];
        }

        $keyOwnerId = (int) ($key->created_by ?? 0);
        $installationId = trim((string) ($key->installation_id ?? ''));
        if ($keyOwnerId <= 0 || $installationId === '') {
            return [];
        }

        $hasActiveAgent = SaAgent::query()
            ->where('client_api_key_id', (int) $key->id)
            ->where('installation_id', $installationId)
            ->where('availability', '!=', AgentAvailability::Disabled->value)
            ->exists();
        if (! $hasActiveAgent) {
            return [];
        }

        if (! class_exists(User::class)) {
            return [$keyOwnerId];
        }

        $keyOwner = User::query()->find($keyOwnerId);
        if (! $this->isActiveUser($keyOwner)) {
            return [];
        }

        $permitted = [$keyOwnerId];
        if ((string) $keyOwner->role === User::ROLE_STAFF) {
            $managerId = (int) ($keyOwner->parent_id ?? 0);
            if ($managerId > 0 && $managerId !== $keyOwnerId) {
                $manager = User::query()->find($managerId);
                if ($this->isActiveUser($manager)
                    && in_array((string) $manager->role, [User::ROLE_OWNER, User::ROLE_ADMIN], true)) {
                    $permitted[] = $managerId;
                }
            }
        }

        return array_values(array_unique($permitted));
    }

    /**
     * @param  int|list<int>  $ownerUserIds
     * @return list<int>
     */
    public static function normalizeOwnerIds(int|array $ownerUserIds): array
    {
        $raw = is_array($ownerUserIds) ? $ownerUserIds : [$ownerUserIds];
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $v): int => (int) $v, $raw),
            static fn (int $id): bool => $id > 0,
        )));

        return $ids;
    }

    private function isActiveUser(mixed $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }
        if ((string) ($user->status ?? '') === User::STATUS_BLOCK) {
            return false;
        }

        return ! $user->isSystemUser();
    }
}
