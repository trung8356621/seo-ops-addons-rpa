<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Repositories;

use Omnichannel\Addons\SocialAutomation\Contracts\KeepAliveNotifyOutboxContract;

final class InMemoryKeepAliveNotifyOutbox implements KeepAliveNotifyOutboxContract
{
    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    private int $seq = 1;

    private bool $ready = true;

    public function setReady(bool $ready): void
    {
        $this->ready = $ready;
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function enqueue(array $row): void
    {
        $key = (string) $row['coalesce_key'];
        foreach ($this->rows as $id => $existing) {
            if (($existing['status'] ?? '') === 'pending' && ($existing['coalesce_key'] ?? '') === $key) {
                $seq = $row['seq_hint'] ?? null;
                if ($seq !== null) {
                    $this->rows[$id]['seq_hint'] = max((int) ($existing['seq_hint'] ?? 0), (int) $seq);
                }
                if (isset($row['route_generation'])) {
                    $this->rows[$id]['route_generation'] = $row['route_generation'];
                }
                if (isset($row['assignment_generation'])) {
                    $this->rows[$id]['assignment_generation'] = $row['assignment_generation'];
                }
                $this->rows[$id]['rooms'] = array_values($row['rooms']);
                $this->rows[$id]['payload'] = $row['payload'] ?? $existing['payload'] ?? null;
                $this->rows[$id]['event_type'] = $row['event_type'];

                return;
            }
        }

        $id = $this->seq++;
        $this->rows[$id] = [
            'id' => $id,
            'installation_id' => (string) $row['installation_id'],
            'campaign_uuid' => (string) $row['campaign_uuid'],
            'event_type' => (string) $row['event_type'],
            'coalesce_key' => $key,
            'seq_hint' => isset($row['seq_hint']) ? (int) $row['seq_hint'] : null,
            'route_generation' => $row['route_generation'] ?? null,
            'assignment_generation' => $row['assignment_generation'] ?? null,
            'rooms' => array_values($row['rooms']),
            'payload' => $row['payload'] ?? null,
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => null,
            'published_at' => null,
            'last_error' => null,
        ];
    }

    public function claimPending(int $limit = 50, ?int $nowUnix = null): array
    {
        $now = $nowUnix ?? time();
        $out = [];
        foreach ($this->rows as $id => $row) {
            if (($row['status'] ?? '') !== 'pending') {
                continue;
            }
            $available = $row['available_at'] ?? null;
            if ($available !== null && (int) $available > $now) {
                continue;
            }
            $this->rows[$id]['attempts'] = (int) $row['attempts'] + 1;
            $out[] = $this->rows[$id];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public function markPublished(int $id): void
    {
        if (! isset($this->rows[$id])) {
            return;
        }
        $this->rows[$id]['status'] = 'published';
        $this->rows[$id]['published_at'] = time();
        $this->rows[$id]['last_error'] = null;
    }

    public function markFailed(int $id, string $error, int $retryAfterSeconds = 5): void
    {
        if (! isset($this->rows[$id])) {
            return;
        }
        $this->rows[$id]['status'] = 'pending';
        $this->rows[$id]['last_error'] = substr($error, 0, 255);
        $this->rows[$id]['available_at'] = time() + max(1, $retryAfterSeconds);
    }

    public function pendingCount(): int
    {
        $n = 0;
        foreach ($this->rows as $row) {
            if (($row['status'] ?? '') === 'pending') {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_values($this->rows);
    }
}
