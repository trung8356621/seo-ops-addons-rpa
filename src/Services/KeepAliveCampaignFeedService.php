<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Omnichannel\Addons\SocialAutomation\Contracts\KeepAliveFeedStoreContract;
use Omnichannel\Addons\SocialAutomation\Contracts\KeepAliveStoreContract;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveAccessPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveEventTypes;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveProtocol;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveRegistrationPolicy;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;

/**
 * Read-only Campaign snapshot + incremental durable event feed for Keep Alive Nodes.
 * Does not claim Jobs, start Campaigns, or touch Browser secrets.
 */
final class KeepAliveCampaignFeedService
{
    public const DEFAULT_LIMIT = 100;

    public const MAX_LIMIT = 500;

    public function __construct(
        private readonly KeepAliveStoreContract $nodes,
        private readonly KeepAliveFeedStoreContract $feed,
        private readonly KeepAliveRegistrationPolicy $registration = new KeepAliveRegistrationPolicy,
        private readonly KeepAliveAccessPolicy $access = new KeepAliveAccessPolicy,
    ) {}

    /**
     * @return array{ok: bool, http?: int, error?: string, errors?: list<string>, data?: array<string, mixed>}
     */
    public function snapshot(
        SaClientApiKey $clientKey,
        string $campaignId,
        string $nodeId,
        mixed $assignmentGeneration,
        mixed $clientTenantId = null,
    ): array {
        $gate = $this->authorize($clientKey, $campaignId, $nodeId, $assignmentGeneration, $clientTenantId);
        if (! ($gate['ok'] ?? false)) {
            return $gate;
        }

        /** @var array{tenant_id: string, node: array<string, mixed>, campaign: array<string, mixed>, assignment_generation: int} $gate */
        $tenantId = $gate['tenant_id'];
        $campaign = $gate['campaign'];
        $this->feed->projectDurableEvents($tenantId, (int) $campaign['id'], (string) $campaign['campaign_uuid']);
        $head = $this->feed->sequenceHead($tenantId, (string) $campaign['campaign_uuid']);
        $body = $this->feed->buildSanitizedSnapshot($tenantId, (string) $campaign['campaign_uuid']);
        if ($body === null) {
            return [
                'ok' => false,
                'http' => 404,
                'error' => KeepAliveAccessPolicy::ERROR_CAMPAIGN_NOT_FOUND,
                'errors' => [KeepAliveAccessPolicy::ERROR_CAMPAIGN_NOT_FOUND],
            ];
        }

        return [
            'ok' => true,
            'http' => 200,
            'data' => [
                'protocol_version' => KeepAliveProtocol::PROTOCOL_VERSION,
                'schema_version' => KeepAliveProtocol::SCHEMA_VERSION,
                'tenant_id' => $tenantId,
                'node_id' => $nodeId,
                'assignment_generation' => $gate['assignment_generation'],
                'campaign_id' => (string) $campaign['campaign_uuid'],
                'campaign_uuid' => (string) $campaign['campaign_uuid'],
                'snapshot_version' => (int) $head['snapshot_version'],
                'event_sequence_head' => (int) $head['last_sequence'],
                'campaign' => $body['campaign'],
                'contents' => $body['contents'],
                'jobs' => $body['jobs'],
                'content_counts' => $body['content_counts'],
                'job_counts' => $body['job_counts'],
            ],
        ];
    }

    /**
     * @return array{ok: bool, http?: int, error?: string, errors?: list<string>, data?: array<string, mixed>}
     */
    public function events(
        SaClientApiKey $clientKey,
        string $campaignId,
        string $nodeId,
        mixed $assignmentGeneration,
        mixed $fromSequence,
        mixed $cursor,
        mixed $toSequence,
        mixed $limit,
        mixed $clientTenantId = null,
    ): array {
        $gate = $this->authorize($clientKey, $campaignId, $nodeId, $assignmentGeneration, $clientTenantId);
        if (! ($gate['ok'] ?? false)) {
            return $gate;
        }

        /** @var array{tenant_id: string, campaign: array<string, mixed>, assignment_generation: int} $gate */
        $tenantId = $gate['tenant_id'];
        $campaign = $gate['campaign'];

        $from = $this->parseNonNegativeInt($fromSequence);
        if ($from === null && $cursor !== null && $cursor !== '') {
            $from = $this->parseNonNegativeInt($cursor);
        }
        if ($from === null) {
            $from = 0;
        }

        $to = null;
        if ($toSequence !== null && $toSequence !== '') {
            $to = $this->parseNonNegativeInt($toSequence);
            if ($to === null) {
                return [
                    'ok' => false,
                    'http' => 422,
                    'error' => 'to_sequence_invalid',
                    'errors' => ['to_sequence_invalid'],
                ];
            }
        }

        $lim = $this->parseNonNegativeInt($limit) ?? self::DEFAULT_LIMIT;
        $lim = max(1, min(self::MAX_LIMIT, $lim));

        $this->feed->projectDurableEvents($tenantId, (int) $campaign['id'], (string) $campaign['campaign_uuid']);
        $events = $this->feed->listEvents(
            $tenantId,
            (string) $campaign['campaign_uuid'],
            $from,
            $to,
            $lim,
        );
        $head = $this->feed->sequenceHead($tenantId, (string) $campaign['campaign_uuid']);
        $nextCursor = $from;
        if ($events !== []) {
            $nextCursor = (int) $events[array_key_last($events)]['event_sequence'];
        }

        return [
            'ok' => true,
            'http' => 200,
            'data' => [
                'protocol_version' => KeepAliveProtocol::PROTOCOL_VERSION,
                'schema_version' => KeepAliveProtocol::SCHEMA_VERSION,
                'tenant_id' => $tenantId,
                'node_id' => $nodeId,
                'assignment_generation' => $gate['assignment_generation'],
                'campaign_id' => (string) $campaign['campaign_uuid'],
                'campaign_uuid' => (string) $campaign['campaign_uuid'],
                'from_sequence' => $from,
                'to_sequence' => $to,
                'limit' => $lim,
                'next_cursor' => $nextCursor,
                'event_sequence_head' => (int) $head['last_sequence'],
                'payload_version' => KeepAliveEventTypes::PAYLOAD_VERSION,
                'events' => $events,
            ],
        ];
    }

    /**
     * @return array{ok: bool, http?: int, error?: string, errors?: list<string>, tenant_id?: string, node?: array<string, mixed>, campaign?: array<string, mixed>, assignment_generation?: int}
     */
    private function authorize(
        SaClientApiKey $clientKey,
        string $campaignId,
        string $nodeId,
        mixed $assignmentGeneration,
        mixed $clientTenantId,
    ): array {
        if (! $this->nodes->isReady() || ! $this->feed->isReady()) {
            return ['ok' => false, 'http' => 503, 'error' => 'schema_unavailable', 'errors' => ['schema_unavailable']];
        }

        $tenant = $this->registration->resolveTenantId(
            (string) $clientKey->installation_id,
            $clientTenantId,
        );
        if (! ($tenant['ok'] ?? false)) {
            $errors = $tenant['errors'] ?? [KeepAliveRegistrationPolicy::ERROR_TENANT_MISMATCH];

            return ['ok' => false, 'http' => 403, 'error' => $errors[0], 'errors' => $errors];
        }
        $tenantId = (string) $tenant['tenant_id'];

        $nodeId = trim($nodeId);
        if ($nodeId === '') {
            return [
                'ok' => false,
                'http' => 422,
                'error' => KeepAliveAccessPolicy::ERROR_NODE_ID_REQUIRED,
                'errors' => [KeepAliveAccessPolicy::ERROR_NODE_ID_REQUIRED],
            ];
        }

        $campaignUuid = trim($campaignId);
        if ($campaignUuid === '') {
            return [
                'ok' => false,
                'http' => 422,
                'error' => KeepAliveAccessPolicy::ERROR_CAMPAIGN_NOT_FOUND,
                'errors' => [KeepAliveAccessPolicy::ERROR_CAMPAIGN_NOT_FOUND],
            ];
        }

        $gen = $this->parseNonNegativeInt($assignmentGeneration);
        $node = $this->nodes->findNode($tenantId, $nodeId);
        $assignment = $this->feed->findAssignment($tenantId, $nodeId, $campaignUuid);
        $campaign = $this->nodes->findCampaignByUuid($campaignUuid, $tenantId);

        $assert = $this->access->assertSnapshotAccess(
            $node,
            $assignment,
            $campaign,
            (int) $clientKey->id,
            $tenantId,
            $gen,
        );
        if (! ($assert['ok'] ?? false)) {
            $errors = $assert['errors'] ?? ['forbidden'];

            return [
                'ok' => false,
                'http' => (int) ($assert['http'] ?? 403),
                'error' => $errors[0],
                'errors' => $errors,
            ];
        }

        return [
            'ok' => true,
            'tenant_id' => $tenantId,
            'node' => $node,
            'campaign' => $campaign,
            'assignment_generation' => (int) ($node['assignment_generation'] ?? 0),
        ];
    }

    private function parseNonNegativeInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        if (is_numeric($value) && (int) $value >= 0 && (string) (int) $value === (string) $value) {
            return (int) $value;
        }

        return null;
    }
}
