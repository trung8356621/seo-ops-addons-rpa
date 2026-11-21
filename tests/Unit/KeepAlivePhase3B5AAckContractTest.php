<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveAckPolicy;
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

final class KeepAlivePhase3B5AAckContractTest extends TestCase
{
    private string $root;

    private InMemoryKeepAliveStore $nodes;

    private InMemoryKeepAliveFeedStore $feedStore;

    private InMemoryKeepAliveAckStore $ackStore;

    private KeepAliveNodeService $nodeService;

    private KeepAliveAckService $acks;

    private ClientKeepAliveController $controller;

    private SaClientApiKey $key;

    private string $campaignUuid = '11111111-1111-1111-1111-111111111111';

    private int $gen = 0;

    /** @var list<string> */
    private array $eventIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
        $this->nodes = new InMemoryKeepAliveStore;
        $this->feedStore = new InMemoryKeepAliveFeedStore($this->nodes);
        $this->ackStore = new InMemoryKeepAliveAckStore($this->feedStore);
        $this->nodeService = new KeepAliveNodeService($this->nodes);
        $feed = new KeepAliveCampaignFeedService($this->nodes, $this->feedStore);
        $this->acks = new KeepAliveAckService($this->nodes, $this->feedStore, $this->ackStore);
        $routes = new \Omnichannel\Addons\SocialAutomation\Repositories\InMemoryKeepAliveRouteStore($this->nodes, $this->feedStore);
        $this->controller = new ClientKeepAliveController(
            $this->nodeService,
            $feed,
            $this->acks,
            new \Omnichannel\Addons\SocialAutomation\Services\KeepAliveSocketTicketService($this->nodes, $routes),
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
            'id' => 200,
            'campaign_uuid' => '22222222-2222-2222-2222-222222222222',
            'installation_id' => 'tenant-beta',
            'name' => 'Campaign B',
            'status' => 'running',
            'platform' => 'facebook',
        ]);

        $this->key = $this->makeKey(1, 'tenant-alpha');
        $this->registerNode('node-a');
        $this->registerNode('node-b');
        $asn = $this->nodeService->assignCampaignExplicitly($this->key, 'node-a', $this->campaignUuid);
        $this->gen = (int) $asn['assignment_generation'];
        $this->nodeService->assignCampaignExplicitly($this->key, 'node-b', $this->campaignUuid);
        $nodeB = $this->nodes->findNode('tenant-alpha', 'node-b');
        // node-b generation may differ; refresh after both assigns
        $this->gen = (int) ($this->nodes->findNode('tenant-alpha', 'node-a')['assignment_generation'] ?? $this->gen);

        $this->seedThreeEvents();
        $this->eventIds = array_column(
            $this->feedStore->listEvents('tenant-alpha', $this->campaignUuid, 0, null, 100),
            'event_id',
        );
    }

    public function test_migration_routes_and_no_job_side_effects(): void
    {
        $mig = (string) file_get_contents($this->root.'/database/migrations/2026_11_20_160000_create_sa_keepalive_ack_tables.php');
        self::assertStringContainsString('sa_keepalive_event_ack_cursors', $mig);
        self::assertStringContainsString('sa_keepalive_artifact_acks', $mig);
        self::assertStringNotContainsString('sa_jobs', $mig);

        $provider = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');
        self::assertStringContainsString('/keepalive/events/ack', $provider);
        self::assertStringContainsString('/keepalive/artifacts/ack', $provider);

        $ackSrc = (string) file_get_contents($this->root.'/src/Services/KeepAliveAckService.php');
        self::assertStringNotContainsString('claimNext', $ackSrc);
        self::assertStringNotContainsString('dispatchCampaign', $ackSrc);
        self::assertStringContainsString('Not Job Claim', $ackSrc);

        $orch = (string) file_get_contents($this->root.'/src/Orchestration/CampaignOrchestrator.php');
        self::assertStringNotContainsString('KeepAliveAck', $orch);

        $openapi = (string) file_get_contents($this->root.'/openapi/openapi.yaml');
        self::assertStringContainsString('clientKeepAliveEventsAck', $openapi);
        self::assertStringContainsString('clientKeepAliveArtifactsAck', $openapi);
    }

    public function test_auth_and_assignment_gates(): void
    {
        $unauth = $this->controller->ackEvents(Request::create('/x', 'POST', [
            'node_id' => 'node-a',
            'assignment_generation' => $this->gen,
            'campaign_id' => $this->campaignUuid,
            'event_ids' => [$this->eventIds[0]],
            'last_contiguous_sequence' => 1,
        ]));
        self::assertSame(401, $unauth->getStatusCode());

        $noAssign = $this->acks->ackEvents($this->key, [
            'node_id' => 'node-a',
            'assignment_generation' => $this->gen,
            'campaign_id' => '22222222-2222-2222-2222-222222222222',
            'event_ids' => [],
            'last_contiguous_sequence' => 0,
        ]);
        self::assertFalse($noAssign['ok']);

        $stale = $this->acks->ackEvents($this->key, [
            'node_id' => 'node-a',
            'assignment_generation' => $this->gen - 1,
            'campaign_id' => $this->campaignUuid,
            'event_ids' => [$this->eventIds[0]],
            'last_contiguous_sequence' => 1,
        ]);
        self::assertFalse($stale['ok']);
        self::assertContains('assignment_generation_stale', $stale['errors']);
    }

    public function test_contiguous_ack_duplicate_gap_and_beyond_head(): void
    {
        $ok = $this->acks->ackEvents($this->key, [
            'node_id' => 'node-a',
            'assignment_generation' => $this->gen,
            'campaign_id' => $this->campaignUuid,
            'event_ids' => [$this->eventIds[0], $this->eventIds[1]],
            'last_contiguous_sequence' => 2,
        ]);
        self::assertTrue($ok['ok']);
        self::assertSame(2, $ok['data']['acked_through_sequence']);
        self::assertSame(2, $ok['data']['acked_event_count']);
        self::assertSame(0, $this->ackStore->jobClaimInvocations());

        $dup = $this->acks->ackEvents($this->key, [
            'node_id' => 'node-a',
            'assignment_generation' => $this->gen,
            'campaign_id' => $this->campaignUuid,
            'event_ids' => [$this->eventIds[0], $this->eventIds[1]],
            'last_contiguous_sequence' => 2,
        ]);
        self::assertTrue($dup['ok']);
        self::assertTrue($dup['data']['idempotent']);
        self::assertSame(2, $dup['data']['acked_through_sequence']);
        self::assertSame(0, $dup['data']['acked_event_count']);

        $gap = $this->acks->ackEvents($this->key, [
            'node_id' => 'node-a',
            'assignment_generation' => $this->gen,
            'campaign_id' => $this->campaignUuid,
            'event_ids' => [$this->eventIds[2]],
            'last_contiguous_sequence' => 4,
        ]);
        self::assertFalse($gap['ok']);
        self::assertContains(KeepAliveAckPolicy::ERROR_ACK_BEYOND_HEAD, $gap['errors']);

        $skip = $this->acks->ackEvents($this->key, [
            'node_id' => 'node-a',
            'assignment_generation' => $this->gen,
            'campaign_id' => $this->campaignUuid,
            'event_ids' => [$this->eventIds[2]],
            'last_contiguous_sequence' => 3,
        ]);
        // missing seq 3 only if we skip - actually eventIds[2] is seq 3, contiguous from 2 → OK
        self::assertTrue($skip['ok']);

        $missing = $this->acks->ackEvents($this->key, [
            'node_id' => 'node-b',
            'assignment_generation' => (int) $this->nodes->findNode('tenant-alpha', 'node-b')['assignment_generation'],
            'campaign_id' => $this->campaignUuid,
            'event_ids' => ['does-not-exist'],
            'last_contiguous_sequence' => 1,
        ]);
        self::assertFalse($missing['ok']);
        self::assertContains(KeepAliveAckPolicy::ERROR_EVENT_IDS_INVALID, $missing['errors']);
    }

    public function test_multi_node_independent_cursors(): void
    {
        $genB = (int) $this->nodes->findNode('tenant-alpha', 'node-b')['assignment_generation'];
        $a = $this->acks->ackEvents($this->key, [
            'node_id' => 'node-a',
            'assignment_generation' => $this->gen,
            'campaign_id' => $this->campaignUuid,
            'event_ids' => [$this->eventIds[0]],
            'last_contiguous_sequence' => 1,
        ]);
        self::assertTrue($a['ok']);
        self::assertSame(1, $a['data']['acked_through_sequence']);

        $bCursor = $this->ackStore->getEventAckCursor('tenant-alpha', 'node-b', $this->campaignUuid);
        self::assertSame(0, $bCursor['acked_through_sequence']);

        $b = $this->acks->ackEvents($this->key, [
            'node_id' => 'node-b',
            'assignment_generation' => $genB,
            'campaign_id' => $this->campaignUuid,
            'event_ids' => [$this->eventIds[0], $this->eventIds[1], $this->eventIds[2]],
            'last_contiguous_sequence' => 3,
        ]);
        self::assertTrue($b['ok']);
        self::assertSame(3, $b['data']['acked_through_sequence']);

        $aCursor = $this->ackStore->getEventAckCursor('tenant-alpha', 'node-a', $this->campaignUuid);
        self::assertSame(1, $aCursor['acked_through_sequence']);
    }

    public function test_revocation_blocks_ack(): void
    {
        $this->ackStore->feed()->revokeAssignment('tenant-alpha', 'node-a', $this->campaignUuid);
        $freshGen = (int) $this->nodes->findNode('tenant-alpha', 'node-a')['assignment_generation'];
        $denied = $this->acks->ackEvents($this->key, [
            'node_id' => 'node-a',
            'assignment_generation' => $freshGen,
            'campaign_id' => $this->campaignUuid,
            'event_ids' => [$this->eventIds[0]],
            'last_contiguous_sequence' => 1,
        ]);
        self::assertFalse($denied['ok']);
        self::assertContains('assignment_revoked', $denied['errors']);
    }

    public function test_artifact_ack_verified_missing_mismatch_and_basename_rejected(): void
    {
        $sha = str_repeat('ab', 32);
        $this->feedStore->seedJob([
            'id' => 701,
            'job_uuid' => 'job-art-1',
            'campaign_id' => 100,
            'status' => 'completed',
            'fencing_generation' => 1,
        ]);
        $this->feedStore->seedArtifact([
            'job_id' => 701,
            'kind' => 'screenshot',
            'artifact_uuid' => 'art-uuid-1',
            'storage_path' => 'artifacts/campaign/job-art-1/final.png',
            'checksum_sha256' => $sha,
            'mime' => 'image/png',
            'attempt_no' => 1,
            'created_at' => '2026-11-20T03:00:00+00:00',
        ]);

        $missing = $this->acks->ackArtifact($this->key, [
            'node_id' => 'node-a',
            'assignment_generation' => $this->gen,
            'campaign_id' => $this->campaignUuid,
            'job_id' => 'job-missing',
            'attempt' => 1,
            'storage_key' => 'artifacts/campaign/job-art-1/final.png',
            'sha256' => $sha,
        ]);
        self::assertFalse($missing['ok']);
        self::assertContains(KeepAliveAckPolicy::ERROR_ARTIFACT_NOT_READY, $missing['errors']);
        self::assertSame(409, $missing['http']);

        $basename = $this->acks->ackArtifact($this->key, [
            'node_id' => 'node-a',
            'assignment_generation' => $this->gen,
            'campaign_id' => $this->campaignUuid,
            'job_id' => 'job-art-1',
            'attempt' => 1,
            'storage_key' => 'final.png',
            'sha256' => $sha,
        ]);
        self::assertFalse($basename['ok']);
        self::assertContains(KeepAliveAckPolicy::ERROR_ARTIFACT_STORAGE_KEY_MISMATCH, $basename['errors']);

        $badSha = $this->acks->ackArtifact($this->key, [
            'node_id' => 'node-a',
            'assignment_generation' => $this->gen,
            'campaign_id' => $this->campaignUuid,
            'job_id' => 'job-art-1',
            'attempt' => 1,
            'storage_key' => 'artifacts/campaign/job-art-1/final.png',
            'sha256' => str_repeat('cd', 32),
        ]);
        self::assertFalse($badSha['ok']);
        self::assertContains(KeepAliveAckPolicy::ERROR_ARTIFACT_CHECKSUM_MISMATCH, $badSha['errors']);

        $ok = $this->acks->ackArtifact($this->key, [
            'node_id' => 'node-a',
            'assignment_generation' => $this->gen,
            'campaign_id' => $this->campaignUuid,
            'job_id' => 'job-art-1',
            'attempt' => 1,
            'storage_key' => 'artifacts/campaign/job-art-1/final.png',
            'sha256' => $sha,
        ]);
        self::assertTrue($ok['ok']);
        self::assertTrue($ok['data']['verified']);
        self::assertFalse($ok['data']['idempotent']);

        $again = $this->acks->ackArtifact($this->key, [
            'node_id' => 'node-a',
            'assignment_generation' => $this->gen,
            'campaign_id' => $this->campaignUuid,
            'job_id' => 'job-art-1',
            'attempt' => 1,
            'storage_key' => 'art-uuid-1',
            'sha256' => $sha,
        ]);
        self::assertTrue($again['ok']);
        self::assertTrue($again['data']['idempotent']);
        self::assertSame(0, $this->ackStore->jobClaimInvocations());
    }

    public function test_artifact_unsupported_flag(): void
    {
        $this->ackStore->setArtifactUnsupported(true);
        $resp = $this->acks->ackArtifact($this->key, [
            'node_id' => 'node-a',
            'assignment_generation' => $this->gen,
            'campaign_id' => $this->campaignUuid,
            'job_id' => 'job-art-1',
            'attempt' => 1,
            'storage_key' => 'x',
            'sha256' => str_repeat('11', 32),
        ]);
        self::assertFalse($resp['ok']);
        self::assertContains(KeepAliveAckPolicy::ERROR_ARTIFACT_UNSUPPORTED, $resp['errors']);
    }

    public function test_controller_http_envelope(): void
    {
        $req = Request::create('/api/v1/social-automation/client/keepalive/events/ack', 'POST', [
            'node_id' => 'node-a',
            'assignment_generation' => $this->gen,
            'campaign_id' => $this->campaignUuid,
            'event_ids' => [$this->eventIds[0]],
            'last_contiguous_sequence' => 1,
        ]);
        $req->attributes->set(EnsureRpaClientApiKey::REQUEST_CLIENT_KEY, $this->key);
        $body = $this->controller->ackEvents($req)->getData(true);
        self::assertTrue($body['ok']);
        self::assertSame(KeepAliveProtocol::PROTOCOL_VERSION, $body['protocol_version']);
        self::assertSame(1, $body['acked_through_sequence']);
    }

    public function test_cumulative_and_restart_retry_contiguous_ack_1_to_10(): void
    {
        for ($i = 4; $i <= 10; $i++) {
            $this->feedStore->seedJobEvent([
                'job_id' => 501,
                'event_type' => 'ack',
                'to_status' => 'running',
                'occurred_at' => sprintf('2026-11-20T01:%02d:00+00:00', $i),
                'attempt_no' => 1,
            ]);
        }
        $this->feedStore->projectDurableEvents('tenant-alpha', 100, $this->campaignUuid);
        $tenIds = array_column(
            $this->feedStore->listEvents('tenant-alpha', $this->campaignUuid, 0, null, 20),
            'event_id',
        );
        self::assertCount(10, $tenIds);

        // 1. Initial contiguous ACK #1-#10
        $genB = (int) $this->nodes->findNode('tenant-alpha', 'node-b')['assignment_generation'];
        $initial = $this->acks->ackEvents($this->key, [
            'node_id' => 'node-b',
            'assignment_generation' => $genB,
            'campaign_id' => $this->campaignUuid,
            'event_ids' => $tenIds,
            'last_contiguous_sequence' => 10,
        ]);
        self::assertTrue($initial['ok']);
        self::assertSame(10, $initial['data']['acked_through_sequence']);
        self::assertSame(10, $initial['data']['acked_event_count']);
        self::assertSame(10, $initial['data']['acked_event_count_total']);
        self::assertFalse($initial['data']['idempotent']);

        // 2. Duplicate ACK idempotency
        $dup = $this->acks->ackEvents($this->key, [
            'node_id' => 'node-b',
            'assignment_generation' => $genB,
            'campaign_id' => $this->campaignUuid,
            'event_ids' => $tenIds,
            'last_contiguous_sequence' => 10,
        ]);
        self::assertTrue($dup['ok']);
        self::assertTrue($dup['data']['idempotent']);
        self::assertSame(10, $dup['data']['acked_through_sequence']);
        self::assertSame(0, $dup['data']['acked_event_count']);
        self::assertSame(10, $dup['data']['acked_event_count_total']);

        // 3. Cursor regression does not lower cursor & negative sequence is rejected
        $regress = $this->acks->ackEvents($this->key, [
            'node_id' => 'node-b',
            'assignment_generation' => $genB,
            'campaign_id' => $this->campaignUuid,
            'event_ids' => array_slice($tenIds, 0, 5),
            'last_contiguous_sequence' => 5,
        ]);
        self::assertTrue($regress['ok']);
        self::assertTrue($regress['data']['idempotent']);
        self::assertSame(10, $regress['data']['acked_through_sequence']);
        self::assertSame(10, $this->ackStore->getEventAckCursor('tenant-alpha', 'node-b', $this->campaignUuid)['acked_through_sequence']);

        $negative = $this->acks->ackEvents($this->key, [
            'node_id' => 'node-b',
            'assignment_generation' => $genB,
            'campaign_id' => $this->campaignUuid,
            'event_ids' => $tenIds,
            'last_contiguous_sequence' => -1,
        ]);
        self::assertFalse($negative['ok']);
        self::assertContains(KeepAliveAckPolicy::ERROR_LAST_CONTIGUOUS_REQUIRED, $negative['errors']);

        // 4. Missing event rejection (gap #9 omitted in #1..#10, and unknown event ID)
        $nineIds = array_slice($tenIds, 0, 9);
        $ack9 = $this->acks->ackEvents($this->key, [
            'node_id' => 'node-a',
            'assignment_generation' => $this->gen,
            'campaign_id' => $this->campaignUuid,
            'event_ids' => $nineIds,
            'last_contiguous_sequence' => 9,
        ]);
        self::assertTrue($ack9['ok']);
        self::assertSame(9, $ack9['data']['acked_through_sequence']);

        $gapIds = array_merge(array_slice($tenIds, 0, 8), [$tenIds[9]]);
        $gap = $this->acks->ackEvents($this->key, [
            'node_id' => 'node-a',
            'assignment_generation' => $this->gen,
            'campaign_id' => $this->campaignUuid,
            'event_ids' => $gapIds,
            'last_contiguous_sequence' => 10,
        ]);
        self::assertFalse($gap['ok']);
        self::assertContains(KeepAliveAckPolicy::ERROR_ACK_NON_CONTIGUOUS, $gap['errors']);

        $unknown = $this->acks->ackEvents($this->key, [
            'node_id' => 'node-a',
            'assignment_generation' => $this->gen,
            'campaign_id' => $this->campaignUuid,
            'event_ids' => array_merge($nineIds, ['missing-event-id']),
            'last_contiguous_sequence' => 10,
        ]);
        self::assertFalse($unknown['ok']);
        self::assertContains(KeepAliveAckPolicy::ERROR_EVENT_IDS_INVALID, $unknown['errors']);

        // 5. Restart/retry ACK: cursor at 9, client sends cumulative #1-#10
        $retry = $this->acks->ackEvents($this->key, [
            'node_id' => 'node-a',
            'assignment_generation' => $this->gen,
            'campaign_id' => $this->campaignUuid,
            'event_ids' => $tenIds,
            'last_contiguous_sequence' => 10,
        ]);
        self::assertTrue($retry['ok']);
        self::assertFalse($retry['data']['idempotent']);
        self::assertSame(10, $retry['data']['acked_through_sequence']);
        self::assertSame(1, $retry['data']['acked_event_count']);
        self::assertSame(10, $retry['data']['acked_event_count_total']);
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

    private function seedThreeEvents(): void
    {
        $this->feedStore->seedJob([
            'id' => 501,
            'job_uuid' => 'job-uuid-1',
            'campaign_id' => 100,
            'status' => 'completed',
            'fencing_generation' => 1,
            'attempt_no' => 1,
        ]);
        foreach ([
            ['reserve', 'reserved', '2026-11-20T01:00:00+00:00'],
            ['claim', 'claimed', '2026-11-20T01:01:00+00:00'],
            ['ack', 'running', '2026-11-20T01:02:00+00:00'],
        ] as [$type, $to, $at]) {
            $this->feedStore->seedJobEvent([
                'job_id' => 501,
                'event_type' => $type,
                'to_status' => $to,
                'occurred_at' => $at,
                'attempt_no' => 1,
            ]);
        }
        $this->feedStore->projectDurableEvents('tenant-alpha', 100, $this->campaignUuid);
    }

    private function makeKey(int $id, string $installationId): SaClientApiKey
    {
        $key = new SaClientApiKey;
        $key->id = $id;
        $key->setAttribute('installation_id', $installationId);

        return $key;
    }
}
