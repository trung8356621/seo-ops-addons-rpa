<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Illuminate\Support\Collection;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaArtifact;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContent;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaJobAttempt;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;

/**
 * Read-only batched loader for Campaign Content -> Job -> Attempt -> Artifact execution history.
 * Uses real DB relationships only; never fabricates IDs, UUIDs, attempts, or URLs.
 */
final class CampaignContentHistoryService
{
    /**
     * Load ordered attempt DTOs grouped by campaign_content_id for a Campaign.
     *
     * @param  Collection<int, SaCampaignContent>|list<SaCampaignContent>  $contents
     * @return array<int, list<array<string, mixed>>>
     */
    public function attemptsByContentId(SaCampaign $campaign, iterable $contents): array
    {
        if (! SocialAutomationSchema::jobsReady() || ! SocialAutomationSchema::has('sa_job_attempts')) {
            return [];
        }

        $contentIds = [];
        foreach ($contents as $c) {
            $contentIds[] = (int) $c->id;
        }
        $contentIds = array_values(array_unique($contentIds));
        if ($contentIds === []) {
            return [];
        }

        $jobs = SaJob::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('campaign_content_id', $contentIds)
            ->orderBy('id')
            ->get();

        if ($jobs->isEmpty()) {
            return [];
        }

        $jobsById = $jobs->keyBy('id');
        $jobIds = $jobs->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();

        $attempts = SaJobAttempt::query()
            ->whereIn('job_id', $jobIds)
            ->orderBy('job_id')
            ->orderBy('attempt_no')
            ->orderBy('id')
            ->get();

        if ($attempts->isEmpty()) {
            return [];
        }

        $agentIds = [];
        $sessionIds = [];
        foreach ($attempts as $a) {
            $job = $jobsById->get((int) $a->job_id);
            $aid = $a->agent_id ?? $job?->agent_id;
            $sid = $a->browser_session_id ?? $job?->browser_session_id;
            if ($aid !== null) {
                $agentIds[] = (int) $aid;
            }
            if ($sid !== null) {
                $sessionIds[] = (int) $sid;
            }
        }
        $agentIds = array_values(array_unique($agentIds));
        $sessionIds = array_values(array_unique($sessionIds));

        $agentsById = ($agentIds !== [] && SocialAutomationSchema::has('sa_agents'))
            ? SaAgent::query()->whereIn('id', $agentIds)->get(['id', 'agent_uuid'])->keyBy('id')
            : collect();

        $sessionsById = ($sessionIds !== [] && SocialAutomationSchema::has('sa_browser_sessions'))
            ? SaBrowserSession::query()->whereIn('id', $sessionIds)->get(['id', 'session_uuid'])->keyBy('id')
            : collect();

        $artifactsByAttemptId = [];
        $artifactsByJobUnlinked = [];
        if (SocialAutomationSchema::has('sa_artifacts')) {
            $artifacts = SaArtifact::query()
                ->whereIn('job_id', $jobIds)
                ->orderBy('id')
                ->get();
            foreach ($artifacts as $art) {
                $hasBinary = is_string($art->storage_path) && $art->storage_path !== '';
                $dto = [
                    'artifact_uuid' => (string) $art->artifact_uuid,
                    'kind' => (string) $art->kind,
                    'mime' => $art->mime !== null ? (string) $art->mime : null,
                    'size_bytes' => $art->size_bytes !== null ? (int) $art->size_bytes : null,
                    'checksum_sha256' => $art->checksum_sha256 !== null ? (string) $art->checksum_sha256 : null,
                    'has_binary' => $hasBinary,
                    'download_url' => $hasBinary
                        ? '/social-automation/api/campaigns/artifacts/'.(string) $art->artifact_uuid
                        : null,
                    'metadata' => is_array($art->metadata) ? $art->metadata : null,
                ];
                if ($art->attempt_id !== null) {
                    $artifactsByAttemptId[(int) $art->attempt_id][] = $dto;
                } else {
                    $artifactsByJobUnlinked[(int) $art->job_id][] = $dto;
                }
            }
        }

        $attemptCountByJob = [];
        foreach ($attempts as $a) {
            $jid = (int) $a->job_id;
            $attemptCountByJob[$jid] = ($attemptCountByJob[$jid] ?? 0) + 1;
        }

        $grouped = [];
        foreach ($attempts as $attempt) {
            /** @var SaJob|null $job */
            $job = $jobsById->get((int) $attempt->job_id);
            if (! $job instanceof SaJob || $job->campaign_content_id === null) {
                continue;
            }

            // Reservation placeholders (Queued, never started) are not execution history.
            // They were left behind when claim used to create a second Attempt row.
            if ((string) $attempt->status === 'queued'
                && $attempt->started_at === null
                && $attempt->finished_at === null) {
                continue;
            }

            $contentId = (int) $job->campaign_content_id;
            $effectiveAgentId = $attempt->agent_id ?? $job->agent_id;
            $effectiveSessionId = $attempt->browser_session_id ?? $job->browser_session_id;

            /** @var SaAgent|null $agent */
            $agent = $effectiveAgentId !== null ? $agentsById->get((int) $effectiveAgentId) : null;
            /** @var SaBrowserSession|null $session */
            $session = $effectiveSessionId !== null ? $sessionsById->get((int) $effectiveSessionId) : null;

            $progress = is_array($attempt->progress) ? $attempt->progress : [];
            $result = is_array($attempt->result) ? $attempt->result : [];
            $step = $progress['step'] ?? $result['failed_step'] ?? $result['step'] ?? null;
            $stepCount = $result['step_count'] ?? $progress['total_steps'] ?? null;

            $artifacts = $artifactsByAttemptId[(int) $attempt->id] ?? [];
            if ($artifacts === [] && ($attemptCountByJob[(int) $job->id] ?? 0) === 1) {
                $artifacts = $artifactsByJobUnlinked[(int) $job->id] ?? [];
            }

            $grouped[$contentId][] = [
                'job_id' => (int) $job->id,
                'job_uuid' => (string) $job->job_uuid,
                'attempt_id' => (int) $attempt->id,
                'attempt_uuid' => (string) $attempt->attempt_uuid,
                'attempt_no' => (int) $attempt->attempt_no,
                'status' => (string) $attempt->status,
                'job_status' => (string) $job->status,
                'step' => $step,
                'step_count' => is_numeric($stepCount) ? (int) $stepCount : null,
                'error_code' => $attempt->error_code ?? $job->error_code,
                'error_message' => $attempt->error_message ?? $job->error_message,
                'started_at' => ($attempt->started_at ?? $job->started_at)?->toIso8601String(),
                'ended_at' => ($attempt->finished_at ?? $job->finished_at)?->toIso8601String(),
                'duration_ms' => $job->duration_ms !== null ? (int) $job->duration_ms : null,
                'agent_uuid' => $agent?->agent_uuid !== null ? (string) $agent->agent_uuid : null,
                'browser_uuid' => $session?->session_uuid !== null ? (string) $session->session_uuid : null,
                'package_key' => (string) $job->package_key,
                'package_version' => (string) $job->package_version,
                'artifacts' => $artifacts,
            ];
        }

        return $grouped;
    }
}
