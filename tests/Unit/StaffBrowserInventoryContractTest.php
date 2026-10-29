<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Registry\SessionSyncService;
use PHPUnit\Framework\TestCase;

/**
 * CP15A — Staff Browser inventory contracts.
 *
 * Harness note: PHPUnit boots Laravel's real Eloquent Model via omnichannel-client
 * vendor/autoload. Datetime attribute setters call connection() without an app
 * container. Use setRawAttributes() (JSON-encode array casts) so unit tests stay
 * offline — no production DB, no business-logic changes.
 */
final class StaffBrowserInventoryContractTest extends TestCase
{
    private SessionSyncService $service;

    private OnlineStateCalculator $online;

    protected function setUp(): void
    {
        parent::setUp();
        $this->online = new OnlineStateCalculator(90);
        $this->service = new SessionSyncService($this->online);
    }

    public function test_status_policy_exposes_existing_heartbeat_ttl(): void
    {
        self::assertSame(90, $this->online->ttlSeconds());
        self::assertSame(OnlineStateCalculator::DEFAULT_ONLINE_TTL_SECONDS, $this->online->ttlSeconds());
    }

    public function test_empty_inventory_response_shape(): void
    {
        $policy = [
            'source' => 'backend',
            'heartbeat_ttl_seconds' => 90,
            'notes' => 'test',
        ];
        $payload = $this->service->assembleStaffBrowserInventoryResponse([], $policy);

        self::assertTrue($payload['ok']);
        self::assertSame([], $payload['browsers']);
        self::assertSame([
            'total_browsers' => 0,
            'online_browsers' => 0,
            'offline_browsers' => 0,
            'total_clients' => 0,
        ], $payload['summary']);
        self::assertSame('backend', $payload['status_policy']['source']);
        self::assertSame(90, $payload['status_policy']['heartbeat_ttl_seconds']);
    }

    public function test_browser_identity_and_bindings_map_from_domain(): void
    {
        $now = new \DateTimeImmutable('2026-10-29 12:00:00');

        $agent = $this->agent([
            'display_name' => 'Office-PC-01',
            'device_id' => 'dev-abc',
            'client_api_key_id' => 42,
            'last_heartbeat_at' => new \DateTimeImmutable('2026-10-29 11:59:30'),
        ]);

        $account = $this->account([
            'id' => 7,
            'account_uuid' => 'acc-uuid-7',
            'name' => 'Brand Page',
            'username' => 'brand.user',
            'platform' => 'facebook',
            'site_url' => 'https://facebook.com',
        ]);

        $session = $this->session([
            'session_uuid' => 'brw-111',
            'account_id' => 7,
            'platform' => 'facebook',
            'ready_status' => 'ready',
            'session_state' => 'verified',
            'capabilities' => [
                'name' => 'Chrome Profile A',
                'status' => 'running',
                'last_reported_at' => '2026-10-29T11:59:40+00:00',
            ],
        ]);

        $row = $this->service->mapStaffBrowserRow($session, $agent, $account, $now);

        self::assertSame('brw-111', $row['browser_id']);
        self::assertSame('Chrome Profile A', $row['browser_name']);
        self::assertSame('Chrome Profile A', $row['browser_label']);
        self::assertSame('brand.user', $row['username']);
        self::assertSame('Brand Page', $row['account_name']);
        self::assertSame(7, $row['account_id']);
        self::assertSame('acc-uuid-7', $row['account_uuid']);
        self::assertSame(42, $row['client_id']);
        self::assertSame(42, $row['client_api_key_id']);
        self::assertSame('Office-PC-01', $row['machine_name']);
        self::assertSame('running', $row['runtime_status']);
        self::assertTrue($row['is_online']);
        self::assertTrue($row['device_online']);
        self::assertSame('2026-10-29T11:59:40+00:00', $row['last_seen_at']);

        foreach (['raw_key', 'encrypted_key', 'password', 'cookies', 'connection_token', 'path'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $row);
        }
    }

    public function test_device_offline_yields_unknown_stale_and_null_is_online(): void
    {
        $now = new \DateTimeImmutable('2026-10-29 12:00:00');

        $agent = $this->agent([
            'display_name' => 'Stale-PC',
            'client_api_key_id' => 9,
            'last_heartbeat_at' => new \DateTimeImmutable('2026-10-29 11:50:00'), // 600s > 90
        ]);

        $session = $this->session([
            'session_uuid' => 'brw-offline-device',
            'ready_status' => 'ready',
            'capabilities' => ['name' => 'X', 'status' => 'running'],
        ]);

        $row = $this->service->mapStaffBrowserRow($session, $agent, null, $now);

        self::assertFalse($row['device_online']);
        self::assertSame('unknown_stale', $row['runtime_status']);
        self::assertNull($row['is_online']);
        self::assertNull($row['username']);
        self::assertNull($row['account_name']);
    }

    public function test_device_online_stopped_browser_is_offline_false(): void
    {
        $now = new \DateTimeImmutable('2026-10-29 12:00:00');

        $agent = $this->agent([
            'client_api_key_id' => 3,
            'last_heartbeat_at' => new \DateTimeImmutable('2026-10-29 11:59:40'),
        ]);

        $session = $this->session([
            'session_uuid' => 'brw-stopped',
            'ready_status' => 'pending',
            'capabilities' => ['status' => 'stopped'],
        ]);

        $row = $this->service->mapStaffBrowserRow($session, $agent, null, $now);

        self::assertTrue($row['device_online']);
        self::assertSame('stopped', $row['runtime_status']);
        self::assertFalse($row['is_online']);
    }

    public function test_summary_consistency_excludes_unknown_from_offline_bucket(): void
    {
        $policy = [
            'source' => 'backend',
            'heartbeat_ttl_seconds' => 90,
            'notes' => 'test',
        ];

        $browsers = [
            ['browser_id' => 'a', 'is_online' => true, 'client_api_key_id' => 1],
            ['browser_id' => 'b', 'is_online' => false, 'client_api_key_id' => 1],
            ['browser_id' => 'c', 'is_online' => null, 'client_api_key_id' => 2],
            ['browser_id' => 'd', 'is_online' => true, 'client_api_key_id' => null],
        ];

        $payload = $this->service->assembleStaffBrowserInventoryResponse($browsers, $policy);

        self::assertSame(4, $payload['summary']['total_browsers']);
        self::assertSame(2, $payload['summary']['online_browsers']);
        self::assertSame(1, $payload['summary']['offline_browsers']);
        self::assertSame(2, $payload['summary']['total_clients']);
        self::assertSame(
            $payload['summary']['online_browsers'] + $payload['summary']['offline_browsers'] + 1,
            $payload['summary']['total_browsers']
        );
    }

    public function test_record_presence_alone_does_not_make_browser_online(): void
    {
        $now = new \DateTimeImmutable('2026-10-29 12:00:00');
        $session = $this->session([
            'session_uuid' => 'brw-orphan',
            'capabilities' => ['status' => 'running'],
        ]);

        $row = $this->service->mapStaffBrowserRow($session, null, null, $now);

        self::assertFalse($row['device_online']);
        self::assertSame('unknown_stale', $row['runtime_status']);
        self::assertNull($row['is_online']);
        self::assertNull($row['client_api_key_id']);
    }

    public function test_provider_registers_staff_browsers_get_with_session_middleware(): void
    {
        $provider = (string) file_get_contents(dirname(__DIR__, 2).'/src/SocialAutomationServiceProvider.php');
        self::assertStringContainsString('SocialAutomationBrowserInventoryController', $provider);
        self::assertStringContainsString("Route::get('/api/browsers'", $provider);
        self::assertStringContainsString("'social-automation.browsers.index'", $provider);
        // CP12A preserved
        self::assertStringContainsString("prefix('api/script-registry')", $provider);
        self::assertStringContainsString('RpaAdminSpaController::class', $provider);
    }

    public function test_openapi_documents_staff_browsers_inventory(): void
    {
        $yaml = (string) file_get_contents(dirname(__DIR__, 2).'/openapi/openapi.yaml');
        self::assertStringContainsString('/social-automation/api/browsers:', $yaml);
        self::assertStringContainsString('staffBrowsersIndex', $yaml);
        self::assertStringContainsString('status_policy', $yaml);
        self::assertStringContainsString('unknown_stale', $yaml);
        // CP12A preserved
        self::assertStringContainsString('/social-automation/api/script-registry:', $yaml);
        self::assertStringContainsString('staffScriptRegistryIndex', $yaml);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function agent(array $attrs): SaAgent
    {
        $agent = new SaAgent;
        $agent->setRawAttributes($attrs, true);

        return $agent;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function account(array $attrs): SaAccount
    {
        $account = new SaAccount;
        $account->setRawAttributes($attrs, true);

        return $account;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function session(array $attrs): SaBrowserSession
    {
        if (isset($attrs['capabilities']) && is_array($attrs['capabilities'])) {
            $attrs['capabilities'] = json_encode($attrs['capabilities'], JSON_THROW_ON_ERROR);
        }
        $session = new SaBrowserSession;
        $session->setRawAttributes($attrs, true);

        return $session;
    }
}
