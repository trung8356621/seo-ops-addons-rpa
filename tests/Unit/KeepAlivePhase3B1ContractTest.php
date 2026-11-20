<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveProtocol;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveRegistrationPolicy;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ClientKeepAliveController;
use Omnichannel\Addons\SocialAutomation\Http\Middleware\EnsureRpaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Repositories\InMemoryKeepAliveAckStore;
use Omnichannel\Addons\SocialAutomation\Repositories\InMemoryKeepAliveFeedStore;
use Omnichannel\Addons\SocialAutomation\Repositories\InMemoryKeepAliveStore;
use Omnichannel\Addons\SocialAutomation\Services\KeepAliveAckService;
use Omnichannel\Addons\SocialAutomation\Services\KeepAliveCampaignFeedService;
use Omnichannel\Addons\SocialAutomation\Services\KeepAliveNodeService;
use Omnichannel\Addons\SocialAutomation\Services\RpaClientApiKeyService;
use PHPUnit\Framework\TestCase;

/**
 * Keep Alive Phase 3B.1 — registration + explicit assignment contracts.
 * No LIVE DB, no Job claim, no Browser dependency.
 */
final class KeepAlivePhase3B1ContractTest extends TestCase
{
    private string $root;

    private InMemoryKeepAliveStore $store;

    private KeepAliveNodeService $service;

    private ClientKeepAliveController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
        $this->store = new InMemoryKeepAliveStore;
        $this->service = new KeepAliveNodeService($this->store);
        $feedStore = new InMemoryKeepAliveFeedStore($this->store);
        $ackStore = new InMemoryKeepAliveAckStore($feedStore);
        $this->controller = new ClientKeepAliveController(
            $this->service,
            new KeepAliveCampaignFeedService($this->store, $feedStore),
            new KeepAliveAckService($this->store, $feedStore, $ackStore),
        );

        $this->store->seedAgent([
            'id' => 10,
            'agent_uuid' => 'agent-alpha-1',
            'installation_id' => 'tenant-alpha',
            'client_api_key_id' => 1,
        ]);
        $this->store->seedAgent([
            'id' => 20,
            'agent_uuid' => 'agent-beta-1',
            'installation_id' => 'tenant-beta',
            'client_api_key_id' => 2,
        ]);
        $this->store->seedCampaign([
            'id' => 100,
            'campaign_uuid' => 'camp-a-1',
            'installation_id' => 'tenant-alpha',
            'name' => 'Campaign A1',
            'status' => 'running',
            'platform' => 'wordpress',
        ]);
        $this->store->seedCampaign([
            'id' => 101,
            'campaign_uuid' => 'camp-a-2',
            'installation_id' => 'tenant-alpha',
            'name' => 'Campaign A2',
            'status' => 'draft',
            'platform' => 'wordpress',
        ]);
        $this->store->seedCampaign([
            'id' => 200,
            'campaign_uuid' => 'camp-b-1',
            'installation_id' => 'tenant-beta',
            'name' => 'Campaign B1',
            'status' => 'running',
            'platform' => 'facebook',
        ]);
    }

    public function test_migration_and_routes_and_openapi_present(): void
    {
        $migration = (string) file_get_contents($this->root.'/database/migrations/2026_11_20_140000_create_sa_keepalive_tables.php');
        self::assertStringContainsString('sa_keepalive_nodes', $migration);
        self::assertStringContainsString('sa_keepalive_campaign_assignments', $migration);
        self::assertStringContainsString('assignment_generation', $migration);
        self::assertStringContainsString('omi_social_automation', $migration);
        self::assertStringNotContainsString('sa_jobs', $migration);
        self::assertStringNotContainsString('lease_token', $migration);
        self::assertStringNotContainsString('fencing_generation', $migration);

        $provider = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');
        self::assertStringContainsString("/keepalive/register", $provider);
        self::assertStringContainsString("/keepalive/assignments", $provider);
        self::assertStringContainsString('ClientKeepAliveController', $provider);
        self::assertStringContainsString('KeepAliveNodeService', $provider);

        $openapi = (string) file_get_contents($this->root.'/openapi/openapi.yaml');
        self::assertStringContainsString('clientKeepAliveRegister', $openapi);
        self::assertStringContainsString('clientKeepAliveAssignments', $openapi);
        self::assertStringContainsString('keepalive.v1', $openapi);
        self::assertStringContainsString('campaign_access_authorized', $openapi);
        self::assertStringContainsString('assignment_generation', $openapi);

        $schema = (string) file_get_contents($this->root.'/src/Support/SocialAutomationSchema.php');
        self::assertStringContainsString('function keepaliveReady', $schema);

        $registerSrc = (string) file_get_contents($this->root.'/src/Services/KeepAliveNodeService.php');
        self::assertStringNotContainsString('claimNext', $registerSrc);
        self::assertStringNotContainsString('dispatchCampaign', $registerSrc);
        self::assertStringNotContainsString('SaBrowserSession', $registerSrc);
        self::assertStringNotContainsString('syncBrowserSessions', $registerSrc);
        $ctrlSrc = (string) file_get_contents($this->root.'/src/Http/Controllers/ClientKeepAliveController.php');
        self::assertStringNotContainsString('SaBrowserSession', $ctrlSrc);
        self::assertStringNotContainsString('claimNext', $ctrlSrc);
    }

    public function test_authentication_required_for_register_and_assignments(): void
    {
        $reg = $this->controller->register(Request::create('/keepalive/register', 'POST', $this->validPayload()));
        self::assertSame(401, $reg->getStatusCode());
        self::assertSame('unauthorized', $reg->getData(true)['error']);

        $asn = $this->controller->assignments(Request::create('/keepalive/assignments?node_id=n1', 'GET'));
        self::assertSame(401, $asn->getStatusCode());
    }

    public function test_middleware_rejects_missing_bearer_for_keepalive_paths(): void
    {
        $generator = new \Omnichannel\Addons\SocialAutomation\Domain\RpaClientApiKeyGenerator;
        $installation = new class extends \Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver
        {
            public function resolveInstallationId(?object $user = null): string
            {
                return 'tenant-alpha';
            }
        };
        $mw = new EnsureRpaClientApiKey(new RpaClientApiKeyService($generator, $installation));

        $req = Request::create('/api/v1/social-automation/client/keepalive/register', 'POST');
        $resp = $mw->handle($req, fn () => new JsonResponse(['ok' => true]));
        self::assertSame(401, $resp->getStatusCode());
        self::assertSame('unauthorized', $resp->getData(true)['error']);

        $provider = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');
        $clientBlock = $this->extractBetween($provider, "prefix('api/v1/social-automation/client')", '// RPA Admin user auth');
        self::assertStringContainsString('EnsureRpaClientApiKey::class', $clientBlock);
        self::assertStringContainsString('/keepalive/register', $clientBlock);
        self::assertStringContainsString('/keepalive/assignments', $clientBlock);
    }

    private function extractBetween(string $src, string $start, string $end): string
    {
        $i = strpos($src, $start);
        self::assertNotFalse($i);
        $j = strpos($src, $end, $i);
        self::assertNotFalse($j);

        return substr($src, $i, $j - $i);
    }

    public function test_register_success_idempotent_and_no_campaign_access(): void
    {
        $key = $this->key(1, 'tenant-alpha');
        $payload = $this->validPayload(['node_id' => 'node-1', 'tenant_id' => 'tenant-alpha']);

        $first = $this->service->register($key, $payload);
        self::assertTrue($first['ok']);
        self::assertFalse($first['data']['campaign_access_authorized']);
        self::assertSame(KeepAliveProtocol::PROTOCOL_VERSION, $first['data']['protocol_version']);
        self::assertSame(2, $first['data']['schema_version']);
        self::assertSame('tenant-alpha', $first['data']['node']['tenant_id']);
        self::assertSame(0, $first['data']['node']['assignment_generation']);
        self::assertSame(0, $this->store->jobClaimInvocations());

        $second = $this->service->register($key, $payload);
        self::assertTrue($second['ok']);
        self::assertSame('node-1', $second['data']['node']['node_id']);
        self::assertFalse($second['data']['campaign_access_authorized']);

        $list = $this->service->listAssignments($key, 'node-1');
        self::assertTrue($list['ok']);
        self::assertSame([], $list['data']['assignments']);
        self::assertSame(0, $this->store->jobClaimInvocations());
    }

    public function test_cross_tenant_rejection(): void
    {
        $key = $this->key(1, 'tenant-alpha');
        $result = $this->service->register($key, $this->validPayload([
            'tenant_id' => 'tenant-beta',
            'agent_uuid' => 'agent-alpha-1',
        ]));
        self::assertFalse($result['ok']);
        self::assertSame(403, $result['http']);
        self::assertContains(KeepAliveRegistrationPolicy::ERROR_TENANT_MISMATCH, $result['errors']);
        self::assertContains(KeepAliveRegistrationPolicy::ERROR_CROSS_TENANT, $result['errors']);
    }

    public function test_agent_identity_validation(): void
    {
        $key = $this->key(1, 'tenant-alpha');

        $missing = $this->service->register($key, $this->validPayload(['agent_uuid' => 'no-such-agent']));
        self::assertFalse($missing['ok']);
        self::assertSame(404, $missing['http']);
        self::assertContains(KeepAliveRegistrationPolicy::ERROR_AGENT_NOT_FOUND, $missing['errors']);

        $foreign = $this->service->register($key, $this->validPayload([
            'agent_uuid' => 'agent-beta-1',
            'tenant_id' => 'tenant-alpha',
        ]));
        self::assertFalse($foreign['ok']);
        self::assertSame(403, $foreign['http']);
        self::assertContains(KeepAliveRegistrationPolicy::ERROR_AGENT_MISMATCH, $foreign['errors']);
    }

    public function test_never_trusts_client_tenant_over_server(): void
    {
        $key = $this->key(1, 'tenant-alpha');
        $ok = $this->service->register($key, $this->validPayload([
            // omitted tenant_id — server installation_id wins
        ]));
        self::assertTrue($ok['ok']);
        self::assertSame('tenant-alpha', $ok['data']['node']['tenant_id']);
    }

    public function test_explicit_only_campaign_assignment_empty_by_default(): void
    {
        $key = $this->key(1, 'tenant-alpha');
        $this->service->register($key, $this->validPayload(['node_id' => 'node-empty']));
        $list = $this->service->listAssignments($key, 'node-empty');
        self::assertTrue($list['ok']);
        self::assertSame([], $list['data']['assignments']);
        self::assertSame(0, $list['data']['assignment_generation']);
    }

    public function test_multi_campaign_multi_node_and_assignment_generation(): void
    {
        $key = $this->key(1, 'tenant-alpha');
        $this->service->register($key, $this->validPayload(['node_id' => 'node-a']));
        $this->service->register($key, $this->validPayload(['node_id' => 'node-b']));

        $a1 = $this->service->assignCampaignExplicitly($key, 'node-a', 'camp-a-1');
        self::assertTrue($a1['ok']);
        self::assertSame(1, $a1['assignment_generation']);

        $a2 = $this->service->assignCampaignExplicitly($key, 'node-a', 'camp-a-2');
        self::assertTrue($a2['ok']);
        self::assertSame(2, $a2['assignment_generation']);

        // Same campaign can also be assigned to another node
        $b1 = $this->service->assignCampaignExplicitly($key, 'node-b', 'camp-a-1');
        self::assertTrue($b1['ok']);
        self::assertSame(1, $b1['assignment_generation']);

        $listA = $this->service->listAssignments($key, 'node-a');
        self::assertCount(2, $listA['data']['assignments']);
        self::assertSame(2, $listA['data']['assignment_generation']);
        $uuids = array_column($listA['data']['assignments'], 'campaign_uuid');
        self::assertContains('camp-a-1', $uuids);
        self::assertContains('camp-a-2', $uuids);
        foreach ($listA['data']['assignments'] as $row) {
            self::assertArrayHasKey('version', $row);
            self::assertArrayNotHasKey('password', $row);
            self::assertArrayNotHasKey('cookies', $row);
            self::assertArrayNotHasKey('encrypted_password', $row);
            self::assertArrayNotHasKey('credentials', $row);
        }

        $listB = $this->service->listAssignments($key, 'node-b');
        self::assertCount(1, $listB['data']['assignments']);
        self::assertSame('camp-a-1', $listB['data']['assignments'][0]['campaign_uuid']);
    }

    public function test_foreign_tenant_cannot_read_assignments(): void
    {
        $alpha = $this->key(1, 'tenant-alpha');
        $this->service->register($alpha, $this->validPayload(['node_id' => 'node-a']));
        $this->service->assignCampaignExplicitly($alpha, 'node-a', 'camp-a-1');

        $beta = $this->key(2, 'tenant-beta');
        $denied = $this->service->listAssignments($beta, 'node-a', 'tenant-beta');
        self::assertFalse($denied['ok']);
        self::assertSame(404, $denied['http']);
    }

    public function test_controller_register_and_assignments_http_envelope(): void
    {
        $key = $this->key(1, 'tenant-alpha');
        $req = Request::create('/api/v1/social-automation/client/keepalive/register', 'POST', $this->validPayload([
            'node_id' => 'node-http',
            'tenant_id' => 'tenant-alpha',
        ]));
        $req->attributes->set(EnsureRpaClientApiKey::REQUEST_CLIENT_KEY, $key);

        $reg = $this->controller->register($req)->getData(true);
        self::assertTrue($reg['ok']);
        self::assertFalse($reg['campaign_access_authorized']);
        self::assertSame('node-http', $reg['node']['node_id']);

        $this->service->assignCampaignExplicitly($key, 'node-http', 'camp-a-1');

        $asnReq = Request::create('/api/v1/social-automation/client/keepalive/assignments?node_id=node-http', 'GET');
        $asnReq->attributes->set(EnsureRpaClientApiKey::REQUEST_CLIENT_KEY, $key);
        $asn = $this->controller->assignments($asnReq)->getData(true);
        self::assertTrue($asn['ok']);
        self::assertSame(1, $asn['assignment_generation']);
        self::assertCount(1, $asn['assignments']);
        self::assertSame('camp-a-1', $asn['assignments'][0]['campaign_id']);
        self::assertArrayNotHasKey('password', $asn['assignments'][0]);
        self::assertSame(0, $this->store->jobClaimInvocations());
    }

    public function test_protocol_and_capabilities_validation(): void
    {
        $key = $this->key(1, 'tenant-alpha');
        $badProto = $this->service->register($key, $this->validPayload(['protocol_version' => 'keepalive.v0']));
        self::assertFalse($badProto['ok']);
        self::assertContains(KeepAliveRegistrationPolicy::ERROR_PROTOCOL_UNSUPPORTED, $badProto['errors']);

        $badCaps = $this->service->register($key, $this->validPayload(['capabilities' => ['observe']]));
        self::assertFalse($badCaps['ok']);
        self::assertContains(KeepAliveRegistrationPolicy::ERROR_CAPABILITIES_INVALID, $badCaps['errors']);
    }

    public function test_no_browser_profile_required_in_register_contract(): void
    {
        $payload = $this->validPayload();
        self::assertArrayNotHasKey('browser_id', $payload);
        self::assertArrayNotHasKey('browser_ids', $payload);
        self::assertArrayNotHasKey('session_id', $payload);

        $key = $this->key(1, 'tenant-alpha');
        $result = $this->service->register($key, $payload);
        self::assertTrue($result['ok']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'node_id' => 'node-1',
            'agent_uuid' => 'agent-alpha-1',
            'protocol_version' => KeepAliveProtocol::PROTOCOL_VERSION,
            'schema_version' => KeepAliveProtocol::SCHEMA_VERSION,
            'capabilities' => KeepAliveProtocol::REQUIRED_CAPABILITIES,
        ], $overrides);
    }

    private function key(int $id, string $installationId): SaClientApiKey
    {
        $key = new SaClientApiKey;
        $key->id = $id;
        $key->setAttribute('installation_id', $installationId);
        $key->setAttribute('name', 'test-key-'.$id);
        $key->setAttribute('key_prefix', 'sak_');

        return $key;
    }
}
