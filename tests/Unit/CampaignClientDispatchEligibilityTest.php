<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Domain\AccountDeduplicator;
use Omnichannel\Addons\SocialAutomation\Domain\AgentKindPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignResourceRecheckNudge;
use Omnichannel\Addons\SocialAutomation\Domain\EligibilitySelector;
use PHPUnit\Framework\TestCase;

/**
 * Client campaign dispatch eligibility + hard-pin inventory identity + recheck nudge contracts.
 */
final class CampaignClientDispatchEligibilityTest extends TestCase
{
    private AgentKindPolicy $kinds;

    private EligibilitySelector $selector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kinds = new AgentKindPolicy;
        $this->selector = new EligibilitySelector(new AccountDeduplicator, $this->kinds);
    }

    public function test_online_client_is_dispatch_eligible(): void
    {
        self::assertTrue($this->kinds->countsAsOnlineForDispatch(AgentKindPolicy::KIND_CLIENT, true));
        self::assertTrue($this->kinds->isClient(AgentKindPolicy::KIND_CLIENT));
    }

    public function test_offline_client_is_not_dispatch_eligible(): void
    {
        self::assertFalse($this->kinds->countsAsOnlineForDispatch(AgentKindPolicy::KIND_CLIENT, false));
    }

    public function test_admin_agent_never_dispatch_eligible(): void
    {
        self::assertFalse($this->kinds->countsAsOnlineForDispatch(AgentKindPolicy::KIND_ADMIN, true));
        self::assertFalse($this->kinds->countsAsOnlineForDispatch(AgentKindPolicy::KIND_ADMIN, false));
    }

    public function test_runner_unchanged_when_online(): void
    {
        self::assertTrue($this->kinds->countsAsOnlineForDispatch(AgentKindPolicy::KIND_RUNNER, true));
        self::assertFalse($this->kinds->countsAsOnlineForDispatch(AgentKindPolicy::KIND_RUNNER, false));
    }

    public function test_eligibility_selects_online_client_with_strong_identity(): void
    {
        $result = $this->selector->select(
            [[
                'agent_id' => 1,
                'agent_kind' => AgentKindPolicy::KIND_CLIENT,
                'online' => true,
                'availability' => 'available',
                'active_jobs' => 0,
                'max_concurrency' => 1,
            ]],
            [[
                'session_id' => 10,
                'agent_id' => 1,
                'ready_status' => 'ready',
                'busy' => false,
                'account_locked' => false,
                'platform' => 'facebook',
                'account' => [
                    'platform' => 'facebook',
                    'platform_account_id' => '1001',
                    'verified' => true,
                    'identity_source' => 'platform_id',
                ],
                'account_id' => 5,
            ]],
            ['platform' => 'facebook', 'target_count' => 1]
        );

        self::assertSame(1, $result['dispatched_count']);
        self::assertCount(1, $result['selected']);
    }

    public function test_hard_pinned_inventory_account_eligible_without_strong_identity(): void
    {
        $result = $this->selector->select(
            [[
                'agent_id' => 1,
                'agent_kind' => AgentKindPolicy::KIND_CLIENT,
                'online' => true,
                'availability' => 'available',
                'active_jobs' => 0,
                'max_concurrency' => 1,
            ]],
            [[
                'session_id' => 10,
                'agent_id' => 1,
                'ready_status' => 'ready',
                'busy' => false,
                'account_locked' => false,
                'platform' => 'mayhopphat',
                'account' => [
                    'platform' => 'mayhopphat',
                    'platform_account_id' => null,
                    'verified' => false,
                    'identity_source' => 'inventory',
                ],
                'account_id' => 1,
            ]],
            [
                'platform' => 'mayhopphat',
                'target_count' => 1,
                'require_strong_identity' => false,
            ]
        );

        self::assertSame(1, $result['dispatched_count']);
        self::assertNotContains('identity_incomplete', $result['skip_reasons']);
    }

    public function test_unpinned_auto_select_still_requires_strong_identity(): void
    {
        $result = $this->selector->select(
            [[
                'agent_id' => 1,
                'agent_kind' => AgentKindPolicy::KIND_CLIENT,
                'online' => true,
                'availability' => 'available',
                'active_jobs' => 0,
                'max_concurrency' => 1,
            ]],
            [[
                'session_id' => 10,
                'agent_id' => 1,
                'ready_status' => 'ready',
                'busy' => false,
                'account_locked' => false,
                'platform' => 'mayhopphat',
                'account' => [
                    'platform' => 'mayhopphat',
                    'identity_source' => 'inventory',
                    'verified' => false,
                ],
                'account_id' => 1,
            ]],
            ['platform' => 'mayhopphat', 'target_count' => 1]
        );

        self::assertSame(0, $result['dispatched_count']);
        self::assertContains('identity_incomplete', $result['skip_reasons']);
    }

    public function test_busy_account_not_selected(): void
    {
        $result = $this->selector->select(
            [[
                'agent_id' => 1,
                'agent_kind' => AgentKindPolicy::KIND_CLIENT,
                'online' => true,
                'availability' => 'available',
                'active_jobs' => 0,
                'max_concurrency' => 1,
            ]],
            [[
                'session_id' => 10,
                'agent_id' => 1,
                'ready_status' => 'ready',
                'busy' => false,
                'account_locked' => true,
                'platform' => 'mayhopphat',
                'account' => [
                    'platform' => 'mayhopphat',
                    'platform_account_id' => 'x',
                    'verified' => true,
                    'identity_source' => 'platform_id',
                ],
                'account_id' => 1,
            ]],
            [
                'platform' => 'mayhopphat',
                'target_count' => 1,
                'require_strong_identity' => false,
            ]
        );

        self::assertSame(0, $result['dispatched_count']);
        self::assertContains('account_locked', $result['skip_reasons']);
    }

    public function test_offline_client_skipped_by_selector(): void
    {
        $result = $this->selector->select(
            [[
                'agent_id' => 1,
                'agent_kind' => AgentKindPolicy::KIND_CLIENT,
                'online' => false,
                'availability' => 'available',
                'active_jobs' => 0,
                'max_concurrency' => 1,
            ]],
            [[
                'session_id' => 10,
                'agent_id' => 1,
                'ready_status' => 'ready',
                'busy' => false,
                'account_locked' => false,
                'platform' => 'mayhopphat',
                'account' => [
                    'platform' => 'mayhopphat',
                    'platform_account_id' => 'x',
                    'verified' => true,
                    'identity_source' => 'platform_id',
                ],
            ]],
            ['platform' => 'mayhopphat', 'target_count' => 1, 'require_strong_identity' => false]
        );

        self::assertSame(0, $result['dispatched_count']);
        self::assertContains('agent_offline', $result['skip_reasons']);
    }

    public function test_orchestrator_passes_require_strong_identity_false_for_hard_pin(): void
    {
        $orc = (string) file_get_contents(dirname(__DIR__, 2).'/src/Orchestration/CampaignOrchestrator.php');
        self::assertStringContainsString("'require_strong_identity' => \$requiredAccountId === null", $orc);
        self::assertStringContainsString('pinned_account_unavailable', $orc);
        self::assertStringContainsString('no silent fallback', $orc);
        self::assertStringContainsString('pinned_account_mismatch', $orc);
    }

    public function test_resource_nudge_min_interval_and_no_direct_dispatch(): void
    {
        self::assertSame(60, CampaignResourceRecheckNudge::MIN_INTERVAL_SECONDS);
        self::assertSame('resource_nudge_at', CampaignResourceRecheckNudge::META_LAST_NUDGE_AT);

        $nudgeSrc = (string) file_get_contents(dirname(__DIR__, 2).'/src/Domain/CampaignResourceRecheckNudge.php');
        self::assertStringContainsString('next_recheck_at', $nudgeSrc);
        self::assertStringNotContainsString('dispatchCampaign', $nudgeSrc);
        self::assertStringNotContainsString('claimNext', $nudgeSrc);

        $agentSrc = (string) file_get_contents(dirname(__DIR__, 2).'/src/Registry/AgentRegistryService.php');
        self::assertStringContainsString('nudgeWaitingCampaignsForAgent', $agentSrc);
        self::assertStringContainsString('! $wasOnline', $agentSrc);

        $syncSrc = (string) file_get_contents(dirname(__DIR__, 2).'/src/Registry/SessionSyncService.php');
        self::assertStringContainsString('CampaignResourceRecheckNudge', $syncSrc);
        self::assertStringContainsString('$eligibleNow && $changed', $syncSrc);
    }
}
