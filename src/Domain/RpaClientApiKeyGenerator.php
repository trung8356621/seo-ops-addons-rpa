<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

use Illuminate\Support\Str;

/**
 * CSPRNG Key generation and verification for RPA Client API Keys.
 * Key format: rpa_client_{lookupId}_{secret}
 * - lookupId (key_prefix): 8 hex chars (from 4 random bytes) to index candidates.
 * - secret: 32 cryptographically secure random bytes (base64url).
 * - raw key is NEVER stored in database, only HMAC-SHA256 hash.
 */
final class RpaClientApiKeyGenerator
{
    public const PREFIX_SCHEME = 'rpa_client_';

    /**
     * @return array{raw_key: string, key_prefix: string, key_hash: string}
     */
    public function generate(): array
    {
        $lookupId = Str::lower(bin2hex(random_bytes(4))); // 8 hex characters
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $rawKey = self::PREFIX_SCHEME . $lookupId . '_' . $secret;
        $keyPrefix = self::PREFIX_SCHEME . $lookupId;

        return [
            'raw_key' => $rawKey,
            'key_prefix' => $keyPrefix,
            'key_hash' => $this->hash($rawKey),
        ];
    }

    public function extractPrefix(string $rawKey): ?string
    {
        $rawKey = trim($rawKey);
        if (preg_match('/^(rpa_client_[a-f0-9]{8})_/i', $rawKey, $matches) !== 1) {
            return null;
        }

        return Str::lower((string) $matches[1]);
    }

    public function hash(string $rawKey): string
    {
        return hash_hmac('sha256', $rawKey, $this->pepper());
    }

    public function verify(string $rawKey, string $storedHash): bool
    {
        if ($rawKey === '' || $storedHash === '') {
            return false;
        }

        return hash_equals($storedHash, $this->hash($rawKey));
    }

    private function pepper(): string
    {
        try {
            if (function_exists('config')) {
                $key = (string) config('app.key', '');
                if ($key !== '') {
                    return $key;
                }
            }
        } catch (\Throwable) {
            // Container uninitialized during isolated unit tests
        }

        return 'seo-ops-rpa-client-fallback-key';
    }
}
