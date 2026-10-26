<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Domain\RpaClientApiKeyGenerator;
use Omnichannel\Addons\SocialAutomation\Domain\RpaClientApiKeyValidationResult;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\RpaClientApiController;
use Omnichannel\Addons\SocialAutomation\Http\Middleware\EnsureRpaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Repositories\InMemoryRpaClientApiKeyRepository;
use Omnichannel\Addons\SocialAutomation\Services\RpaClientApiKeyService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1: Client API Key Foundation tests.
 * Covers requirements A through J strictly.
 */
final class RpaClientApiKeyTest extends TestCase
{
    private RpaClientApiKeyGenerator $generator;
    private SocialAutomationInstallationResolver $installationResolver;
    private InMemoryRpaClientApiKeyRepository $repository;
    private RpaClientApiKeyService $keyService;
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = dirname(__DIR__, 2);
        $this->generator = new RpaClientApiKeyGenerator();
        $this->installationResolver = new SocialAutomationInstallationResolver();
        $this->installationResolver->setForcedInstallationId('ins_test_alpha');
        $this->repository = new InMemoryRpaClientApiKeyRepository();
        $this->keyService = new RpaClientApiKeyService(
            $this->generator,
            $this->installationResolver,
            $this->repository
        );
    }

    /**
     * Requirement A: Authorized Staff tạo Key thành công.
     */
    public function test_a_authorized_staff_creates_key_successfully(): void
    {
        $created = $this->keyService->create('Office Runner 01', 42);

        self::assertInstanceOf(SaClientApiKey::class, $created['key']);
        self::assertSame('Office Runner 01', $created['key']->name);
        self::assertSame('ins_test_alpha', $created['key']->installation_id);
        self::assertSame(42, $created['key']->created_by);
        self::assertNotNull($created['raw_key']);
        self::assertStringStartsWith('rpa_client_', $created['raw_key']);
        self::assertTrue($created['key']->isActive());
        self::assertFalse($created['key']->isRevoked());
    }

    /**
     * Requirement B: Raw Key chỉ trả về khi tạo.
     * Database chỉ lưu hash/prefix, model hidden không lộ hash, list không có raw key.
     */
    public function test_b_raw_key_only_returned_at_creation_time(): void
    {
        $created = $this->keyService->create('Secret Machine', 10);
        $rawKey = $created['raw_key'];
        $model = $created['key'];

        // Raw key is returned only in memory array
        self::assertNotEmpty($rawKey);

        // Stored entity only holds hash and prefix — NOT the raw key
        self::assertNotSame($rawKey, $model->key_hash);
        self::assertSame($this->generator->hash($rawKey), $model->key_hash);
        self::assertSame($this->generator->extractPrefix($rawKey), $model->key_prefix);

        // Serialization check: model attributes hidden from output
        $attributes = $model->getAttributes();
        self::assertArrayNotHasKey('raw_key', $attributes);
        self::assertContains('key_hash', $model->getHidden());
    }

    /**
     * Requirement C: Key hợp lệ xác thực thành công.
     */
    public function test_c_valid_key_authenticates_successfully_and_touches_last_used(): void
    {
        $created = $this->keyService->create('Valid Worker', 1);
        $rawKey = $created['raw_key'];

        $result = $this->keyService->validate($rawKey);

        self::assertTrue($result->valid);
        self::assertNull($result->errorCode);
        self::assertInstanceOf(SaClientApiKey::class, $result->key);
        self::assertSame($created['key']->id, $result->key->id);
        self::assertNotNull($result->key->last_used_at);

        // Test via EnsureRpaClientApiKey Middleware
        $middleware = new EnsureRpaClientApiKey($this->keyService);
        $request = Request::create('/api/v1/social-automation/client/validate', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $rawKey);

        $nextCalled = false;
        $response = $middleware->handle($request, function ($req) use (&$nextCalled) {
            $nextCalled = true;
            return new JsonResponse(['ok' => true]);
        });

        self::assertTrue($nextCalled);
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Requirement D: Key không hợp lệ bị từ chối.
     */
    public function test_d_invalid_or_malformed_key_is_rejected(): void
    {
        // 1. Completely random string
        $res1 = $this->keyService->validate('random-invalid-string');
        self::assertFalse($res1->valid);
        self::assertSame(RpaClientApiKeyValidationResult::ERROR_INVALID_KEY, $res1->errorCode);

        // 2. Correct prefix format but wrong secret
        $res2 = $this->keyService->validate('rpa_client_12345678_wrongsecretpartthatdoesntexist');
        self::assertFalse($res2->valid);
        self::assertSame(RpaClientApiKeyValidationResult::ERROR_INVALID_KEY, $res2->errorCode);

        // 3. Empty key
        $res3 = $this->keyService->validate('');
        self::assertFalse($res3->valid);
        self::assertSame(RpaClientApiKeyValidationResult::ERROR_MISSING_KEY, $res3->errorCode);

        // 4. Middleware rejection with 401
        $middleware = new EnsureRpaClientApiKey($this->keyService);
        $req = Request::create('/api/v1/social-automation/client/validate', 'GET');
        $req->headers->set('Authorization', 'Bearer rpa_client_00000000_fake');
        $resp = $middleware->handle($req, fn () => new JsonResponse(['ok' => true]));

        self::assertSame(401, $resp->getStatusCode());
    }

    /**
     * Requirement E: Key revoked bị từ chối.
     */
    public function test_e_revoked_key_is_rejected(): void
    {
        $created = $this->keyService->create('Machine To Revoke', 1);
        $rawKey = $created['raw_key'];
        $keyId = $created['key']->id;

        // Revoke the key
        $revoked = $this->keyService->revoke($keyId);
        self::assertTrue($revoked);

        // Validation must reject
        $result = $this->keyService->validate($rawKey);
        self::assertFalse($result->valid);
        self::assertSame(RpaClientApiKeyValidationResult::ERROR_KEY_REVOKED, $result->errorCode);

        // Middleware must return 401 key_revoked
        $middleware = new EnsureRpaClientApiKey($this->keyService);
        $req = Request::create('/api/v1/social-automation/client/validate', 'GET');
        $req->headers->set('Authorization', 'Bearer ' . $rawKey);
        $resp = $middleware->handle($req, fn () => new JsonResponse(['ok' => true]));

        self::assertSame(401, $resp->getStatusCode());
        $payload = json_decode((string) $resp->getContent(), true);
        self::assertSame('key_revoked', $payload['error']);
    }

    /**
     * Requirement F: Một Key không bị giới hạn vào một Machine.
     * Multiple machines / requests can authenticate with the same key.
     */
    public function test_f_single_key_can_be_used_by_multiple_machines(): void
    {
        $created = $this->keyService->create('Shared Pool Key', 1);
        $rawKey = $created['raw_key'];

        $middleware = new EnsureRpaClientApiKey($this->keyService);

        // Machine 1
        $req1 = Request::create('/api/v1/social-automation/client/validate', 'GET');
        $req1->headers->set('Authorization', 'Bearer ' . $rawKey);
        $req1->headers->set('X-Device-Id', 'device-mac-mini-01');
        $resp1 = $middleware->handle($req1, fn () => new JsonResponse(['ok' => true, 'machine' => 'm1']));
        self::assertSame(200, $resp1->getStatusCode());

        // Machine 2
        $req2 = Request::create('/api/v1/social-automation/client/validate', 'GET');
        $req2->headers->set('Authorization', 'Bearer ' . $rawKey);
        $req2->headers->set('X-Device-Id', 'device-windows-worker-02');
        $resp2 = $middleware->handle($req2, fn () => new JsonResponse(['ok' => true, 'machine' => 'm2']));
        self::assertSame(200, $resp2->getStatusCode());

        // Machine 3
        $req3 = Request::create('/api/v1/social-automation/client/validate', 'GET');
        $req3->headers->set('Authorization', 'Bearer ' . $rawKey);
        $req3->headers->set('X-Device-Id', 'device-linux-docker-03');
        $resp3 = $middleware->handle($req3, fn () => new JsonResponse(['ok' => true, 'machine' => 'm3']));
        self::assertSame(200, $resp3->getStatusCode());
    }

    /**
     * Requirement G: Nhiều Key cùng Installation hoạt động độc lập.
     */
    public function test_g_multiple_keys_in_same_installation_work_independently(): void
    {
        $keyA = $this->keyService->create('Key A', 1);
        $keyB = $this->keyService->create('Key B', 1);

        // Both keys validate
        self::assertTrue($this->keyService->validate($keyA['raw_key'])->valid);
        self::assertTrue($this->keyService->validate($keyB['raw_key'])->valid);

        // Revoke Key A
        $this->keyService->revoke($keyA['key']->id);

        // Key A must now be rejected
        self::assertFalse($this->keyService->validate($keyA['raw_key'])->valid);
        self::assertSame(RpaClientApiKeyValidationResult::ERROR_KEY_REVOKED, $this->keyService->validate($keyA['raw_key'])->errorCode);

        // Key B MUST remain valid and active
        $resB = $this->keyService->validate($keyB['raw_key']);
        self::assertTrue($resB->valid);
        self::assertSame($keyB['key']->id, $resB->key->id);
    }

    /**
     * Requirement H: Không chấp nhận truy cập chéo Installation.
     * Client cannot claim or spoof an installation identity.
     */
    public function test_h_cross_installation_access_is_rejected(): void
    {
        // Create key on Installation Alpha
        $this->installationResolver->setForcedInstallationId('ins_installation_alpha');
        $createdOnAlpha = $this->keyService->create('Alpha Machine', 1);

        // Server switches or operates in context of Installation Beta
        $this->installationResolver->setForcedInstallationId('ins_installation_beta');

        // Presenting Alpha key to Beta server must be rejected with installation_mismatch
        $result = $this->keyService->validate($createdOnAlpha['raw_key']);
        self::assertFalse($result->valid);
        self::assertSame(RpaClientApiKeyValidationResult::ERROR_INSTALLATION_MISMATCH, $result->errorCode);

        // Middleware returns 403 Forbidden
        $middleware = new EnsureRpaClientApiKey($this->keyService);
        $req = Request::create('/api/v1/social-automation/client/validate', 'GET');
        $req->headers->set('Authorization', 'Bearer ' . $createdOnAlpha['raw_key']);
        $resp = $middleware->handle($req, fn () => new JsonResponse(['ok' => true]));

        self::assertSame(403, $resp->getStatusCode());
        $payload = json_decode((string) $resp->getContent(), true);
        self::assertSame('installation_mismatch', $payload['error']);
    }

    /**
     * Requirement I: Không lộ raw Key qua list/detail APIs.
     */
    public function test_i_raw_key_and_hash_never_leaked_in_list_or_detail(): void
    {
        $created = $this->keyService->create('Audited Key', 1);
        $rawKey = $created['raw_key'];

        $list = $this->keyService->list();
        $found = $list->firstWhere('id', $created['key']->id);

        self::assertNotNull($found);
        self::assertSame($created['key']->key_prefix, $found->key_prefix);

        $json = json_encode($found->toArray());
        self::assertStringNotContainsString($rawKey, (string) $json);
        self::assertStringNotContainsString($created['key']->key_hash, (string) $json);

        // Check Controller validate endpoint output
        $controller = new RpaClientApiController();
        $req = Request::create('/api/v1/social-automation/client/validate', 'GET');
        $req->attributes->set(EnsureRpaClientApiKey::REQUEST_CLIENT_KEY, $created['key']);
        $response = $controller->validateKey($req);

        $data = json_decode((string) $response->getContent(), true);
        self::assertTrue($data['ok']);
        self::assertSame($created['key']->id, $data['client_api_key']['id']);
        self::assertSame($created['key']->key_prefix, $data['client_api_key']['key_prefix']);
        self::assertArrayNotHasKey('raw_key', $data['client_api_key']);
        self::assertArrayNotHasKey('key_hash', $data['client_api_key']);
    }

    /**
     * Requirement J: Không phá Agent API/auth hiện có.
     * Existing migration, runner routes and contracts remain intact.
     */
    public function test_j_existing_agent_api_and_tables_remain_compatible(): void
    {
        // 1. Migration for sa_client_api_keys exists on omi_social_automation
        $migrationContent = (string) file_get_contents(
            $this->root . '/database/migrations/2026_10_26_110000_create_sa_client_api_keys_table.php'
        );
        self::assertStringContainsString('SocialAutomationServiceConfig::CONNECTION', $migrationContent);
        self::assertStringContainsString('sa_client_api_keys', $migrationContent);
        self::assertStringContainsString('installation_id', $migrationContent);
        self::assertStringContainsString('key_prefix', $migrationContent);
        self::assertStringContainsString('key_hash', $migrationContent);
        self::assertStringContainsString('client_api_key_id', $migrationContent);

        // 2. Existing Runner routes remain intact in routes/api-services.php
        $routesContent = (string) file_get_contents($this->root . '/routes/api-services.php');
        self::assertStringContainsString('social-automation:runner:agent', $routesContent);
        self::assertStringContainsString('runner/register', $routesContent);
        self::assertStringContainsString('runner/agents/{agentUuid}/heartbeat', $routesContent);
        self::assertStringContainsString('runner/agents/{agentUuid}/sessions/sync', $routesContent);
        self::assertStringContainsString('runner/agents/{agentUuid}/jobs/{jobUuid}/claim', $routesContent);
        self::assertStringContainsString('client/validate', $routesContent);
    }
}
