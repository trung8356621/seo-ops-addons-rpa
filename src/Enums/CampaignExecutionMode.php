<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Enums;

enum CampaignExecutionMode: string
{
    case OneAccountOneJob = 'one_account_one_job';
    case OneAccountManyJobs = 'one_account_many_jobs';

    public static function tryFromInput(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }
}
