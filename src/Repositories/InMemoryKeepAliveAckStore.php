<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Repositories;

use Omnichannel\Addons\SocialAutomation\Contracts\KeepAliveAckStoreContract;

final class InMemoryKeepAliveAckStore implements KeepAliveAckStoreContract
{
    private InMemoryKeepAliveFeedStore $feed;

    /** @var array<string, array{acked_through_sequence: int, acked_event_count: int, last_acked_at: string|null}> */
    private array $cursors = [];

    /** @var array<string, array<string, mixed>> */
    private array $artifactAcks = [];

    private bool $ready = true;

    private bool $artifactUnsupported = false;

    public function __construct(?InMemoryKeepAliveFeedStore $feed = null)
    {
        $this->feed = $feed ?? new InMemoryKeepAliveFeedStore;
    }

    public function feed(): InMemoryKeepAliveFeedStore
    {
        return $this->feed;
    }

    public function setReady(bool $ready): void
    {
        $this->ready = $ready;
    }

    public function setArtifactUnsupported(bool $unsupported): void
    {
        $this->artifactUnsupported = $unsupported;
    }

    public function isReady(): bool
    {
        return $this->ready && $this->feed->isReady();
    }

    public function getEventAckCursor(string $installationId, string $nodeId, string $campaignUuid): array
    {
        $key = $this->cursorKey($installationId, $nodeId, $campaignUuid);

        return $this->cursors[$key] ?? [
            'acked_through_sequence' => 0,
            'acked_event_count' => 0,
            'last_acked_at' => null,
        ];
    }

    public function saveEventAckCursor(
        string $installationId,
        string $nodeId,
        int $campaignId,
        string $campaignUuid,
        int $ackedThroughSequence,
        int $ackedEventCountTotal,
    ): void {
        $key = $this->cursorKey($installationId, $nodeId, $campaignUuid);
        $this->cursors[$key] = [
            'acked_through_sequence' => $ackedThroughSequence,
            'acked_event_count' => $ackedEventCountTotal,
            'last_acked_at' => gmdate('c'),
            'campaign_id' => $campaignId,
        ];
    }

    public function findFeedEventsByIds(string $installationId, string $campaignUuid, array $eventIds): array
    {
        return $this->feed->findEventsByIds($installationId, $campaignUuid, $eventIds);
    }

    public function findVerifiedScreenshotArtifact(
        string $installationId,
        string $campaignUuid,
        string $jobUuid,
        ?int $attemptNo,
    ): ?array {
        return $this->feed->findScreenshotArtifact($installationId, $campaignUuid, $jobUuid, $attemptNo);
    }

    public function artifactVerificationUnsupported(): bool
    {
        return $this->artifactUnsupported;
    }

    public function getArtifactAck(
        string $installationId,
        string $nodeId,
        string $campaignUuid,
        string $jobUuid,
        ?int $attemptNo,
        int $artifactId,
    ): array {
        $key = $this->artifactKey($installationId, $nodeId, $campaignUuid, $jobUuid, $attemptNo, $artifactId);
        if (! isset($this->artifactAcks[$key])) {
            return ['already_acked' => false, 'acked_at' => null];
        }

        return [
            'already_acked' => true,
            'acked_at' => (string) ($this->artifactAcks[$key]['acked_at'] ?? null),
        ];
    }

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
    ): void {
        $key = $this->artifactKey($installationId, $nodeId, $campaignUuid, $jobUuid, $attemptNo, $artifactId);
        $this->artifactAcks[$key] = [
            'installation_id' => $installationId,
            'node_id' => $nodeId,
            'campaign_id' => $campaignId,
            'campaign_uuid' => $campaignUuid,
            'job_id' => $jobUuid,
            'attempt' => $attemptNo,
            'artifact_id' => $artifactId,
            'artifact_uuid' => $artifactUuid,
            'storage_key' => $storageKey,
            'sha256' => $sha256,
            'acked_at' => gmdate('c'),
        ];
    }

    public function jobClaimInvocations(): int
    {
        return $this->feed->jobClaimInvocations();
    }

    private function cursorKey(string $installationId, string $nodeId, string $campaignUuid): string
    {
        return $installationId.'|'.$nodeId.'|'.$campaignUuid;
    }

    private function artifactKey(
        string $installationId,
        string $nodeId,
        string $campaignUuid,
        string $jobUuid,
        ?int $attemptNo,
        int $artifactId,
    ): string {
        return $installationId.'|'.$nodeId.'|'.$campaignUuid.'|'.$jobUuid.'|'.($attemptNo ?? 'null').'|'.$artifactId;
    }
}
