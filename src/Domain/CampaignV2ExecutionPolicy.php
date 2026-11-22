<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Default-OFF feature gate for Campaign V2 Execution POC.
 * Controls whether V2 Drafts may transition to Running and dispatch Jobs.
 */
final class CampaignV2ExecutionPolicy
{
    public const CONFIG_KEY = 'social-automation.campaign_v2_execution_enabled';

    public const ENV_KEY = 'SA_CAMPAIGN_V2_EXECUTION_ENABLED';

    private static ?bool $testOverride = null;

    public static function setTestOverride(?bool $enabled): void
    {
        self::$testOverride = $enabled;
    }

    public static function isEnabled(): bool
    {
        if (self::$testOverride !== null) {
            return self::$testOverride;
        }

        if (function_exists('app') && app()->bound('config')) {
            return (bool) config(self::CONFIG_KEY, false);
        }

        $env = getenv(self::ENV_KEY);
        if ($env === false) {
            return false;
        }

        return filter_var($env, FILTER_VALIDATE_BOOLEAN);
    }
}
