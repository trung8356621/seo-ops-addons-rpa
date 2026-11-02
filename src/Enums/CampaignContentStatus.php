<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Enums;

enum CampaignContentStatus: string
{
    case Pending = 'pending';
    case WaitingResources = 'waiting_resources';
    case Running = 'running';
    case Success = 'success';
    case FailedLocked = 'failed_locked';
    case Unknown = 'unknown';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Success, self::FailedLocked, self::Skipped, self::Cancelled => true,
            default => false,
        };
    }

    public function isDispatchable(): bool
    {
        return $this === self::Pending || $this === self::WaitingResources;
    }

    public function countsAsWaiting(): bool
    {
        return $this === self::Pending || $this === self::WaitingResources;
    }
}
