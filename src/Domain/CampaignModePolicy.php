<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

use Omnichannel\Addons\SocialAutomation\Enums\CampaignExecutionMode;

/**
 * Campaign-scoped account reuse rules. Global account lock is separate.
 */
final class CampaignModePolicy
{
    /**
     * @param  list<int|string>  $assignedAccountIdsInCampaign  durable assignments (mode A)
     * @param  list<int|string>  $accountsWithNonTerminalContent  mode B sequential guard
     */
    public function canAssignAccount(
        CampaignExecutionMode $mode,
        int|string $accountId,
        array $assignedAccountIdsInCampaign,
        array $accountsWithNonTerminalContent = [],
    ): bool {
        $id = (string) $accountId;
        if ($id === '' || $id === '0') {
            return false;
        }

        if ($mode === CampaignExecutionMode::OneAccountOneJob) {
            foreach ($assignedAccountIdsInCampaign as $assigned) {
                if ((string) $assigned === $id) {
                    return false;
                }
            }

            return true;
        }

        // one_account_many_jobs — sequential per account inside campaign
        foreach ($accountsWithNonTerminalContent as $busy) {
            if ((string) $busy === $id) {
                return false;
            }
        }

        return true;
    }

    /**
     * Failed/unknown in mode A still consume the account for the campaign.
     */
    public function assignmentIsDurable(CampaignExecutionMode $mode): bool
    {
        return $mode === CampaignExecutionMode::OneAccountOneJob;
    }
}
