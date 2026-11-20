<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveAccessPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveProtocol;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveRegistrationPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveSocketTicket;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ClientKeepAliveController;
use Omnichannel\Addons\SocialAutomation\Http\Middleware\EnsureRpaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Repositories\InMemoryKeepAliveAckStore;
use Omnichannel\Addons\SocialAutomation\Repositories\InMemoryKeepAliveFeedStore;
use Omnichannel\Addons\SocialAutomation\Repositories\InMemoryKeepAliveRouteStore;
use Omnichannel\Addons\SocialAutomation\Repositories\InMemoryKeepAliveStore;
use Omnichannel\Addons\SocialAutomation\Services\KeepAliveAckService;
use Omnichannel\Addons\SocialAutomation\Services\KeepAliveCampaignFeedService;
use Omnichannel\Addons\SocialAutomation\Services\KeepAliveNodeService;
use Omnichannel\Addons\SocialAutomation\Services\KeepAliveSocketTicketService;
use PHPUnit\Framework\TestCase;

final class KeepAlivePhase3B6BSocketTicketContractTest extends TestCase
{
    private string $root;

    private InMemoryKeepAliveStore $nodes;

    private InMemoryKeepAliveRouteStore $routes;

    private KeepAliveNodeService $nodeService;

    private KeepAliveSocketTicketService $tickets;

    private ClientKeepAliveController $controller;

    private SaClientApiKey $key;

    private SaClientApiKey $otherKey;

    private string $campaignUuid = '11111111-1111-1111-1111-111111111111';

    private string $campaignB = '33333333-3333-3333-3333-333333333333';

    private int $genA = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
        $this->nodes = new InMemoryKeepAliveStore;
        $feedStore = new InMemoryKeepAliveFeedStore($this->nodes);
        $this->routes = new InMemoryKeepAliveRouteStore($this->nodes, $feedStore);
        $this->nodeService = new KeepAliveNodeService($this->nodes, new KeepAliveRegistrationPolicy, $this->routes);
        $this->tickets = new KeepAliveSocketTicketService(
            $this->nodes,
            $this->routes,
            new KeepAliveSocketTicket('test-ka-sock-secret'),
        );
        $ackStore = new InMemoryKeepAliveAckStore($feedStore);
        $this->controller = new ClientKeepAliveController(
            $this->nodeService,
            new KeepAliveCampaignFeedService($this->nodes, $feedStore),
            new KeepAliveAckService($this->nodes, $feedStore, $ackStore),
            $this->tickets,
        );

        $this->nodes->seedAgent([
            'id' => 10,
            'agent_uuid' => 'agent-alpha-1',
            'installation_id' => 'tenant-alpha',
            'client_api_key_id' => 1,
        ]);
        $this->nodes->seedCampaign([
            'id' => 100,
            'campaign_uuid' => $this->campaignUuid,
            'installation_id' => 'tenant-alpha',
            'name' => 'Campaign A',
            'status' => 'running',
            'platform' => 'wordpress',
        ]);
        $this->nodes->seedCampaign([
            'id' => 101,
            'campaign_uuid' => $this->campaignB,
            'installation_id' => 'tenant-alpha',
            'name' => 'Campaign B',
            'status' => 'running',
            'platform' => 'facebook',
        ]);
        $this->nodes->seedCampaign([
            'id' => 200,
            'campaign_uuid' => '22222222-2222-2222-2222-222222222222',
            'installation_id' => 'tenant-beta',
            'name' => 'Foreign',
            'status' => 'running',
            'platform' => 'facebook',
        ]);

        $this->key = $this->makeKey(1, 'tenant-alpha');
        $this->otherKey = $this->makeKey(2, 'tenant-beta');
        $this->registerNode('node-a');
        $this->registerNode('node-b');
        $asn = $this->nodeService->assignCampaignExplicitly($this->key, 'node-a', $this->campaignUuid);
        $this->genA = (int) $asn['assignment_generation'];
        $this->nodeService->assignCampaignExplicitly($this->key, 'node-b', $this->campaignUuid);
        $this->nodeService->assignCampaignExplicitly($this->key, 'node-a', $this->campaignB);
        $this->genA = (int) ($this->nodes->findNode('tenant-alpha', 'node-a')['assignment_generation'] ?? $this->genA);
    }

    public function test_migration_routes_openapi_and_no_job_side_effects(): void
    {
        $mig = (string) file_get_contents($this->root.'/database/migrations/2026_11_20_170000_create_sa_keepalive_socket_route_tables.php');
        self::assertStringContainsString('sa_keepalive_route_states', $mig);
        self::assertStringContainsString('sa_keepalive_socket_ticket_jtis', $mig);
        self::assertStringNotContainsString('sa_jobs', $mig);

        $provider = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');
        self::assertStringContainsString('/keepalive/socket/ticket', $provider);
        self::assertStringContainsString('keepalive-gateway', $provider);
        self::assertStringContainsString('/socket/verify', $provider);

        $svc = (string) file_get_contents($this->root.'/src/Services/KeepAliveSocketTicketService.php');
        self::assertStringNotContainsString('claimNext', $svc);
        self::assertStringNotContainsString('dispatchCampaign', $svc);
        self::assertStringContainsString('never Client API Key', $svc);

        $openapi = (string) file_get_contents($this->root.'/openapi/openapi.yaml');
        self::assertStringContainsString('clientKeepAliveSocketTicket', $openapi);
        self::assertStringContainsString('keepaliveGatewaySocketVerify', $openapi);

        self::assertSame(0, $this->routes->jobClaimInvocations());
    }

    public function test_mint_requires_client_api_key(): void
    {
        $resp = $this->controller->socketTicket(Request::create('/x', 'POST', [
            'role' => 'recovery',
            'node_id' => 'node-a',
            'assignment_generation' => $this->genA,
        ]));
        self::assertSame(401, $resp->getStatusCode());
    }

    public function test_recovery_ticket_binds_rooms_and_generations(): void
    {
        $minted = $this->tickets->mint($this->key, [
            'role' => KeepAliveSocketTicket::ROLE_RECOVERY,
            'node_id' => 'node-a',
            'assignment_generation' => $this->genA,
            'campaign_ids' => [$this->campaignUuid, $this->campaignB],
        ]);
        self::assertTrue($minted['ok']);
        $data = $minted['data'];
        self::assertSame(KeepAliveProtocol::PROTOCOL_VERSION, $data['protocol_version']);
        self::assertSame('recovery', $data['role']);
        self::assertSame($this->genA, $data['assignment_generation']);
        self::assertGreaterThanOrEqual(1, $data['route_generation']);
        self::assertContains(
            KeepAliveSocketTicket::roomName('tenant-alpha', $this->campaignUuid, 'node-a'),
            $data['rooms'],
        );
        self::assertContains(
            KeepAliveSocketTicket::roomName('tenant-alpha', $this->campaignB, 'node-a'),
            $data['rooms'],
        );
        self::assertStringStartsWith('ka_sock_', $data['ticket']);
        self::assertLessThanOrEqual(120, $data['ttl_seconds']);
        self::assertArrayNotHasKey('client_api_key', $data);
        self::assertStringNotContainsString('sak_', $data['ticket']);
    }

    public function test_executor_ticket_preserves_multi_node_rooms(): void
    {
        $minted = $this->tickets->mint($this->key, [
            'role' => KeepAliveSocketTicket::ROLE_EXECUTOR,
            'campaign_ids' => [$this->campaignUuid],
        ]);
        self::assertTrue($minted['ok'], json_encode($minted));
        $rooms = $minted['data']['rooms'];
        self::assertContains(
            KeepAliveSocketTicket::roomName('tenant-alpha', $this->campaignUuid, 'node-a'),
            $rooms,
        );
        self::assertContains(
            KeepAliveSocketTicket::roomName('tenant-alpha', $this->campaignUuid, 'node-b'),
            $rooms,
        );
        self::assertNull($minted['data']['node_id']);
    }

    public function test_cross_tenant_and_unauthorized_campaign_rejected(): void
    {
        $foreign = $this->tickets->mint($this->key, [
            'role' => KeepAliveSocketTicket::ROLE_RECOVERY,
            'node_id' => 'node-a',
            'assignment_generation' => $this->genA,
            'campaign_ids' => ['22222222-2222-2222-2222-222222222222'],
            'tenant_id' => 'tenant-beta',
        ]);
        self::assertFalse($foreign['ok']);
        self::assertContains(KeepAliveRegistrationPolicy::ERROR_TENANT_MISMATCH, $foreign['errors']);

        $unauth = $this->tickets->mint($this->key, [
            'role' => KeepAliveSocketTicket::ROLE_RECOVERY,
            'node_id' => 'node-a',
            'assignment_generation' => $this->genA,
            'campaign_ids' => ['22222222-2222-2222-2222-222222222222'],
        ]);
        self::assertFalse($unauth['ok']);
        self::assertContains(KeepAliveSocketTicket::ERROR_CAMPAIGN_UNAUTHORIZED, $unauth['errors']);

        $stale = $this->tickets->mint($this->key, [
            'role' => KeepAliveSocketTicket::ROLE_RECOVERY,
            'node_id' => 'node-a',
            'assignment_generation' => $this->genA - 1,
            'campaign_ids' => [$this->campaignUuid],
        ]);
        self::assertFalse($stale['ok']);
        self::assertContains(KeepAliveAccessPolicy::ERROR_ASSIGNMENT_GENERATION_STALE, $stale['errors']);
    }

    public function test_privilege_escalation_role_and_other_node_key_rejected(): void
    {
        $badRole = $this->tickets->mint($this->key, [
            'role' => 'admin',
            'node_id' => 'node-a',
            'assignment_generation' => $this->genA,
        ]);
        self::assertFalse($badRole['ok']);
        self::assertContains(KeepAliveSocketTicket::ERROR_ROLE_INVALID, $badRole['errors']);

        // Other tenant key cannot mint for alpha node.
        $cross = $this->tickets->mint($this->otherKey, [
            'role' => KeepAliveSocketTicket::ROLE_RECOVERY,
            'node_id' => 'node-a',
            'assignment_generation' => $this->genA,
        ]);
        self::assertFalse($cross['ok']);
    }

    public function test_gateway_verify_without_api_key_and_replay_blocked(): void
    {
        $minted = $this->tickets->mint($this->key, [
            'role' => KeepAliveSocketTicket::ROLE_RECOVERY,
            'node_id' => 'node-a',
            'assignment_generation' => $this->genA,
            'campaign_ids' => [$this->campaignUuid],
        ]);
        self::assertTrue($minted['ok']);
        $ticket = (string) $minted['data']['ticket'];
        $gw = $this->tickets->expectedGatewaySecret();

        $badSecret = $this->tickets->verifyForGateway('wrong-secret', ['ticket' => $ticket]);
        self::assertFalse($badSecret['ok']);
        self::assertContains(KeepAliveSocketTicket::ERROR_GATEWAY_UNAUTHORIZED, $badSecret['errors']);

        $ok = $this->tickets->verifyForGateway($gw, ['ticket' => $ticket, 'consume' => true]);
        self::assertTrue($ok['ok'], json_encode($ok));
        self::assertSame($minted['data']['rooms'], $ok['data']['authorized_rooms']);
        self::assertArrayNotHasKey('api_key', $ok['data'] ?? []);

        $replay = $this->tickets->verifyForGateway($gw, ['ticket' => $ticket, 'consume' => true]);
        self::assertFalse($replay['ok']);
        self::assertContains(KeepAliveSocketTicket::ERROR_REPLAY, $replay['errors']);
    }

    public function test_route_generation_stale_after_assign_revoke(): void
    {
        $minted = $this->tickets->mint($this->key, [
            'role' => KeepAliveSocketTicket::ROLE_RECOVERY,
            'node_id' => 'node-a',
            'assignment_generation' => $this->genA,
            'campaign_ids' => [$this->campaignUuid],
        ]);
        self::assertTrue($minted['ok']);
        $ticket = (string) $minted['data']['ticket'];
        $rgen = (int) $minted['data']['route_generation'];

        $revoked = $this->nodeService->revokeCampaignExplicitly($this->key, 'node-a', $this->campaignB);
        self::assertTrue($revoked['ok']);
        self::assertGreaterThan($rgen, (int) $revoked['route_generation']);

        // assignment_generation also bumped — re-read node
        $freshGen = (int) $this->nodes->findNode('tenant-alpha', 'node-a')['assignment_generation'];
        self::assertNotSame($this->genA, $freshGen);

        $gw = $this->tickets->expectedGatewaySecret();
        $stale = $this->tickets->verifyForGateway($gw, ['ticket' => $ticket, 'consume' => true]);
        self::assertFalse($stale['ok']);
        self::assertTrue(
            in_array(KeepAliveSocketTicket::ERROR_ROUTE_GENERATION_STALE, $stale['errors'], true)
            || in_array(KeepAliveAccessPolicy::ERROR_ASSIGNMENT_GENERATION_STALE, $stale['errors'], true)
        );

        $renew = $this->tickets->mint($this->key, [
            'role' => KeepAliveSocketTicket::ROLE_RECOVERY,
            'node_id' => 'node-a',
            'assignment_generation' => $freshGen,
            'campaign_ids' => [$this->campaignUuid],
        ]);
        self::assertTrue($renew['ok'], json_encode($renew));
        self::assertGreaterThan($rgen, $renew['data']['route_generation']);
    }

    public function test_expired_and_tampered_ticket_rejected(): void
    {
        $domain = new KeepAliveSocketTicket('test-ka-sock-secret');
        $issued = $domain->issue([
            'tenant_id' => 'tenant-alpha',
            'role' => 'recovery',
            'node_id' => 'node-a',
            'campaign_ids' => [$this->campaignUuid],
            'rooms' => [KeepAliveSocketTicket::roomName('tenant-alpha', $this->campaignUuid, 'node-a')],
            'assignment_generation' => $this->genA,
            'route_generation' => 1,
            'client_api_key_id' => 1,
        ], 1);
        $expired = $domain->verify($issued['ticket'], time() + 5);
        self::assertFalse($expired['ok']);
        self::assertSame(KeepAliveSocketTicket::ERROR_EXPIRED, $expired['error']);

        $tampered = substr($issued['ticket'], 0, -4).'abcd';
        $bad = $domain->verify($tampered);
        self::assertFalse($bad['ok']);
    }

    public function test_controller_http_envelopes(): void
    {
        $req = Request::create('/api/v1/social-automation/client/keepalive/socket/ticket', 'POST', [
            'role' => 'recovery',
            'node_id' => 'node-a',
            'assignment_generation' => $this->genA,
            'campaign_ids' => [$this->campaignUuid],
        ]);
        $req->attributes->set(EnsureRpaClientApiKey::REQUEST_CLIENT_KEY, $this->key);
        $body = $this->controller->socketTicket($req)->getData(true);
        self::assertTrue($body['ok']);
        self::assertArrayHasKey('ticket', $body);
        self::assertArrayHasKey('gateway_url', $body);
        self::assertArrayHasKey('renewal', $body);

        $verifyReq = Request::create('/api/v1/social-automation/keepalive-gateway/socket/verify', 'POST', [
            'ticket' => $body['ticket'],
            'consume' => true,
        ]);
        $verifyReq->headers->set('X-KeepAlive-Gateway-Secret', $this->tickets->expectedGatewaySecret());
        $verified = $this->controller->socketVerify($verifyReq)->getData(true);
        self::assertTrue($verified['ok']);
        self::assertSame($body['rooms'], $verified['authorized_rooms']);
    }

    private function registerNode(string $nodeId): void
    {
        $this->nodeService->register($this->key, [
            'node_id' => $nodeId,
            'agent_uuid' => 'agent-alpha-1',
            'protocol_version' => KeepAliveProtocol::PROTOCOL_VERSION,
            'schema_version' => KeepAliveProtocol::SCHEMA_VERSION,
            'capabilities' => KeepAliveProtocol::REQUIRED_CAPABILITIES,
        ]);
    }

    private function makeKey(int $id, string $installationId): SaClientApiKey
    {
        $key = new SaClientApiKey;
        $key->id = $id;
        $key->setAttribute('installation_id', $installationId);

        return $key;
    }
}
