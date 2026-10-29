<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Domain\RpaClientApiKeyGenerator;
use Omnichannel\Addons\SocialAutomation\Domain\RpaClientApiKeyValidationResult;
use Omnichannel\Addons\SocialAutomation\Domain\StaffClientEnrollmentBootstrap;
use Omnichannel\Addons\SocialAutomation\Http\Middleware\EnsureRpaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Repositories\InMemoryRpaClientApiKeyRepository;
use Omnichannel\Addons\SocialAutomation\Services\RpaClientApiKeyService;
use Omnichannel\Addons\SocialAutomation\Services\StaffClientEnrollmentService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use PHPUnit\Framework\TestCase;

/**
 * CP17A — Staff Client enrollment backend contracts (isolated fixtures, no production DB).
 */
final class StaffClientEnrollmentContractTest extends TestCase
{
    private string $root;

    private StaffClientEnrollmentBootstrap $bootstrap;

    private RpaClientApiKeyGenerator $generator;

    private SocialAutomationInstallationResolver $installationResolver;

    private InMemoryRpaClientApiKeyRepository $repository;

    private StaffClientEnrollmentService $enrollment;

    private RpaClientApiKeyService $keyService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
        $this->bootstrap = new StaffClientEnrollmentBootstrap;
        $this->generator = new RpaClientApiKeyGenerator;
        $this->installationResolver = new SocialAutomationInstallationResolver;
        $this->installationResolver->setForcedInstallationId('ins_cp17a');
        $this->repository = new InMemoryRpaClientApiKeyRepository;
        $this->enrollment = new StaffClientEnrollmentService(
            $this->bootstrap,
            $this->generator,
            $this->installationResolver,
            $this->repository,
            null,
        );
        $this->keyService = new RpaClientApiKeyService(
            $this->generator,
            $this->installationResolver,
            $this->repository,
        );
    }

    public function test_staff_without_client_obtains_bootstrap_key_with_zero_db_writes(): void
    {
        self::assertSame(0, $this->repository->countForInstallation('ins_cp17a'));
        $state = $this->enrollment->connectionState(42);
        self::assertFalse($state['connected']);
        self::assertSame('not_connected', $state['status']);
        self::assertNotNull($state['bootstrap']);
        self::assertStringStartsWith('rpa_client_', (string) $state['bootstrap']['raw_key']);
        self::assertSame(StaffClientEnrollmentService::LOCALSTORAGE_KEY, $state['localstorage_key']);
        self::assertTrue((bool) ($state['bootstrap']['tutorial_video']['placeholder'] ?? false));
        self::assertSame(0, $this->repository->countForInstallation('ins_cp17a'));
    }

    public function test_unused_bootstrap_creates_no_database_record_on_refresh(): void
    {
        $a = $this->enrollment->issueBootstrapKey(42);
        $b = $this->enrollment->issueBootstrapKey(42);
        self::assertTrue($a['ok']);
        self::assertTrue($b['ok']);
        self::assertNotSame($a['bootstrap']['raw_key'], $b['bootstrap']['raw_key']);
        self::assertSame(0, $this->repository->countForInstallation('ins_cp17a'));
    }

    public function test_invalid_and_expired_bootstrap_rejected(): void
    {
        self::assertNull($this->bootstrap->parse('rpa_client_deadbeef_not-a-bootstrap'));
        $issued = $this->bootstrap->issue(42, 'ins_cp17a', 60);
        $parsed = $this->bootstrap->parse($issued['raw_key']);
        self::assertNotNull($parsed);
        $parsed['expires_at'] = time() - 10;
        self::assertSame(
            StaffClientEnrollmentBootstrap::ERROR_EXPIRED,
            $this->bootstrap->assertUsable($parsed, 'ins_cp17a')
        );
        self::assertSame(
            StaffClientEnrollmentBootstrap::ERROR_INSTALLATION_MISMATCH,
            $this->bootstrap->assertUsable(
                $this->bootstrap->parse($issued['raw_key']) ?? [],
                'ins_other'
            )
        );
    }

    public function test_bootstrap_cannot_impersonate_another_staff(): void
    {
        $issued = $this->bootstrap->issue(42, 'ins_cp17a');
        $parsed = $this->bootstrap->parse($issued['raw_key']);
        self::assertNotNull($parsed);
        self::assertSame(42, $parsed['staff_user_id']);
        self::assertSame(
            StaffClientEnrollmentBootstrap::ERROR_STAFF_MISMATCH,
            $this->bootstrap->assertUsable($parsed, 'ins_cp17a', 99)
        );
    }

    public function test_successful_materialize_creates_one_staff_association(): void
    {
        $issued = $this->bootstrap->issue(42, 'ins_cp17a');
        $parsed = $this->bootstrap->parse($issued['raw_key']);
        self::assertNotNull($parsed);
        $result = $this->enrollment->materializeFromBootstrap($parsed, $issued['raw_key']);
        self::assertTrue($result['ok']);
        self::assertInstanceOf(SaClientApiKey::class, $result['key']);
        self::assertSame(42, (int) $result['key']->created_by);
        self::assertSame(1, $this->repository->countForInstallation('ins_cp17a'));

        $validate = $this->keyService->validate($issued['raw_key']);
        self::assertTrue($validate->valid);
    }

    public function test_failed_slot_does_not_create_second_active_key(): void
    {
        $first = $this->bootstrap->issue(42, 'ins_cp17a');
        $this->enrollment->materializeFromBootstrap(
            $this->bootstrap->parse($first['raw_key']) ?? [],
            $first['raw_key']
        );
        $second = $this->bootstrap->issue(42, 'ins_cp17a');
        $result = $this->enrollment->materializeFromBootstrap(
            $this->bootstrap->parse($second['raw_key']) ?? [],
            $second['raw_key']
        );
        self::assertFalse($result['ok']);
        self::assertSame(StaffClientEnrollmentService::ERROR_STAFF_SLOT_TAKEN, $result['error']);
        self::assertSame(1, $this->repository->countForInstallation('ins_cp17a'));
    }

    public function test_repeated_bootstrap_after_connect_does_not_create_another_client(): void
    {
        $first = $this->bootstrap->issue(42, 'ins_cp17a');
        $this->enrollment->materializeFromBootstrap($this->bootstrap->parse($first['raw_key']) ?? [], $first['raw_key']);
        $issue = $this->enrollment->issueBootstrapKey(42);
        self::assertFalse($issue['ok']);
        self::assertSame(StaffClientEnrollmentService::ERROR_ALREADY_CONNECTED, $issue['error']);
        $dup = $this->bootstrap->issue(42, 'ins_cp17a');
        $result = $this->enrollment->materializeFromBootstrap($this->bootstrap->parse($dup['raw_key']) ?? [], $dup['raw_key']);
        self::assertFalse($result['ok']);
        self::assertSame(StaffClientEnrollmentService::ERROR_STAFF_SLOT_TAKEN, $result['error']);
        self::assertSame(1, $this->repository->countForInstallation('ins_cp17a'));
    }

    /**
     * Documents the empty-row FOR UPDATE hole: interleaved check→create without an outer
     * stable-row / named lock can insert two active keys for one Staff.
     */
    public function test_interleaved_check_create_without_slot_lock_can_double_insert(): void
    {
        self::assertNull($this->repository->findActiveByCreator('ins_cp17a', 42));
        self::assertNull($this->repository->findActiveByCreator('ins_cp17a', 42));
        $this->repository->create([
            'installation_id' => 'ins_cp17a',
            'name' => 'T1',
            'key_prefix' => 'rpa_client_aaaa1111',
            'key_hash' => 'hash_t1',
            'encrypted_key' => 'enc:dDE=',
            'created_by' => 42,
        ]);
        $this->repository->create([
            'installation_id' => 'ins_cp17a',
            'name' => 'T2',
            'key_prefix' => 'rpa_client_bbbb2222',
            'key_hash' => 'hash_t2',
            'encrypted_key' => 'enc:dDI=',
            'created_by' => 42,
        ]);
        $active = array_values(array_filter(
            $this->repository->list('ins_cp17a')->all(),
            static fn ($k): bool => (int) $k->created_by === 42 && $k->isActive()
        ));
        self::assertCount(2, $active);
    }

    public function test_slot_lock_strategy_uses_stable_user_row_or_get_lock(): void
    {
        $lockSrc = (string) file_get_contents($this->root.'/src/Domain/StaffClientEnrollmentSlotLock.php');
        $svcSrc = (string) file_get_contents($this->root.'/src/Services/StaffClientEnrollmentService.php');
        self::assertStringContainsString('User::query()->whereKey($staffUserId)->lockForUpdate()', $lockSrc);
        self::assertStringContainsString('GET_LOCK', $lockSrc);
        self::assertStringContainsString('RELEASE_LOCK', $lockSrc);
        self::assertStringContainsString('Empty-row SELECT', $lockSrc);
        self::assertStringContainsString('slotLock->synchronize', $svcSrc);
        self::assertStringContainsString(StaffClientEnrollmentService::ERROR_STAFF_SLOT_TAKEN, $svcSrc);
        $lock = new \Omnichannel\Addons\SocialAutomation\Domain\StaffClientEnrollmentSlotLock;
        self::assertLessThanOrEqual(64, strlen($lock->namedLock('ins_cp17a', 42)));
    }

    public function test_concurrent_style_second_staff_gets_own_key(): void
    {
        $a = $this->bootstrap->issue(10, 'ins_cp17a');
        $b = $this->bootstrap->issue(11, 'ins_cp17a');
        self::assertTrue($this->enrollment->materializeFromBootstrap($this->bootstrap->parse($a['raw_key']) ?? [], $a['raw_key'])['ok']);
        self::assertTrue($this->enrollment->materializeFromBootstrap($this->bootstrap->parse($b['raw_key']) ?? [], $b['raw_key'])['ok']);
        self::assertSame(2, $this->repository->countForInstallation('ins_cp17a'));
        self::assertNotNull($this->repository->findActiveByCreator('ins_cp17a', 10));
        self::assertNotNull($this->repository->findActiveByCreator('ins_cp17a', 11));
    }

    public function test_staff_get_returns_own_machine_only_after_connect(): void
    {
        $issued = $this->bootstrap->issue(42, 'ins_cp17a');
        $this->enrollment->materializeFromBootstrap($this->bootstrap->parse($issued['raw_key']) ?? [], $issued['raw_key']);
        $mine = $this->enrollment->connectionState(42);
        $other = $this->enrollment->connectionState(99);
        self::assertTrue($mine['connected']);
        self::assertSame(42, $mine['connection']['staff_user_id']);
        self::assertFalse($other['connected']);
        self::assertNull($other['connection']);
    }

    public function test_staff_cannot_disconnect_another_staff_client(): void
    {
        $issued = $this->bootstrap->issue(42, 'ins_cp17a');
        $this->enrollment->materializeFromBootstrap($this->bootstrap->parse($issued['raw_key']) ?? [], $issued['raw_key']);
        $other = $this->enrollment->disconnect(99);
        self::assertFalse($other['ok']);
        self::assertSame(StaffClientEnrollmentService::ERROR_NOT_CONNECTED, $other['error']);
        self::assertNotNull($this->repository->findActiveByCreator('ins_cp17a', 42));
    }

    public function test_disconnect_succeeds_without_electron_and_revokes_credential(): void
    {
        $issued = $this->bootstrap->issue(42, 'ins_cp17a');
        $this->enrollment->materializeFromBootstrap($this->bootstrap->parse($issued['raw_key']) ?? [], $issued['raw_key']);
        $result = $this->enrollment->disconnect(42);
        self::assertTrue($result['ok']);
        self::assertNull($this->repository->findActiveByCreator('ins_cp17a', 42));
        $validate = $this->keyService->validate($issued['raw_key']);
        self::assertFalse($validate->valid);
        self::assertSame(RpaClientApiKeyValidationResult::ERROR_KEY_REVOKED, $validate->errorCode);
    }

    public function test_old_client_middleware_rejects_revoked_and_bootstrap_on_heartbeat(): void
    {
        $issued = $this->bootstrap->issue(42, 'ins_cp17a');
        $middleware = new EnsureRpaClientApiKey($this->keyService, $this->enrollment, $this->installationResolver);

        $hb = Request::create('/api/v1/social-automation/client/heartbeat', 'POST');
        $hb->headers->set('Authorization', 'Bearer '.$issued['raw_key']);
        $hbResponse = $middleware->handle($hb, fn () => new JsonResponse(['ok' => true]));
        self::assertSame(401, $hbResponse->getStatusCode());
        self::assertStringContainsString('enrollment_bootstrap_not_authorized', (string) $hbResponse->getContent());

        $sync = Request::create('/api/v1/social-automation/client/browser-sync', 'POST');
        $sync->headers->set('Authorization', 'Bearer '.$issued['raw_key']);
        $syncResponse = $middleware->handle($sync, fn () => new JsonResponse(['ok' => true]));
        self::assertSame(401, $syncResponse->getStatusCode());

        $reg = Request::create('/api/v1/social-automation/client/register', 'POST');
        $reg->headers->set('Authorization', 'Bearer '.$issued['raw_key']);
        $passed = false;
        $regResponse = $middleware->handle($reg, function (Request $req) use (&$passed) {
            $passed = is_array($req->attributes->get(EnsureRpaClientApiKey::REQUEST_ENROLLMENT_BOOTSTRAP));

            return new JsonResponse(['ok' => true]);
        });
        self::assertSame(200, $regResponse->getStatusCode());
        self::assertTrue($passed);

        $this->enrollment->materializeFromBootstrap($this->bootstrap->parse($issued['raw_key']) ?? [], $issued['raw_key']);
        $this->enrollment->disconnect(42);
        $after = Request::create('/api/v1/social-automation/client/heartbeat', 'POST');
        $after->headers->set('Authorization', 'Bearer '.$issued['raw_key']);
        $afterResponse = $middleware->handle($after, fn () => new JsonResponse(['ok' => true]));
        self::assertSame(401, $afterResponse->getStatusCode());
    }

    public function test_staff_can_reconnect_after_disconnect(): void
    {
        $first = $this->bootstrap->issue(42, 'ins_cp17a');
        $this->enrollment->materializeFromBootstrap($this->bootstrap->parse($first['raw_key']) ?? [], $first['raw_key']);
        $this->enrollment->disconnect(42);
        $second = $this->bootstrap->issue(42, 'ins_cp17a');
        $result = $this->enrollment->materializeFromBootstrap($this->bootstrap->parse($second['raw_key']) ?? [], $second['raw_key']);
        self::assertTrue($result['ok']);
        self::assertNotNull($this->repository->findActiveByCreator('ins_cp17a', 42));
        self::assertSame(2, $this->repository->countForInstallation('ins_cp17a'));
    }

    public function test_owner_inventory_shape_excludes_keys_without_agent(): void
    {
        $key = new SaClientApiKey;
        $key->setRawAttributes([
            'id' => 7,
            'installation_id' => 'ins_cp17a',
            'name' => 'Staff Client 42',
            'key_prefix' => 'rpa_client_abcd1234',
            'key_hash' => 'x',
            'encrypted_key' => null,
            'created_by' => 42,
            'last_used_at' => null,
            'revoked_at' => null,
            'created_at' => '2026-10-29 01:00:00',
            'updated_at' => '2026-10-29 01:00:00',
        ], true);
        $row = $this->enrollment->serializeConnection($key, null, includeHistory: true);
        self::assertSame(42, $row['staff_user_id']);
        self::assertSame('rpa_client_abcd1234', $row['key_prefix']);
        self::assertSame('key_active_unregistered', $row['connection_status']);
        // ownerSuccessfulConnections filters these out when agent missing — contract asserted in source.
        $src = (string) file_get_contents($this->root.'/src/Services/StaffClientEnrollmentService.php');
        self::assertStringContainsString('Never registered = not a successful connection', $src);
        self::assertStringContainsString('sa_staff_client_bootstrap_v1', $src);
    }

    public function test_routes_and_openapi_document_staff_enrollment(): void
    {
        $provider = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');
        self::assertStringContainsString("prefix('api/staff/client-connection')", $provider);
        self::assertStringContainsString('bootstrap-key', $provider);
        self::assertStringContainsString('/api/owner/client-connections', $provider);
        self::assertStringContainsString('forceReleaseConnection', (string) file_get_contents($this->root.'/src/Registry/AgentRegistryService.php'));

        $openapi = (string) file_get_contents($this->root.'/openapi/openapi.yaml');
        self::assertStringContainsString('/social-automation/api/staff/client-connection', $openapi);
        self::assertStringContainsString('/social-automation/api/staff/client-connection/bootstrap-key', $openapi);
        self::assertStringContainsString('/social-automation/api/staff/client-connection/disconnect', $openapi);
        self::assertStringContainsString('/social-automation/api/owner/client-connections', $openapi);
        self::assertStringContainsString('sa_staff_client_bootstrap_v1', $openapi);
        self::assertStringContainsString('Staff Client enrollment (CP17A)', $openapi);
    }

    public function test_register_controller_materializes_bootstrap_and_strips_spoofed_staff(): void
    {
        $ctrl = (string) file_get_contents($this->root.'/src/Http/Controllers/RpaClientApiController.php');
        self::assertStringContainsString('materializeFromBootstrap', $ctrl);
        self::assertStringContainsString("unset(\$payload['staff_user_id']", $ctrl);
        self::assertStringContainsString('forceReleaseConnection', $ctrl);
        self::assertStringContainsString('REQUEST_ENROLLMENT_BOOTSTRAP', $ctrl);
    }
}
