<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Illuminate\Support\Collection;
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
     * Create a new Client API Key scoped to the current SEO-OPS Installation.
     * The raw key is returned ONLY here and never stored in plaintext.
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
     * Validates installation scope and rejects revoked / mismatched / unknown keys.
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
}
