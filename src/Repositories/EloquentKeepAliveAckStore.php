<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Repositories;

use Omnichannel\Addons\SocialAutomation\Contracts\KeepAliveAckStoreContract;
use Omnichannel\Addons\SocialAutomation\Models\SaArtifact;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaJobAttempt;
use Omnichannel\Addons\SocialAutomation\Models\SaKeepAliveArtifactAck;
use Omnichannel\Addons\SocialAutomation\Models\SaKeepAliveCampaignEvent;
use Omnichannel\Addons\SocialAutomation\Models\SaKeepAliveEventAckCursor;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;

final class EloquentKeepAliveAckStore implements KeepAliveAckStoreContract
{
    public function isReady(): bool
    {
        return SocialAutomationSchema::keepaliveAckReady();
    }

    public function getEventAckCursor(string $installationId, string $nodeId, string $campaignUuid): array
    {
        $row = SaKeepAliveEventAckCursor::query()
            ->where('installation_id', $installationId)
            ->where('node_id', $nodeId)
            ->where('campaign_uuid', $campaignUuid)
            ->first();

        if (! $row instanceof SaKeepAliveEventAckCursor) {
            return [
                'acked_through_sequence' => 0,
                'acked_event_count' => 0,
                'last_acked_at' => null,
            ];
        }

        return [
            'acked_through_sequence' => (int) $row->acked_through_sequence,
            'acked_event_count' => (int) $row->acked_event_count,
            'last_acked_at' => $row->last_acked_at?->toIso8601String(),
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
        $row = SaKeepAliveEventAckCursor::query()
            ->where('installation_id', $installationId)
            ->where('node_id', $nodeId)
            ->where('campaign_id', $campaignId)
            ->first();

        $attrs = [
            'installation_id' => $installationId,
            'node_id' => $nodeId,
            'campaign_id' => $campaignId,
            'campaign_uuid' => $campaignUuid,
            'acked_through_sequence' => $ackedThroughSequence,
            'acked_event_count' => $ackedEventCountTotal,
            'last_acked_at' => now(),
        ];

        if ($row instanceof SaKeepAliveEventAckCursor) {
            $row->fill($attrs)->save();
        } else {
            SaKeepAliveEventAckCursor::query()->create($attrs);
        }
    }

    public function findFeedEventsByIds(string $installationId, string $campaignUuid, array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $rows = SaKeepAliveCampaignEvent::query()
            ->where('installation_id', $installationId)
            ->where('campaign_uuid', $campaignUuid)
            ->whereIn('event_id', $eventIds)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            if (! $row instanceof SaKeepAliveCampaignEvent) {
                continue;
            }
            $out[] = [
                'event_id' => (string) $row->event_id,
                'campaign_id' => (string) $row->campaign_uuid,
                'event_sequence' => (int) $row->event_sequence,
            ];
        }

        return $out;
    }

    public function findVerifiedScreenshotArtifact(
        string $installationId,
        string $campaignUuid,
        string $jobUuid,
        ?int $attemptNo,
    ): ?array {
        if ($this->artifactVerificationUnsupported()) {
            return null;
        }

        $campaign = SaCampaign::query()
            ->where('campaign_uuid', $campaignUuid)
            ->where('installation_id', $installationId)
            ->first();
        if (! $campaign instanceof SaCampaign) {
            return null;
        }

        $job = SaJob::query()
            ->where('job_uuid', $jobUuid)
            ->where('campaign_id', $campaign->id)
            ->first();
        if (! $job instanceof SaJob) {
            return null;
        }

        $q = SaArtifact::query()
            ->where('job_id', $job->id)
            ->where('kind', 'screenshot');

        if ($attemptNo !== null) {
            $attempt = SaJobAttempt::query()
                ->where('job_id', $job->id)
                ->where('attempt_no', $attemptNo)
                ->first();
            if ($attempt instanceof SaJobAttempt) {
                $q->where(function ($inner) use ($attempt, $attemptNo): void {
                    $inner->where('attempt_id', $attempt->id)
                        ->orWhereNull('attempt_id');
                });
            }
        }

        $artifact = $q->orderByDesc('id')->first();
        if (! $artifact instanceof SaArtifact) {
            return null;
        }

        $sha = strtolower(trim((string) ($artifact->checksum_sha256 ?? '')));
        if ($sha === '' || ! preg_match('/^[a-f0-9]{64}$/', $sha)) {
            return null;
        }

        $resolvedAttempt = $attemptNo;
        if ($artifact->attempt_id) {
            $att = SaJobAttempt::query()->find($artifact->attempt_id);
            if ($att instanceof SaJobAttempt) {
                $resolvedAttempt = (int) $att->attempt_no;
            }
        }

        return [
            'artifact_id' => (int) $artifact->id,
            'artifact_uuid' => (string) $artifact->artifact_uuid,
            'job_id' => $jobUuid,
            'attempt' => $resolvedAttempt,
            'storage_path' => (string) ($artifact->storage_path ?? ''),
            'checksum_sha256' => $sha,
            'kind' => 'screenshot',
        ];
    }

    public function artifactVerificationUnsupported(): bool
    {
        return ! SocialAutomationSchema::has('sa_artifacts');
    }

    public function getArtifactAck(
        string $installationId,
        string $nodeId,
        string $campaignUuid,
        string $jobUuid,
        ?int $attemptNo,
        int $artifactId,
    ): array {
        $q = SaKeepAliveArtifactAck::query()
            ->where('installation_id', $installationId)
            ->where('node_id', $nodeId)
            ->where('campaign_uuid', $campaignUuid)
            ->where('job_id', $jobUuid)
            ->where('artifact_id', $artifactId);
        if ($attemptNo === null) {
            $q->whereNull('attempt');
        } else {
            $q->where('attempt', $attemptNo);
        }
        $row = $q->first();
        if (! $row instanceof SaKeepAliveArtifactAck) {
            return ['already_acked' => false, 'acked_at' => null];
        }

        return [
            'already_acked' => true,
            'acked_at' => $row->acked_at?->toIso8601String(),
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
        $existing = SaKeepAliveArtifactAck::query()
            ->where('installation_id', $installationId)
            ->where('node_id', $nodeId)
            ->where('campaign_id', $campaignId)
            ->where('job_id', $jobUuid)
            ->where('artifact_id', $artifactId)
            ->when(
                $attemptNo === null,
                static fn ($q) => $q->whereNull('attempt'),
                static fn ($q) => $q->where('attempt', $attemptNo),
            )
            ->first();

        $attrs = [
            'installation_id' => $installationId,
            'node_id' => $nodeId,
            'campaign_id' => $campaignId,
            'campaign_uuid' => $campaignUuid,
            'job_id' => $jobUuid,
            'attempt' => $attemptNo,
            'artifact_id' => $artifactId,
            'artifact_uuid' => $artifactUuid,
            'storage_key' => $storageKey,
            'sha256' => strtolower($sha256),
            'acked_at' => now(),
        ];

        if ($existing instanceof SaKeepAliveArtifactAck) {
            $existing->fill($attrs)->save();
        } else {
            SaKeepAliveArtifactAck::query()->create($attrs);
        }
    }

    public function jobClaimInvocations(): int
    {
        return 0;
    }
}
