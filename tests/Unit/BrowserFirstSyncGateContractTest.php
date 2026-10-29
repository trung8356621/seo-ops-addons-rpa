<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;
use Omnichannel\Addons\SocialAutomation\Domain\SessionSignalState;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Registry\SessionSyncService;
use PHPUnit\Framework\TestCase;

/**
 * CP16B — Browser first-sync gate correction.
 *
 * Browser existence != authentication success. Persistence eligibility is
 * identity/binding; session_signal only records login evidence.
 *
 * Persistence paths that need Eloquent schema remain source-contract asserted;
 * signal mapping + Staff inventory projection are exercised in-process.
 */
final class BrowserFirstSyncGateContractTest extends TestCase
{
    private string $syncSrc;

    private SessionSyncService $service;

    private OnlineStateCalculator $online;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncSrc = (string) file_get_contents(dirname(__DIR__, 2).'/src/Registry/SessionSyncService.php');
        $this->online = new OnlineStateCalculator(90);
        $this->service = new SessionSyncService($this->online);
    }

    public function test_1_valid_browser_plus_present_is_eligible_and_maps_present(): void
    {
        self::assertStringNotContainsString("! \$session->exists && \$incomingSignalStatus !== 'present'", $this->syncSrc);
        self::assertStringContainsString('SessionSignalState::mergeLastWriteWins', $this->syncSrc);

        $signal = SessionSignalState::normalize([
            'status' => 'present',
            'checked_at' => '2026-10-29T12:00:00Z',
            'method' => 'facebook_c_user_cookie',
        ]);
        self::assertTrue($signal['present']);
        self::assertSame('present', $signal['status']);
    }

    public function test_2_valid_browser_plus_absent_is_eligible_and_maps_absent(): void
    {
        self::assertStringNotContainsString('New browsers still require a present session signal', $this->syncSrc);

        $signal = SessionSignalState::normalize([
            'status' => 'absent',
            'checked_at' => '2026-10-29T12:00:00Z',
            'method' => 'facebook_c_user_cookie',
        ]);
        self::assertFalse($signal['present']);
        self::assertSame('absent', $signal['status']);
    }

    public function test_3_unsupported_and_error_never_marked_authenticated(): void
    {
        foreach (['unsupported', 'error', 'unknown', ''] as $status) {
            self::assertFalse(
                SessionSignalState::isPresent(['status' => $status]),
                "status={$status} must not authenticate"
            );
        }
        // Boolean contract collapses non-present → absent (never invents present).
        self::assertSame('absent', SessionSignalState::normalize(['status' => 'unsupported'])['status']);
        self::assertSame('absent', SessionSignalState::normalize(['status' => 'error'])['status']);
    }

    public function test_4_missing_account_binding_rejected(): void
    {
        self::assertStringContainsString('account_id_required', $this->syncSrc);
        self::assertStringContainsString("throw new \\DomainException('account_id_required')", $this->syncSrc);
    }

    public function test_5_wrong_installation_rejected(): void
    {
        self::assertStringContainsString('account_installation_mismatch', $this->syncSrc);
    }

    public function test_6_wrong_platform_rejected(): void
    {
        self::assertStringContainsString('account_platform_mismatch', $this->syncSrc);
    }

    public function test_7_invalid_client_auth_and_lease_gates_preserved(): void
    {
        $provider = (string) file_get_contents(dirname(__DIR__, 2).'/src/SocialAutomationServiceProvider.php');
        self::assertStringContainsString('EnsureRpaClientApiKey::class', $provider);
        self::assertStringContainsString("Route::post('/browser-sync'", $provider);
        self::assertStringContainsString('EnsureClientConnectionLease::class', $provider);

        $controller = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Http/Controllers/RpaClientApiController.php'
        );
        self::assertStringContainsString('authorizedClient', $controller);
        self::assertStringContainsString('syncClientBrowsers', $controller);
    }

    public function test_8_existing_browser_update_path_preserved(): void
    {
        self::assertStringContainsString('browser_account_binding_immutable', $this->syncSrc);
        self::assertStringContainsString('firstOrNew', $this->syncSrc);
        self::assertStringContainsString('SessionSignalState::mergeLastWriteWins', $this->syncSrc);
        self::assertStringContainsString("=== 'needs_login' ? 'needs_login' : 'candidate'", $this->syncSrc);
    }

    public function test_9_duplicate_sync_is_idempotent_by_session_uuid(): void
    {
        self::assertStringContainsString("firstOrNew(['session_uuid' => \$browserId])", $this->syncSrc);
        self::assertStringContainsString('account_browser_duplicate_on_device', $this->syncSrc);
        self::assertStringContainsString('browser_device_mismatch', $this->syncSrc);
    }

    public function test_10_browser_delete_behavior_preserved(): void
    {
        self::assertStringContainsString('function deleteClientBrowser', $this->syncSrc);
        self::assertStringContainsString("->where('session_uuid', \$browserId)", $this->syncSrc);
        self::assertStringContainsString("->where('agent_id', \$agent->id)", $this->syncSrc);

        $controller = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Http/Controllers/RpaClientApiController.php'
        );
        self::assertStringContainsString('deleteClientBrowser', $controller);
    }

    public function test_11_staff_inventory_displays_valid_unlogged_browser(): void
    {
        $now = new \DateTimeImmutable('2026-10-29 12:00:00');
        $agent = $this->agent([
            'display_name' => 'Office-PC',
            'client_api_key_id' => 11,
            'last_heartbeat_at' => new \DateTimeImmutable('2026-10-29 11:59:30'),
        ]);
        $account = $this->account([
            'id' => 5,
            'account_uuid' => 'acc-unlogged',
            'name' => 'Unlogged Brand',
            'username' => 'brand.unlogged',
            'platform' => 'facebook',
        ]);
        $session = $this->session([
            'session_uuid' => 'brw-unlogged',
            'account_id' => 5,
            'platform' => 'facebook',
            'session_state' => 'candidate',
            'ready_status' => 'ready',
            'capabilities' => [
                'name' => 'Unlogged Brand',
                'status' => 'stopped',
                'session_signal' => [
                    'present' => false,
                    'status' => 'absent',
                    'checked_at' => '2026-10-29T11:59:00Z',
                    'method' => 'facebook_c_user_cookie',
                ],
            ],
        ]);

        $row = $this->service->mapStaffBrowserRow($session, $agent, $account, $now);

        self::assertSame('brw-unlogged', $row['browser_id']);
        self::assertSame('acc-unlogged', $row['account_uuid']);
        self::assertSame('absent', $row['signal_status']);
        self::assertSame('candidate', $row['session_state']);
        self::assertNotSame('verified', $row['session_state']);
        self::assertFalse(SessionSignalState::isPresent([
            'status' => $row['signal_status'],
        ]));
    }

    public function test_12_online_status_independent_of_login_status(): void
    {
        $now = new \DateTimeImmutable('2026-10-29 12:00:00');
        $agent = $this->agent([
            'client_api_key_id' => 22,
            'last_heartbeat_at' => new \DateTimeImmutable('2026-10-29 11:59:40'),
        ]);

        $loggedOutRunning = $this->session([
            'session_uuid' => 'brw-run-absent',
            'session_state' => 'candidate',
            'ready_status' => 'ready',
            'capabilities' => [
                'status' => 'running',
                'session_signal' => ['status' => 'absent', 'present' => false],
            ],
        ]);
        $loggedInStopped = $this->session([
            'session_uuid' => 'brw-stop-present',
            'session_state' => 'verified',
            'ready_status' => 'ready',
            'capabilities' => [
                'status' => 'stopped',
                'session_signal' => ['status' => 'present', 'present' => true],
            ],
        ]);

        $a = $this->service->mapStaffBrowserRow($loggedOutRunning, $agent, null, $now);
        $b = $this->service->mapStaffBrowserRow($loggedInStopped, $agent, null, $now);

        self::assertTrue($a['device_online']);
        self::assertTrue($a['is_online']);
        self::assertSame('absent', $a['signal_status']);

        self::assertTrue($b['device_online']);
        self::assertFalse($b['is_online']);
        self::assertSame('present', $b['signal_status']);

        // OnlineStateCalculator remains heartbeat-only (no login coupling).
        self::assertTrue($this->online->isOnline(
            new \DateTimeImmutable('2026-10-29 11:59:40'),
            $now
        ));
        $calcSrc = (string) file_get_contents(dirname(__DIR__, 2).'/src/Domain/OnlineStateCalculator.php');
        self::assertStringNotContainsString('session_signal', $calcSrc);
        self::assertStringNotContainsString('login', $calcSrc);
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
