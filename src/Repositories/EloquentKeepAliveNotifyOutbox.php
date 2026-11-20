<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Repositories;

use Omnichannel\Addons\SocialAutomation\Contracts\KeepAliveNotifyOutboxContract;
use Omnichannel\Addons\SocialAutomation\Models\SaKeepAliveNotifyOutbox;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;

final class EloquentKeepAliveNotifyOutbox implements KeepAliveNotifyOutboxContract
{
    public function isReady(): bool
    {
        return SocialAutomationSchema::keepaliveNotifyReady();
    }

    public function enqueue(array $row): void
    {
        $key = (string) $row['coalesce_key'];
        $existing = SaKeepAliveNotifyOutbox::query()
            ->where('coalesce_key', $key)
            ->where('status', 'pending')
            ->first();

        if ($existing instanceof SaKeepAliveNotifyOutbox) {
            if (isset($row['seq_hint'])) {
                $existing->seq_hint = max((int) ($existing->seq_hint ?? 0), (int) $row['seq_hint']);
            }
            if (array_key_exists('route_generation', $row)) {
                $existing->route_generation = $row['route_generation'];
            }
            if (array_key_exists('assignment_generation', $row)) {
                $existing->assignment_generation = $row['assignment_generation'];
            }
            $existing->rooms = array_values($row['rooms']);
            $existing->event_type = (string) $row['event_type'];
            if (array_key_exists('payload', $row)) {
                $existing->payload = $row['payload'];
            }
            $existing->available_at = null;
            $existing->save();

            return;
        }

        SaKeepAliveNotifyOutbox::query()->create([
            'installation_id' => (string) $row['installation_id'],
            'campaign_uuid' => (string) $row['campaign_uuid'],
            'event_type' => (string) $row['event_type'],
            'coalesce_key' => $key,
            'seq_hint' => $row['seq_hint'] ?? null,
            'route_generation' => $row['route_generation'] ?? null,
            'assignment_generation' => $row['assignment_generation'] ?? null,
            'rooms' => array_values($row['rooms']),
            'payload' => $row['payload'] ?? null,
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => null,
        ]);
    }

    public function claimPending(int $limit = 50, ?int $nowUnix = null): array
    {
        $now = $nowUnix !== null ? date('Y-m-d H:i:s', $nowUnix) : now();
        $rows = SaKeepAliveNotifyOutbox::query()
            ->where('status', 'pending')
            ->where(function ($q) use ($now): void {
                $q->whereNull('available_at')->orWhere('available_at', '<=', $now);
            })
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        $out = [];
        foreach ($rows as $row) {
            if (! $row instanceof SaKeepAliveNotifyOutbox) {
                continue;
            }
            $row->attempts = (int) $row->attempts + 1;
            $row->save();
            $out[] = [
                'id' => (int) $row->id,
                'installation_id' => (string) $row->installation_id,
                'campaign_uuid' => (string) $row->campaign_uuid,
                'event_type' => (string) $row->event_type,
                'coalesce_key' => (string) $row->coalesce_key,
                'seq_hint' => $row->seq_hint !== null ? (int) $row->seq_hint : null,
                'route_generation' => $row->route_generation !== null ? (int) $row->route_generation : null,
                'assignment_generation' => $row->assignment_generation !== null ? (int) $row->assignment_generation : null,
                'rooms' => array_values((array) $row->rooms),
                'payload' => is_array($row->payload) ? $row->payload : null,
                'attempts' => (int) $row->attempts,
            ];
        }

        return $out;
    }

    public function markPublished(int $id): void
    {
        SaKeepAliveNotifyOutbox::query()->where('id', $id)->update([
            'status' => 'published',
            'published_at' => now(),
            'last_error' => null,
        ]);
    }

    public function markFailed(int $id, string $error, int $retryAfterSeconds = 5): void
    {
        SaKeepAliveNotifyOutbox::query()->where('id', $id)->update([
            'status' => 'pending',
            'last_error' => substr($error, 0, 255),
            'available_at' => now()->addSeconds(max(1, $retryAfterSeconds)),
        ]);
    }

    public function pendingCount(): int
    {
        return (int) SaKeepAliveNotifyOutbox::query()->where('status', 'pending')->count();
    }
}
