<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Enums;

enum CampaignRunMode: string
{
    case Test = 'test';
    case Live = 'live';

    public static function tryFromInput(mixed $value): ?self
    {
        if ($value === null || $value === '') {
            return self::Live;
        }
        if (! is_string($value)) {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }

    public function requiresTestCompatibleScript(): bool
    {
        return $this === self::Test;
    }
}
