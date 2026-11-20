<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Contracts;

/**
 * Persistence for Keep Alive Recovery Node delivery ACKs (not Job execution ACKs).
 *
 * @phpstan-type EventAckCursor array{
 *   acked_through_sequence: int,
 *   acked_event_count: int,
 *   last_acked_at: string|null
 * }
 * @phpstan-type ResolvedFeedEvent array{
 *   event_id: string,
 *   campaign_id: string,
 *   event_sequence: int
 * }
 * @phpstan-type VerifiedArtifact array{
 *   artifact_id: int,
 *   artifact_uuid: string,
 *   job_id: string,
 *   attempt: int|null,
 *   storage_path: string,
 *   checksum_sha256: string,
 *   kind: string
 * }
 */
interface KeepAliveAckStoreContract
{
    public function isReady(): bool;

    /**
     * @return EventAckCursor
     */
    public function getEventAckCursor(string $installationId, string $nodeId, string $campaignUuid): array;

    public function saveEventAckCursor(
        string $installationId,
        string $nodeId,
        int $campaignId,
        string $campaignUuid,
        int $ackedThroughSequence,
        int $ackedEventCountTotal,
    ): void;

    /**
     * @param  list<string>  $eventIds
     * @return list<ResolvedFeedEvent>
     */
    public function findFeedEventsByIds(string $installationId, string $campaignUuid, array $eventIds): array;

    /**
     * @return VerifiedArtifact|null null when no authoritative screenshot record exists
     */
    public function findVerifiedScreenshotArtifact(
        string $installationId,
        string $campaignUuid,
        string $jobUuid,
        ?int $attemptNo,
    ): ?array;

    /**
     * True when artifact tables/schema cannot support verification.
     */
    public function artifactVerificationUnsupported(): bool;

    /**
     * @return array{already_acked: bool, acked_at: string|null}
     */
    public function getArtifactAck(
        string $installationId,
        string $nodeId,
        string $campaignUuid,
        string $jobUuid,
        ?int $attemptNo,
        int $artifactId,
    ): array;

    public function saveArtifactAck(
        string $installationId,
        string $nodeId,
        int $campaignId,
        string $campaignUuid,
        string $jobUuid,
        ?int $attemptNo,
        int $artifactId,
        string $artifactUuid,
        string $storageKey,
        string $sha256,
    ): void;

    public function jobClaimInvocations(): int;
}
