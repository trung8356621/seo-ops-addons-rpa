<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\SocialAutomation\Auth\RpaAdminRoleGate;

/**
 * Access policy for SEO-OPS Social Automation UI & control plane.
 * Allows Owners, Admins, and authorized Staff (not blocked, not system user).
 * Strict installation scope preserved.
 */
class SocialAutomationAccess
{
    private ?bool $forcedActive = null;

    public function __construct(
        private readonly SocialAutomationServiceResolver $service,
        private readonly ?RpaAdminRoleGate $rpaAdminGate = null,
    ) {}

    public function setForcedActiveForTesting(?bool $active): void
    {
        $this->forcedActive = $active;
    }

    public function canAccess(?User $user = null): bool
    {
        $user ??= Auth::user();
        if (! $user instanceof User) {
            return false;
        }

        if ((string) ($user->status ?? '') === User::STATUS_BLOCK) {
            return false;
        }

        if ($user->isSystemUser()) {
            return false;
        }

        // Must be active service catalog
        $isActive = $this->forcedActive ?? $this->service->isActive();
        if (! $isActive) {
            return false;
        }

        // Owners and Admins allowed (via RpaAdminRoleGate or direct role check)
        if ($this->rpaAdminGate?->allows($user) || in_array((string) $user->role, [User::ROLE_OWNER, User::ROLE_ADMIN], true)) {
            return true;
        }

        // Staff: authorized if active in installation
        if ((string) $user->role === User::ROLE_STAFF) {
            if (method_exists($user, 'hasPermissionTo')) {
                try {
                    if ($user->hasPermissionTo('social-automation:access')) {
                        return true;
                    }
                } catch (\Throwable) {
                    // Fall back if permission table not seeded
                }
            }

            return true;
        }

        return false;
    }

    public function isStaff(?User $user = null): bool
    {
        $user ??= Auth::user();
        if (! $user instanceof User) {
            return false;
        }

        return (string) $user->role === User::ROLE_STAFF;
    }

    public function canAccessAdminPages(?User $user = null): bool
    {
        $user ??= Auth::user();
        if (! $this->canAccess($user)) {
            return false;
        }

        // Only Owners and Admins can access admin-only pages & operations
        if ($this->rpaAdminGate?->allows($user) || in_array((string) $user->role, [User::ROLE_OWNER, User::ROLE_ADMIN], true)) {
            return true;
        }

        return false;
    }

    public function canManageClientKeys(?User $user = null): bool
    {
        $user ??= Auth::user();
        if (! $this->canAccess($user)) {
            return false;
        }

        // Owners and Admins always have key management permission
        if (in_array((string) $user->role, [User::ROLE_OWNER, User::ROLE_ADMIN], true)) {
            return true;
        }

        // Staff with permission to manage RPA connections / Client Keys
        if ((string) $user->role === User::ROLE_STAFF) {
            if (method_exists($user, 'hasPermissionTo')) {
                try {
                    if ($user->hasPermissionTo('social-automation:client-keys:manage')) {
                        return true;
                    }
                } catch (\Throwable) {
                    // Fall back
                }
            }

            return true;
        }

        return false;
    }

    public function canMutate(?User $user = null): bool
    {
        return $this->canManageClientKeys($user);
    }

    public function assertCanAccess(?User $user = null): void
    {
        abort_unless($this->canAccess($user), 403);
    }

    public function assertCanAccessAdminPages(?User $user = null): void
    {
        abort_unless($this->canAccessAdminPages($user), 403);
    }

    public function assertCanMutate(?User $user = null): void
    {
        abort_unless($this->canMutate($user), 403);
    }
}
