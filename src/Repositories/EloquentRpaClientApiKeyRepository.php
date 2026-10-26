<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Repositories;

use Illuminate\Support\Collection;
use Omnichannel\Addons\SocialAutomation\Contracts\RpaClientApiKeyRepositoryContract;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;

final class EloquentRpaClientApiKeyRepository implements RpaClientApiKeyRepositoryContract
{
    public function create(array $attributes): SaClientApiKey
    {
        /** @var SaClientApiKey $key */
        $key = SaClientApiKey::query()->create($attributes);

        return $key;
    }

    public function list(string $installationId): Collection
    {
        return SaClientApiKey::query()
            ->where('installation_id', $installationId)
            ->with('creator')
            ->orderByDesc('id')
            ->get();
    }

    public function find(int $id, string $installationId): ?SaClientApiKey
    {
        return SaClientApiKey::query()
            ->where('id', $id)
            ->where('installation_id', $installationId)
            ->first();
    }

    public function findByPrefix(string $prefix): Collection
    {
        return SaClientApiKey::query()
            ->where('key_prefix', $prefix)
            ->limit(10)
            ->get();
    }

    public function revoke(int $id, string $installationId): bool
    {
        $key = $this->find($id, $installationId);
        if (! $key instanceof SaClientApiKey) {
            return false;
        }

        $key->revoke();

        return true;
    }

    public function touchLastUsed(SaClientApiKey $key): void
    {
        $key->touchLastUsed();
    }
}
