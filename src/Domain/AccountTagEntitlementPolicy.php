<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * SB1=A — explicit Account↔Tag membership only. No platform / installation implicit access.
 */
final class AccountTagEntitlementPolicy
{
    public function isAuthorized(bool $bindingExists): bool
    {
        return $bindingExists;
    }

    /**
     * @return array{ok: true}|array{ok: false, errors: list<string>}
     */
    public function assertAuthorized(bool $bindingExists): array
    {
        if (! $this->isAuthorized($bindingExists)) {
            return ['ok' => false, 'errors' => ['account_tag_unauthorized']];
        }

        return ['ok' => true];
    }
}
