<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Repositories;

use Illuminate\Support\Collection;
use Omnichannel\Addons\SocialAutomation\Contracts\SaAccountRepositoryContract;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;

final class EloquentSaAccountRepository implements SaAccountRepositoryContract
{
    public function create(array $attributes): SaAccount
    {
        /** @var SaAccount $account */
        $account = SaAccount::query()->create($attributes);

        return $account;
    }

    public function update(int $id, string $installationId, array $attributes): ?SaAccount
    {
        $account = $this->find($id, $installationId);
        if (! $account instanceof SaAccount) {
            return null;
        }

        $account->fill($attributes)->save();

        return $account;
    }

    public function list(string $installationId): Collection
    {
        return SaAccount::query()
            ->where('installation_id', $installationId)
            ->with('creator')
            ->orderByDesc('id')
            ->get();
    }

    public function find(int $id, string $installationId): ?SaAccount
    {
        return SaAccount::query()
            ->where('id', $id)
            ->where('installation_id', $installationId)
            ->first();
    }

    public function delete(int $id, string $installationId): bool
    {
        $account = $this->find($id, $installationId);
        if (! $account instanceof SaAccount) {
            return false;
        }

        return (bool) $account->delete();
    }
}
