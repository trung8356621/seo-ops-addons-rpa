<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Repositories;

use Omnichannel\Addons\SocialAutomation\Contracts\KeepAliveFeedStoreContract;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveEventTypes;
use Omnichannel\Addons\SocialAutomation\Support\JobObservability;

/**
 * In-memory Keep Alive feed for unit tests (no LIVE DB).
 */
final class InMemoryKeepAliveFeedStore implements KeepAliveFeedStoreContract
{
    private InMemoryKeepAliveStore $nodes;

    /** @var array<int, array<string, mixed>> */
    private array $jobs = [];

    /** @var array<int, array<string, mixed>> */
    private array $jobEvents = [];

    /** @var array<int, array<string, mixed>> */
    private array $artifacts = [];

    /** @var array<int, array<string, mixed>> */
    private array $contents = [];

    /** @var array<string, array{last_sequence: int, snapshot_version: int}> */
    private array $sequences = [];

    /** @var array<int, array<string, mixed>> */
    private array $feedEvents = [];

    private int $jobEventSeq = 1;

    private int $artifactSeq = 1;

    private int $feedEventSeq = 1;

    private bool $ready = true;

    private JobObservability $redactor;

    public function __construct(?InMemoryKeepAliveStore $nodes = null)
    {
        $this->nodes = $nodes ?? new InMemoryKeepAliveStore;
        $this->redactor = new JobObservability;
    }

    public function nodes(): InMemoryKeepAliveStore
    {
        return $this->nodes;
    }

    public function setReady(bool $ready): void
    {
        $this->ready = $ready;
        $this->nodes->setReady($ready);
    }

    public function isReady(): bool
    {
        return $this->ready && $this->nodes->isReady();
    }

    /**
     * @param  array<string, mixed>  $job
     */
    public function seedJob(array $job): void
    {
        $this->jobs[(int) $job['id']] = $job;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    public function seedContent(array $content): void
    {
        $this->contents[(int) $content['id']] = $content;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    public function seedJobEvent(array $attrs): int
    {
        $id = $this->jobEventSeq++;
        $this->jobEvents[$id] = array_merge($attrs, ['id' => $id]);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    public function seedArtifact(array $attrs): int
    {
        $id = $this->artifactSeq++;
        $this->artifacts[$id] = array_merge($attrs, ['id' => $id]);

        return $id;
    }

    public function findAssignment(string $installationId, string $nodeId, string $campaignUuid): ?array
    {
        foreach ($this->nodes->rawAssignments() as $row) {
            if ((string) $row['installation_id'] === $installationId
                && (string) $row['node_id'] === $nodeId
                && (string) $row['campaign_uuid'] === $campaignUuid) {
                return $row;
            }
        }

        return null;
    }

    public function revokeAssignment(string $installationId, string $nodeId, string $campaignUuid): bool
    {
        $ok = $this->nodes->revokeAssignment($installationId, $nodeId, $campaignUuid);
        if ($ok) {
            $this->nodes->bumpAssignmentGeneration($installationId, $nodeId);
        }

        return $ok;
    }

    public function revokeNode(string $installationId, string $nodeId): bool
    {
        return $this->nodes->revokeNode($installationId, $nodeId);
    }

    public function projectDurableEvents(string $installationId, int $campaignId, string $campaignUuid): array
    {
        $key = $installationId.'|'.$campaignUuid;
        if (! isset($this->sequences[$key])) {
            $this->sequences[$key] = ['last_sequence' => 0, 'snapshot_version' => 0];
        }

        $projected = 0;
        $candidates = [];

        foreach ($this->jobEvents as $ev) {
            $job = $this->jobs[(int) ($ev['job_id'] ?? 0)] ?? null;
            if ($job === null || (int) ($job['campaign_id'] ?? 0) !== $campaignId) {
                continue;
            }
            $type = KeepAliveEventTypes::fromJobEvent(
                (string) ($ev['event_type'] ?? ''),
                isset($ev['to_status']) ? (string) $ev['to_status'] : null,
            );
            if ($type === null) {
                continue;
            }
            $candidates[] = [
                'source_kind' => 'job_event',
                'source_id' => (int) $ev['id'],
                'event_type' => $type,
                'job_id' => (string) ($job['job_uuid'] ?? ''),
                'attempt' => isset($ev['attempt_no']) ? (int) $ev['attempt_no'] : (isset($job['attempt_no']) ? (int) $job['attempt_no'] : null),
                'fencing_generation' => (int) ($job['fencing_generation'] ?? 0),
                'occurred_at' => (string) ($ev['occurred_at'] ?? gmdate('c')),
                'payload' => null,
                'sort' => [(string) ($ev['occurred_at'] ?? ''), (int) $ev['id']],
            ];
        }

        foreach ($this->artifacts as $art) {
            if (($art['kind'] ?? '') !== 'screenshot') {
                continue;
            }
            $job = $this->jobs[(int) ($art['job_id'] ?? 0)] ?? null;
            if ($job === null || (int) ($job['campaign_id'] ?? 0) !== $campaignId) {
                continue;
            }
            $candidates[] = [
                'source_kind' => 'artifact',
                'source_id' => (int) $art['id'],
                'event_type' => KeepAliveEventTypes::SCREENSHOT_UPLOADED,
                'job_id' => (string) ($job['job_uuid'] ?? ''),
                'attempt' => isset($art['attempt_no']) ? (int) $art['attempt_no'] : null,
                'fencing_generation' => (int) ($job['fencing_generation'] ?? 0),
                'occurred_at' => (string) ($art['created_at'] ?? gmdate('c')),
                'payload' => $this->redactor->redactArray([
                    'artifact_uuid' => $art['artifact_uuid'] ?? null,
                    'kind' => 'screenshot',
                    'mime' => $art['mime'] ?? null,
                ]),
                'sort' => [(string) ($art['created_at'] ?? ''), (int) $art['id']],
            ];
        }

        usort($candidates, static function (array $a, array $b): int {
            return $a['sort'] <=> $b['sort'];
        });

        foreach ($candidates as $c) {
            if ($this->hasSource($c['source_kind'], $c['source_id'])) {
                continue;
            }
            $seq = $this->sequences[$key]['last_sequence'] + 1;
            $this->sequences[$key]['last_sequence'] = $seq;
            $this->sequences[$key]['snapshot_version'] = $seq;
            $id = $this->feedEventSeq++;
            $this->feedEvents[$id] = [
                'id' => $id,
                'event_id' => sprintf('kev_%s_%d', $c['source_kind'], $c['source_id']),
                'installation_id' => $installationId,
                'campaign_id' => $campaignId,
                'campaign_uuid' => $campaignUuid,
                'job_id' => $c['job_id'],
                'attempt' => $c['attempt'],
                'fencing_generation' => $c['fencing_generation'],
                'event_sequence' => $seq,
                'occurred_at' => $c['occurred_at'],
                'payload_version' => KeepAliveEventTypes::PAYLOAD_VERSION,
                'event_type' => $c['event_type'],
                'source_kind' => $c['source_kind'],
                'source_id' => $c['source_id'],
                'payload' => $c['payload'],
            ];
            $projected++;
        }

        // Meta-only snapshot bump when campaign/contents exist but no new events.
        if ($projected === 0 && $this->sequences[$key]['snapshot_version'] === 0) {
            $this->sequences[$key]['snapshot_version'] = 0;
        }

        return [
            'last_sequence' => $this->sequences[$key]['last_sequence'],
            'snapshot_version' => $this->sequences[$key]['snapshot_version'],
            'projected' => $projected,
        ];
    }

    public function listEvents(
        string $installationId,
        string $campaignUuid,
        int $fromSequenceExclusive,
        ?int $toSequenceInclusive,
        int $limit,
    ): array {
        $out = [];
        foreach ($this->feedEvents as $row) {
            if ((string) $row['installation_id'] !== $installationId
                || (string) $row['campaign_uuid'] !== $campaignUuid) {
                continue;
            }
            $seq = (int) $row['event_sequence'];
            if ($seq <= $fromSequenceExclusive) {
                continue;
            }
            if ($toSequenceInclusive !== null && $seq > $toSequenceInclusive) {
                continue;
            }
            $out[] = $this->serializeFeedEvent($row);
        }
        usort($out, static fn (array $a, array $b): int => $a['event_sequence'] <=> $b['event_sequence']);

        return array_slice($out, 0, max(1, $limit));
    }

    public function sequenceHead(string $installationId, string $campaignUuid): array
    {
        $key = $installationId.'|'.$campaignUuid;
        $row = $this->sequences[$key] ?? ['last_sequence' => 0, 'snapshot_version' => 0];

        return [
            'last_sequence' => (int) $row['last_sequence'],
            'snapshot_version' => (int) $row['snapshot_version'],
        ];
    }

    public function buildSanitizedSnapshot(string $installationId, string $campaignUuid): ?array
    {
        $campaign = $this->nodes->findCampaignByUuid($campaignUuid, $installationId);
        if ($campaign === null) {
            return null;
        }
        $campaignId = (int) $campaign['id'];

        $contents = [];
        $contentCounts = [];
        foreach ($this->contents as $c) {
            if ((int) ($c['campaign_id'] ?? 0) !== $campaignId) {
                continue;
            }
            $status = (string) ($c['status'] ?? 'unknown');
            $contentCounts[$status] = ($contentCounts[$status] ?? 0) + 1;
            $contents[] = [
                'content_id' => (string) ($c['content_uuid'] ?? $c['id']),
                'status' => $status,
                'sort_order' => (int) ($c['sort_order'] ?? 0),
            ];
        }

        $jobs = [];
        $jobCounts = [];
        foreach ($this->jobs as $j) {
            if ((int) ($j['campaign_id'] ?? 0) !== $campaignId) {
                continue;
            }
            $status = (string) ($j['status'] ?? 'unknown');
            $jobCounts[$status] = ($jobCounts[$status] ?? 0) + 1;
            $jobs[] = [
                'job_id' => (string) ($j['job_uuid'] ?? ''),
                'status' => $status,
                'fencing_generation' => (int) ($j['fencing_generation'] ?? 0),
                'attempt' => isset($j['attempt_no']) ? (int) $j['attempt_no'] : null,
                'content_id' => isset($j['content_uuid']) ? (string) $j['content_uuid'] : null,
                'reserved_at' => $j['reserved_at'] ?? null,
                'claimed_at' => $j['claimed_at'] ?? null,
                'started_at' => $j['started_at'] ?? null,
                'finished_at' => $j['finished_at'] ?? null,
                'error_code' => $j['error_code'] ?? null,
            ];
            // Explicitly never include lease_token / cookies / passwords
        }

        return [
            'campaign' => [
                'campaign_id' => $campaign['campaign_uuid'],
                'campaign_uuid' => $campaign['campaign_uuid'],
                'name' => $campaign['name'],
                'status' => $campaign['status'],
                'platform' => $campaign['platform'],
                'execution_mode' => $campaign['execution_mode'] ?? null,
                'run_mode' => $campaign['run_mode'] ?? null,
                'script_id' => $campaign['script_id'] ?? null,
                'script_version' => $campaign['script_version'] ?? null,
            ],
            'contents' => $contents,
            'jobs' => $jobs,
            'content_counts' => $contentCounts,
            'job_counts' => $jobCounts,
        ];
    }

    public function jobClaimInvocations(): int
    {
        return $this->nodes->jobClaimInvocations();
    }

    /**
     * @param  list<string>  $eventIds
     * @return list<array{event_id: string, campaign_id: string, event_sequence: int}>
     */
    public function findEventsByIds(string $installationId, string $campaignUuid, array $eventIds): array
    {
        $wanted = array_fill_keys($eventIds, true);
        $out = [];
        foreach ($this->feedEvents as $row) {
            $id = (string) $row['event_id'];
            if (! isset($wanted[$id])) {
                continue;
            }
            if ((string) $row['installation_id'] !== $installationId
                || (string) $row['campaign_uuid'] !== $campaignUuid) {
                continue;
            }
            $out[] = [
                'event_id' => $id,
                'campaign_id' => (string) $row['campaign_uuid'],
                'event_sequence' => (int) $row['event_sequence'],
            ];
        }

        return $out;
    }

    /**
     * @return array{
     *   artifact_id: int,
     *   artifact_uuid: string,
     *   job_id: string,
     *   attempt: int|null,
     *   storage_path: string,
     *   checksum_sha256: string,
     *   kind: string
     * }|null
     */
    public function findScreenshotArtifact(
        string $installationId,
        string $campaignUuid,
        string $jobUuid,
        ?int $attemptNo,
    ): ?array {
        $campaign = $this->nodes->findCampaignByUuid($campaignUuid, $installationId);
        if ($campaign === null) {
            return null;
        }
        $campaignId = (int) $campaign['id'];
        $job = null;
        foreach ($this->jobs as $j) {
            if ((string) ($j['job_uuid'] ?? '') === $jobUuid && (int) ($j['campaign_id'] ?? 0) === $campaignId) {
                $job = $j;
                break;
            }
        }
        if ($job === null) {
            return null;
        }

        foreach ($this->artifacts as $art) {
            if ((int) ($art['job_id'] ?? 0) !== (int) $job['id']) {
                continue;
            }
            if (($art['kind'] ?? '') !== 'screenshot') {
                continue;
            }
            if ($attemptNo !== null && isset($art['attempt_no']) && (int) $art['attempt_no'] !== $attemptNo) {
                continue;
            }
            $sha = strtolower(trim((string) ($art['checksum_sha256'] ?? '')));
            if ($sha === '') {
                return null; // exists but not verifiable
            }

            return [
                'artifact_id' => (int) $art['id'],
                'artifact_uuid' => (string) ($art['artifact_uuid'] ?? ''),
                'job_id' => $jobUuid,
                'attempt' => isset($art['attempt_no']) ? (int) $art['attempt_no'] : $attemptNo,
                'storage_path' => (string) ($art['storage_path'] ?? ''),
                'checksum_sha256' => $sha,
                'kind' => 'screenshot',
            ];
        }

        return null;
    }

    private function hasSource(string $kind, int $sourceId): bool
    {
        foreach ($this->feedEvents as $row) {
            if ((string) $row['source_kind'] === $kind && (int) $row['source_id'] === $sourceId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function serializeFeedEvent(array $row): array
    {
        return [
            'event_id' => (string) $row['event_id'],
            'tenant_id' => (string) $row['installation_id'],
            'campaign_id' => (string) $row['campaign_uuid'],
            'job_id' => $row['job_id'] !== null && $row['job_id'] !== '' ? (string) $row['job_id'] : null,
            'attempt' => $row['attempt'] !== null ? (int) $row['attempt'] : null,
            'fencing_generation' => (int) $row['fencing_generation'],
            'event_sequence' => (int) $row['event_sequence'],
            'timestamp' => (string) $row['occurred_at'],
            'payload_version' => (int) $row['payload_version'],
            'event_type' => (string) $row['event_type'],
            'payload' => is_array($row['payload'] ?? null) ? $row['payload'] : null,
        ];
    }
}
