<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveSocketEnvelope;

/**
 * Best-effort HTTP publish to Keep Alive Socket.IO gateway.
 * Failures never throw to Job/Campaign callers — caller must catch.
 */
final class KeepAliveGatewayPublisher
{
    public const DEFAULT_TIMEOUT_SECONDS = 2;

    public const MAX_ATTEMPTS = 2;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    private bool $offline = false;

    private int $failures = 0;

    /** @var (callable(array<string, mixed>): array{ok: bool, error?: string, http?: int})|null */
    private $publishOverride = null;

    public function __construct(
        private readonly ?string $publishUrl = null,
        private readonly ?string $gatewaySecret = null,
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {}

    public function setOffline(bool $offline): void
    {
        $this->offline = $offline;
    }

    /**
     * Test/dev hook to simulate a reachable gateway without HTTP.
     *
     * @param  (callable(array<string, mixed>): array{ok: bool, error?: string, http?: int})|null  $override
     */
    public function setPublishOverride(?callable $override): void
    {
        $this->publishOverride = $override;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    public function failureCount(): int
    {
        return $this->failures;
    }

    /**
     * @param  array{
     *   event_type: string,
     *   installation_id: string,
     *   campaign_uuid: string,
     *   rooms: list<string>,
     *   seq_hint?: int|null,
     *   route_generation?: int|null,
     *   assignment_generation?: int|null,
     *   payload?: array<string, mixed>|null
     * }  $row
     * @return array{ok: bool, error?: string, http?: int}
     */
    public function publish(array $row): array
    {
        if ($this->offline) {
            $this->failures++;

            return ['ok' => false, 'error' => 'gateway_offline'];
        }

        if ($this->publishOverride !== null) {
            $result = ($this->publishOverride)($row);
            if ($result['ok'] ?? false) {
                $this->sent[] = [
                    'event' => (string) $row['event_type'],
                    'tenant_id' => (string) $row['installation_id'],
                    'campaign_id' => (string) $row['campaign_uuid'],
                    'rooms' => array_values($row['rooms']),
                    'seq_hint' => $row['seq_hint'] ?? null,
                    'route_generation' => $row['route_generation'] ?? null,
                    'assignment_generation' => $row['assignment_generation'] ?? null,
                    'reason' => is_array($row['payload'] ?? null) ? ($row['payload']['reason'] ?? null) : null,
                ];
            } else {
                $this->failures++;
            }

            return $result;
        }

        $body = [
            'event' => (string) $row['event_type'],
            'tenant_id' => (string) $row['installation_id'],
            'campaign_id' => (string) $row['campaign_uuid'],
            'rooms' => array_values($row['rooms']),
            'seq_hint' => $row['seq_hint'] ?? null,
            'route_generation' => $row['route_generation'] ?? null,
            'assignment_generation' => $row['assignment_generation'] ?? null,
            'reason' => is_array($row['payload'] ?? null) ? ($row['payload']['reason'] ?? null) : null,
        ];

        $encoded = json_encode($body, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > KeepAliveSocketEnvelope::MAX_PAYLOAD_BYTES) {
            $this->failures++;

            return ['ok' => false, 'error' => 'payload_too_large'];
        }

        $url = $this->resolveUrl();
        $secret = $this->resolveSecret();
        // Unconfigured gateway must NOT discard durable outbox rows (no soft success).
        if ($url === '' || $secret === '') {
            $this->failures++;

            return ['ok' => false, 'error' => 'gateway_unconfigured', 'http' => 0];
        }

        $lastError = 'publish_failed';
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $result = $this->httpPost($url, $secret, $encoded);
            if ($result['ok'] ?? false) {
                $this->sent[] = $body;

                return ['ok' => true, 'http' => (int) ($result['http'] ?? 200)];
            }
            $lastError = (string) ($result['error'] ?? 'publish_failed');
            if ((int) ($result['http'] ?? 0) === 401 || (int) ($result['http'] ?? 0) === 422) {
                break;
            }
        }

        $this->failures++;

        return ['ok' => false, 'error' => $lastError];
    }

    /**
     * @return array{ok: bool, error?: string, http?: int}
     */
    private function httpPost(string $url, string $secret, string $jsonBody): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return ['ok' => false, 'error' => 'curl_init_failed'];
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'X-KeepAlive-Gateway-Secret: '.$secret,
                ],
                CURLOPT_POSTFIELDS => $jsonBody,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            ]);
            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($errno !== 0) {
                return ['ok' => false, 'error' => 'gateway_unreachable', 'http' => 0];
            }
            if ($http >= 200 && $http < 300) {
                return ['ok' => true, 'http' => $http];
            }

            return ['ok' => false, 'error' => 'gateway_http_'.$http, 'http' => $http];
        }

        // Fallback for environments without curl extension.
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\nX-KeepAlive-Gateway-Secret: {$secret}\r\n",
                'content' => $jsonBody,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return ['ok' => false, 'error' => 'gateway_unreachable', 'http' => 0];
        }

        return ['ok' => true, 'http' => 200];
    }

    private function resolveUrl(): string
    {
        if ($this->publishUrl !== null && $this->publishUrl !== '') {
            return $this->publishUrl;
        }
        try {
            if (function_exists('config')) {
                return trim((string) config('social-automation.keepalive_gateway_publish_url', ''));
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function resolveSecret(): string
    {
        if ($this->gatewaySecret !== null && $this->gatewaySecret !== '') {
            return $this->gatewaySecret;
        }
        try {
            if (function_exists('config')) {
                $configured = (string) config('social-automation.keepalive_gateway_secret', '');
                if ($configured !== '') {
                    return $configured;
                }
                $sock = (string) config('social-automation.keepalive_socket_secret', '');
                if ($sock !== '') {
                    return 'ka-gw-v1|'.$sock;
                }
                $key = (string) config('app.key', '');
                if ($key !== '') {
                    return 'ka-gw-v1|ka-sock-v1|'.$key;
                }
            }
        } catch (\Throwable) {
        }

        return 'ka-gw-v1|ka-sock-v1|seo-ops-keepalive-socket-fallback';
    }
}
