<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Repositories;

use Illuminate\Support\Collection;
use Omnichannel\Addons\SocialAutomation\Contracts\SaAccountRepositoryContract;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;

final class InMemorySaAccountRepository implements SaAccountRepositoryContract
{
    /** @var array<int, SaAccount> */
    private array $items = [];

    private int $autoIncrement = 1;

    public function create(array $attributes): SaAccount
    {
        $id = $this->autoIncrement++;
        $model = new SaAccount();
        $now = date('Y-m-d H:i:s');

        $raw = array_merge([
            'id' => $id,
            'account_uuid' => (string) ($attributes['account_uuid'] ?? ''),
            'installation_id' => (string) ($attributes['installation_id'] ?? ''),
            'platform' => (string) ($attributes['platform'] ?? ''),
            'name' => (string) ($attributes['name'] ?? ''),
            'label' => (string) ($attributes['label'] ?? ($attributes['name'] ?? '')),
            'username' => isset($attributes['username']) ? (string) $attributes['username'] : null,
            'declared_username' => isset($attributes['declared_username']) ? (string) $attributes['declared_username'] : null,
            'email' => isset($attributes['email']) ? (string) $attributes['email'] : null,
            'declared_email' => isset($attributes['declared_email']) ? (string) $attributes['declared_email'] : null,
            'encrypted_password' => isset($attributes['encrypted_password']) ? (string) $attributes['encrypted_password'] : null,
            'note' => isset($attributes['note']) ? (string) $attributes['note'] : null,
            'created_by' => isset($attributes['created_by']) ? (int) $attributes['created_by'] : null,
            'identity_source' => (string) ($attributes['identity_source'] ?? 'inventory'),
            'verified' => ! empty($attributes['verified']),
            'last_interaction_at' => isset($attributes['last_interaction_at']) ? (string) $attributes['last_interaction_at'] : null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $attributes);

        $model->setRawAttributes($raw, true);
        $this->items[$id] = $model;

        return $model;
    }

    public function update(int $id, string $installationId, array $attributes): ?SaAccount
    {
        $account = $this->find($id, $installationId);
        if (! $account instanceof SaAccount) {
            return null;
        }

        $current = $account->getAttributes();
        $merged = array_merge($current, $attributes);
        $merged['updated_at'] = date('Y-m-d H:i:s');
        $account->setRawAttributes($merged, true);

        return $account;
    }

    public function list(string $installationId): Collection
    {
        $filtered = array_filter(
            $this->items,
            static fn (SaAccount $a): bool => (string) $a->getAttribute('installation_id') === $installationId
        );

        uasort($filtered, static fn (SaAccount $a, SaAccount $b): int => (int) $b->getAttribute('id') <=> (int) $a->getAttribute('id'));

        return new Collection(array_values($filtered));
    }

    public function find(int $id, string $installationId): ?SaAccount
    {
        $account = $this->items[$id] ?? null;
        if (! $account instanceof SaAccount) {
            return null;
        }

        if ((string) $account->getAttribute('installation_id') !== $installationId) {
            return null;
        }

        return $account;
    }

    public function delete(int $id, string $installationId): bool
    {
        $account = $this->find($id, $installationId);
        if (! $account instanceof SaAccount) {
            return false;
        }

        unset($this->items[$id]);

        return true;
    }
}
