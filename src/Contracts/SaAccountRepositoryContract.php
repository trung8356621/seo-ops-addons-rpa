<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Contracts;

use Illuminate\Support\Collection;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;

interface SaAccountRepositoryContract
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): SaAccount;

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(int $id, string $installationId, array $attributes): ?SaAccount;

    /**
     * @return Collection<int, SaAccount>
     */
    public function list(string $installationId): Collection;

    public function find(int $id, string $installationId): ?SaAccount;

    public function delete(int $id, string $installationId): bool;
}
