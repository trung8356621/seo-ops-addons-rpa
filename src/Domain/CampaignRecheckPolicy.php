<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

use DateTimeImmutable;
use DateTimeInterface;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignStatus;

/**
 * Durable resource recheck scheduling — not job retry.
 */
final class CampaignRecheckPolicy
{
    public const DEFAULT_INTERVAL_SECONDS = 3600;

    public function normalizeInterval(int $seconds): int
    {
        return max(60, $seconds > 0 ? $seconds : self::DEFAULT_INTERVAL_SECONDS);
    }

    public function nextRecheckAt(DateTimeInterface $from, int $intervalSeconds): DateTimeImmutable
    {
        $base = DateTimeImmutable::createFromInterface($from);

        return $base->modify('+'.$this->normalizeInterval($intervalSeconds).' seconds');
    }

    public function isDue(
        CampaignStatus $status,
        ?DateTimeInterface $nextRecheckAt,
        DateTimeInterface $now,
        bool $hasWaitingContents,
    ): bool {
        if (! $status->allowsDispatch() || ! $hasWaitingContents) {
            return false;
        }
        if ($nextRecheckAt === null) {
            return true;
        }

        return DateTimeImmutable::createFromInterface($nextRecheckAt)->getTimestamp()
            <= DateTimeImmutable::createFromInterface($now)->getTimestamp();
    }
}
