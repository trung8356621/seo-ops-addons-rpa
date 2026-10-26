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

    public function test_generic_site_cookie_presence_method_allowed(): void
    {
        $agent = new \Omnichannel\Addons\SocialAutomation\Models\SaAgent();
        $agent->id = 1;
        $agent->agent_uuid = 'test-agent-uuid';

        $payload = [
            [
                'browser_id' => 'b_generic_1',
                'name' => 'General Website Profile',
                'platform' => 'general',
                'session_signal' => [
                    'status' => 'present',
                    'checked_at' => '2026-10-26T12:00:00Z',
                    'method' => 'site_cookie_presence',
                ],
            ],
            [
                'browser_id' => 'b_fb_1',
                'name' => 'Facebook Profile',
                'platform' => 'facebook',
                'session_signal' => [
                    'status' => 'present',
                    'checked_at' => '2026-10-26T12:00:00Z',
                    'method' => 'facebook_c_user_cookie',
                ],
            ],
        ];

        $res = $this->service->syncClientBrowsers($agent, $payload, 'ins_123');
        $errors = $res['errors'] ?? [];
        self::assertContains('schema_unavailable', $errors);
        self::assertFalse(in_array('session_forbidden_field:session_signal.method', $errors, true));
    }
}
