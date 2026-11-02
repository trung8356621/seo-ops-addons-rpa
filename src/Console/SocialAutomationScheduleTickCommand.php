<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\SocialAutomation\Models\SaSchedule;
use Omnichannel\Addons\SocialAutomation\Orchestration\CampaignOrchestrator;
use Omnichannel\Addons\SocialAutomation\Orchestration\JobOrchestrator;
use Omnichannel\Addons\SocialAutomation\Scheduling\ScheduleDispatcher;
use Omnichannel\Addons\SocialAutomation\Services\ClientCleanupService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceResolver;

/**
 * Tick enabled schedules + campaign resource recheck + reconcile expired leases + stale client cleanup.
 * Wire into Laravel scheduler after migrate (see final integration notes).
 */
final class SocialAutomationScheduleTickCommand extends Command
{
    protected $signature = 'social-automation:schedule-tick';

    protected $description = 'Fire due schedules, campaign resource recheck, reconcile leases, cleanup stale clients';

    public function handle(
        ScheduleDispatcher $dispatcher,
        JobOrchestrator $orchestrator,
        CampaignOrchestrator $campaigns,
        SocialAutomationServiceResolver $resolver,
        ClientCleanupService $clientCleanup,
    ): int {
        if (! $resolver->isActive()) {
            $this->warn('Social Automation service inactive — skip.');

            return self::SUCCESS;
        }

        $now = now();
        $fired = 0;
        if (SocialAutomationSchema::schedulesReady()) {
            foreach (SaSchedule::query()->where('enabled', true)->get() as $schedule) {
                // V1: caller/cron decides cadence; each invoke creates one trigger with idempotency on fire timestamp minute.
                $fireAt = $now->copy()->startOfMinute();
                $result = $dispatcher->fire($schedule, $fireAt);
                if ($result['ok'] ?? false) {
                    $fired++;
                }
            }
        } else {
            $this->warn('PENDING DB MIGRATION — sa_schedules missing.');
        }

        $campaignRecheck = ['checked' => 0, 'dispatched' => 0];
        $campaignLeases = ['reconciled' => 0];
        if (SocialAutomationSchema::campaignsReady()) {
            $campaignRecheck = $campaigns->recheckDueCampaigns($now);
            $campaignLeases = $campaigns->reconcileExpiredCampaignLeases();
        }

        $reconciled = $orchestrator->reconcileExpiredLeases();
        $cleanup = $clientCleanup->cleanupStaleClients($now);
        $this->info(
            "triggers_considered={$fired}"
            .' campaign_rechecks='.((int) ($campaignRecheck['checked'] ?? 0))
            .' campaign_dispatched='.((int) ($campaignRecheck['dispatched'] ?? 0))
            .' campaign_leases='.((int) ($campaignLeases['reconciled'] ?? 0))
            .' leases_reconciled='.((int) ($reconciled['reconciled'] ?? 0))
            .' clients_cleaned='.((int) ($cleanup['cleaned'] ?? 0))
            .' keys_revoked='.((int) ($cleanup['revoked_keys'] ?? 0))
        );

        return self::SUCCESS;
    }
}
