<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Domain\AccountDeduplicator;
use Omnichannel\Addons\SocialAutomation\Domain\AgentKindPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\EligibilitySelector;
use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;
use PHPUnit\Framework\TestCase;

final class RpaClientRegisterAndHeartbeatTest extends TestCase
{
    private AgentKindPolicy $agentKinds;
    private OnlineStateCalculator $online;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agentKinds = new AgentKindPolicy();
        $this->online = new OnlineStateCalculator();
    }

    public function test_client_kind_never_counts_as_online_for_dispatch(): void
    {
        self::assertSame(AgentKindPolicy::KIND_CLIENT, $this->agentKinds->normalize('client'));
        self::assertFalse($this->agentKinds->isRunner(AgentKindPolicy::KIND_CLIENT));
        self::assertFalse($this->agentKinds->countsAsOnlineForDispatch(AgentKindPolicy::KIND_CLIENT, true));
        self::assertFalse($this->agentKinds->countsAsOnlineForDispatch(AgentKindPolicy::KIND_CLIENT, false));
    }

    public function test_eligibility_selector_skips_client_agents(): void
    {
        $selector = new EligibilitySelector(new AccountDeduplicator(), $this->agentKinds);

        $agents = [
            [
                'agent_id' => 'client_1',
                'agent_uuid' => 'client_uuid_1',
                'agent_kind' => AgentKindPolicy::KIND_CLIENT,
                'online' => true,
                'availability' => 'available',
                'active_jobs' => 0,
                'max_concurrency' => 1,
            ],
        ];

        $sessions = [
            [
                'session_uuid' => 'sess_1',
                'agent_id' => 'client_1',
                'ready_status' => 'ready',
                'account_id' => 10,
                'platform' => 'facebook',
            ],
        ];

        $result = $selector->select($sessions, $agents, ['platform' => 'facebook'], 1);

        self::assertCount(0, $result['selected']);
        self::assertContains('agent_offline', $result['skip_reasons']);
    }

    public function test_online_state_calculator(): void
    {
        $now = new \DateTimeImmutable('2026-10-26 12:00:00');
        $freshHb = new \DateTimeImmutable('2026-10-26 11:59:30'); // 30s ago
        $staleHb = new \DateTimeImmutable('2026-10-26 11:58:20'); // 100s ago

        self::assertTrue($this->online->isOnline($freshHb, $now));
        self::assertFalse($this->online->isOnline($staleHb, $now));
        self::assertSame('online', $this->online->state($freshHb, $now));
        self::assertSame('offline', $this->online->state($staleHb, $now));
    }
}
