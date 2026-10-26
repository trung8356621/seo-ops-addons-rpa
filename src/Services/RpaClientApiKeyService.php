<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Omnichannel\Addons\SocialAutomation\Contracts\RpaClientApiKeyRepositoryContract;
use Omnichannel\Addons\SocialAutomation\Domain\RpaClientApiKeyGenerator;
use Omnichannel\Addons\SocialAutomation\Domain\RpaClientApiKeyValidationResult;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Repositories\EloquentRpaClientApiKeyRepository;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;

final class RpaClientApiKeyService
{
    private RpaClientApiKeyRepositoryContract $repository;

    public function __construct(
        private readonly RpaClientApiKeyGenerator $generator,
        private readonly SocialAutomationInstallationResolver $installationResolver,
        ?RpaClientApiKeyRepositoryContract $repository = null,
    ) {
        $this->repository = $repository ?? new EloquentRpaClientApiKeyRepository();
    }

    /**
     * Return active usable key if one exists, or generate a candidate key (lazy provisioning).
     * Does NOT write to database if no key exists.
     *
     * @return array{
     *     is_activated: bool,
     *     status: string,
     *     status_label: string,
     *     raw_key: string,
     *     key_id: int|null,
     *     name: string,
     *     prefix: string,
     *     candidate_token: string|null
     * }
     */
    public function getActiveOrCandidateKey(?string $installationId = null): array
    {
        $targetInstallation = $installationId ?? $this->installationResolver->resolveInstallationId();
        $existing = $this->repository->firstUsable($targetInstallation);

        if ($existing instanceof SaClientApiKey) {
            $raw = $existing->getDecryptedRawKey();

            return [
                'is_activated' => true,
                'status' => 'active',
                'status_label' => 'Đã kích hoạt',
                'raw_key' => $raw ?? ($existing->key_prefix . '...'),
                'key_id' => (int) $existing->id,
                'name' => (string) $existing->name,
                'prefix' => (string) $existing->key_prefix,
                'candidate_token' => null,
            ];
        }

        // Candidate key: Server-issued, CSPRNG, tamper-resistant, NOT written to DB
        $generated = $this->generator->generate();
        $token = $this->issueCandidateToken($generated['raw_key'], $targetInstallation);

        return [
            'is_activated' => false,
            'status' => 'candidate',
            'status_label' => 'Chưa kích hoạt',
            'raw_key' => $generated['raw_key'],
            'key_id' => null,
            'name' => 'Default Client Key',
            'prefix' => $generated['key_prefix'],
            'candidate_token' => $token,
        ];
    }

    /**
     * Issue a signed, tamper-resistant candidate token with expiration.
     */
    public function issueCandidateToken(string $rawKey, string $installationId, int $ttl = 900): string
    {
        $payload = [
            'raw_key' => $rawKey,
            'installation_id' => $installationId,
            'expires_at' => time() + $ttl,
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encoded, $this->tokenSigningSecret());

        return $encoded . '.' . $signature;
    }

    /**
     * Verify candidate token integrity, signature and expiration.
     *
     * @return array{raw_key: string, installation_id: string, expires_at: int}|null
     */
    public function verifyCandidateToken(string $token): ?array
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $signature] = $parts;
        $expectedSignature = hash_hmac('sha256', $encoded, $this->tokenSigningSecret());
        if (! hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($data) || empty($data['raw_key']) || empty($data['installation_id']) || empty($data['expires_at'])) {
            return null;
        }

        if ($data['expires_at'] < time()) {
            return null; // Expired
        }

        return $data;
    }

    /**
     * Atomically activate a candidate key upon user copy action.
     *
     * @return array{ok: bool, message: string, raw_key?: string, already_active?: bool, key?: SaClientApiKey, error?: string}
     */
    public function activateCandidateKey(string $candidateToken, ?int $userId = null): array
    {
        $candidate = $this->verifyCandidateToken($candidateToken);
        if ($candidate === null) {
            return [
                'ok' => false,
                'error' => 'invalid_or_expired_candidate',
                'message' => 'Candidate Key không hợp lệ hoặc đã hết hạn. Vui lòng làm mới trang.',
            ];
        }

        $targetInstallation = $this->installationResolver->resolveInstallationId();
        if (! hash_equals($targetInstallation, (string) $candidate['installation_id'])) {
            return [
                'ok' => false,
                'error' => 'installation_mismatch',
                'message' => 'Candidate Key không thuộc phạm vi Installation hiện tại.',
            ];
        }

        // Concurrency safeguard: if another Staff already activated a key, reuse it
        $existing = $this->repository->firstUsable($targetInstallation);
        if ($existing instanceof SaClientApiKey) {
            return [
                'ok' => true,
                'already_active' => true,
                'message' => 'Key đã được kích hoạt trước đó.',
                'raw_key' => $existing->getDecryptedRawKey() ?? ($existing->key_prefix . '...'),
                'key' => $existing,
            ];
        }

        $rawKey = (string) $candidate['raw_key'];
        $prefix = $this->generator->extractPrefix($rawKey);
        if ($prefix === null) {
            return [
                'ok' => false,
                'error' => 'invalid_key_format',
                'message' => 'Định dạng Key không hợp lệ.',
            ];
        }

        $created = $this->repository->create([
            'installation_id' => $targetInstallation,
            'name' => 'Default Client Key',
            'key_prefix' => $prefix,
            'key_hash' => $this->generator->hash($rawKey),
            'encrypted_key' => $this->encryptRawKey($rawKey),
            'created_by' => $userId,
            'revoked_at' => null,
            'last_used_at' => null,
        ]);

        return [
            'ok' => true,
            'already_active' => false,
            'message' => 'Đã kích hoạt và sao chép',
            'raw_key' => $rawKey,
            'key' => $created,
        ];
    }

    /**
     * Create a new Client API Key scoped to the current SEO-OPS Installation.
     *
     * @return array{key: SaClientApiKey, raw_key: string}
     */
    public function create(string $name, ?int $createdByUserId = null): array
    {
        $name = trim($name);
        if ($name === '') {
            $name = 'Client API Key ' . date('Y-m-d H:i');
        }

        $installationId = $this->installationResolver->resolveInstallationId();
        $generated = $this->generator->generate();

        $key = $this->repository->create([
            'installation_id' => $installationId,
            'name' => $name,
            'key_prefix' => $generated['key_prefix'],
            'key_hash' => $generated['key_hash'],
            'encrypted_key' => $this->encryptRawKey($generated['raw_key']),
            'created_by' => $createdByUserId,
            'revoked_at' => null,
            'last_used_at' => null,
        ]);

        return [
            'key' => $key,
            'raw_key' => $generated['raw_key'],
        ];
    }

    /**
     * List all keys belonging to the installation.
     *
     * @return Collection<int, SaClientApiKey>
     */
    public function list(?string $installationId = null): Collection
    {
        $targetInstallation = $installationId ?? $this->installationResolver->resolveInstallationId();

        return $this->repository->list($targetInstallation);
    }

    /**
     * Find a key within installation scope.
     */
    public function find(int $id, ?string $installationId = null): ?SaClientApiKey
    {
        $targetInstallation = $installationId ?? $this->installationResolver->resolveInstallationId();

        return $this->repository->find($id, $targetInstallation);
    }

    /**
     * Revoke a key within installation scope.
     */
    public function revoke(int $id, ?string $installationId = null): bool
    {
        $targetInstallation = $installationId ?? $this->installationResolver->resolveInstallationId();

        return $this->repository->revoke($id, $targetInstallation);
    }

    /**
     * Validate a bearer token presenting an RPA Client API Key.
     */
    public function validate(string $rawKey, ?string $expectedInstallationId = null): RpaClientApiKeyValidationResult
    {
        $rawKey = trim($rawKey);
        if ($rawKey === '') {
            return RpaClientApiKeyValidationResult::failure(
                RpaClientApiKeyValidationResult::ERROR_MISSING_KEY,
                'API key is required.'
            );
        }

        $prefix = $this->generator->extractPrefix($rawKey);
        if ($prefix === null) {
            return RpaClientApiKeyValidationResult::failure(
                RpaClientApiKeyValidationResult::ERROR_INVALID_KEY,
                'Invalid API key format.'
            );
        }

        $candidates = $this->repository->findByPrefix($prefix);

        $matchedKey = null;
        foreach ($candidates as $candidate) {
            if ($candidate instanceof SaClientApiKey && $this->generator->verify($rawKey, (string) $candidate->key_hash)) {
                $matchedKey = $candidate;
                break;
            }
        }

        if (! $matchedKey instanceof SaClientApiKey) {
            return RpaClientApiKeyValidationResult::failure(
                RpaClientApiKeyValidationResult::ERROR_INVALID_KEY,
                'Invalid API key.'
            );
        }

        if ($matchedKey->isRevoked()) {
            return RpaClientApiKeyValidationResult::failure(
                RpaClientApiKeyValidationResult::ERROR_KEY_REVOKED,
                'API key has been revoked.'
            );
        }

        $targetInstallation = $expectedInstallationId ?? $this->installationResolver->resolveInstallationId();
        if (! hash_equals($targetInstallation, (string) $matchedKey->installation_id)) {
            return RpaClientApiKeyValidationResult::failure(
                RpaClientApiKeyValidationResult::ERROR_INSTALLATION_MISMATCH,
                'API key belongs to another SEO-OPS installation.'
            );
        }

        // Touch last used timestamp
        $this->repository->touchLastUsed($matchedKey);

        return RpaClientApiKeyValidationResult::success($matchedKey);
    }

    private function encryptRawKey(string $rawKey): string
    {
        if (function_exists('app') && app()->bound('encrypter')) {
            try {
                return Crypt::encryptString($rawKey);
            } catch (\Throwable) {
                // Test or unbooted container fallback
            }
        }

        return 'enc:' . base64_encode($rawKey);
    }

    private function tokenSigningSecret(): string
    {
        try {
            if (function_exists('config')) {
                $key = (string) config('app.key', '');
                if ($key !== '') {
                    return $key;
                }
            }
        } catch (\Throwable) {
        }

        return 'seo-ops-rpa-candidate-signing-secret';
    }
}
