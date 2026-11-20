<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Contracts;

/**
 * Crash-safe advisory notify outbox for Keep Alive Socket.IO gateway.
 */
interface KeepAliveNotifyOutboxContract
{
    public function isReady(): bool;

    /**
     * Upsert/coalesce a pending row by coalesce_key (max seq_hint wins).
     *
     * @param  array{
     *   installation_id: string,
     *   campaign_uuid: string,
     *   event_type: string,
     *   coalesce_key: string,
     *   seq_hint?: int|null,
     *   route_generation?: int|null,
     *   assignment_generation?: int|null,
     *   rooms: list<string>,
     *   payload?: array<string, mixed>|null
     * }  $row
     */
    public function enqueue(array $row): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function claimPending(int $limit = 50, ?int $nowUnix = null): array;

    public function markPublished(int $id): void;

    public function markFailed(int $id, string $error, int $retryAfterSeconds = 5): void;

    public function pendingCount(): int;
}
