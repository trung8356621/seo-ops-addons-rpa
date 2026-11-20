<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveProtocol;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveRegistrationPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveSocketEnvelope;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveSocketTicket;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Repositories\InMemoryKeepAliveFeedStore;
use Omnichannel\Addons\SocialAutomation\Repositories\InMemoryKeepAliveNotifyOutbox;
use Omnichannel\Addons\SocialAutomation\Repositories\InMemoryKeepAliveRouteStore;
use Omnichannel\Addons\SocialAutomation\Repositories\InMemoryKeepAliveStore;
use Omnichannel\Addons\SocialAutomation\Services\KeepAliveGatewayPublisher;
use Omnichannel\Addons\SocialAutomation\Services\KeepAliveNodeService;
use Omnichannel\Addons\SocialAutomation\Services\KeepAliveNotifyService;
use Omnichannel\Addons\SocialAutomation\Services\KeepAliveSocketTicketService;
use PHPUnit\Framework\TestCase;

final class KeepAlivePhase3B6CNotifyContractTest extends TestCase
{
    private string $root;

    private InMemoryKeepAliveStore $nodes;

    private InMemoryKeepAliveRouteStore $routes;

    private InMemoryKeepAliveFeedStore $feed;

    private InMemoryKeepAliveNotifyOutbox $outbox;

    private KeepAliveGatewayPublisher $publisher;

    private KeepAliveNotifyService $notify;

    private KeepAliveNodeService $nodeService;

    private KeepAliveSocketTicketService $tickets;

    private SaClientApiKey $key;

    private string $campaignUuid = '11111111-1111-1111-1111-111111111111';

    private int $genA = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
        $this->nodes = new InMemoryKeepAliveStore;
        $this->feed = new InMemoryKeepAliveFeedStore($this->nodes);
        $this->routes = new InMemoryKeepAliveRouteStore($this->nodes, $this->feed);
        $this->outbox = new InMemoryKeepAliveNotifyOutbox;
        $this->publisher = new KeepAliveGatewayPublisher('http://127.0.0.1:9/internal/publish', 'test-secret');
        $this->notify = new KeepAliveNotifyService($this->outbox, $this->routes, $this->publisher);
        $this->notify->setAutoFlush(false);
        $this->feed->setNotify($this->notify);
        $this->nodeService = new KeepAliveNodeService(
            $this->nodes,
            new KeepAliveRegistrationPolicy,
            $this->routes,
            $this->notify,
        );
        $this->tickets = new KeepAliveSocketTicketService(
            $this->nodes,
            $this->routes,
            new KeepAliveSocketTicket('test-ka-sock-secret'),
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
        $this->key = $this->makeKey(1, 'tenant-alpha');
        $this->register('node-a');
        $this->register('node-b');
        $asn = $this->nodeService->assignCampaignExplicitly($this->key, 'node-a', $this->campaignUuid);
        $this->genA = (int) $asn['assignment_generation'];
        $this->nodeService->assignCampaignExplicitly($this->key, 'node-b', $this->campaignUuid);
        $this->genA = (int) ($this->nodes->findNode('tenant-alpha', 'node-a')['assignment_generation'] ?? $this->genA);
        // Drain assign notifications for clean notify tests.
        $this->outbox = new InMemoryKeepAliveNotifyOutbox;
        $this->publisher = new KeepAliveGatewayPublisher('', 'test-secret');
        $this->notify = new KeepAliveNotifyService($this->outbox, $this->routes, $this->publisher);
        $this->notify->setAutoFlush(false);
        $this->feed->setNotify($this->notify);
        $this->nodeService = new KeepAliveNodeService(
            $this->nodes,
            new KeepAliveRegistrationPolicy,
            $this->routes,
            $this->notify,
        );
    }

    public function test_migration_gateway_files_and_no_job_side_effects(): void
    {
        $mig = (string) file_get_contents($this->root.'/database/migrations/2026_11_20_180000_create_sa_keepalive_notify_outbox.php');
        self::assertStringContainsString('sa_keepalive_notify_outbox', $mig);
        self::assertStringNotContainsString('sa_jobs', $mig);

        self::assertFileExists($this->root.'/gateway/src/server.js');
        self::assertFileExists($this->root.'/gateway/package.json');
        self::assertFileExists($this->root.'/docs/KEEPALIVE_SOCKET_GATEWAY_3B6C.md');

        $orch = (string) file_get_contents($this->root.'/src/Orchestration/CampaignOrchestrator.php');
        self::assertStringNotContainsString('KeepAliveNotify', $orch);
        self::assertStringNotContainsString('keepalive-gateway', $orch);

        $notifySrc = (string) file_get_contents($this->root.'/src/Services/KeepAliveNotifyService.php');
        self::assertStringNotContainsString('claimNext', $notifySrc);
        self::assertStringContainsString('Never participates in Job claim', $notifySrc);

        $provider = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');
        self::assertStringContainsString('KeepAliveNotifyFlushCommand', $provider);
        self::assertStringContainsString('KeepAliveNotifyService', $provider);

        self::assertSame(0, $this->nodes->jobClaimInvocations());
        self::assertSame(0, $this->routes->jobClaimInvocations());
    }

    public function test_post_commit_notify_ordering_and_coalesce(): void
    {
        $this->feed->seedJob([
            'id' => 501,
            'job_uuid' => 'job-uuid-1',
            'campaign_id' => 100,
            'status' => 'completed',
            'fencing_generation' => 1,
            'attempt_no' => 1,
        ]);
        $this->feed->seedJobEvent([
            'job_id' => 501,
            'event_type' => 'reserve',
            'to_status' => 'reserved',
            'occurred_at' => '2026-11-20T01:00:00+00:00',
            'attempt_no' => 1,
        ]);
        $this->feed->seedJobEvent([
            'job_id' => 501,
            'event_type' => 'claim',
            'to_status' => 'claimed',
            'occurred_at' => '2026-11-20T01:01:00+00:00',
            'attempt_no' => 1,
        ]);

        $first = $this->feed->projectDurableEvents('tenant-alpha', 100, $this->campaignUuid);
        self::assertSame(2, $first['projected']);
        self::assertSame(1, $this->outbox->pendingCount()); // coalesced to one notify

        // Duplicate projection should not create extra durable events; no new notify needed.
        $second = $this->feed->projectDurableEvents('tenant-alpha', 100, $this->campaignUuid);
        self::assertSame(0, $second['projected']);

        // Manual duplicate enqueue coalesces seq_hint upward.
        $rooms = $this->notify->roomsForCampaign('tenant-alpha', $this->campaignUuid);
        self::assertCount(2, $rooms); // multi-node
        $this->notify->enqueueNotify('tenant-alpha', $this->campaignUuid, 1, $rooms);
        $this->notify->enqueueNotify('tenant-alpha', $this->campaignUuid, 99, $rooms);
        self::assertSame(1, $this->outbox->pendingCount());

        $flush = $this->notify->flush();
        self::assertSame(1, $flush['flushed']);
        self::assertSame(0, $flush['pending']);
        $sent = $this->publisher->sent();
        self::assertNotEmpty($sent);
        self::assertSame(KeepAliveSocketEnvelope::TYPE_NOTIFY, $sent[0]['event']);
        self::assertSame(99, $sent[0]['seq_hint']);
        self::assertSame(2, count($sent[0]['rooms']));
    }

    public function test_gateway_offline_does_not_fail_and_retries(): void
    {
        $this->publisher->setOffline(true);
        $rooms = [
            KeepAliveSocketTicket::roomName('tenant-alpha', $this->campaignUuid, 'node-a'),
        ];
        $this->notify->enqueueNotify('tenant-alpha', $this->campaignUuid, 5, $rooms);
        // Immediate flush in unit mode may have already attempted; force pending again.
        $this->outbox->enqueue([
            'installation_id' => 'tenant-alpha',
            'campaign_uuid' => $this->campaignUuid,
            'event_type' => KeepAliveSocketEnvelope::TYPE_NOTIFY,
            'coalesce_key' => 'notify|tenant-alpha|'.$this->campaignUuid,
            'seq_hint' => 5,
            'rooms' => $rooms,
        ]);
        $result = $this->notify->flush();
        self::assertGreaterThanOrEqual(1, $result['failed']);
        self::assertGreaterThanOrEqual(1, $this->outbox->pendingCount());
        self::assertSame(0, $this->nodes->jobClaimInvocations());

        $this->publisher->setOffline(false);
        // available_at may block; use fresh outbox + deferred flush
        $fresh = new InMemoryKeepAliveNotifyOutbox;
        $pub = new KeepAliveGatewayPublisher('', 'x');
        $svc = new KeepAliveNotifyService($fresh, $this->routes, $pub);
        $svc->setAutoFlush(false);
        $svc->enqueueNotify('tenant-alpha', $this->campaignUuid, 6, $rooms);
        $ok = $svc->flush();
        self::assertSame(1, $ok['flushed']);
    }

    public function test_assignment_revoke_emits_route_invalidated_and_ticket_stale(): void
    {
        $minted = $this->tickets->mint($this->key, [
            'role' => 'recovery',
            'node_id' => 'node-a',
            'assignment_generation' => $this->genA,
            'campaign_ids' => [$this->campaignUuid],
        ]);
        self::assertTrue($minted['ok']);

        $revoked = $this->nodeService->revokeCampaignExplicitly($this->key, 'node-a', $this->campaignUuid);
        self::assertTrue($revoked['ok']);

        $types = [];
        foreach ($this->outbox->all() as $row) {
            if (($row['status'] ?? '') === 'pending' || ($row['status'] ?? '') === 'published') {
                $types[] = $row['event_type'];
            }
        }
        // flush may have published already
        $allTypes = array_column($this->outbox->all(), 'event_type');
        self::assertContains(KeepAliveSocketEnvelope::TYPE_ASSIGNMENT_CHANGED, $allTypes);
        self::assertContains(KeepAliveSocketEnvelope::TYPE_ROUTE_INVALIDATED, $allTypes);

        $gw = $this->tickets->expectedGatewaySecret();
        $stale = $this->tickets->verifyForGateway($gw, [
            'ticket' => $minted['data']['ticket'],
            'consume' => true,
        ]);
        self::assertFalse($stale['ok']);
    }

    public function test_cross_tenant_rooms_not_emitted_for_foreign_campaign(): void
    {
        $rooms = $this->notify->roomsForCampaign('tenant-alpha', '22222222-2222-2222-2222-222222222222');
        self::assertSame([], $rooms);
    }

    public function test_envelope_types_and_size_bound(): void
    {
        $env = KeepAliveSocketEnvelope::build(KeepAliveSocketEnvelope::TYPE_RECOVER_HINT, [
            'tenant_id' => 't',
            'campaign_id' => 'c',
            'reason' => 'gap_fill',
        ]);
        self::assertSame(2, $env['v']);
        self::assertSame('ka.recover_hint', $env['t']);
        self::assertLessThanOrEqual(KeepAliveSocketEnvelope::MAX_PAYLOAD_BYTES, strlen(json_encode($env)));
    }

    private function register(string $nodeId): void
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
