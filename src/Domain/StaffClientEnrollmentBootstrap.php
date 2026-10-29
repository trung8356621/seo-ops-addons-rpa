<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

use Illuminate\Support\Str;

/**
 * Stateless staff Client enrollment bootstrap credential.
 *
 * Format (Electron-compatible opaque Bearer):
 *   rpa_client_{8hex}_{base64url(json)}.{hmac_sha256_hex}
 *
 * Purpose-scoped: enrollment/register only. Does not grant Browser Sync,
 * heartbeat, accounts, or other Client session APIs until materialized into
 * sa_client_api_keys on successful registration.
 */
final class StaffClientEnrollmentBootstrap
{
    public const PURPOSE = 'staff_client_enroll';

    public const DEFAULT_TTL_SECONDS = 3600;

    public const ERROR_INVALID = 'invalid_enrollment_bootstrap';

    public const ERROR_EXPIRED = 'enrollment_bootstrap_expired';

    public const ERROR_INSTALLATION_MISMATCH = 'enrollment_installation_mismatch';

    public const ERROR_STAFF_MISMATCH = 'enrollment_staff_mismatch';

    /**
     * @return array{
     *     raw_key: string,
     *     key_prefix: string,
     *     staff_user_id: int,
     *     installation_id: string,
     *     expires_at: int,
     *     expires_at_iso: string
     * }
     */
    public function issue(int $staffUserId, string $installationId, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): array
    {
        $staffUserId = max(1, $staffUserId);
        $installationId = trim($installationId);
        $ttlSeconds = max(60, $ttlSeconds);
        $expiresAt = time() + $ttlSeconds;
        $lookupId = Str::lower(bin2hex(random_bytes(4)));
        $payload = [
            'v' => 1,
            'purpose' => self::PURPOSE,
            'sid' => $staffUserId,
            'iid' => $installationId,
            'exp' => $expiresAt,
            'n' => Str::lower(bin2hex(random_bytes(8))),
        ];
        $encoded = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $encoded, $this->signingSecret());
        $rawKey = RpaClientApiKeyGenerator::PREFIX_SCHEME.$lookupId.'_'.$encoded.'.'.$signature;
        $keyPrefix = RpaClientApiKeyGenerator::PREFIX_SCHEME.$lookupId;

        return [
            'raw_key' => $rawKey,
            'key_prefix' => $keyPrefix,
            'staff_user_id' => $staffUserId,
            'installation_id' => $installationId,
            'expires_at' => $expiresAt,
            'expires_at_iso' => gmdate('c', $expiresAt),
        ];
    }

    /**
     * @return array{
     *     staff_user_id: int,
     *     installation_id: string,
     *     expires_at: int,
     *     key_prefix: string,
     *     nonce: string
     * }|null
     */
    public function parse(string $rawKey): ?array
    {
        $rawKey = trim($rawKey);
        if ($rawKey === '' || ! str_starts_with($rawKey, RpaClientApiKeyGenerator::PREFIX_SCHEME)) {
            return null;
        }

        if (preg_match('/^(rpa_client_[a-f0-9]{8})_(.+)\.([a-f0-9]{64})$/i', $rawKey, $matches) !== 1) {
            return null;
        }

        $keyPrefix = Str::lower((string) $matches[1]);
        $encoded = (string) $matches[2];
        $signature = Str::lower((string) $matches[3]);
        $expected = hash_hmac('sha256', $encoded, $this->signingSecret());
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
            || ($data['purpose'] ?? null) !== self::PURPOSE
            || (int) ($data['v'] ?? 0) !== 1
            || empty($data['sid'])
            || empty($data['iid'])
            || empty($data['exp'])
            || empty($data['n'])
        ) {
            return null;
        }

        return [
            'staff_user_id' => (int) $data['sid'],
            'installation_id' => (string) $data['iid'],
            'expires_at' => (int) $data['exp'],
            'key_prefix' => $keyPrefix,
            'nonce' => (string) $data['n'],
        ];
    }

    /**
     * @param  array{staff_user_id: int, installation_id: string, expires_at: int, key_prefix: string, nonce: string}  $bootstrap
     */
    public function assertUsable(array $bootstrap, string $expectedInstallationId, ?int $expectedStaffUserId = null): ?string
    {
        if ((int) $bootstrap['expires_at'] < time()) {
            return self::ERROR_EXPIRED;
        }
        if (! hash_equals(trim($expectedInstallationId), (string) $bootstrap['installation_id'])) {
            return self::ERROR_INSTALLATION_MISMATCH;
        }
        if ($expectedStaffUserId !== null && (int) $bootstrap['staff_user_id'] !== $expectedStaffUserId) {
            return self::ERROR_STAFF_MISMATCH;
        }

        return null;
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

    private function signingSecret(): string
    {
        try {
            if (function_exists('config')) {
                $key = (string) config('app.key', '');
                if ($key !== '') {
                    return 'sa-enroll-v1|'.$key;
                }
            }
        } catch (\Throwable) {
        }

        return 'sa-enroll-v1|seo-ops-rpa-enrollment-fallback';
    }
}
