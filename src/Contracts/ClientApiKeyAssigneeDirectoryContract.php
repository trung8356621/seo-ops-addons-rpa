<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Contracts;

use App\Models\User;

/**
 * Resolves account-scoped users eligible to own a Client API Key.
 */
interface ClientApiKeyAssigneeDirectoryContract
{
    public function findById(int $userId): ?User;

    /**
     * @return list<User>
     */
    public function listEligibleAssignees(User $actor): array;
}
