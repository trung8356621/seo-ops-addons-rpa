<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Support;

use App\Models\H1Installation;
use Illuminate\Support\Facades\Schema;

/**
 * Server-authoritative resolution of SEO-OPS Installation identity.
 * Never trusts any installation ID claimed by a client or request body.
 */
class SocialAutomationInstallationResolver
{
    private ?string $forcedInstallationId = null;

    public function setForcedInstallationId(?string $id): void
    {
        $this->forcedInstallationId = $id;
    }

    public function resolveInstallationId(): string
    {
        if ($this->forcedInstallationId !== null && $this->forcedInstallationId !== '') {
            return $this->forcedInstallationId;
        }

        try {
            if (class_exists(H1Installation::class) && Schema::hasTable('h1_installations')) {
                $id = H1Installation::query()->orderBy('id')->value('installation_id');
                if (is_string($id) && $id !== '') {
                    return $id;
                }
            }
        } catch (\Throwable) {
            // Fallback gracefully during isolated tests or incomplete bootstrap
        }

        $configId = (string) config('social-automation.installation_id', '');
        if ($configId !== '') {
            return $configId;
        }

        return 'ins_default';
    }

    public function matches(?string $installationId): bool
    {
        if ($installationId === null || $installationId === '') {
            return false;
        }

        return hash_equals($this->resolveInstallationId(), $installationId);
    }
}
