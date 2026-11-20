<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SocialAutomation\Contracts\KeepAliveNotifyOutboxContract;
use Omnichannel\Addons\SocialAutomation\Contracts\KeepAliveRouteStoreContract;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveSocketEnvelope;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveSocketTicket;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Post-commit advisory notifications for Keep Alive Socket.IO.
 * Never participates in Job claim/lease/fencing. Gateway failures never fail callers.
 */
final class KeepAliveNotifyService
{
    private bool $autoFlush = true;

    public function __construct(
        private readonly KeepAliveNotifyOutboxContract $outbox,
        private readonly KeepAliveRouteStoreContract $routes,
        private readonly KeepAliveGatewayPublisher $publisher = new KeepAliveGatewayPublisher,
    ) {}

    public function setAutoFlush(bool $autoFlush): void
    {
        $this->autoFlush = $autoFlush;
    }

    public function publisher(): KeepAliveGatewayPublisher
    {
        return $this->publisher;
    }

    public function outbox(): KeepAliveNotifyOutboxContract
    {
        return $this->outbox;
    }

    /**
     * Enqueue ka.notify after durable campaign events were committed.
     * Call while still inside the durable write transaction when possible;
     * flush is scheduled via afterCommit / explicit flush.
     *
     * @param  list<string>  $rooms
     */
    public function enqueueNotify(
        string $installationId,
        string $campaignUuid,
        int $seqHint,
        array $rooms,
        ?int $routeGeneration = null,
    ): void {
        if (! $this->outbox->isReady() || $rooms === []) {
            return;
        }

        $this->outbox->enqueue([
            'installation_id' => $installationId,
            'campaign_uuid' => $campaignUuid,
            'event_type' => KeepAliveSocketEnvelope::TYPE_NOTIFY,
            'coalesce_key' => 'notify|'.$installationId.'|'.$campaignUuid,
            'seq_hint' => $seqHint,
            'route_generation' => $routeGeneration,
            'rooms' => array_values($rooms),
            'payload' => null,
        ]);
        $this->scheduleFlush();
    }

    /**
     * @param  list<string>  $rooms
     */
    public function enqueueAssignmentChanged(
        string $installationId,
        string $campaignUuid,
        array $rooms,
        ?int $assignmentGeneration,
        ?int $routeGeneration,
        string $reason = 'assignment_changed',
    ): void {
        if (! $this->outbox->isReady() || $rooms === []) {
            return;
        }

        $this->outbox->enqueue([
            'installation_id' => $installationId,
            'campaign_uuid' => $campaignUuid,
            'event_type' => KeepAliveSocketEnvelope::TYPE_ASSIGNMENT_CHANGED,
            'coalesce_key' => 'asn|'.$installationId.'|'.$campaignUuid,
            'seq_hint' => null,
            'route_generation' => $routeGeneration,
            'assignment_generation' => $assignmentGeneration,
            'rooms' => array_values($rooms),
            'payload' => ['reason' => $reason],
        ]);

        $this->outbox->enqueue([
            'installation_id' => $installationId,
            'campaign_uuid' => $campaignUuid,
            'event_type' => KeepAliveSocketEnvelope::TYPE_ROUTE_INVALIDATED,
            'coalesce_key' => 'rgen|'.$installationId.'|'.$campaignUuid,
            'seq_hint' => null,
            'route_generation' => $routeGeneration,
            'assignment_generation' => $assignmentGeneration,
            'rooms' => array_values($rooms),
            'payload' => ['reason' => 'route_generation_bumped'],
        ]);

        $this->scheduleFlush();
    }

    /**
     * Resolve active rooms for a campaign without per-socket SQL.
     *
     * @return list<string>
     */
    public function roomsForCampaign(string $installationId, string $campaignUuid): array
    {
        $rows = $this->routes->listActiveAssignmentsForCampaigns($installationId, [$campaignUuid]);
        $rooms = [];
        foreach ($rows as $row) {
            $rooms[] = KeepAliveSocketTicket::roomName(
                $installationId,
                (string) $row['campaign_uuid'],
                (string) $row['node_id'],
            );
        }

        return array_values(array_unique($rooms));
    }

    /**
     * Flush pending outbox rows to the gateway (best-effort).
     *
     * @return array{flushed: int, failed: int, pending: int}
     */
    public function flush(int $limit = 50): array
    {
        if (! $this->outbox->isReady()) {
            return ['flushed' => 0, 'failed' => 0, 'pending' => 0];
        }

        $rows = $this->outbox->claimPending($limit);
        $flushed = 0;
        $failed = 0;
        foreach ($rows as $row) {
            try {
                $result = $this->publisher->publish([
                    'event_type' => (string) $row['event_type'],
                    'installation_id' => (string) $row['installation_id'],
                    'campaign_uuid' => (string) $row['campaign_uuid'],
                    'rooms' => array_values((array) $row['rooms']),
                    'seq_hint' => $row['seq_hint'] ?? null,
                    'route_generation' => $row['route_generation'] ?? null,
                    'assignment_generation' => $row['assignment_generation'] ?? null,
                    'payload' => is_array($row['payload'] ?? null) ? $row['payload'] : null,
                ]);
                if ($result['ok'] ?? false) {
                    $this->outbox->markPublished((int) $row['id']);
                    $flushed++;
                } else {
                    $this->outbox->markFailed((int) $row['id'], (string) ($result['error'] ?? 'publish_failed'));
                    $failed++;
                }
            } catch (\Throwable $e) {
                $this->outbox->markFailed((int) $row['id'], $e->getMessage());
                $failed++;
            }
        }

        return [
            'flushed' => $flushed,
            'failed' => $failed,
            'pending' => $this->outbox->pendingCount(),
        ];
    }

    private function scheduleFlush(): void
    {
        if (! $this->autoFlush) {
            return;
        }

        try {
            if (function_exists('app') && class_exists(DB::class)) {
                $connection = SocialAutomationServiceConfig::CONNECTION;
                DB::connection($connection)->afterCommit(function (): void {
                    try {
                        $this->flush();
                    } catch (\Throwable) {
                        // Advisory only — never surface to caller.
                    }
                });

                return;
            }
        } catch (\Throwable) {
        }

        // Unit / no-Laravel: flush immediately (still best-effort).
        try {
            $this->flush();
        } catch (\Throwable) {
        }
    }
}
