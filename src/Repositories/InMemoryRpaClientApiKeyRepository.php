<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Repositories;

use Illuminate\Support\Collection;
use Omnichannel\Addons\SocialAutomation\Contracts\RpaClientApiKeyRepositoryContract;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;

final class InMemoryRpaClientApiKeyRepository implements RpaClientApiKeyRepositoryContract
{
    /** @var array<int, SaClientApiKey> */
    private array $items = [];

    private int $autoIncrement = 1;

    public function create(array $attributes): SaClientApiKey
    {
        $id = $this->autoIncrement++;
        $model = new SaClientApiKey();
        $now = date('Y-m-d H:i:s');
        $model->setRawAttributes([
            'id' => $id,
            'installation_id' => (string) ($attributes['installation_id'] ?? ''),
            'name' => (string) ($attributes['name'] ?? ''),
            'key_prefix' => (string) ($attributes['key_prefix'] ?? ''),
            'key_hash' => (string) ($attributes['key_hash'] ?? ''),
            'encrypted_key' => isset($attributes['encrypted_key']) ? (string) $attributes['encrypted_key'] : null,
            'created_by' => isset($attributes['created_by']) ? (int) $attributes['created_by'] : null,
            'last_used_at' => null,
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], true);

        $this->items[$id] = $model;

        return $model;
    }

    public function list(string $installationId): Collection
    {
        $filtered = array_filter(
            $this->items,
            static fn (SaClientApiKey $key): bool => (string) $key->getAttribute('installation_id') === $installationId
        );

        uasort($filtered, static fn (SaClientApiKey $a, SaClientApiKey $b): int => (int) $b->getAttribute('id') <=> (int) $a->getAttribute('id'));

        return new Collection(array_values($filtered));
    }

    public function find(int $id, string $installationId): ?SaClientApiKey
    {
        $item = $this->items[$id] ?? null;
        if ($item instanceof SaClientApiKey && (string) $item->getAttribute('installation_id') === $installationId) {
            return $item;
        }

        return null;
    }

    public function firstUsable(string $installationId): ?SaClientApiKey
    {
        foreach ($this->items as $item) {
            if ((string) $item->getAttribute('installation_id') === $installationId && $item->isActive()) {
                return $item;
            }
        }

        return null;
    }

    public function findByPrefix(string $prefix): Collection
    {
        $filtered = array_filter(
            $this->items,
            static fn (SaClientApiKey $key): bool => (string) $key->getAttribute('key_prefix') === $prefix
        );

        return new Collection(array_values($filtered));
    }

    public function revoke(int $id, string $installationId): bool
    {
        $key = $this->find($id, $installationId);
        if (! $key instanceof SaClientApiKey) {
            return false;
        }

        $raw = $key->getAttributes();
        $raw['revoked_at'] = date('Y-m-d H:i:s');
        $key->setRawAttributes($raw, true);

        return true;
    }

    public function touchLastUsed(SaClientApiKey $key): void
    {
        $raw = $key->getAttributes();
        $raw['last_used_at'] = date('Y-m-d H:i:s');
        $key->setRawAttributes($raw, true);
    }
}
