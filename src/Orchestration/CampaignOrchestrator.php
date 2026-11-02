<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Orchestration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Artifacts\ArtifactAcceptPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\AccountLockPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\AttemptFencePolicy;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignModePolicy;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignRecheckPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignTestScriptPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\EligibilitySelector;
use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;
use Omnichannel\Addons\SocialAutomation\Domain\SessionLockPolicy;
use Omnichannel\Addons\SocialAutomation\Enums\AgentAvailability;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignAttemptStatus;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignContentStatus;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignExecutionMode;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignRunMode;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignStatus;
use Omnichannel\Addons\SocialAutomation\Enums\JobStatus;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaAccountDeviceAffinity;
use Omnichannel\Addons\SocialAutomation\Models\SaAccountExecutionLease;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaArtifact;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignAccountAssignment;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContent;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaJobAttempt;
use Omnichannel\Addons\SocialAutomation\Models\SaJobEvent;
use Omnichannel\Addons\SocialAutomation\Models\SaSessionAccount;
use Omnichannel\Addons\SocialAutomation\Support\JobObservability;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use Throwable;

/**
 * Campaign dispatch + client claim/lease lifecycle.
 * Reuses AccountLockPolicy / EligibilitySelector / AttemptFencePolicy / execution leases.
 * NO automatic job retry. Resource recheck ≠ retry.
 */
final class CampaignOrchestrator
{
    public const DEFAULT_LEASE_SECONDS = 300;

    public function __construct(
        private readonly EligibilitySelector $eligibility = new EligibilitySelector,
        private readonly OnlineStateCalculator $online = new OnlineStateCalculator,
        private readonly AccountLockPolicy $accountLocks = new AccountLockPolicy,
        private readonly SessionLockPolicy $sessionLocks = new SessionLockPolicy,
        private readonly CampaignModePolicy $modePolicy = new CampaignModePolicy,
        private readonly CampaignRecheckPolicy $recheck = new CampaignRecheckPolicy,
        private readonly AttemptFencePolicy $fence = new AttemptFencePolicy,
        private readonly JobObservability $observability = new JobObservability,
        private readonly CampaignTestScriptPolicy $testScripts = new CampaignTestScriptPolicy,
        private readonly ArtifactAcceptPolicy $artifacts = new ArtifactAcceptPolicy,
    ) {}

    /**
     * Dispatch pending/waiting contents for a running campaign.
     *
     * @return array{ok: bool, dispatched: int, waiting: int, errors?: list<string>}
     */
    public function dispatchCampaign(SaCampaign $campaign): array
    {
        if (! SocialAutomationSchema::campaignsReady()) {
            return ['ok' => false, 'dispatched' => 0, 'waiting' => 0, 'errors' => ['schema_unavailable']];
        }

        $status = CampaignStatus::tryFrom((string) $campaign->status);
        if ($status === null || ! $status->allowsDispatch()) {
            return ['ok' => true, 'dispatched' => 0, 'waiting' => 0];
        }

        try {
            return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($campaign): array {
                $locked = SaCampaign::query()->where('id', $campaign->id)->lockForUpdate()->first();
                if (! $locked instanceof SaCampaign) {
                    return ['ok' => false, 'dispatched' => 0, 'waiting' => 0, 'errors' => ['campaign_not_found']];
                }
                $status = CampaignStatus::tryFrom((string) $locked->status);
                if ($status === null || ! $status->allowsDispatch()) {
                    return ['ok' => true, 'dispatched' => 0, 'waiting' => 0];
                }

                $mode = CampaignExecutionMode::tryFrom((string) $locked->execution_mode)
                    ?? CampaignExecutionMode::OneAccountOneJob;

                $contents = SaCampaignContent::query()
                    ->where('campaign_id', $locked->id)
                    ->whereIn('status', [
                        CampaignContentStatus::Pending->value,
                        CampaignContentStatus::WaitingResources->value,
                    ])
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $assignedIds = SaCampaignAccountAssignment::query()
                    ->where('campaign_id', $locked->id)
                    ->pluck('account_id')
                    ->all();

                $busyInCampaign = SaCampaignContent::query()
                    ->where('campaign_id', $locked->id)
                    ->whereIn('status', [
                        CampaignContentStatus::Running->value,
                        CampaignContentStatus::Unknown->value,
                    ])
                    ->whereNotNull('assigned_account_id')
                    ->pluck('assigned_account_id')
                    ->all();

                $dispatched = 0;
                $stillWaiting = 0;

                foreach ($contents as $content) {
                    // Mode A: durable assignment blocks OTHER contents; retry may reuse own assigned account.
                    $blockedAssigned = $assignedIds;
                    if ($mode === CampaignExecutionMode::OneAccountOneJob && $content->assigned_account_id) {
                        $blockedAssigned = array_values(array_filter(
                            $assignedIds,
                            static fn ($id): bool => (int) $id !== (int) $content->assigned_account_id
                        ));
                    }
                    $selection = $this->selectEligibleForCampaign(
                        $locked,
                        $mode,
                        $blockedAssigned,
                        $busyInCampaign,
                        1,
                        $content->assigned_account_id ? (int) $content->assigned_account_id : null,
                    );
                    if ($selection['selected'] === []) {
                        $content->status = CampaignContentStatus::WaitingResources->value;
                        $content->save();
                        $stillWaiting++;
                        continue;
                    }

                    $row = $selection['selected'][0];
                    $job = $this->reserveCampaignJob($locked, $content, $row, $mode);
                    if ($job === null) {
                        $content->status = CampaignContentStatus::WaitingResources->value;
                        $content->save();
                        $stillWaiting++;
                        continue;
                    }

                    $accountId = (int) ($row['account_id'] ?? 0);
                    if ($accountId > 0) {
                        $assignedIds[] = $accountId;
                        $busyInCampaign[] = $accountId;
                    }
                    $dispatched++;
                }

                if ($stillWaiting > 0 || $contents->isNotEmpty() && $dispatched === 0) {
                    $locked->next_recheck_at = $this->recheck->nextRecheckAt(
                        now(),
                        (int) $locked->resource_recheck_seconds
                    );
                } else {
                    $waitingLeft = SaCampaignContent::query()
                        ->where('campaign_id', $locked->id)
                        ->whereIn('status', [
                            CampaignContentStatus::Pending->value,
                            CampaignContentStatus::WaitingResources->value,
                        ])->exists();
                    $locked->next_recheck_at = $waitingLeft
                        ? $this->recheck->nextRecheckAt(now(), (int) $locked->resource_recheck_seconds)
                        : null;
                }
                $locked->save();
                $this->maybeCompleteCampaign($locked);

                return ['ok' => true, 'dispatched' => $dispatched, 'waiting' => $stillWaiting];
            });
        } catch (Throwable) {
            return ['ok' => false, 'dispatched' => 0, 'waiting' => 0, 'errors' => ['dispatch_failed']];
        }
    }

    /**
     * Idempotent recheck for campaigns due for resource evaluation.
     *
     * @return array{checked: int, dispatched: int}
     */
    public function recheckDueCampaigns(?\DateTimeInterface $now = null): array
    {
        if (! SocialAutomationSchema::campaignsReady()) {
            return ['checked' => 0, 'dispatched' => 0];
        }

        $now = $now ?? now();
        $checked = 0;
        $dispatched = 0;

        $campaigns = SaCampaign::query()
            ->where('status', CampaignStatus::Running->value)
            ->where(function ($q) use ($now): void {
                $q->whereNull('next_recheck_at')->orWhere('next_recheck_at', '<=', $now);
            })
            ->orderBy('id')
            ->limit(50)
            ->get();

        foreach ($campaigns as $campaign) {
            $hasWaiting = SaCampaignContent::query()
                ->where('campaign_id', $campaign->id)
                ->whereIn('status', [
                    CampaignContentStatus::Pending->value,
                    CampaignContentStatus::WaitingResources->value,
                ])->exists();
            if (! $hasWaiting) {
                continue;
            }
            $checked++;
            $result = $this->dispatchCampaign($campaign);
            $dispatched += (int) ($result['dispatched'] ?? 0);
        }

        return ['checked' => $checked, 'dispatched' => $dispatched];
    }

    /**
     * Client pull-claim: one reserved campaign job for the agent/browsers, or null.
     *
     * @param  list<string>  $browserIds  session_uuid list
     * @return array{ok: bool, data: ?array<string, mixed>, errors?: list<string>}
     */
    public function claimNext(
        string $agentUuid,
        array $browserIds,
        ?int $clientApiKeyId,
        string $installationId,
    ): array {
        if (! SocialAutomationSchema::campaignsReady()) {
            return ['ok' => false, 'data' => null, 'errors' => ['schema_unavailable']];
        }

        $agent = $this->authorizedAgent($agentUuid, $clientApiKeyId, $installationId);
        if ($agent === null) {
            return ['ok' => false, 'data' => null, 'errors' => ['agent_not_found']];
        }
        if ((string) $agent->availability === AgentAvailability::Disabled->value) {
            return ['ok' => false, 'data' => null, 'errors' => ['agent_disabled']];
        }

        $browserUuids = array_values(array_filter(array_map(
            static fn ($v): string => trim((string) $v),
            $browserIds
        ), static fn (string $v): bool => $v !== ''));

        try {
            return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($agent, $browserUuids): array {
                $sessions = SaBrowserSession::query()
                    ->where('agent_id', $agent->id)
                    ->when($browserUuids !== [], fn ($q) => $q->whereIn('session_uuid', $browserUuids))
                    ->lockForUpdate()
                    ->get();
                if ($sessions->isEmpty()) {
                    return ['ok' => true, 'data' => null];
                }
                $sessionIds = $sessions->pluck('id')->all();

                $job = SaJob::query()
                    ->where('agent_id', $agent->id)
                    ->whereIn('browser_session_id', $sessionIds)
                    ->whereNotNull('campaign_content_id')
                    ->where('status', JobStatus::Reserved->value)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if (! $job instanceof SaJob) {
                    // Idempotent re-claim of already claimed/running for same agent
                    $active = SaJob::query()
                        ->where('agent_id', $agent->id)
                        ->whereIn('browser_session_id', $sessionIds)
                        ->whereNotNull('campaign_content_id')
                        ->whereIn('status', [JobStatus::Claimed->value, JobStatus::Running->value])
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->first();
                    if ($active instanceof SaJob) {
                        return ['ok' => true, 'data' => $this->claimPayload($active)];
                    }

                    return ['ok' => true, 'data' => null];
                }

                $leaseToken = (string) ($job->lease_token ?: Str::random(40));
                $fencing = (int) $job->fencing_generation;
                $attemptNo = (int) SaJobAttempt::query()->where('job_id', $job->id)->max('attempt_no') + 1;
                $attempt = SaJobAttempt::query()->create([
                    'attempt_uuid' => (string) Str::uuid(),
                    'lease_token' => $leaseToken,
                    'job_id' => $job->id,
                    'attempt_no' => max(1, $attemptNo),
                    'fencing_generation' => $fencing,
                    'status' => CampaignAttemptStatus::Claimed->value,
                    'agent_id' => $job->agent_id,
                    'browser_session_id' => $job->browser_session_id,
                    'account_id' => $job->account_id,
                    'started_at' => now(),
                    'lease_expires_at' => now()->addSeconds(self::DEFAULT_LEASE_SECONDS),
                ]);

                $job->status = JobStatus::Claimed->value;
                $job->claimed_at = now();
                $job->lease_token = $leaseToken;
                $job->lease_expires_at = now()->addSeconds(self::DEFAULT_LEASE_SECONDS);
                $payload = is_array($job->payload) ? $job->payload : [];
                $payload['_fence'] = [
                    'attempt_uuid' => $attempt->attempt_uuid,
                    'attempt_no' => $attempt->attempt_no,
                    'lease_token' => $leaseToken,
                    'fencing_generation' => $fencing,
                ];
                $job->payload = $payload;
                $job->save();

                SaAccountExecutionLease::query()->where('job_id', $job->id)->where('status', 'active')->update([
                    'lease_token' => $leaseToken,
                    'attempt_id' => $attempt->id,
                    'fencing_generation' => $fencing,
                    'heartbeat_at' => now(),
                    'expires_at' => now()->addSeconds(self::DEFAULT_LEASE_SECONDS),
                ]);

                SaJobEvent::query()->create([
                    'job_id' => $job->id,
                    'attempt_id' => $attempt->id,
                    'event_type' => 'claim',
                    'from_status' => JobStatus::Reserved->value,
                    'to_status' => JobStatus::Claimed->value,
                    'payload' => null,
                    'occurred_at' => now(),
                ]);

                return ['ok' => true, 'data' => $this->claimPayload($job->fresh() ?? $job, $attempt)];
            });
        } catch (Throwable) {
            return ['ok' => false, 'data' => null, 'errors' => ['claim_failed']];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, data?: array<string, mixed>, errors?: list<string>, idempotent?: bool}
     */
    public function acknowledge(
        string $jobUuid,
        array $payload,
        ?int $clientApiKeyId,
        string $installationId,
    ): array {
        return $this->advanceClient(
            $jobUuid,
            $payload,
            $clientApiKeyId,
            $installationId,
            CampaignAttemptStatus::Running,
            JobStatus::Running,
            'ack',
            ['started_at' => now()],
            requireFence: true,
            releaseLock: false,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, data?: array<string, mixed>, errors?: list<string>}
     */
    public function progress(
        string $jobUuid,
        array $payload,
        ?int $clientApiKeyId,
        string $installationId,
    ): array {
        if (! SocialAutomationSchema::campaignsReady()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }

        try {
            return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($jobUuid, $payload, $clientApiKeyId, $installationId): array {
                $job = $this->authorizedCampaignJob($jobUuid, $clientApiKeyId, $installationId, true);
                if ($job === null) {
                    return ['ok' => false, 'errors' => ['job_not_found_or_forbidden']];
                }
                $fenceCheck = $this->validateLeaseFence($job, $payload);
                if (! ($fenceCheck['ok'] ?? false)) {
                    return ['ok' => false, 'errors' => [$fenceCheck['error'] ?? AttemptFencePolicy::ERR_MISMATCH]];
                }

                $progress = $this->observability->redactArray([
                    'message' => isset($payload['message']) ? substr((string) $payload['message'], 0, 500) : null,
                    'percent' => $payload['percent'] ?? null,
                    'step' => $payload['step'] ?? null,
                ]);

                $attempt = $this->activeAttempt($job);
                if ($attempt instanceof SaJobAttempt) {
                    $attempt->progress = $progress;
                    $attempt->lease_expires_at = now()->addSeconds(self::DEFAULT_LEASE_SECONDS);
                    $attempt->save();
                }

                $job->lease_expires_at = now()->addSeconds(self::DEFAULT_LEASE_SECONDS);
                $job->save();
                SaAccountExecutionLease::query()->where('job_id', $job->id)->where('status', 'active')->update([
                    'heartbeat_at' => now(),
                    'expires_at' => now()->addSeconds(self::DEFAULT_LEASE_SECONDS),
                ]);

                SaJobEvent::query()->create([
                    'job_id' => $job->id,
                    'attempt_id' => $attempt?->id,
                    'event_type' => 'progress',
                    'from_status' => $job->status,
                    'to_status' => $job->status,
                    'payload' => $progress,
                    'occurred_at' => now(),
                ]);

                return ['ok' => true, 'data' => ['job_id' => $job->job_uuid, 'status' => $job->status]];
            });
        } catch (Throwable) {
            return ['ok' => false, 'errors' => ['progress_failed']];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, data?: array<string, mixed>, errors?: list<string>, idempotent?: bool}
     */
    public function result(
        string $jobUuid,
        array $payload,
        ?int $clientApiKeyId,
        string $installationId,
    ): array {
        $attemptStatus = CampaignAttemptStatus::fromResultStatus((string) ($payload['status'] ?? ''));
        if ($attemptStatus === null) {
            return ['ok' => false, 'errors' => ['invalid_result_status']];
        }
        if (! $attemptStatus->isTerminal() && $attemptStatus !== CampaignAttemptStatus::Unknown) {
            return ['ok' => false, 'errors' => ['invalid_result_status']];
        }

        $untrusted = $this->testScripts->rejectUntrustedNonPublishClaims($payload);
        if ($untrusted !== []) {
            return ['ok' => false, 'errors' => $untrusted];
        }

        $artifactRefs = $this->normalizeArtifactRefs($payload);
        if (! ($artifactRefs['ok'] ?? false)) {
            return ['ok' => false, 'errors' => $artifactRefs['errors'] ?? ['artifact_invalid']];
        }

        $output = is_array($payload['output'] ?? null)
            ? $this->observability->redactArray($payload['output'])
            : (is_array($payload['result'] ?? null) ? $this->observability->redactArray($payload['result']) : []);
        if (($artifactRefs['refs'] ?? []) !== []) {
            $output = is_array($output) ? $output : [];
            $output['artifacts'] = $artifactRefs['refs'];
            $output['screenshot_proves_publication'] = false;
        }

        $jobStatus = $attemptStatus->toJobStatus();
        $release = $attemptStatus !== CampaignAttemptStatus::Unknown;

        return $this->advanceClient(
            $jobUuid,
            $payload,
            $clientApiKeyId,
            $installationId,
            $attemptStatus,
            $jobStatus,
            'result',
            [
                'result' => $output === [] ? null : $output,
                'error_code' => is_array($payload['error'] ?? null)
                    ? ($payload['error']['code'] ?? $payload['error_code'] ?? null)
                    : ($payload['error_code'] ?? null),
                'error_message' => $this->errorMessageFromPayload($payload),
                'finished_at' => now(),
            ],
            requireFence: true,
            releaseLock: $release,
            terminalContent: true,
            artifactRefs: $artifactRefs['refs'] ?? [],
        );
    }

    /**
     * Admin retry — new attempt only; never auto. Idempotent via idempotency key.
     *
     * @return array{ok: bool, content?: array<string, mixed>, errors?: list<string>, idempotent?: bool}
     */
    public function adminRetry(SaCampaignContent $content, string $idempotencyKey): array
    {
        if (! SocialAutomationSchema::campaignsReady()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            return ['ok' => false, 'errors' => ['idempotency_key_required']];
        }

        try {
            return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($content, $idempotencyKey): array {
                $locked = SaCampaignContent::query()->where('id', $content->id)->lockForUpdate()->first();
                if (! $locked instanceof SaCampaignContent) {
                    return ['ok' => false, 'errors' => ['content_not_found']];
                }

                if ($locked->admin_action_idempotency === $idempotencyKey
                    && in_array((string) $locked->status, [
                        CampaignContentStatus::Pending->value,
                        CampaignContentStatus::WaitingResources->value,
                        CampaignContentStatus::Running->value,
                    ], true)) {
                    return [
                        'ok' => true,
                        'idempotent' => true,
                        'content' => ['id' => $locked->content_uuid, 'status' => $locked->status],
                    ];
                }

                $status = CampaignContentStatus::tryFrom((string) $locked->status);
                if ($status !== CampaignContentStatus::FailedLocked && $status !== CampaignContentStatus::Unknown) {
                    return ['ok' => false, 'errors' => ['content_not_retryable']];
                }

                $campaign = SaCampaign::query()->where('id', $locked->campaign_id)->lockForUpdate()->first();
                if (! $campaign instanceof SaCampaign) {
                    return ['ok' => false, 'errors' => ['campaign_not_found']];
                }
                $cStatus = CampaignStatus::tryFrom((string) $campaign->status);
                if ($cStatus === null || $cStatus->isTerminal()) {
                    return ['ok' => false, 'errors' => ['campaign_not_dispatchable']];
                }
                if ($cStatus === CampaignStatus::Draft) {
                    return ['ok' => false, 'errors' => ['campaign_not_started']];
                }

                // Unknown keeps global lease until Admin acts — release before new attempt.
                // Attempt rows stay durable; job shell leaves ACTIVE_STATUSES so a fresh lease can be acquired.
                if ($locked->current_job_id) {
                    $prev = SaJob::query()->where('id', $locked->current_job_id)->lockForUpdate()->first();
                    if ($prev instanceof SaJob) {
                        $prevStatus = JobStatus::tryFrom((string) $prev->status);
                        if ($prevStatus === JobStatus::Unknown || ($prevStatus && $prevStatus->isActive())) {
                            $this->releaseJobLocks($prev);
                            $payload = is_array($prev->payload) ? $prev->payload : [];
                            unset($payload['_fence']);
                            $prev->payload = $payload;
                            $prev->lease_token = null;
                            $prev->status = JobStatus::Cancelled->value;
                            $prev->error_code = 'superseded_by_admin_retry';
                            $prev->finished_at = $prev->finished_at ?? now();
                            $prev->save();
                        }
                    }
                }

                // Mode A: account remains assigned; retry reuses same account when possible.
                $locked->status = CampaignContentStatus::Pending->value;
                $locked->last_error_code = null;
                $locked->last_error_message = null;
                $locked->last_error_meta = null;
                $locked->current_job_id = null;
                $locked->admin_action_idempotency = $idempotencyKey;
                $locked->admin_action_at = now();
                $locked->save();

                if ((string) $campaign->status !== CampaignStatus::Running->value) {
                    $campaign->status = CampaignStatus::Running->value;
                    $campaign->paused_at = null;
                }
                $campaign->next_recheck_at = now();
                $campaign->save();

                return [
                    'ok' => true,
                    'content' => ['id' => $locked->content_uuid, 'status' => $locked->status],
                ];
            });
        } catch (Throwable) {
            return ['ok' => false, 'errors' => ['retry_failed']];
        }
    }

    /**
     * @return array{ok: bool, content?: array<string, mixed>, errors?: list<string>}
     */
    public function adminSkipOrCancel(SaCampaignContent $content, string $action, string $idempotencyKey): array
    {
        $to = $action === 'skip' ? CampaignContentStatus::Skipped : CampaignContentStatus::Cancelled;
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            return ['ok' => false, 'errors' => ['idempotency_key_required']];
        }

        try {
            return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($content, $to, $idempotencyKey): array {
                $locked = SaCampaignContent::query()->where('id', $content->id)->lockForUpdate()->first();
                if (! $locked instanceof SaCampaignContent) {
                    return ['ok' => false, 'errors' => ['content_not_found']];
                }
                if ($locked->admin_action_idempotency === $idempotencyKey
                    && (string) $locked->status === $to->value) {
                    return ['ok' => true, 'content' => ['id' => $locked->content_uuid, 'status' => $locked->status]];
                }
                $from = CampaignContentStatus::tryFrom((string) $locked->status);
                if ($from !== null && $from->isTerminal() && $from !== CampaignContentStatus::FailedLocked
                    && $from !== CampaignContentStatus::Unknown) {
                    return ['ok' => false, 'errors' => ['content_already_terminal']];
                }

                if ($locked->current_job_id) {
                    $job = SaJob::query()->where('id', $locked->current_job_id)->lockForUpdate()->first();
                    if ($job instanceof SaJob && ! JobStatus::from((string) $job->status)->isTerminal()) {
                        $this->forceCancelJob($job);
                    }
                }

                $locked->status = $to->value;
                $locked->admin_action_idempotency = $idempotencyKey;
                $locked->admin_action_at = now();
                $locked->save();

                $campaign = SaCampaign::query()->find($locked->campaign_id);
                if ($campaign instanceof SaCampaign) {
                    $this->maybeCompleteCampaign($campaign);
                }

                return ['ok' => true, 'content' => ['id' => $locked->content_uuid, 'status' => $locked->status]];
            });
        } catch (Throwable) {
            return ['ok' => false, 'errors' => ['admin_action_failed']];
        }
    }

    /**
     * Lease reconciliation for campaign jobs — unknown, never auto-retry.
     *
     * @return array{reconciled: int}
     */
    public function reconcileExpiredCampaignLeases(): array
    {
        if (! SocialAutomationSchema::campaignsReady()) {
            return ['reconciled' => 0];
        }

        $count = 0;
        $jobs = SaJob::query()
            ->whereNotNull('campaign_content_id')
            ->whereIn('status', [
                JobStatus::Reserved->value,
                JobStatus::Claimed->value,
                JobStatus::Running->value,
            ])
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', now())
            ->limit(100)
            ->get();

        foreach ($jobs as $job) {
            try {
                DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($job, &$count): void {
                    $locked = SaJob::query()->where('id', $job->id)->lockForUpdate()->first();
                    if (! $locked instanceof SaJob) {
                        return;
                    }
                    if ($locked->lease_expires_at === null || $locked->lease_expires_at->isFuture()) {
                        return;
                    }
                    $from = JobStatus::from((string) $locked->status);
                    if ($from === JobStatus::Reserved) {
                        // Never claimed — release locks, content back to waiting_resources (not failed).
                        $this->releaseJobLocks($locked);
                        $locked->status = JobStatus::Cancelled->value;
                        $locked->finished_at = now();
                        $locked->error_code = 'lease_expired_unclaimed';
                        $locked->save();
                        $content = SaCampaignContent::query()->where('id', $locked->campaign_content_id)->lockForUpdate()->first();
                        if ($content instanceof SaCampaignContent
                            && (string) $content->status === CampaignContentStatus::Running->value) {
                            $content->status = CampaignContentStatus::WaitingResources->value;
                            $content->current_job_id = null;
                            // Mode A assignment stays — account was reserved for this content.
                            $content->save();
                        }
                        $count++;

                        return;
                    }

                    // Claimed/running → unknown; keep account lock until admin acts.
                    $attempt = $this->activeAttempt($locked);
                    if ($attempt instanceof SaJobAttempt) {
                        $attempt->status = CampaignAttemptStatus::Unknown->value;
                        $attempt->finished_at = now();
                        $attempt->error_code = 'lease_expired';
                        $attempt->save();
                    }
                    $locked->status = JobStatus::Unknown->value;
                    $payload = is_array($locked->payload) ? $locked->payload : [];
                    unset($payload['_fence']);
                    $locked->payload = $payload;
                    $locked->lease_token = null;
                    $locked->save();

                    $content = SaCampaignContent::query()->where('id', $locked->campaign_content_id)->lockForUpdate()->first();
                    if ($content instanceof SaCampaignContent) {
                        $content->status = CampaignContentStatus::Unknown->value;
                        $content->last_error_code = 'lease_expired';
                        $content->last_error_message = 'Agent lease expired during execution; outcome unknown';
                        $content->save();
                    }
                    // Do NOT release account lease for unknown — prevents concurrent re-execution.
                    $count++;
                });
            } catch (Throwable) {
                continue;
            }
        }

        return ['reconciled' => $count];
    }

    /**
     * @param  list<int|string>  $assignedIds
     * @param  list<int|string>  $busyInCampaign
     * @return array{selected: list<array<string, mixed>>, skip_reasons: list<string>}
     */
    private function selectEligibleForCampaign(
        SaCampaign $campaign,
        CampaignExecutionMode $mode,
        array $assignedIds,
        array $busyInCampaign,
        int $target,
        ?int $preferAccountId = null,
    ): array {
        $agents = SaAgent::query()
            ->where('installation_id', $campaign->installation_id)
            ->get()
            ->map(function (SaAgent $a): array {
                return [
                    'agent_id' => $a->id,
                    'online' => $this->online->isOnline($a->last_heartbeat_at),
                    'availability' => (string) $a->availability,
                    'active_jobs' => (int) $a->active_jobs,
                    'max_concurrency' => max(1, (int) $a->max_concurrency),
                    'capabilities' => is_array($a->capabilities) ? $a->capabilities : [],
                    'runtime_version' => $a->runtime_version,
                    'agent_kind' => $a->agent_kind ?? null,
                ];
            })->all();

        $busyAccountIds = SaJob::query()
            ->whereIn('status', AccountLockPolicy::ACTIVE_STATUSES)
            ->whereNotNull('account_id')
            ->pluck('account_id')
            ->all();

        // Unknown keeps global lock (ACTIVE_STATUSES includes unknown).

        $sessions = [];
        foreach (SaBrowserSession::query()->get() as $session) {
            $agent = null;
            foreach ($agents as $a) {
                if ((int) $a['agent_id'] === (int) $session->agent_id) {
                    $agent = $a;
                    break;
                }
            }
            if ($agent === null) {
                continue;
            }
            if (! (bool) ($session->dispatch_enabled ?? true)
                || ! in_array((string) ($session->session_state ?? 'candidate'), ['candidate', 'verified'], true)) {
                continue;
            }

            $activeBind = SaSessionAccount::query()
                ->where('browser_session_id', $session->id)
                ->where('is_active', true)
                ->first();
            $accountId = $activeBind?->account_id ?? $session->account_id;
            if ($accountId === null || (int) $accountId <= 0) {
                continue;
            }
            $account = SaAccount::query()->find($accountId);
            if (! $account instanceof SaAccount) {
                continue;
            }
            if ((string) $account->installation_id !== (string) $campaign->installation_id) {
                continue;
            }
            if (strcasecmp((string) $account->platform, (string) $campaign->platform) !== 0) {
                continue;
            }
            if ($preferAccountId !== null && (int) $accountId !== $preferAccountId) {
                continue;
            }
            if (! $this->modePolicy->canAssignAccount($mode, (int) $accountId, $assignedIds, $busyInCampaign)) {
                continue;
            }

            $affinity = SaAccountDeviceAffinity::query()->where('account_id', $accountId)->first();
            if ($affinity instanceof SaAccountDeviceAffinity && $affinity->affinity_until?->isFuture()
                && (int) $affinity->agent_id !== (int) $session->agent_id) {
                continue;
            }

            $busy = $session->locked_job_id !== null
                && ($session->lock_expires_at === null || $session->lock_expires_at->isFuture());

            $sessions[] = [
                'session_id' => $session->id,
                'agent_id' => $session->agent_id,
                'ready_status' => $session->ready_status,
                'busy' => $busy,
                'account_locked' => $this->accountLocks->isAccountBusy($accountId, $busyAccountIds),
                'platform' => $account->platform,
                'domain' => $account->domain,
                'account' => [
                    'platform' => $account->platform,
                    'domain' => $account->domain,
                    'platform_account_id' => $account->platform_account_id,
                    'verified' => (bool) $account->verified,
                    'identity_source' => $account->identity_source,
                ],
                'capabilities' => is_array($session->capabilities) ? $session->capabilities : [],
                'account_id' => $accountId,
            ];
        }

        $result = $this->eligibility->select($agents, $sessions, [
            'platform' => $campaign->platform,
            'target_count' => $target,
        ]);

        return [
            'selected' => $result['selected'],
            'skip_reasons' => $result['skip_reasons'],
        ];
    }

    /**
     * @param  array<string, mixed>  $sessionRow
     */
    private function reserveCampaignJob(
        SaCampaign $campaign,
        SaCampaignContent $content,
        array $sessionRow,
        CampaignExecutionMode $mode,
    ): ?SaJob {
        $session = SaBrowserSession::query()->lockForUpdate()->find($sessionRow['session_id'] ?? null);
        if (! $session instanceof SaBrowserSession) {
            return null;
        }
        if (! $this->sessionLocks->canLock([
            'locked_job_id' => $session->locked_job_id,
            'lock_expires_at' => $session->lock_expires_at,
        ], 0)) {
            return null;
        }

        $agent = SaAgent::query()->lockForUpdate()->find($session->agent_id);
        if (! $agent instanceof SaAgent) {
            return null;
        }

        $accountId = (int) ($sessionRow['account_id'] ?? 0);
        $account = SaAccount::query()->lockForUpdate()->find($accountId);
        if (! $account instanceof SaAccount) {
            return null;
        }

        $accountJobs = SaJob::query()
            ->where('account_id', $accountId)
            ->whereIn('status', AccountLockPolicy::ACTIVE_STATUSES)
            ->lockForUpdate()
            ->get(['id', 'status'])
            ->map(static fn (SaJob $j): array => [
                'job_id' => $j->id,
                'status' => $j->status,
                'account_id' => $accountId,
            ])->all();
        if (! $this->accountLocks->canAssign($accountId, $accountJobs)) {
            return null;
        }

        $idem = 'campaign:'.$campaign->campaign_uuid.':content:'.$content->content_uuid.':attempt:'
            .((int) SaJob::query()->where('campaign_content_id', $content->id)->count() + 1);

        $existing = SaJob::query()->where('idempotency_key', $idem)->lockForUpdate()->first();
        if ($existing instanceof SaJob) {
            return $existing;
        }

        $leaseToken = Str::random(40);
        $fencing = 1;
        $input = is_array($content->input) ? $content->input : [];

        $job = SaJob::query()->create([
            'job_uuid' => (string) Str::uuid(),
            'task_id' => null,
            'campaign_id' => $campaign->id,
            'campaign_content_id' => $content->id,
            'agent_id' => $agent->id,
            'browser_session_id' => $session->id,
            'account_id' => $accountId,
            'package_key' => $campaign->script_id,
            'package_version' => $campaign->script_version,
            'content_digest' => $campaign->script_content_digest,
            'status' => JobStatus::Reserved->value,
            'idempotency_key' => $idem,
            'lease_token' => $leaseToken,
            'fencing_generation' => $fencing,
            'payload' => [
                'input' => $input,
                'campaign_uuid' => $campaign->campaign_uuid,
                'content_uuid' => $content->content_uuid,
                'run_mode' => (string) ($campaign->run_mode ?: CampaignRunMode::Live->value),
                'execution_mode' => (string) $campaign->execution_mode,
            ],
            'reserved_at' => now(),
            'lease_expires_at' => now()->addSeconds(self::DEFAULT_LEASE_SECONDS),
            'dispatch_source' => 'campaign',
        ]);

        SaJobAttempt::query()->create([
            'attempt_uuid' => (string) Str::uuid(),
            'lease_token' => $leaseToken,
            'job_id' => $job->id,
            'attempt_no' => 1,
            'fencing_generation' => $fencing,
            'status' => CampaignAttemptStatus::Queued->value,
            'agent_id' => $agent->id,
            'browser_session_id' => $session->id,
            'account_id' => $accountId,
            'lease_expires_at' => now()->addSeconds(self::DEFAULT_LEASE_SECONDS),
        ]);

        SaAccountExecutionLease::query()->create([
            'account_id' => $accountId,
            'agent_id' => $agent->id,
            'browser_session_id' => $session->id,
            'job_id' => $job->id,
            'lease_token' => $leaseToken,
            'fencing_generation' => $fencing,
            'status' => 'active',
            'acquired_at' => now(),
            'heartbeat_at' => now(),
            'expires_at' => now()->addSeconds(self::DEFAULT_LEASE_SECONDS),
        ]);

        SaAccountDeviceAffinity::query()->updateOrCreate(['account_id' => $accountId], [
            'agent_id' => $agent->id,
            'last_used_at' => now(),
            'affinity_until' => now()->addMinutes((int) config('social-automation.account_device_affinity_minutes', 60)),
        ]);

        $session->fill([
            'locked_job_id' => $job->id,
            'locked_at' => now(),
            'lock_expires_at' => now()->addSeconds(self::DEFAULT_LEASE_SECONDS),
        ])->save();

        $agent->active_jobs = (int) $agent->active_jobs + 1;
        if ((int) $agent->active_jobs >= max(1, (int) $agent->max_concurrency)) {
            $agent->availability = AgentAvailability::Busy->value;
        }
        $agent->save();

        $content->status = CampaignContentStatus::Running->value;
        $content->assigned_account_id = $accountId;
        $content->current_job_id = $job->id;
        $content->save();

        if ($this->modePolicy->assignmentIsDurable($mode)) {
            SaCampaignAccountAssignment::query()->firstOrCreate(
                ['campaign_id' => $campaign->id, 'account_id' => $accountId],
                ['content_id' => $content->id, 'reason' => 'dispatch'],
            );
        }

        SaJobEvent::query()->create([
            'job_id' => $job->id,
            'event_type' => 'reserve',
            'from_status' => null,
            'to_status' => JobStatus::Reserved->value,
            'payload' => null,
            'occurred_at' => now(),
        ]);

        return $job;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $attrs
     * @param  list<array<string, mixed>>  $artifactRefs
     * @return array{ok: bool, data?: array<string, mixed>, errors?: list<string>, idempotent?: bool}
     */
    private function advanceClient(
        string $jobUuid,
        array $payload,
        ?int $clientApiKeyId,
        string $installationId,
        CampaignAttemptStatus $attemptStatus,
        JobStatus $to,
        string $event,
        array $attrs,
        bool $requireFence,
        bool $releaseLock,
        bool $terminalContent = false,
        array $artifactRefs = [],
    ): array {
        if (! SocialAutomationSchema::campaignsReady()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }

        try {
            return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use (
                $jobUuid, $payload, $clientApiKeyId, $installationId, $attemptStatus, $to, $event, $attrs, $requireFence, $releaseLock, $terminalContent, $artifactRefs
            ): array {
                $job = $this->authorizedCampaignJob($jobUuid, $clientApiKeyId, $installationId, true);
                if ($job === null) {
                    return ['ok' => false, 'errors' => ['job_not_found_or_forbidden']];
                }

                if ($requireFence) {
                    $presented = isset($payload['attempt_id']) ? (string) $payload['attempt_id'] : (isset($payload['attempt_uuid']) ? (string) $payload['attempt_uuid'] : null);
                    $active = $this->activeFenceAttemptUuid($job);
                    if ($this->fence->isDuplicateTerminal($presented, $active, (string) $job->status)
                        || $this->isDuplicateTerminalByLease($job, $payload)) {
                        return ['ok' => true, 'idempotent' => true, 'data' => ['job_id' => $job->job_uuid, 'status' => $job->status]];
                    }
                    $fenceCheck = $this->validateLeaseFence($job, $payload);
                    if (! ($fenceCheck['ok'] ?? false)) {
                        return ['ok' => false, 'errors' => [$fenceCheck['error'] ?? AttemptFencePolicy::ERR_MISMATCH]];
                    }
                }

                $from = (string) $job->status;
                foreach ($attrs as $k => $v) {
                    $job->{$k} = $v;
                }
                $job->status = $to->value;
                if ($to === JobStatus::Running) {
                    $job->lease_expires_at = now()->addSeconds(self::DEFAULT_LEASE_SECONDS);
                }
                $job->save();

                $attempt = $this->activeAttempt($job);
                if ($attempt instanceof SaJobAttempt) {
                    $attempt->status = $attemptStatus->value;
                    if ($to === JobStatus::Running) {
                        $attempt->lease_expires_at = now()->addSeconds(self::DEFAULT_LEASE_SECONDS);
                    }
                    if ($attemptStatus->isTerminal() || $attemptStatus === CampaignAttemptStatus::Unknown) {
                        $attempt->finished_at = now();
                        $attempt->result = $attrs['result'] ?? null;
                        $attempt->error_code = isset($attrs['error_code']) ? (string) $attrs['error_code'] : null;
                        $attempt->error_message = isset($attrs['error_message']) ? (string) $attrs['error_message'] : null;
                    }
                    $attempt->save();
                    if ($artifactRefs !== [] && SocialAutomationSchema::has('sa_artifacts')) {
                        $this->linkArtifactRefsToAttempt($job, $attempt, $artifactRefs);
                    }
                }

                SaJobEvent::query()->create([
                    'job_id' => $job->id,
                    'attempt_id' => $attempt?->id,
                    'event_type' => $event,
                    'from_status' => $from,
                    'to_status' => $to->value,
                    'payload' => null,
                    'occurred_at' => now(),
                ]);

                if ($releaseLock || $to->isTerminal()) {
                    $this->releaseJobLocks($job);
                }

                if ($terminalContent && $job->campaign_content_id) {
                    $this->applyTerminalContent($job, $attemptStatus, $attrs);
                }

                return ['ok' => true, 'data' => ['job_id' => $job->job_uuid, 'status' => $job->status]];
            });
        } catch (Throwable) {
            return ['ok' => false, 'errors' => ['advance_failed']];
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function applyTerminalContent(SaJob $job, CampaignAttemptStatus $attemptStatus, array $attrs): void
    {
        $content = SaCampaignContent::query()->where('id', $job->campaign_content_id)->lockForUpdate()->first();
        if (! $content instanceof SaCampaignContent) {
            return;
        }

        $contentStatus = match ($attemptStatus) {
            CampaignAttemptStatus::Succeeded => CampaignContentStatus::Success,
            CampaignAttemptStatus::Failed => CampaignContentStatus::FailedLocked,
            CampaignAttemptStatus::Unknown => CampaignContentStatus::Unknown,
            CampaignAttemptStatus::Cancelled => CampaignContentStatus::Cancelled,
            default => null,
        };
        if ($contentStatus === null) {
            return;
        }

        $content->status = $contentStatus->value;
        if ($contentStatus === CampaignContentStatus::FailedLocked || $contentStatus === CampaignContentStatus::Unknown) {
            $content->last_error_code = isset($attrs['error_code']) ? (string) $attrs['error_code'] : $attemptStatus->value;
            $content->last_error_message = isset($attrs['error_message']) ? (string) $attrs['error_message'] : null;
            $content->last_error_meta = [
                'job_id' => $job->job_uuid,
                'attempt_status' => $attemptStatus->value,
            ];
        }
        $content->save();

        $campaign = SaCampaign::query()->find($content->campaign_id);
        if ($campaign instanceof SaCampaign) {
            $this->maybeCompleteCampaign($campaign);
        }
    }

    private function maybeCompleteCampaign(SaCampaign $campaign): void
    {
        $open = SaCampaignContent::query()
            ->where('campaign_id', $campaign->id)
            ->whereNotIn('status', [
                CampaignContentStatus::Success->value,
                CampaignContentStatus::FailedLocked->value,
                CampaignContentStatus::Skipped->value,
                CampaignContentStatus::Cancelled->value,
            ])->exists();
        if ($open) {
            return;
        }
        if ((string) $campaign->status === CampaignStatus::Running->value
            || (string) $campaign->status === CampaignStatus::Paused->value) {
            $campaign->status = CampaignStatus::Completed->value;
            $campaign->completed_at = now();
            $campaign->next_recheck_at = null;
            $campaign->save();
        }
    }

    private function releaseJobLocks(SaJob $job): void
    {
        SaAccountExecutionLease::query()->where('job_id', $job->id)->where('status', 'active')->update([
            'status' => 'released',
            'released_at' => now(),
            'heartbeat_at' => now(),
        ]);
        if ($job->browser_session_id) {
            $session = SaBrowserSession::query()->lockForUpdate()->find($job->browser_session_id);
            if ($session instanceof SaBrowserSession && (int) $session->locked_job_id === (int) $job->id) {
                $session->fill([
                    'locked_job_id' => null,
                    'locked_at' => null,
                    'lock_expires_at' => null,
                    'last_task_at' => now(),
                ])->save();
            }
        }
        if ($job->agent_id) {
            $agent = SaAgent::query()->lockForUpdate()->find($job->agent_id);
            if ($agent instanceof SaAgent) {
                $agent->active_jobs = max(0, (int) $agent->active_jobs - 1);
                if ($agent->active_jobs === 0 && $agent->availability === AgentAvailability::Busy->value) {
                    $agent->availability = AgentAvailability::Available->value;
                }
                $agent->save();
            }
        }
    }

    private function forceCancelJob(SaJob $job): void
    {
        $attempt = $this->activeAttempt($job);
        if ($attempt instanceof SaJobAttempt) {
            $attempt->status = CampaignAttemptStatus::Cancelled->value;
            $attempt->finished_at = now();
            $attempt->save();
        }
        $job->status = JobStatus::Cancelled->value;
        $job->finished_at = now();
        $job->save();
        $this->releaseJobLocks($job);
    }

    private function authorizedAgent(string $agentUuid, ?int $clientApiKeyId, string $installationId): ?SaAgent
    {
        $agent = SaAgent::query()->where('agent_uuid', $agentUuid)->first();
        if (! $agent instanceof SaAgent) {
            return null;
        }
        if ((string) $agent->installation_id !== $installationId) {
            return null;
        }
        if ($clientApiKeyId !== null && $agent->client_api_key_id !== null
            && (int) $agent->client_api_key_id !== $clientApiKeyId) {
            return null;
        }

        return $agent;
    }

    private function authorizedCampaignJob(
        string $jobUuid,
        ?int $clientApiKeyId,
        string $installationId,
        bool $forUpdate,
    ): ?SaJob {
        $q = SaJob::query()->where('job_uuid', $jobUuid)->whereNotNull('campaign_content_id');
        if ($forUpdate) {
            $q->lockForUpdate();
        }
        $job = $q->first();
        if (! $job instanceof SaJob || ! $job->agent_id) {
            return null;
        }
        $agent = SaAgent::query()->find($job->agent_id);
        if (! $agent instanceof SaAgent) {
            return null;
        }
        if ((string) $agent->installation_id !== $installationId) {
            return null;
        }
        if ($clientApiKeyId !== null && $agent->client_api_key_id !== null
            && (int) $agent->client_api_key_id !== $clientApiKeyId) {
            return null;
        }

        return $job;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, error?: string}
     */
    private function validateLeaseFence(SaJob $job, array $payload): array
    {
        $presentedAttempt = isset($payload['attempt_id'])
            ? (string) $payload['attempt_id']
            : (isset($payload['attempt_uuid']) ? (string) $payload['attempt_uuid'] : null);
        $activeAttempt = $this->activeFenceAttemptUuid($job);
        $attemptCheck = $this->fence->validate($presentedAttempt, $activeAttempt);
        if (! ($attemptCheck['ok'] ?? false)) {
            return $attemptCheck;
        }

        $presentedToken = isset($payload['lease_token']) ? trim((string) $payload['lease_token']) : '';
        $activeToken = trim((string) ($job->lease_token ?? ''));
        if ($activeToken === '' || $presentedToken === '' || ! hash_equals($activeToken, $presentedToken)) {
            return ['ok' => false, 'error' => 'stale_lease_token'];
        }

        return ['ok' => true];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isDuplicateTerminalByLease(SaJob $job, array $payload): bool
    {
        $status = (string) $job->status;
        if (! in_array($status, ['completed', 'failed', 'cancelled'], true)) {
            return false;
        }
        $token = isset($payload['lease_token']) ? trim((string) $payload['lease_token']) : '';
        $active = trim((string) ($job->lease_token ?? ''));
        if ($token === '' || $active === '') {
            // After terminal, lease_token may remain on row for idempotency; also check attempt.
            $attempt = SaJobAttempt::query()
                ->where('job_id', $job->id)
                ->orderByDesc('attempt_no')
                ->first();
            if (! $attempt instanceof SaJobAttempt) {
                return false;
            }
            $presented = isset($payload['attempt_id']) ? (string) $payload['attempt_id'] : '';

            return $presented !== '' && hash_equals((string) $attempt->attempt_uuid, $presented);
        }

        return hash_equals($active, $token);
    }

    private function activeFenceAttemptUuid(SaJob $job): ?string
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $uuid = $payload['_fence']['attempt_uuid'] ?? null;

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    private function activeAttempt(SaJob $job): ?SaJobAttempt
    {
        $uuid = $this->activeFenceAttemptUuid($job);
        if ($uuid !== null) {
            $attempt = SaJobAttempt::query()->where('job_id', $job->id)->where('attempt_uuid', $uuid)->first();
            if ($attempt instanceof SaJobAttempt) {
                return $attempt;
            }
        }

        $attempt = SaJobAttempt::query()->where('job_id', $job->id)->orderByDesc('attempt_no')->first();

        return $attempt instanceof SaJobAttempt ? $attempt : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function claimPayload(SaJob $job, ?SaJobAttempt $attempt = null): array
    {
        $attempt ??= $this->activeAttempt($job);
        $account = $job->account_id ? SaAccount::query()->find($job->account_id) : null;
        $session = $job->browser_session_id ? SaBrowserSession::query()->find($job->browser_session_id) : null;
        $payload = is_array($job->payload) ? $job->payload : [];
        $input = is_array($payload['input'] ?? null) ? $payload['input'] : [];
        // Keep placeholders; never expand credentials. Strip accidental plaintext secrets.
        $input = $this->publicJobInput($input);

        $campaign = $job->campaign_id ? SaCampaign::query()->find($job->campaign_id) : null;
        $content = $job->campaign_content_id ? SaCampaignContent::query()->find($job->campaign_content_id) : null;

        $runMode = trim((string) ($payload['run_mode'] ?? ''));
        if ($runMode === '') {
            $runMode = trim((string) ($campaign?->run_mode ?? ''));
        }
        if ($runMode === '') {
            $runMode = CampaignRunMode::Live->value;
        }

        return [
            'job_id' => $job->job_uuid,
            'attempt_id' => $attempt?->attempt_uuid,
            'campaign_id' => $campaign?->campaign_uuid,
            'content_id' => $content?->content_uuid,
            'browser_id' => $session?->session_uuid,
            'account_id' => $account?->account_uuid,
            'script_id' => $job->package_key,
            'script_version' => $job->package_version,
            'run_mode' => $runMode,
            'execution_mode' => (string) ($payload['execution_mode'] ?? $campaign?->execution_mode),
            'input' => $input,
            'lease_token' => $job->lease_token ?? $attempt?->lease_token,
            'lease_expires_at' => ($job->lease_expires_at ?? $attempt?->lease_expires_at)?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function errorMessageFromPayload(array $payload): ?string
    {
        if (is_array($payload['error'] ?? null)) {
            $msg = $payload['error']['message'] ?? null;

            return is_string($msg) ? substr($msg, 0, 2000) : null;
        }
        if (isset($payload['error_message'])) {
            return substr((string) $payload['error_message'], 0, 2000);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function publicJobInput(array $input): array
    {
        $out = [];
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $out[$key] = $this->publicJobInput($value);
                continue;
            }
            if (! is_string($value)) {
                $out[$key] = $value;
                continue;
            }
            $trimmed = trim($value);
            if (in_array($trimmed, \Omnichannel\Addons\SocialAutomation\Domain\CampaignInputSanitizer::ALLOWED_PLACEHOLDERS, true)) {
                $out[$key] = $trimmed;
                continue;
            }
            $lower = strtolower((string) $key);
            if (str_contains($lower, 'password') || str_contains($lower, 'secret') || str_contains($lower, 'token')
                || str_contains($lower, 'credential') || str_contains($lower, 'cookie')) {
                $out[$key] = '[redacted]';
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Normalize artifact references from result payload (existing artifact contract).
     * Screenshot metadata is evidence of capture only — never publication proof.
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, refs?: list<array<string, mixed>>, errors?: list<string>}
     */
    private function normalizeArtifactRefs(array $payload): array
    {
        $raw = $payload['artifacts'] ?? null;
        if ($raw === null) {
            return ['ok' => true, 'refs' => []];
        }
        if (! is_array($raw)) {
            return ['ok' => false, 'errors' => ['artifacts_invalid']];
        }

        $refs = [];
        foreach ($raw as $i => $row) {
            if (! is_array($row)) {
                return ['ok' => false, 'errors' => ['artifact_invalid:'.$i]];
            }
            $kind = strtolower(trim((string) ($row['kind'] ?? 'other')));
            if (! in_array($kind, ['screenshot', 'video', 'log', 'other'], true)) {
                return ['ok' => false, 'errors' => ['artifact_kind_invalid:'.$i]];
            }
            $meta = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
            $mime = strtolower(trim((string) ($row['mime'] ?? ($kind === 'screenshot' ? 'image/png' : 'application/octet-stream'))));
            $size = (int) ($row['size_bytes'] ?? 0);
            $gate = $this->artifacts->validate($mime, max(0, $size), $meta);
            if (! ($gate['ok'] ?? false)) {
                return ['ok' => false, 'errors' => array_map(
                    static fn (string $e): string => 'artifact_'.$e.':'.$i,
                    $gate['errors'] ?? ['invalid']
                )];
            }
            if ($kind === 'screenshot') {
                $outcome = strtolower((string) ($row['outcome'] ?? $meta['outcome'] ?? 'success'));
                $shot = $this->artifacts->validateScreenshotMetadata($outcome, $meta);
                if (! ($shot['ok'] ?? false)) {
                    return ['ok' => false, 'errors' => $shot['errors'] ?? ['screenshot_metadata_invalid']];
                }
                if (($shot['phase'] ?? null) !== null) {
                    $meta['phase'] = $shot['phase'];
                }
                $meta['proves_publication'] = false;
                $meta['intent'] = $meta['intent'] ?? 'pre_publish_stop';
            }

            $refs[] = [
                'artifact_uuid' => isset($row['artifact_uuid']) ? (string) $row['artifact_uuid'] : null,
                'kind' => $kind,
                'mime' => $mime,
                'size_bytes' => $size > 0 ? $size : null,
                'checksum_sha256' => isset($row['checksum_sha256']) ? strtolower((string) $row['checksum_sha256']) : null,
                'metadata' => $this->observability->redactArray($meta),
            ];
        }

        return ['ok' => true, 'refs' => $refs];
    }

    /**
     * @param  list<array<string, mixed>>  $refs
     */
    private function linkArtifactRefsToAttempt(SaJob $job, SaJobAttempt $attempt, array $refs): void
    {
        foreach ($refs as $ref) {
            $uuid = trim((string) ($ref['artifact_uuid'] ?? ''));
            if ($uuid === '') {
                continue;
            }
            $artifact = SaArtifact::query()->where('artifact_uuid', $uuid)->where('job_id', $job->id)->first();
            if (! $artifact instanceof SaArtifact) {
                continue;
            }
            $meta = is_array($artifact->metadata) ? $artifact->metadata : [];
            $incoming = is_array($ref['metadata'] ?? null) ? $ref['metadata'] : [];
            $artifact->attempt_id = $attempt->id;
            $artifact->metadata = array_merge($meta, $incoming, [
                'linked_attempt_uuid' => $attempt->attempt_uuid,
                'proves_publication' => false,
                'run_mode' => is_array($job->payload) ? ($job->payload['run_mode'] ?? null) : null,
            ]);
            $artifact->save();
        }
    }
}
