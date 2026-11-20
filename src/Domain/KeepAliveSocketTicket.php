<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Stateless signed Keep Alive Socket.IO route ticket (HMAC).
 *
 * Wire format: ka_sock_{base64url(json)}.{hmac_sha256_hex}
 * Audience-bound to keepalive-gateway. Never embeds Client API Key plaintext.
 */
final class KeepAliveSocketTicket
{
    public const PURPOSE = 'keepalive_socket';

    public const AUDIENCE = 'keepalive-gateway';

    public const VERSION = 1;

    public const DEFAULT_TTL_SECONDS = 120;

    public const MAX_TTL_SECONDS = 120;

    public const ROLE_RECOVERY = 'recovery';

    public const ROLE_EXECUTOR = 'executor';

    public const ERROR_INVALID = 'socket_ticket_invalid';

    public const ERROR_EXPIRED = 'socket_ticket_expired';

    public const ERROR_AUDIENCE = 'socket_ticket_audience_mismatch';

    public const ERROR_REPLAY = 'socket_ticket_replay';

    public const ERROR_ROUTE_GENERATION_STALE = 'route_generation_stale';

    public const ERROR_ROLE_INVALID = 'socket_role_invalid';

    public const ERROR_CAMPAIGNS_REQUIRED = 'campaign_ids_required';

    public const ERROR_CAMPAIGN_UNAUTHORIZED = 'campaign_unauthorized';

    public const ERROR_GATEWAY_UNAUTHORIZED = 'gateway_unauthorized';

    private string $signingSecret;

    public function __construct(?string $signingSecret = null)
    {
        $this->signingSecret = $signingSecret ?? $this->defaultSigningSecret();
    }

    /**
     * @param  array{
     *   tenant_id: string,
     *   role: string,
     *   node_id: string|null,
     *   campaign_ids: list<string>,
     *   rooms: list<string>,
     *   assignment_generation: int,
     *   route_generation: int,
     *   client_api_key_id: int,
     *   agent_uuid?: string|null
     * }  $claims
     * @return array{ticket: string, jti: string, expires_at: int, expires_at_iso: string, claims: array<string, mixed>}
     */
    public function issue(array $claims, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): array
    {
        $ttlSeconds = max(1, min(self::MAX_TTL_SECONDS, $ttlSeconds));
        $now = time();
        $expiresAt = $now + $ttlSeconds;
        $jti = bin2hex(random_bytes(16));
        $payload = [
            'v' => self::VERSION,
            'purpose' => self::PURPOSE,
            'aud' => self::AUDIENCE,
            'jti' => $jti,
            'iat' => $now,
            'exp' => $expiresAt,
            'tid' => (string) $claims['tenant_id'],
            'role' => (string) $claims['role'],
            'nid' => $claims['node_id'],
            'cid' => array_values($claims['campaign_ids']),
            'rooms' => array_values($claims['rooms']),
            'agen' => (int) $claims['assignment_generation'],
            'rgen' => (int) $claims['route_generation'],
            'kid' => (int) $claims['client_api_key_id'],
            'aid' => $claims['agent_uuid'] ?? null,
        ];
        $encoded = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $encoded, $this->signingSecret);
        $ticket = 'ka_sock_'.$encoded.'.'.$signature;

        return [
            'ticket' => $ticket,
            'jti' => $jti,
            'expires_at' => $expiresAt,
            'expires_at_iso' => gmdate('c', $expiresAt),
            'claims' => $payload,
        ];
    }

    /**
     * Cryptographic verify only (no replay store). Gateway may reimplement with same secret.
     *
     * @return array{ok: true, claims: array<string, mixed>}|array{ok: false, error: string}
     */
    public function verify(string $ticket, ?int $now = null): array
    {
        $parsed = $this->parse($ticket);
        if ($parsed === null) {
            return ['ok' => false, 'error' => self::ERROR_INVALID];
        }
        if (($parsed['aud'] ?? null) !== self::AUDIENCE) {
            return ['ok' => false, 'error' => self::ERROR_AUDIENCE];
        }
        $nowTs = $now ?? time();
        if ((int) ($parsed['exp'] ?? 0) < $nowTs) {
            return ['ok' => false, 'error' => self::ERROR_EXPIRED];
        }

        return ['ok' => true, 'claims' => $parsed];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function parse(string $ticket): ?array
    {
        $ticket = trim($ticket);
        if ($ticket === '' || ! str_starts_with($ticket, 'ka_sock_')) {
            return null;
        }
        $body = substr($ticket, strlen('ka_sock_'));
        $dot = strrpos($body, '.');
        if ($dot === false) {
            return null;
        }
        $encoded = substr($body, 0, $dot);
        $signature = strtolower(substr($body, $dot + 1));
        if ($encoded === '' || ! preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return null;
        }
        $expected = hash_hmac('sha256', $encoded, $this->signingSecret);
        if (! hash_equals($expected, $signature)) {
            return null;
        }
        $json = $this->base64UrlDecode($encoded);
        if ($json === null) {
            return null;
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        if (! is_array($data)
            || (int) ($data['v'] ?? 0) !== self::VERSION
            || ($data['purpose'] ?? null) !== self::PURPOSE
            || empty($data['jti'])
            || empty($data['tid'])
            || empty($data['exp'])
            || empty($data['role'])
        ) {
            return null;
        }

        return $data;
    }

    public static function roomName(string $tenantId, string $campaignUuid, string $nodeId): string
    {
        return 'tenant:'.$tenantId.':campaign:'.$campaignUuid.':node:'.$nodeId;
    }

    public static function isValidRole(string $role): bool
    {
        return $role === self::ROLE_RECOVERY || $role === self::ROLE_EXECUTOR;
    }

    public function signingSecretForGateway(): string
    {
        return $this->signingSecret;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    private function defaultSigningSecret(): string
    {
        try {
            if (function_exists('config')) {
                $configured = (string) config('social-automation.keepalive_socket_secret', '');
                if ($configured !== '') {
                    return $configured;
                }
                $key = (string) config('app.key', '');
                if ($key !== '') {
                    return 'ka-sock-v1|'.$key;
                }
            }
        } catch (\Throwable) {
        }

        return 'ka-sock-v1|seo-ops-keepalive-socket-fallback';
    }
}
