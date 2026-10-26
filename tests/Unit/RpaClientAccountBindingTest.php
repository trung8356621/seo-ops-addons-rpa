<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;
use Omnichannel\Addons\SocialAutomation\Registry\SessionSyncService;
use PHPUnit\Framework\TestCase;

final class RpaClientAccountBindingTest extends TestCase
{
    private SessionSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SessionSyncService(new OnlineStateCalculator());
    }

    public function test_cookie_values_in_nested_signal_rejected(): void
    {
        $agent = new \Omnichannel\Addons\SocialAutomation\Models\SaAgent();
        $agent->id = 1;
        $agent->agent_uuid = 'test-agent-uuid';

        $payload = [
            [
                'browser_id' => 'b1',
                'name' => 'Profile 1',
                'platform' => 'facebook',
                'session_signal' => [
                    'status' => 'present',
                    'cookie_value' => 'leak_secret_value',
                ],
            ],
        ];

        $res = $this->service->syncClientBrowsers($agent, $payload, 'ins_123');
        self::assertFalse($res['ok']);
        self::assertContains('session_forbidden_field:session_signal.cookie_value', $res['errors'] ?? []);
    }

    public function test_c_user_in_account_binding_rejected(): void
    {
        $agent = new \Omnichannel\Addons\SocialAutomation\Models\SaAgent();
        $agent->id = 1;
        $agent->agent_uuid = 'test-agent-uuid';

        $payload = [
            [
                'browser_id' => 'b1',
                'name' => 'Profile 1',
                'platform' => 'facebook',
                'account_binding' => [
                    'account_id' => 'acc_1',
                    'c_user' => '100012345678',
                ],
            ],
        ];

        $res = $this->service->syncClientBrowsers($agent, $payload, 'ins_123');
        self::assertFalse($res['ok']);
        self::assertContains('session_forbidden_field:account_binding.c_user', $res['errors'] ?? []);
    }
}
