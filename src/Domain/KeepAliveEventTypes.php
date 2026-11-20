<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Durable Keep Alive Job event types (never includes transient progress).
 */
final class KeepAliveEventTypes
{
    public const JOB_RESERVED = 'job_reserved';

    public const JOB_CLAIMED = 'job_claimed';

    public const JOB_STARTED = 'job_started';

    public const JOB_COMPLETED = 'job_completed';

    public const JOB_FAILED = 'job_failed';

    public const JOB_UNKNOWN = 'job_unknown';

    public const SCREENSHOT_UPLOADED = 'screenshot_uploaded';

    public const PAYLOAD_VERSION = 2;

    /** @var list<string> */
    public const ALL = [
        self::JOB_RESERVED,
        self::JOB_CLAIMED,
        self::JOB_STARTED,
        self::JOB_COMPLETED,
        self::JOB_FAILED,
        self::JOB_UNKNOWN,
        self::SCREENSHOT_UPLOADED,
    ];

    /**
     * Map durable sa_job_events.event_type (+ to_status) → Keep Alive event_type.
     * Returns null for transient/unsupported events (e.g. progress).
     */
    public static function fromJobEvent(string $sourceType, ?string $toStatus): ?string
    {
        return match ($sourceType) {
            'reserve' => self::JOB_RESERVED,
            'claim' => self::JOB_CLAIMED,
            'ack' => self::JOB_STARTED,
            'result' => match ($toStatus) {
                'completed' => self::JOB_COMPLETED,
                'failed' => self::JOB_FAILED,
                'unknown' => self::JOB_UNKNOWN,
                default => null,
            },
            'progress' => null,
            default => null,
        };
    }
}
