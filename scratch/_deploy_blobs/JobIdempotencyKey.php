<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Deterministic job idempotency — retry/re-dispatch must not invent a second job
 * for the same task + session + trigger window.
 *
 * Keys MUST fit sa_jobs.idempotency_key VARCHAR(64). Plain
 * `campaign:{uuid}:content:{uuid}:attempt:{n}` is ~100 chars and truncates on MySQL.
 */
final class JobIdempotencyKey
{
    public const MAX_LENGTH = 64;

    public static function forDispatch(
        string $taskUuid,
        string|int $sessionId,
        string|int|null $triggerId,
        string|int|null $accountId = null,
    ): string {
        return hash('sha256', implode('|', [
            $taskUuid,
            (string) $sessionId,
            (string) ($triggerId ?? 'manual'),
            (string) ($accountId ?? 'no-account'),
        ]));
    }

    /**
     * Campaign content reservation key — stable across Start / schedule recheck.
     * SHA-256 hex (64) fits VARCHAR(64); never emit the verbose campaign:…:content:… form.
     */
    public static function forCampaignContent(
        string $campaignUuid,
        string $contentUuid,
        int $attempt,
    ): string {
        return hash('sha256', implode('|', [
            'campaign',
            $campaignUuid,
            'content',
            $contentUuid,
            'attempt',
            (string) max(1, $attempt),
        ]));
    }
}
