<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Contracts;

use Illuminate\Support\Collection;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;

interface RpaClientApiKeyRepositoryContract
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): SaClientApiKey;

    /**
     * @return Collection<int, SaClientApiKey>
     */
    public function list(string $installationId): Collection;

    public function find(int $id, string $installationId): ?SaClientApiKey;

    public function firstUsable(string $installationId): ?SaClientApiKey;

    /**
     * Active (non-revoked) key owned by staff user within installation.
     */
    public function findActiveByCreator(string $installationId, int $createdByUserId): ?SaClientApiKey;

    /**
     * @return Collection<int, SaClientApiKey>
     */
    public function findByPrefix(string $prefix): Collection;

    public function revoke(int $id, string $installationId): bool;

    public function touchLastUsed(SaClientApiKey $key): void;
}
