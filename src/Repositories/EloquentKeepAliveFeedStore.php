<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Contracts\KeepAliveFeedStoreContract;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveEventTypes;
use Omnichannel\Addons\SocialAutomation\Models\SaArtifact;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContent;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaJobAttempt;
use Omnichannel\Addons\SocialAutomation\Models\SaJobEvent;
use Omnichannel\Addons\SocialAutomation\Models\SaKeepAliveCampaignAssignment;
use Omnichannel\Addons\SocialAutomation\Models\SaKeepAliveCampaignEvent;
use Omnichannel\Addons\SocialAutomation\Models\SaKeepAliveCampaignSequence;
use Omnichannel\Addons\SocialAutomation\Models\SaKeepAliveNode;
use Omnichannel\Addons\SocialAutomation\Support\JobObservability;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

final class EloquentKeepAliveFeedStore implements KeepAliveFeedStoreContract
{
    public function __construct(
        private readonly JobObservability $redactor = new JobObservability,
    ) {}

    public function isReady(): bool
    {
        return SocialAutomationSchema::keepaliveFeedReady();
    }

    public function findAssignment(string $installationId, string $nodeId, string $campaignUuid): ?array
    {
        $row = SaKeepAliveCampaignAssignment::query()
            ->where('installation_id', $installationId)
            ->where('node_id', $nodeId)
            ->where('campaign_uuid', $campaignUuid)
            ->first();
        if (! $row instanceof SaKeepAliveCampaignAssignment) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'installation_id' => (string) $row->installation_id,
            'keepalive_node_id' => (int) $row->keepalive_node_id,
            'node_id' => (string) $row->node_id,
            'campaign_id' => (int) $row->campaign_id,
            'campaign_uuid' => (string) $row->campaign_uuid,
            'version' => (int) $row->version,
            'row_version' => (int) $row->version,
            'assigned_at' => $row->assigned_at?->toIso8601String(),
            'revoked_at' => $row->revoked_at?->toIso8601String(),
        ];
    }

    public function revokeAssignment(string $installationId, string $nodeId, string $campaignUuid): bool
    {
        $row = SaKeepAliveCampaignAssignment::query()
            ->where('installation_id', $installationId)
            ->where('node_id', $nodeId)
            ->where('campaign_uuid', $campaignUuid)
            ->whereNull('revoked_at')
            ->first();
        if (! $row instanceof SaKeepAliveCampaignAssignment) {
            return false;
        }
        $row->revoked_at = now();
        $row->save();

        $node = SaKeepAliveNode::query()
            ->where('installation_id', $installationId)
            ->where('node_id', $nodeId)
            ->first();
        if ($node instanceof SaKeepAliveNode) {
            $node->assignment_generation = (int) $node->assignment_generation + 1;
            $node->save();
        }

        return true;
    }

    public function revokeNode(string $installationId, string $nodeId): bool
    {
        $node = SaKeepAliveNode::query()
            ->where('installation_id', $installationId)
            ->where('node_id', $nodeId)
            ->whereNull('revoked_at')
            ->first();
        if (! $node instanceof SaKeepAliveNode) {
            return false;
        }
        $node->revoked_at = now();
        $node->save();

        return true;
    }

    public function projectDurableEvents(string $installationId, int $campaignId, string $campaignUuid): array
    {
        return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($installationId, $campaignId, $campaignUuid): array {
            $seq = SaKeepAliveCampaignSequence::query()
                ->where('campaign_id', $campaignId)
                ->lockForUpdate()
                ->first();
            if (! $seq instanceof SaKeepAliveCampaignSequence) {
                $seq = SaKeepAliveCampaignSequence::query()->create([
                    'installation_id' => $installationId,
                    'campaign_id' => $campaignId,
                    'campaign_uuid' => $campaignUuid,
                    'last_sequence' => 0,
                    'snapshot_version' => 0,
                ]);
                $seq = SaKeepAliveCampaignSequence::query()->where('id', $seq->id)->lockForUpdate()->first() ?? $seq;
            }

            $jobIds = SaJob::query()->where('campaign_id', $campaignId)->pluck('id')->all();
            $projected = 0;

            if ($jobIds !== []) {
                $events = SaJobEvent::query()
                    ->whereIn('job_id', $jobIds)
                    ->orderBy('occurred_at')
                    ->orderBy('id')
                    ->get();

                foreach ($events as $ev) {
                    if (! $ev instanceof SaJobEvent) {
                        continue;
                    }
                    $type = KeepAliveEventTypes::fromJobEvent(
                        (string) $ev->event_type,
                        $ev->to_status !== null ? (string) $ev->to_status : null,
                    );
                    if ($type === null) {
                        continue;
                    }
                    if ($this->sourceExists('job_event', (int) $ev->id)) {
                        continue;
                    }
                    $job = SaJob::query()->find($ev->job_id);
                    if (! $job instanceof SaJob) {
                        continue;
                    }
                    $attemptNo = null;
                    if ($ev->attempt_id) {
                        $attempt = SaJobAttempt::query()->find($ev->attempt_id);
                        $attemptNo = $attempt instanceof SaJobAttempt ? (int) $attempt->attempt_no : null;
                    }
                    $next = (int) $seq->last_sequence + 1;
                    SaKeepAliveCampaignEvent::query()->create([
                        'event_id' => (string) Str::uuid(),
                        'installation_id' => $installationId,
                        'campaign_id' => $campaignId,
                        'campaign_uuid' => $campaignUuid,
                        'job_id' => (string) $job->job_uuid,
                        'attempt' => $attemptNo,
                        'fencing_generation' => (int) ($job->fencing_generation ?? 0),
                        'event_sequence' => $next,
                        'occurred_at' => $ev->occurred_at ?? now(),
                        'payload_version' => KeepAliveEventTypes::PAYLOAD_VERSION,
                        'event_type' => $type,
                        'source_kind' => 'job_event',
                        'source_id' => (int) $ev->id,
                        'payload' => null,
                    ]);
                    $seq->last_sequence = $next;
                    $seq->snapshot_version = $next;
                    $projected++;
                }

                if (SocialAutomationSchema::has('sa_artifacts') && class_exists(SaArtifact::class)) {
                    $artifacts = SaArtifact::query()
                        ->whereIn('job_id', $jobIds)
                        ->where('kind', 'screenshot')
                        ->orderBy('id')
                        ->get();
                    foreach ($artifacts as $art) {
                        if (! $art instanceof SaArtifact) {
                            continue;
                        }
                        if ($this->sourceExists('artifact', (int) $art->id)) {
                            continue;
                        }
                        $job = SaJob::query()->find($art->job_id);
                        if (! $job instanceof SaJob) {
                            continue;
                        }
                        $attemptNo = null;
                        if ($art->attempt_id) {
                            $attempt = SaJobAttempt::query()->find($art->attempt_id);
                            $attemptNo = $attempt instanceof SaJobAttempt ? (int) $attempt->attempt_no : null;
                        }
                        $next = (int) $seq->last_sequence + 1;
                        SaKeepAliveCampaignEvent::query()->create([
                            'event_id' => (string) Str::uuid(),
                            'installation_id' => $installationId,
                            'campaign_id' => $campaignId,
                            'campaign_uuid' => $campaignUuid,
                            'job_id' => (string) $job->job_uuid,
                            'attempt' => $attemptNo,
                            'fencing_generation' => (int) ($job->fencing_generation ?? 0),
                            'event_sequence' => $next,
                            'occurred_at' => $art->created_at ?? now(),
                            'payload_version' => KeepAliveEventTypes::PAYLOAD_VERSION,
                            'event_type' => KeepAliveEventTypes::SCREENSHOT_UPLOADED,
                            'source_kind' => 'artifact',
                            'source_id' => (int) $art->id,
                            'payload' => $this->redactor->redactArray([
                                'artifact_uuid' => $art->artifact_uuid ?? null,
                                'kind' => 'screenshot',
                                'mime' => $art->mime ?? null,
                            ]),
                        ]);
                        $seq->last_sequence = $next;
                        $seq->snapshot_version = $next;
                        $projected++;
                    }
                }
            }

            $seq->save();

            return [
                'last_sequence' => (int) $seq->last_sequence,
                'snapshot_version' => (int) $seq->snapshot_version,
                'projected' => $projected,
            ];
        });
    }

    public function listEvents(
        string $installationId,
        string $campaignUuid,
        int $fromSequenceExclusive,
        ?int $toSequenceInclusive,
        int $limit,
    ): array {
        $q = SaKeepAliveCampaignEvent::query()
            ->where('installation_id', $installationId)
            ->where('campaign_uuid', $campaignUuid)
            ->where('event_sequence', '>', $fromSequenceExclusive)
            ->orderBy('event_sequence');
        if ($toSequenceInclusive !== null) {
            $q->where('event_sequence', '<=', $toSequenceInclusive);
        }
        $rows = $q->limit(max(1, $limit))->get();
        $out = [];
        foreach ($rows as $row) {
            if (! $row instanceof SaKeepAliveCampaignEvent) {
                continue;
            }
            $out[] = [
                'event_id' => (string) $row->event_id,
                'tenant_id' => (string) $row->installation_id,
                'campaign_id' => (string) $row->campaign_uuid,
                'job_id' => $row->job_id !== null ? (string) $row->job_id : null,
                'attempt' => $row->attempt !== null ? (int) $row->attempt : null,
                'fencing_generation' => (int) $row->fencing_generation,
                'event_sequence' => (int) $row->event_sequence,
                'timestamp' => $row->occurred_at?->toIso8601String() ?? '',
                'payload_version' => (int) $row->payload_version,
                'event_type' => (string) $row->event_type,
                'payload' => is_array($row->payload) ? $row->payload : null,
            ];
        }

        return $out;
    }

    public function sequenceHead(string $installationId, string $campaignUuid): array
    {
        $seq = SaKeepAliveCampaignSequence::query()
            ->where('installation_id', $installationId)
            ->where('campaign_uuid', $campaignUuid)
            ->first();

        return [
            'last_sequence' => $seq instanceof SaKeepAliveCampaignSequence ? (int) $seq->last_sequence : 0,
            'snapshot_version' => $seq instanceof SaKeepAliveCampaignSequence ? (int) $seq->snapshot_version : 0,
        ];
    }

    public function buildSanitizedSnapshot(string $installationId, string $campaignUuid): ?array
    {
        $campaign = SaCampaign::query()
            ->where('campaign_uuid', $campaignUuid)
            ->where('installation_id', $installationId)
            ->first();
        if (! $campaign instanceof SaCampaign) {
            return null;
        }

        $contents = [];
        $contentCounts = [];
        $contentRows = SaCampaignContent::query()->where('campaign_id', $campaign->id)->orderBy('sort_order')->get();
        foreach ($contentRows as $c) {
            if (! $c instanceof SaCampaignContent) {
                continue;
            }
            $status = (string) $c->status;
            $contentCounts[$status] = ($contentCounts[$status] ?? 0) + 1;
            $contents[] = [
                'content_id' => (string) $c->content_uuid,
                'status' => $status,
                'sort_order' => (int) $c->sort_order,
            ];
        }

        $jobs = [];
        $jobCounts = [];
        $jobRows = SaJob::query()->where('campaign_id', $campaign->id)->orderBy('id')->get();
        foreach ($jobRows as $j) {
            if (! $j instanceof SaJob) {
                continue;
            }
            $status = (string) $j->status;
            $jobCounts[$status] = ($jobCounts[$status] ?? 0) + 1;
            $attempt = SaJobAttempt::query()->where('job_id', $j->id)->orderByDesc('attempt_no')->first();
            $contentUuid = null;
            if ($j->campaign_content_id) {
                $cc = SaCampaignContent::query()->find($j->campaign_content_id);
                $contentUuid = $cc instanceof SaCampaignContent ? (string) $cc->content_uuid : null;
            }
            $jobs[] = [
                'job_id' => (string) $j->job_uuid,
                'status' => $status,
                'fencing_generation' => (int) ($j->fencing_generation ?? 0),
                'attempt' => $attempt instanceof SaJobAttempt ? (int) $attempt->attempt_no : null,
                'content_id' => $contentUuid,
                'reserved_at' => $j->reserved_at?->toIso8601String(),
                'claimed_at' => $j->claimed_at?->toIso8601String(),
                'started_at' => $j->started_at?->toIso8601String(),
                'finished_at' => $j->finished_at?->toIso8601String(),
                'error_code' => $j->error_code,
            ];
        }

        return [
            'campaign' => [
                'campaign_id' => (string) $campaign->campaign_uuid,
                'campaign_uuid' => (string) $campaign->campaign_uuid,
                'name' => (string) $campaign->name,
                'status' => (string) $campaign->status,
                'platform' => (string) $campaign->platform,
                'execution_mode' => (string) $campaign->execution_mode,
                'run_mode' => (string) $campaign->run_mode,
                'script_id' => (string) $campaign->script_id,
                'script_version' => (string) $campaign->script_version,
            ],
            'contents' => $contents,
            'jobs' => $jobs,
            'content_counts' => $contentCounts,
            'job_counts' => $jobCounts,
        ];
    }

    public function jobClaimInvocations(): int
    {
        return 0;
    }

    private function sourceExists(string $kind, int $sourceId): bool
    {
        return SaKeepAliveCampaignEvent::query()
            ->where('source_kind', $kind)
            ->where('source_id', $sourceId)
            ->exists();
    }
}
