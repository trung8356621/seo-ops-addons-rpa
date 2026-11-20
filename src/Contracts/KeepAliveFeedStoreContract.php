<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Contracts;

/**
 * Durable Keep Alive campaign event feed + snapshot projection.
 *
 * @phpstan-type FeedEventRow array{
 *   event_id: string,
 *   tenant_id: string,
 *   campaign_id: string,
 *   job_id: string|null,
 *   attempt: int|null,
 *   fencing_generation: int,
 *   event_sequence: int,
 *   timestamp: string,
 *   payload_version: int,
 *   event_type: string,
 *   payload?: array<string, mixed>|null
 * }
 * @phpstan-type SnapshotJobRow array{
 *   job_id: string,
 *   status: string,
 *   fencing_generation: int,
 *   attempt: int|null,
 *   content_id: string|null,
 *   reserved_at: string|null,
 *   claimed_at: string|null,
 *   started_at: string|null,
 *   finished_at: string|null,
 *   error_code: string|null
 * }
 * @phpstan-type SnapshotContentRow array{
 *   content_id: string,
 *   status: string,
 *   sort_order: int
 * }
 */
interface KeepAliveFeedStoreContract
{
    public function isReady(): bool;

    /**
     * @return array<string, mixed>|null
     */
    public function findAssignment(string $installationId, string $nodeId, string $campaignUuid): ?array;

    /**
     * Soft-revoke an explicit assignment (bumps node assignment_generation).
     */
    public function revokeAssignment(string $installationId, string $nodeId, string $campaignUuid): bool;

    /**
     * Soft-revoke a node (blocks further feed reads for that key/node).
     */
    public function revokeNode(string $installationId, string $nodeId): bool;

    /**
     * Project durable Job/Artifact history into sequenced Keep Alive events (idempotent).
     *
     * @return array{last_sequence: int, snapshot_version: int, projected: int}
     */
    public function projectDurableEvents(string $installationId, int $campaignId, string $campaignUuid): array;

    /**
     * @return list<FeedEventRow>
     */
    public function listEvents(
        string $installationId,
        string $campaignUuid,
        int $fromSequenceExclusive,
        ?int $toSequenceInclusive,
        int $limit,
    ): array;

    /**
     * @return array{last_sequence: int, snapshot_version: int}
     */
    public function sequenceHead(string $installationId, string $campaignUuid): array;

    /**
     * Sanitized campaign snapshot body (no secrets).
     *
     * @return array{
     *   campaign: array<string, mixed>,
     *   contents: list<SnapshotContentRow>,
     *   jobs: list<SnapshotJobRow>,
     *   content_counts: array<string, int>,
     *   job_counts: array<string, int>
     * }|null
     */
    public function buildSanitizedSnapshot(string $installationId, string $campaignUuid): ?array;

    public function jobClaimInvocations(): int;
}
