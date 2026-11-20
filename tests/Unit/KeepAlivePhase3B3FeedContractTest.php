<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveEventTypes;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveProtocol;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ClientKeepAliveController;
use Omnichannel\Addons\SocialAutomation\Http\Middleware\EnsureRpaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Repositories\InMemoryKeepAliveAckStore;
use Omnichannel\Addons\SocialAutomation\Repositories\InMemoryKeepAliveFeedStore;
use Omnichannel\Addons\SocialAutomation\Repositories\InMemoryKeepAliveStore;
use Omnichannel\Addons\SocialAutomation\Services\KeepAliveAckService;
use Omnichannel\Addons\SocialAutomation\Services\KeepAliveCampaignFeedService;
use Omnichannel\Addons\SocialAutomation\Services\KeepAliveNodeService;
use PHPUnit\Framework\TestCase;

final class KeepAlivePhase3B3FeedContractTest extends TestCase
{
    private string $root;

    private InMemoryKeepAliveStore $nodes;

    private InMemoryKeepAliveFeedStore $feedStore;

    private KeepAliveNodeService $nodeService;

    private KeepAliveCampaignFeedService $feed;

    private ClientKeepAliveController $controller;

    private SaClientApiKey $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
        $this->nodes = new InMemoryKeepAliveStore;
        $this->feedStore = new InMemoryKeepAliveFeedStore($this->nodes);
        $this->nodeService = new KeepAliveNodeService($this->nodes);
        $this->feed = new KeepAliveCampaignFeedService($this->nodes, $this->feedStore);
        $ackStore = new InMemoryKeepAliveAckStore($this->feedStore);
        $this->controller = new ClientKeepAliveController(
            $this->nodeService,
            $this->feed,
            new KeepAliveAckService($this->nodes, $this->feedStore, $ackStore),
        );

        $this->nodes->seedAgent([
            'id' => 10,
            'agent_uuid' => 'agent-alpha-1',
            'installation_id' => 'tenant-alpha',
            'client_api_key_id' => 1,
        ]);
        $this->nodes->seedAgent([
            'id' => 20,
            'agent_uuid' => 'agent-beta-1',
            'installation_id' => 'tenant-beta',
            'client_api_key_id' => 2,
        ]);
        $this->nodes->seedCampaign([
            'id' => 100,
            'campaign_uuid' => '11111111-1111-1111-1111-111111111111',
            'installation_id' => 'tenant-alpha',
            'name' => 'Campaign A',
            'status' => 'running',
            'platform' => 'wordpress',
            'execution_mode' => 'one_account_one_job',
            'run_mode' => 'live',
            'script_id' => 'wp.draft',
            'script_version' => '1',
        ]);
        $this->nodes->seedCampaign([
            'id' => 200,
            'campaign_uuid' => '22222222-2222-2222-2222-222222222222',
            'installation_id' => 'tenant-beta',
            'name' => 'Campaign B',
            'status' => 'running',
            'platform' => 'facebook',
        ]);

        $this->key = $this->key(1, 'tenant-alpha');
        $this->nodeService->register($this->key, [
            'node_id' => 'node-a',
            'agent_uuid' => 'agent-alpha-1',
            'protocol_version' => KeepAliveProtocol::PROTOCOL_VERSION,
            'schema_version' => KeepAliveProtocol::SCHEMA_VERSION,
            'capabilities' => KeepAliveProtocol::REQUIRED_CAPABILITIES,
        ]);
        $this->nodeService->register($this->key, [
            'node_id' => 'node-b',
            'agent_uuid' => 'agent-alpha-1',
            'protocol_version' => KeepAliveProtocol::PROTOCOL_VERSION,
            'schema_version' => KeepAliveProtocol::SCHEMA_VERSION,
            'capabilities' => KeepAliveProtocol::REQUIRED_CAPABILITIES,
        ]);
    }

    public function test_migration_routes_openapi_and_no_orchestrator_coupling(): void
    {
        $mig = (string) file_get_contents($this->root.'/database/migrations/2026_11_20_150000_create_sa_keepalive_feed_tables.php');
        self::assertStringContainsString('sa_keepalive_campaign_events', $mig);
        self::assertStringContainsString('sa_keepalive_campaign_sequences', $mig);
        self::assertStringContainsString('event_sequence', $mig);
        self::assertStringContainsString('revoked_at', $mig);
        self::assertStringNotContainsString('create(\'sa_jobs\'', $mig);
        self::assertStringNotContainsString('lease_token', $mig);

        $provider = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');
        self::assertStringContainsString('/keepalive/campaigns/{id}/snapshot', $provider);
        self::assertStringContainsString('/keepalive/campaigns/{id}/events', $provider);

        $openapi = (string) file_get_contents($this->root.'/openapi/openapi.yaml');
        self::assertStringContainsString('clientKeepAliveCampaignSnapshot', $openapi);
        self::assertStringContainsString('clientKeepAliveCampaignEvents', $openapi);
        self::assertStringContainsString('row_version', $openapi);

        $orch = (string) file_get_contents($this->root.'/src/Orchestration/CampaignOrchestrator.php');
        self::assertStringNotContainsString('KeepAliveCampaignFeed', $orch);
        self::assertStringNotContainsString('sa_keepalive_campaign_events', $orch);

        $feedSrc = (string) file_get_contents($this->root.'/src/Services/KeepAliveCampaignFeedService.php');
        self::assertStringNotContainsString('claimNext', $feedSrc);
        self::assertStringNotContainsString('dispatchCampaign', $feedSrc);
        self::assertStringNotContainsString('SaBrowserSession', $feedSrc);
    }

    public function test_assignments_expose_row_version_alias(): void
    {
        $asn = $this->nodeService->assignCampaignExplicitly($this->key, 'node-a', '11111111-1111-1111-1111-111111111111');
        self::assertTrue($asn['ok']);
        $list = $this->nodeService->listAssignments($this->key, 'node-a');
        self::assertSame($list['data']['assignments'][0]['row_version'], $list['data']['assignments'][0]['version']);
    }

    public function test_authorization_and_empty_campaign_snapshot(): void
    {
        $asn = $this->nodeService->assignCampaignExplicitly($this->key, 'node-a', '11111111-1111-1111-1111-111111111111');
        $gen = (int) $asn['assignment_generation'];

        $unauth = $this->controller->snapshot(
            Request::create('/x', 'GET', ['node_id' => 'node-a', 'assignment_generation' => $gen]),
            '11111111-1111-1111-1111-111111111111',
        );
        self::assertSame(401, $unauth->getStatusCode());

        $ok = $this->feed->snapshot($this->key, '11111111-1111-1111-1111-111111111111', 'node-a', $gen);
        self::assertTrue($ok['ok']);
        self::assertSame(0, $ok['data']['event_sequence_head']);
        self::assertSame([], $ok['data']['jobs']);
        self::assertArrayNotHasKey('password', $ok['data']['campaign']);
        self::assertArrayNotHasKey('lease_token', $ok['data']);
    }

    public function test_no_assignment_and_revoked_assignment_rejected(): void
    {
        $denied = $this->feed->snapshot($this->key, '11111111-1111-1111-1111-111111111111', 'node-a', 0);
        self::assertFalse($denied['ok']);
        self::assertContains('assignment_required', $denied['errors']);

        $asn = $this->nodeService->assignCampaignExplicitly($this->key, 'node-a', '11111111-1111-1111-1111-111111111111');
        $gen = (int) $asn['assignment_generation'];
        $this->feedStore->revokeAssignment('tenant-alpha', 'node-a', '11111111-1111-1111-1111-111111111111');
        // generation bumped on revoke
        $node = $this->nodes->findNode('tenant-alpha', 'node-a');
        $stale = $this->feed->snapshot($this->key, '11111111-1111-1111-1111-111111111111', 'node-a', $gen);
        self::assertFalse($stale['ok']);
        self::assertTrue(
            in_array('assignment_generation_stale', $stale['errors'], true)
            || in_array('assignment_revoked', $stale['errors'], true)
        );

        $freshGen = (int) $node['assignment_generation'];
        $revoked = $this->feed->snapshot($this->key, '11111111-1111-1111-1111-111111111111', 'node-a', $freshGen);
        self::assertFalse($revoked['ok']);
        self::assertContains('assignment_revoked', $revoked['errors']);
    }

    public function test_cross_tenant_and_revoked_node(): void
    {
        $asn = $this->nodeService->assignCampaignExplicitly($this->key, 'node-a', '11111111-1111-1111-1111-111111111111');
        $gen = (int) $asn['assignment_generation'];

        $beta = $this->key(2, 'tenant-beta');
        $cross = $this->feed->snapshot($beta, '11111111-1111-1111-1111-111111111111', 'node-a', $gen);
        self::assertFalse($cross['ok']);

        $this->feedStore->revokeNode('tenant-alpha', 'node-a');
        $revoked = $this->feed->snapshot($this->key, '11111111-1111-1111-1111-111111111111', 'node-a', $gen);
        self::assertFalse($revoked['ok']);
        self::assertContains('node_revoked', $revoked['errors']);
    }

    public function test_multi_node_same_campaign_and_stale_generation(): void
    {
        $a = $this->nodeService->assignCampaignExplicitly($this->key, 'node-a', '11111111-1111-1111-1111-111111111111');
        $b = $this->nodeService->assignCampaignExplicitly($this->key, 'node-b', '11111111-1111-1111-1111-111111111111');
        $genA = (int) $a['assignment_generation'];
        $genB = (int) $b['assignment_generation'];

        $snapA = $this->feed->snapshot($this->key, '11111111-1111-1111-1111-111111111111', 'node-a', $genA);
        $snapB = $this->feed->snapshot($this->key, '11111111-1111-1111-1111-111111111111', 'node-b', $genB);
        self::assertTrue($snapA['ok']);
        self::assertTrue($snapB['ok']);

        $stale = $this->feed->snapshot($this->key, '11111111-1111-1111-1111-111111111111', 'node-a', $genA - 1);
        self::assertFalse($stale['ok']);
        self::assertContains('assignment_generation_stale', $stale['errors']);
        self::assertSame(409, $stale['http']);
    }

    public function test_event_projection_pagination_dedupe_and_sanitization(): void
    {
        $asn = $this->nodeService->assignCampaignExplicitly($this->key, 'node-a', '11111111-1111-1111-1111-111111111111');
        $gen = (int) $asn['assignment_generation'];

        $this->feedStore->seedJob([
            'id' => 501,
            'job_uuid' => 'job-uuid-1',
            'campaign_id' => 100,
            'status' => 'completed',
            'fencing_generation' => 3,
            'attempt_no' => 1,
            'lease_token' => 'SECRET_LEASE',
            'reserved_at' => '2026-11-20T01:00:00+00:00',
            'claimed_at' => '2026-11-20T01:01:00+00:00',
            'started_at' => '2026-11-20T01:02:00+00:00',
            'finished_at' => '2026-11-20T01:03:00+00:00',
            'error_code' => null,
        ]);
        $this->feedStore->seedJobEvent([
            'job_id' => 501,
            'event_type' => 'reserve',
            'to_status' => 'reserved',
            'occurred_at' => '2026-11-20T01:00:00+00:00',
            'attempt_no' => 1,
        ]);
        $this->feedStore->seedJobEvent([
            'job_id' => 501,
            'event_type' => 'claim',
            'to_status' => 'claimed',
            'occurred_at' => '2026-11-20T01:01:00+00:00',
            'attempt_no' => 1,
        ]);
        $this->feedStore->seedJobEvent([
            'job_id' => 501,
            'event_type' => 'progress',
            'to_status' => 'running',
            'occurred_at' => '2026-11-20T01:01:30+00:00',
            'attempt_no' => 1,
        ]);
        $this->feedStore->seedJobEvent([
            'job_id' => 501,
            'event_type' => 'ack',
            'to_status' => 'running',
            'occurred_at' => '2026-11-20T01:02:00+00:00',
            'attempt_no' => 1,
        ]);
        $this->feedStore->seedJobEvent([
            'job_id' => 501,
            'event_type' => 'result',
            'to_status' => 'completed',
            'occurred_at' => '2026-11-20T01:03:00+00:00',
            'attempt_no' => 1,
        ]);
        $this->feedStore->seedArtifact([
            'job_id' => 501,
            'kind' => 'screenshot',
            'artifact_uuid' => 'art-1',
            'mime' => 'image/png',
            'created_at' => '2026-11-20T01:03:05+00:00',
            'attempt_no' => 1,
        ]);

        $page1 = $this->feed->events(
            $this->key,
            '11111111-1111-1111-1111-111111111111',
            'node-a',
            $gen,
            0,
            null,
            null,
            2,
        );
        self::assertTrue($page1['ok']);
        self::assertCount(2, $page1['data']['events']);
        self::assertSame(KeepAliveEventTypes::JOB_RESERVED, $page1['data']['events'][0]['event_type']);
        self::assertSame(KeepAliveEventTypes::JOB_CLAIMED, $page1['data']['events'][1]['event_type']);
        self::assertSame(1, $page1['data']['events'][0]['event_sequence']);
        self::assertSame(2, $page1['data']['next_cursor']);

        $page2 = $this->feed->events(
            $this->key,
            '11111111-1111-1111-1111-111111111111',
            'node-a',
            $gen,
            $page1['data']['next_cursor'],
            null,
            null,
            10,
        );
        $types = array_column($page2['data']['events'], 'event_type');
        self::assertNotContains('progress', $types);
        self::assertContains(KeepAliveEventTypes::JOB_STARTED, $types);
        self::assertContains(KeepAliveEventTypes::JOB_COMPLETED, $types);
        self::assertContains(KeepAliveEventTypes::SCREENSHOT_UPLOADED, $types);
        self::assertSame(5, $page2['data']['event_sequence_head']);

        // Gap-fill range
        $gap = $this->feed->events(
            $this->key,
            '11111111-1111-1111-1111-111111111111',
            'node-a',
            $gen,
            1,
            null,
            3,
            10,
        );
        self::assertCount(2, $gap['data']['events']);
        self::assertSame(2, $gap['data']['events'][0]['event_sequence']);
        self::assertSame(3, $gap['data']['events'][1]['event_sequence']);

        // Duplicate projection does not invent sequences
        $again = $this->feed->events(
            $this->key,
            '11111111-1111-1111-1111-111111111111',
            'node-a',
            $gen,
            0,
            null,
            null,
            100,
        );
        self::assertSame(5, $again['data']['event_sequence_head']);
        self::assertCount(5, $again['data']['events']);
        self::assertSame(0, $this->feedStore->jobClaimInvocations());

        $snap = $this->feed->snapshot($this->key, '11111111-1111-1111-1111-111111111111', 'node-a', $gen);
        self::assertTrue($snap['ok']);
        self::assertSame(5, $snap['data']['snapshot_version']);
        self::assertArrayNotHasKey('lease_token', $snap['data']['jobs'][0]);
        self::assertArrayNotHasKey('password', $snap['data']['jobs'][0]);
        self::assertArrayNotHasKey('cookies', $snap['data']['jobs'][0]);
        foreach ($again['data']['events'] as $ev) {
            self::assertArrayHasKey('event_id', $ev);
            self::assertArrayHasKey('fencing_generation', $ev);
            self::assertSame('tenant-alpha', $ev['tenant_id']);
            self::assertSame(KeepAliveEventTypes::PAYLOAD_VERSION, $ev['payload_version']);
        }
    }

    public function test_controller_http_envelope_and_cursor_alias(): void
    {
        $asn = $this->nodeService->assignCampaignExplicitly($this->key, 'node-a', '11111111-1111-1111-1111-111111111111');
        $gen = (int) $asn['assignment_generation'];
        $this->feedStore->seedJob([
            'id' => 601,
            'job_uuid' => 'job-uuid-2',
            'campaign_id' => 100,
            'status' => 'reserved',
            'fencing_generation' => 1,
        ]);
        $this->feedStore->seedJobEvent([
            'job_id' => 601,
            'event_type' => 'reserve',
            'to_status' => 'reserved',
            'occurred_at' => '2026-11-20T02:00:00+00:00',
        ]);

        $req = Request::create(
            '/api/v1/social-automation/client/keepalive/campaigns/11111111-1111-1111-1111-111111111111/events',
            'GET',
            ['node_id' => 'node-a', 'assignment_generation' => $gen, 'cursor' => 0, 'limit' => 10],
        );
        $req->attributes->set(EnsureRpaClientApiKey::REQUEST_CLIENT_KEY, $this->key);
        $resp = $this->controller->events($req, '11111111-1111-1111-1111-111111111111')->getData(true);
        self::assertTrue($resp['ok']);
        self::assertCount(1, $resp['events']);
        self::assertSame(KeepAliveEventTypes::JOB_RESERVED, $resp['events'][0]['event_type']);
    }

    private function key(int $id, string $installationId): SaClientApiKey
    {
        $key = new SaClientApiKey;
        $key->id = $id;
        $key->setAttribute('installation_id', $installationId);

        return $key;
    }
}
