<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;
use Omnichannel\Addons\SocialAutomation\Registry\SessionSyncService;
use PHPUnit\Framework\TestCase;

final class RpaClientBrowserSyncTest extends TestCase
{
    private SessionSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SessionSyncService(new OnlineStateCalculator());
    }

    public function test_forbidden_fields_are_rejected(): void
    {
        // When schema is not booted or tested standalone, sync validates forbidden fields first
        $agent = new \Omnichannel\Addons\SocialAutomation\Models\SaAgent();
        $agent->id = 1;
        $agent->agent_uuid = 'test-agent-uuid';

        $payloadWithCookie = [
            [
                'browser_id' => 'b1',
                'name' => 'Profile 1',
                'cookies' => ['c_user' => '12345'],
            ],
        ];

        $res = $this->service->syncClientBrowsers($agent, $payloadWithCookie);
        self::assertFalse($res['ok']);
        self::assertContains('session_forbidden_field:cookies', $res['errors'] ?? []);

        $payloadWithPassword = [
            [
                'browser_id' => 'b2',
                'name' => 'Profile 2',
                'password' => 'secret123',
            ],
        ];

        $res2 = $this->service->syncClientBrowsers($agent, $payloadWithPassword);
        self::assertFalse($res2['ok']);
        self::assertContains('session_forbidden_field:password', $res2['errors'] ?? []);
    }

    public function test_online_state_calculator_for_browser_stale(): void
    {
        $calc = new OnlineStateCalculator(90);
        $now = new \DateTimeImmutable('2026-10-26 12:00:00');

        $onlineTime = new \DateTimeImmutable('2026-10-26 11:59:00'); // 60s ago -> online
        $offlineTime = new \DateTimeImmutable('2026-10-26 11:57:00'); // 180s ago -> offline

        self::assertTrue($calc->isOnline($onlineTime, $now));
        self::assertFalse($calc->isOnline($offlineTime, $now));
    }
}
