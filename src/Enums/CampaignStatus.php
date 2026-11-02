<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case Running = 'running';
    case Paused = 'paused';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function allowsDispatch(): bool
    {
        return $this === self::Running;
    }

    public function isTerminal(): bool
    {
        return $this === self::Cancelled || $this === self::Completed;
    }
}
