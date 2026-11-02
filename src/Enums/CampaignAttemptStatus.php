<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Enums;

enum CampaignAttemptStatus: string
{
    case Queued = 'queued';
    case Claimed = 'claimed';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Unknown = 'unknown';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    public function toJobStatus(): JobStatus
    {
        return match ($this) {
            self::Queued => JobStatus::Reserved,
            self::Claimed => JobStatus::Claimed,
            self::Running => JobStatus::Running,
            self::Succeeded => JobStatus::Completed,
            self::Failed => JobStatus::Failed,
            self::Unknown => JobStatus::Unknown,
            self::Cancelled => JobStatus::Cancelled,
        };
    }

    public static function fromResultStatus(string $status): ?self
    {
        return match (strtolower(trim($status))) {
            'succeeded', 'success', 'completed' => self::Succeeded,
            'failed', 'fail' => self::Failed,
            'cancelled', 'canceled' => self::Cancelled,
            'unknown' => self::Unknown,
            default => null,
        };
    }
}
