<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Omnichannel\Addons\SocialAutomation\Contracts\KeepAliveAckStoreContract;
use Omnichannel\Addons\SocialAutomation\Contracts\KeepAliveFeedStoreContract;
use Omnichannel\Addons\SocialAutomation\Contracts\KeepAliveStoreContract;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveAccessPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveAckPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveProtocol;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveRegistrationPolicy;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;

/**
 * Durable delivery ACK for Keep Alive Recovery Nodes.
 * Not Job Claim/ACK/Result — never mutates Job execution state.
 */
final class KeepAliveAckService
{
    public function __construct(
        private readonly KeepAliveStoreContract $nodes,
        private readonly KeepAliveFeedStoreContract $feed,
        private readonly KeepAliveAckStoreContract $acks,
        private readonly KeepAliveRegistrationPolicy $registration = new KeepAliveRegistrationPolicy,
        private readonly KeepAliveAccessPolicy $access = new KeepAliveAccessPolicy,
        private readonly KeepAliveAckPolicy $policy = new KeepAliveAckPolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, http?: int, error?: string, errors?: list<string>, data?: array<string, mixed>}
     */
    public function ackEvents(SaClientApiKey $clientKey, array $payload): array
    {
        $gate = $this->authorize(
            $clientKey,
            (string) ($payload['campaign_id'] ?? $payload['campaign_uuid'] ?? ''),
            (string) ($payload['node_id'] ?? ''),
            $payload['assignment_generation'] ?? null,
            $payload['tenant_id'] ?? null,
        );
        if (! ($gate['ok'] ?? false)) {
            return $gate;
        }
        if (! $this->acks->isReady()) {
            return ['ok' => false, 'http' => 503, 'error' => 'schema_unavailable', 'errors' => ['schema_unavailable']];
        }

        /** @var array{tenant_id: string, campaign: array<string, mixed>, assignment_generation: int} $gate */
        $tenantId = $gate['tenant_id'];
        $nodeId = trim((string) ($payload['node_id'] ?? ''));
        $campaign = $gate['campaign'];
        $campaignUuid = (string) $campaign['campaign_uuid'];

        $lastContiguous = $this->parseNonNegativeInt($payload['last_contiguous_sequence'] ?? null);
        if ($lastContiguous === null) {
            return [
                'ok' => false,
                'http' => 422,
                'error' => KeepAliveAckPolicy::ERROR_LAST_CONTIGUOUS_REQUIRED,
                'errors' => [KeepAliveAckPolicy::ERROR_LAST_CONTIGUOUS_REQUIRED],
            ];
        }

        $rawIds = $payload['event_ids'] ?? [];
        if (! is_array($rawIds)) {
            return [
                'ok' => false,
                'http' => 422,
                'error' => KeepAliveAckPolicy::ERROR_EVENT_IDS_INVALID,
                'errors' => [KeepAliveAckPolicy::ERROR_EVENT_IDS_INVALID],
            ];
        }
        $eventIds = [];
        foreach ($rawIds as $id) {
            if (! is_string($id) && ! is_int($id)) {
                return [
                    'ok' => false,
                    'http' => 422,
                    'error' => KeepAliveAckPolicy::ERROR_EVENT_IDS_INVALID,
                    'errors' => [KeepAliveAckPolicy::ERROR_EVENT_IDS_INVALID],
                ];
            }
            $trimmed = trim((string) $id);
            if ($trimmed === '') {
                return [
                    'ok' => false,
                    'http' => 422,
                    'error' => KeepAliveAckPolicy::ERROR_EVENT_IDS_INVALID,
                    'errors' => [KeepAliveAckPolicy::ERROR_EVENT_IDS_INVALID],
                ];
            }
            $eventIds[] = $trimmed;
        }
        $eventIds = array_values(array_unique($eventIds));

        $this->feed->projectDurableEvents($tenantId, (int) $campaign['id'], $campaignUuid);
        $head = $this->feed->sequenceHead($tenantId, $campaignUuid);
        $cursor = $this->acks->getEventAckCursor($tenantId, $nodeId, $campaignUuid);
        $resolved = $this->acks->findFeedEventsByIds($tenantId, $campaignUuid, $eventIds);

        $validated = $this->policy->validateContiguousEventAck(
            (int) $cursor['acked_through_sequence'],
            $lastContiguous,
            (int) $head['last_sequence'],
            $resolved,
            $eventIds,
            $campaignUuid,
        );
        if (! ($validated['ok'] ?? false)) {
            $errors = $validated['errors'] ?? [KeepAliveAckPolicy::ERROR_ACK_NON_CONTIGUOUS];

            return [
                'ok' => false,
                'http' => 422,
                'error' => $errors[0],
                'errors' => $errors,
            ];
        }

        $ackedThrough = (int) $validated['acked_through'];
        $delta = (int) ($validated['acked_count'] ?? 0);
        $totalCount = (int) $cursor['acked_event_count'] + $delta;
        if (! ($validated['idempotent'] ?? false) && $ackedThrough > (int) $cursor['acked_through_sequence']) {
            $this->acks->saveEventAckCursor(
                $tenantId,
                $nodeId,
                (int) $campaign['id'],
                $campaignUuid,
                $ackedThrough,
                $totalCount,
            );
        } else {
            $totalCount = (int) $cursor['acked_event_count'];
            $ackedThrough = (int) $cursor['acked_through_sequence'];
        }

        return [
            'ok' => true,
            'http' => 200,
            'data' => [
                'protocol_version' => KeepAliveProtocol::PROTOCOL_VERSION,
                'schema_version' => KeepAliveProtocol::SCHEMA_VERSION,
                'campaign_id' => $campaignUuid,
                'campaign_uuid' => $campaignUuid,
                'node_id' => $nodeId,
                'tenant_id' => $tenantId,
                'acked_through_sequence' => $ackedThrough,
                'acked_event_count' => $delta,
                'acked_event_count_total' => $totalCount,
                'assignment_generation' => $gate['assignment_generation'],
                'idempotent' => (bool) ($validated['idempotent'] ?? false),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, http?: int, error?: string, errors?: list<string>, data?: array<string, mixed>}
     */
    public function ackArtifact(SaClientApiKey $clientKey, array $payload): array
    {
        $gate = $this->authorize(
            $clientKey,
            (string) ($payload['campaign_id'] ?? $payload['campaign_uuid'] ?? ''),
            (string) ($payload['node_id'] ?? ''),
            $payload['assignment_generation'] ?? null,
            $payload['tenant_id'] ?? null,
        );
        if (! ($gate['ok'] ?? false)) {
            return $gate;
        }
        if (! $this->acks->isReady()) {
            return ['ok' => false, 'http' => 503, 'error' => 'schema_unavailable', 'errors' => ['schema_unavailable']];
        }

        /** @var array{tenant_id: string, campaign: array<string, mixed>, assignment_generation: int} $gate */
        $tenantId = $gate['tenant_id'];
        $nodeId = trim((string) ($payload['node_id'] ?? ''));
        $campaign = $gate['campaign'];
        $campaignUuid = (string) $campaign['campaign_uuid'];

        $jobId = trim((string) ($payload['job_id'] ?? ''));
        $storageKey = trim((string) ($payload['storage_key'] ?? ''));
        $sha256 = strtolower(trim((string) ($payload['sha256'] ?? '')));
        $attempt = $this->parseNonNegativeInt($payload['attempt'] ?? null);

        if ($jobId === '') {
            return [
                'ok' => false,
                'http' => 422,
                'error' => KeepAliveAckPolicy::ERROR_JOB_ID_REQUIRED,
                'errors' => [KeepAliveAckPolicy::ERROR_JOB_ID_REQUIRED],
            ];
        }
        if ($storageKey === '') {
            return [
                'ok' => false,
                'http' => 422,
                'error' => KeepAliveAckPolicy::ERROR_STORAGE_KEY_REQUIRED,
                'errors' => [KeepAliveAckPolicy::ERROR_STORAGE_KEY_REQUIRED],
            ];
        }
        if ($sha256 === '' || ! preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            return [
                'ok' => false,
                'http' => 422,
                'error' => KeepAliveAckPolicy::ERROR_SHA256_REQUIRED,
                'errors' => [KeepAliveAckPolicy::ERROR_SHA256_REQUIRED],
            ];
        }

        if ($this->acks->artifactVerificationUnsupported()) {
            return [
                'ok' => false,
                'http' => 422,
                'error' => KeepAliveAckPolicy::ERROR_ARTIFACT_UNSUPPORTED,
                'errors' => [KeepAliveAckPolicy::ERROR_ARTIFACT_UNSUPPORTED],
            ];
        }

        $artifact = $this->acks->findVerifiedScreenshotArtifact(
            $tenantId,
            $campaignUuid,
            $jobId,
            $attempt,
        );
        if ($artifact === null) {
            return [
                'ok' => false,
                'http' => 409,
                'error' => KeepAliveAckPolicy::ERROR_ARTIFACT_NOT_READY,
                'errors' => [KeepAliveAckPolicy::ERROR_ARTIFACT_NOT_READY],
            ];
        }

        if ((string) $artifact['job_id'] !== $jobId) {
            return [
                'ok' => false,
                'http' => 422,
                'error' => KeepAliveAckPolicy::ERROR_ARTIFACT_JOB_MISMATCH,
                'errors' => [KeepAliveAckPolicy::ERROR_ARTIFACT_JOB_MISMATCH],
            ];
        }

        if (! $this->policy->storageKeyMatches(
            $storageKey,
            (string) $artifact['storage_path'],
            (string) $artifact['artifact_uuid'],
        )) {
            return [
                'ok' => false,
                'http' => 422,
                'error' => KeepAliveAckPolicy::ERROR_ARTIFACT_STORAGE_KEY_MISMATCH,
                'errors' => [KeepAliveAckPolicy::ERROR_ARTIFACT_STORAGE_KEY_MISMATCH],
            ];
        }

        if (! $this->policy->checksumMatches($sha256, (string) $artifact['checksum_sha256'])) {
            return [
                'ok' => false,
                'http' => 422,
                'error' => KeepAliveAckPolicy::ERROR_ARTIFACT_CHECKSUM_MISMATCH,
                'errors' => [KeepAliveAckPolicy::ERROR_ARTIFACT_CHECKSUM_MISMATCH],
            ];
        }

        $existing = $this->acks->getArtifactAck(
            $tenantId,
            $nodeId,
            $campaignUuid,
            $jobId,
            $attempt,
            (int) $artifact['artifact_id'],
        );
        if (! ($existing['already_acked'] ?? false)) {
            $this->acks->saveArtifactAck(
                $tenantId,
                $nodeId,
                (int) $campaign['id'],
                $campaignUuid,
                $jobId,
                $attempt,
                (int) $artifact['artifact_id'],
                (string) $artifact['artifact_uuid'],
                $storageKey,
                $sha256,
            );
        }

        return [
            'ok' => true,
            'http' => 200,
            'data' => [
                'protocol_version' => KeepAliveProtocol::PROTOCOL_VERSION,
                'schema_version' => KeepAliveProtocol::SCHEMA_VERSION,
                'campaign_id' => $campaignUuid,
                'campaign_uuid' => $campaignUuid,
                'node_id' => $nodeId,
                'tenant_id' => $tenantId,
                'job_id' => $jobId,
                'attempt' => $attempt,
                'artifact_uuid' => (string) $artifact['artifact_uuid'],
                'assignment_generation' => $gate['assignment_generation'],
                'idempotent' => (bool) ($existing['already_acked'] ?? false),
                'verified' => true,
            ],
        ];
    }

    /**
     * @return array{ok: bool, http?: int, error?: string, errors?: list<string>, tenant_id?: string, campaign?: array<string, mixed>, assignment_generation?: int}
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
