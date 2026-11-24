<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Orchestration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Artifacts\ArtifactAcceptPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\AccountLockPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\AttemptFencePolicy;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignModePolicy;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignRecheckPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignScriptVariableSchema;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodePipeline;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignTestScriptPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignV2ExecutionPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\EligibilitySelector;
use Omnichannel\Addons\SocialAutomation\Domain\JobIdempotencyKey;
use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;
use Omnichannel\Addons\SocialAutomation\Domain\SessionLockPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\VariantPreferenceService;
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
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContent;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContentAssignment;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaJobAttempt;
use Omnichannel\Addons\SocialAutomation\Models\SaJobEvent;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaSessionAccount;
use Omnichannel\Addons\SocialAutomation\Services\ActionEntryUrlResolver;
use Omnichannel\Addons\SocialAutomation\Services\ActionScriptDistributionService;
use Omnichannel\Addons\SocialAutomation\Services\SocialAutomationSettingsService;
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
        private readonly ActionScriptDistributionService $actionScripts = new ActionScriptDistributionService,
        private readonly CampaignShortcodePipeline $shortcodePipeline = new CampaignShortcodePipeline,
        private readonly CampaignScriptVariableSchema $variableSchema = new CampaignScriptVariableSchema,
        private readonly SocialAutomationSettingsService $settings = new SocialAutomationSettingsService,
        private readonly ActionEntryUrlResolver $actionEntryUrls = new ActionEntryUrlResolver,
        private readonly VariantPreferenceService $variantPreferences = new VariantPreferenceService,
    ) {}

    /**
     * Dispatch pending/waiting contents for a running campaign.
     *
     * @return array{ok: bool, dispatched: int, waiting: int, errors?: list<string>}
     */
    public function dispatchCampaign(SaCampaign $campaign, ?\DateTimeInterface $now = null): array
    {
        if (! SocialAutomationSchema::campaignsReady()) {
            return ['ok' => false, 'dispatched' => 0, 'waiting' => 0, 'errors' => ['schema_unavailable']];
        }
        if ((int) ($campaign->plan_version ?? 1) >= 2) {
            if (! CampaignV2ExecutionPolicy::isEnabled()) {
                return ['ok' => false, 'dispatched' => 0, 'waiting' => 0, 'errors' => ['v2_campaign_execution_unsupported']];
            }

            return $this->dispatchV2Campaign($campaign, $now);
        }

        $status = CampaignStatus::tryFrom((string) $campaign->status);
        if ($status === null || ! $status->allowsDispatch()) {
            return ['ok' => true, 'dispatched' => 0, 'waiting' => 0];
        }

        try {
            return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($campaign): array {
                $locked = SaCampaign::query()->notDeleted()->where('id', $campaign->id)->lockForUpdate()->first();
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
                    // Hard Account pin (create account_id / content.assigned_account_id / campaign.meta).
                    // NEVER silently fall back to another eligible account when pin is set.
                    $requiredAccountId = $this->resolveRequiredAccountId($locked, $content);

                    // Mode A: durable assignment blocks OTHER contents; retry may reuse own assigned account.
                    $blockedAssigned = $assignedIds;
                    if ($mode === CampaignExecutionMode::OneAccountOneJob && $requiredAccountId !== null) {
                        $blockedAssigned = array_values(array_filter(
                            $assignedIds,
                            static fn ($id): bool => (int) $id !== $requiredAccountId
                        ));
                    }
                    $selection = $this->selectEligibleForCampaign(
                        $locked,
                        $mode,
                        $blockedAssigned,
                        $busyInCampaign,
                        1,
                        $requiredAccountId,
                    );
                    if ($selection['selected'] === []) {
                        $content->status = CampaignContentStatus::WaitingResources->value;
                        $content->last_error_code = $requiredAccountId !== null
                            ? 'pinned_account_unavailable'
                            : 'waiting_resources';
                        $content->last_error_message = $requiredAccountId !== null
                            ? 'Pinned account is offline or not eligible; no fallback'
                            : null;
                        $content->save();
                        $stillWaiting++;
                        continue;
                    }

                    $row = $selection['selected'][0];
                    $selectedAccountId = (int) ($row['account_id'] ?? 0);
                    if ($requiredAccountId !== null && $selectedAccountId !== $requiredAccountId) {
                        // Defense-in-depth: refuse wrong account even if selector regresses.
                        $content->status = CampaignContentStatus::WaitingResources->value;
                        $content->last_error_code = 'pinned_account_mismatch';
                        $content->last_error_message = 'Dispatch refused non-pinned account (no silent fallback)';
                        $content->save();
                        $stillWaiting++;
                        continue;
                    }

                    $inputGate = $this->assertContentInputForDispatch($locked, $content);
                    if (! ($inputGate['ok'] ?? false)) {
                        $content->status = CampaignContentStatus::WaitingResources->value;
                        $content->last_error_code = (string) (($inputGate['errors'][0] ?? 'content_input_invalid'));
                        $content->last_error_message = 'Content input failed pre-dispatch validation';
                        $content->save();
                        $stillWaiting++;
                        continue;
                    }

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
        } catch (Throwable $e) {
            // Never log raw QueryException message — it embeds SQL + bindings (payload/input).
            $sqlstate = null;
            $driverCode = null;
            if ($e instanceof \Illuminate\Database\QueryException) {
                $sqlstate = is_array($e->errorInfo ?? null) ? ($e->errorInfo[0] ?? null) : null;
                $driverCode = is_array($e->errorInfo ?? null) ? ($e->errorInfo[1] ?? null) : null;
            }
            Log::warning('social_automation.campaign_dispatch_failed', [
                'campaign_id' => $campaign->id ?? null,
                'campaign_uuid' => $campaign->campaign_uuid ?? null,
                'exception' => $e::class,
                'sqlstate' => $sqlstate,
                'driver_code' => $driverCode,
            ]);

            return ['ok' => false, 'dispatched' => 0, 'waiting' => 0, 'errors' => ['dispatch_failed']];
        }
    }

    /**
     * Unified single-cycle V2 execution: re-evaluate waiting/quarantined articles
     * against each article's fixed target Action Tag/Site and dispatch eligible Jobs.
     *
     * @return array{ok: bool, dispatched: int, waiting: int, errors?: list<string>}
     */
    private function dispatchV2Campaign(SaCampaign $campaign, ?\DateTimeInterface $now = null): array
    {
        if (! SocialAutomationSchema::campaignV2Ready()) {
            return ['ok' => false, 'dispatched' => 0, 'waiting' => 0, 'errors' => ['schema_unavailable']];
        }

        $cycleNow = $now ?? now();

        try {
            return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($campaign, $cycleNow): array {
                $locked = SaCampaign::query()->notDeleted()->where('id', $campaign->id)->lockForUpdate()->first();
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

                $planTagsById = SaCampaignActionTag::query()
                    ->where('campaign_id', $locked->id)
                    ->get()
                    ->keyBy('id');

                $campaignMeta = is_array($locked->meta) ? $locked->meta : [];
                $gates = is_array($campaignMeta['action_tag_gates'] ?? null) ? $campaignMeta['action_tag_gates'] : [];

                // Active in-flight Action Tag queues (1 active job per Action Tag queue).
                $inFlightTagIds = [];
                $runningContentIds = SaCampaignContent::query()
                    ->where('campaign_id', $locked->id)
                    ->where('status', CampaignContentStatus::Running->value)
                    ->pluck('id')
                    ->all();
                if ($runningContentIds !== []) {
                    $runningAssignments = SaCampaignContentAssignment::query()
                        ->whereIn('campaign_content_id', $runningContentIds)
                        ->pluck('campaign_action_tag_id')
                        ->all();
                    foreach ($runningAssignments as $tid) {
                        $inFlightTagIds[(int) $tid] = true;
                    }
                }
                $activeManualJobs = SaJob::query()
                    ->where('campaign_id', $locked->id)
                    ->whereNull('campaign_content_id')
                    ->whereIn('status', AccountLockPolicy::ACTIVE_STATUSES)
                    ->get(['payload']);
                foreach ($activeManualJobs as $mj) {
                    $mjTagId = is_array($mj->payload) ? (int) ($mj->payload['campaign_action_tag_id'] ?? 0) : 0;
                    if ($mjTagId > 0) {
                        $inFlightTagIds[$mjTagId] = true;
                    }
                }

                // Fallback failure gate detection for legacy FailedLocked/Unknown contents without gate metadata.
                $blockedTagIds = [];
                foreach ($gates as $tidKey => $gateRow) {
                    if (is_array($gateRow) && ($gateRow['blocked'] ?? false) === true) {
                        $blockedTagIds[(int) $tidKey] = true;
                    }
                }
                $failedOrUnknownIds = SaCampaignContent::query()
                    ->where('campaign_id', $locked->id)
                    ->whereIn('status', [
                        CampaignContentStatus::FailedLocked->value,
                        CampaignContentStatus::Unknown->value,
                    ])
                    ->pluck('id')
                    ->all();
                if ($failedOrUnknownIds !== []) {
                    $failedAssignments = SaCampaignContentAssignment::query()
                        ->whereIn('campaign_content_id', $failedOrUnknownIds)
                        ->pluck('campaign_action_tag_id')
                        ->all();
                    foreach ($failedAssignments as $tid) {
                        $tidInt = (int) $tid;
                        $gateKey = (string) $tidInt;
                        if (! array_key_exists($gateKey, $gates)) {
                            $blockedTagIds[$tidInt] = true;
                        }
                    }
                }

                // Batch-load assignments for all candidate contents in deterministic queue order.
                $contentIds = $contents->pluck('id')->all();
                $assignmentsByContent = $contentIds !== []
                    ? SaCampaignContentAssignment::query()
                        ->whereIn('campaign_content_id', $contentIds)
                        ->get()
                        ->groupBy('campaign_content_id')
                    : collect();

                $handledQueues = [];
                $dispatched = 0;
                $stillWaiting = 0;
                $earliestNextRunAt = null;
                $lastSuccessByTagId = $this->lastSuccessByActionTag($locked);

                foreach ($contents as $content) {
                    // Prevent duplicate dispatch: one active logical Job per Article.
                    $hasActiveJob = $content->current_job_id !== null
                        || SaJob::query()
                            ->where('campaign_content_id', $content->id)
                            ->whereIn('status', AccountLockPolicy::ACTIVE_STATUSES)
                            ->exists();
                    if ($hasActiveJob) {
                        continue;
                    }

                    $assignments = $assignmentsByContent->get($content->id, collect());
                    if ($assignments->count() !== 1) {
                        $content->status = CampaignContentStatus::WaitingResources->value;
                        $content->last_error_code = $assignments->count() > 1
                            ? 'v2_multi_tag_content_unsupported'
                            : 'content_assignment_required';
                        $content->last_error_message = 'Article must belong to exactly one target Action Tag';
                        $content->save();
                        $stillWaiting++;
                        continue;
                    }

                    /** @var SaCampaignContentAssignment $assignment */
                    $assignment = $assignments->first();
                    $planTagId = (int) $assignment->campaign_action_tag_id;
                    /** @var SaCampaignActionTag|null $planTag */
                    $planTag = $planTagsById->get($planTagId);
                    if (! $planTag instanceof SaCampaignActionTag || ! $planTag->is_enabled) {
                        $content->status = CampaignContentStatus::WaitingResources->value;
                        $content->last_error_code = 'action_tag_unavailable';
                        $content->last_error_message = 'Target Action Tag is missing or disabled';
                        $content->save();
                        $stillWaiting++;
                        continue;
                    }

                    $errMeta = is_array($content->last_error_meta) ? $content->last_error_meta : [];
                    $hasOneShotGateRecovery = ($errMeta['manual_recovery_origin'] ?? null) === 'manual_retry'
                        && ($errMeta['gate_recovery_authorized'] ?? false) === true
                        && is_string($errMeta['gate_recovery_token'] ?? null)
                        && trim((string) $errMeta['gate_recovery_token']) !== '';

                    // Blocked by Failure: stop automatic dispatch for this Action Tag queue unless this head item
                    // holds an explicit one-shot manual_retry authorization; keep following items Pending.
                    if (isset($blockedTagIds[$planTagId])) {
                        if (! $hasOneShotGateRecovery || isset($inFlightTagIds[$planTagId]) || isset($handledQueues[$planTagId])) {
                            $this->normalizeNonHeadWaitingToPending($content);
                            continue;
                        }
                    }

                    // Sequential queue: at most one active/head item evaluated per Action Tag queue.
                    if (isset($inFlightTagIds[$planTagId]) || isset($handledQueues[$planTagId])) {
                        $this->normalizeNonHeadWaitingToPending($content);
                        continue;
                    }
                    $handledQueues[$planTagId] = true;

                    // Posting interval check (before resource selection).
                    $intervalMinutes = max(0, (int) ($planTag->interval_minutes ?? 0));
                    $lastSuccessAt = $lastSuccessByTagId[$planTagId] ?? null;
                    if ($intervalMinutes > 0 && $lastSuccessAt instanceof \DateTimeInterface) {
                        $nextRunAt = \Carbon\CarbonImmutable::instance($lastSuccessAt)->addMinutes($intervalMinutes);
                        if ($cycleNow->lt($nextRunAt)) {
                            $this->normalizeNonHeadWaitingToPending($content);
                            if ($earliestNextRunAt === null || $nextRunAt->lt($earliestNextRunAt)) {
                                $earliestNextRunAt = $nextRunAt;
                            }
                            continue;
                        }
                    }

                    $requiredAccountId = $content->assigned_account_id ? (int) $content->assigned_account_id : null;
                    $blockedAssigned = $assignedIds;
                    if ($mode === CampaignExecutionMode::OneAccountOneJob && $requiredAccountId !== null) {
                        $blockedAssigned = array_values(array_filter(
                            $assignedIds,
                            static fn ($id): bool => (int) $id !== $requiredAccountId
                        ));
                    }

                    $selection = $this->selectEligibleForCampaign(
                        $locked,
                        $mode,
                        $blockedAssigned,
                        $busyInCampaign,
                        1,
                        $requiredAccountId,
                        $planTag,
                    );
                    if ($selection['selected'] === []) {
                        $content->status = CampaignContentStatus::WaitingResources->value;
                        $content->last_error_code = 'waiting_resources';
                        $content->last_error_message = $this->formatWaitingResourcesMessage(
                            (string) $planTag->site_key,
                            $selection['skip_reasons'] ?? [],
                        );
                        $content->save();
                        $stillWaiting++;
                        continue;
                    }

                    $row = $selection['selected'][0];
                    $errMeta = is_array($content->last_error_meta) ? $content->last_error_meta : [];
                    $executionOrigin = ($errMeta['manual_recovery_origin'] ?? null) === 'manual_retry'
                        ? 'manual_retry'
                        : 'scheduler';

                    $job = $this->reserveCampaignJob($locked, $content, $row, $mode, [
                        'script_id' => (string) $planTag->script_id,
                        'script_version' => (string) $planTag->script_version,
                        'script_content_digest' => $planTag->script_content_digest,
                        'input' => is_array($assignment->input) ? $assignment->input : [],
                        'campaign_action_tag_id' => $planTagId,
                        'plan_tag_id' => (string) $planTag->plan_tag_uuid,
                        'action_tag_id' => (int) $planTag->action_tag_id,
                        'action_tag_key' => (string) $planTag->action_tag_key,
                        'site_key' => (string) $planTag->site_key,
                        'release_id' => (int) $planTag->release_id,
                        'variant_group_id' => $planTag->variant_group_uuid ?? null,
                        'script_variants' => is_array($planTag->pinned_variants ?? null) ? $planTag->pinned_variants : null,
                        'execution_origin' => $executionOrigin,
                    ]);
                    if ($job === null) {
                        $content->status = CampaignContentStatus::WaitingResources->value;
                        $content->save();
                        $stillWaiting++;
                        continue;
                    }

                    $content->last_error_code = null;
                    $content->last_error_message = null;
                    if (isset($errMeta['manual_recovery_origin'])
                        || isset($errMeta['gate_recovery_authorized'])
                        || isset($errMeta['gate_recovery_token'])
                        || isset($errMeta['gate_recovery_authorized_at'])) {
                        unset(
                            $errMeta['manual_recovery_origin'],
                            $errMeta['gate_recovery_authorized'],
                            $errMeta['gate_recovery_token'],
                            $errMeta['gate_recovery_authorized_at'],
                        );
                        $content->last_error_meta = $errMeta === [] ? null : $errMeta;
                    }
                    $content->save();

                    $assignment->status = CampaignContentStatus::Running->value;
                    $assignment->save();

                    $accountId = (int) ($row['account_id'] ?? 0);
                    if ($accountId > 0) {
                        $assignedIds[] = $accountId;
                        $busyInCampaign[] = $accountId;
                    }
                    $inFlightTagIds[$planTagId] = true;
                    $dispatched++;
                }

                $waitingLeft = $stillWaiting > 0 || SaCampaignContent::query()
                    ->where('campaign_id', $locked->id)
                    ->where('status', CampaignContentStatus::WaitingResources->value)
                    ->exists();

                $intervalSeconds = $this->settings->getSchedulerIntervalSeconds((string) $locked->installation_id);
                if ($waitingLeft) {
                    $normalRecheck = $this->recheck->nextRecheckAt($cycleNow, $intervalSeconds);
                    $locked->next_recheck_at = ($earliestNextRunAt !== null && $earliestNextRunAt->lt($normalRecheck))
                        ? $earliestNextRunAt
                        : $normalRecheck;
                } elseif ($earliestNextRunAt !== null) {
                    $locked->next_recheck_at = $earliestNextRunAt;
                } else {
                    $locked->next_recheck_at = null;
                }
                $locked->save();
                $this->maybeCompleteCampaign($locked);

                return ['ok' => true, 'dispatched' => $dispatched, 'waiting' => $stillWaiting];
            });
        } catch (Throwable $e) {
            Log::warning('social_automation.campaign_v2_dispatch_failed', [
                'campaign_id' => $campaign->id ?? null,
                'campaign_uuid' => $campaign->campaign_uuid ?? null,
                'exception' => $e::class,
            ]);

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
            ->notDeleted()
            ->where('status', CampaignStatus::Running->value)
            ->where(function ($q) use ($now): void {
                $q->whereNull('next_recheck_at')->orWhere('next_recheck_at', '<=', $now);
            })
            ->orderBy('id')
            ->limit(50)
            ->get();

        foreach ($campaigns as $campaign) {
            if ((int) ($campaign->plan_version ?? 1) >= 2 && ! CampaignV2ExecutionPolicy::isEnabled()) {
                continue;
            }
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
            $result = $this->dispatchCampaign($campaign, $now);
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

                $reservedJobs = SaJob::query()
                    ->where('agent_id', $agent->id)
                    ->whereIn('browser_session_id', $sessionIds)
                    ->where(function ($q): void {
                        $q->whereNotNull('campaign_content_id')->orWhereNotNull('campaign_id');
                    })
                    ->where('status', JobStatus::Reserved->value)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $job = $reservedJobs->first(function (SaJob $candidate): bool {
                    if ($candidate->campaign_id === null) {
                        return true;
                    }
                    $campaign = SaCampaign::query()->notDeleted()->find($candidate->campaign_id);

                    return $campaign instanceof SaCampaign
                        && ! CampaignStatus::tryFrom((string) $campaign->status)?->isTerminal();
                });

                if (! $job instanceof SaJob) {
                    // Idempotent re-claim of already claimed/running for same agent
                    $active = SaJob::query()
                        ->where('agent_id', $agent->id)
                        ->whereIn('browser_session_id', $sessionIds)
                        ->where(function ($q): void {
                            $q->whereNotNull('campaign_content_id')->orWhereNotNull('campaign_id');
                        })
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
        if (isset($payload['step_count']) && is_numeric($payload['step_count']) && (int) $payload['step_count'] >= 0) {
            $output = is_array($output) ? $output : [];
            $output['step_count'] = (int) $payload['step_count'];
        }

        $jobStatus = $attemptStatus->toJobStatus();
        $release = $attemptStatus !== CampaignAttemptStatus::Unknown;
        $attrs = [
            'result' => $output === [] ? null : $output,
            'error_code' => is_array($payload['error'] ?? null)
                ? ($payload['error']['code'] ?? $payload['error_code'] ?? null)
                : ($payload['error_code'] ?? null),
            'error_message' => $this->errorMessageFromPayload($payload),
            'finished_at' => now(),
        ];
        if (isset($payload['duration_ms']) && is_numeric($payload['duration_ms']) && (int) $payload['duration_ms'] >= 0) {
            $attrs['duration_ms'] = (int) $payload['duration_ms'];
        }

        return $this->advanceClient(
            $jobUuid,
            $payload,
            $clientApiKeyId,
            $installationId,
            $attemptStatus,
            $jobStatus,
            'result',
            $attrs,
            requireFence: true,
            releaseLock: $release,
            terminalContent: true,
            artifactRefs: $artifactRefs['refs'] ?? [],
        );
    }

    /**
     * Authenticated Client binary artifact upload (screenshots, etc.) stored outside public webroot.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, idempotent?: bool, artifact?: array<string, mixed>, errors?: list<string>}
     */
    public function uploadCampaignArtifact(
        string $jobUuid,
        array $input,
        ?string $binary,
        ?int $clientApiKeyId,
        string $installationId,
    ): array {
        if (! SocialAutomationSchema::campaignsReady() || ! SocialAutomationSchema::has('sa_artifacts')) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }

        if ($binary === null || $binary === '') {
            return ['ok' => false, 'errors' => ['content_invalid']];
        }

        $mime = strtolower(trim((string) ($input['mime'] ?? 'image/png')));
        $kind = strtolower(trim((string) ($input['kind'] ?? 'screenshot')));
        if (! in_array($kind, ['screenshot', 'video', 'log', 'other'], true)) {
            return ['ok' => false, 'errors' => ['artifact_kind_invalid']];
        }

        $meta = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];
        foreach (['variant_id', 'language_label', 'package_key', 'screenshot_role', 'role', 'step', 'failed_step', 'phase', 'outcome'] as $field) {
            if (isset($input[$field]) && ! isset($meta[$field])) {
                $meta[$field] = is_string($input[$field]) ? trim($input[$field]) : $input[$field];
            }
        }
        foreach (['release_id', 'priority'] as $intField) {
            if (isset($input[$intField]) && is_numeric($input[$intField]) && ! isset($meta[$intField])) {
                $meta[$intField] = (int) $input[$intField];
            }
        }

        $policyCheck = $this->artifacts->validate($mime, strlen($binary), $meta);
        if (! ($policyCheck['ok'] ?? false)) {
            return ['ok' => false, 'errors' => $policyCheck['errors'] ?? ['artifact_invalid']];
        }

        if ($kind === 'screenshot') {
            $outcome = strtolower(trim((string) ($input['outcome'] ?? $meta['outcome'] ?? 'success')));
            $shotCheck = $this->artifacts->validateScreenshotMetadata($outcome, $meta);
            if (! ($shotCheck['ok'] ?? false)) {
                return ['ok' => false, 'errors' => $shotCheck['errors'] ?? ['screenshot_metadata_invalid']];
            }
            if (($shotCheck['phase'] ?? null) !== null) {
                $meta['phase'] = $shotCheck['phase'];
            }
            $meta['outcome'] = $outcome;
            $meta['proves_publication'] = false;
        }

        $checksum = hash('sha256', $binary);
        $job = $this->authorizedCampaignJob($jobUuid, $clientApiKeyId, $installationId, false);
        if (! $job instanceof SaJob) {
            return ['ok' => false, 'errors' => ['job_not_found_or_forbidden']];
        }

        $jobStatus = JobStatus::tryFrom((string) $job->status);
        if ($jobStatus === JobStatus::Cancelled) {
            return ['ok' => false, 'errors' => ['job_cancelled']];
        }

        $attemptUuid = trim((string) ($input['attempt_uuid'] ?? $input['attempt_id'] ?? ''));
        $activeFence = $this->activeFenceAttemptUuid($job);
        $attempt = null;
        if ($attemptUuid !== '') {
            $attempt = SaJobAttempt::query()
                ->where('job_id', $job->id)
                ->where('attempt_uuid', $attemptUuid)
                ->first();
            if (! $attempt instanceof SaJobAttempt) {
                return ['ok' => false, 'errors' => ['stale_attempt']];
            }
            if ((string) $attempt->status === CampaignAttemptStatus::Cancelled->value) {
                return ['ok' => false, 'errors' => ['stale_attempt']];
            }
            if ($activeFence !== null && ! hash_equals($activeFence, $attemptUuid) && ! $jobStatus?->isTerminal()) {
                return ['ok' => false, 'errors' => ['stale_attempt']];
            }
        } else {
            $attempt = $this->activeAttempt($job);
            if (! $attempt instanceof SaJobAttempt) {
                return ['ok' => false, 'errors' => ['stale_attempt']];
            }
            $attemptUuid = (string) $attempt->attempt_uuid;
        }

        // Idempotent duplicate check (same job + attempt + sha256 checksum).
        $existing = SaArtifact::query()
            ->where('job_id', $job->id)
            ->where('attempt_id', $attempt->id)
            ->where('checksum_sha256', $checksum)
            ->first();
        if ($existing instanceof SaArtifact
            && $this->artifacts->isDuplicateChecksum($existing->checksum_sha256, $checksum)) {
            return [
                'ok' => true,
                'idempotent' => true,
                'artifact' => [
                    'artifact_uuid' => (string) $existing->artifact_uuid,
                    'job_uuid' => (string) $job->job_uuid,
                    'attempt_uuid' => (string) $attempt->attempt_uuid,
                    'kind' => (string) $existing->kind,
                    'mime' => (string) $existing->mime,
                    'size_bytes' => (int) $existing->size_bytes,
                    'checksum_sha256' => (string) $existing->checksum_sha256,
                    'metadata' => is_array($existing->metadata) ? $existing->metadata : null,
                ],
            ];
        }

        $artifactUuid = (string) Str::uuid();
        $ext = match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'application/json' => 'json',
            'text/plain' => 'txt',
            default => 'bin',
        };
        $storagePath = sprintf('social-automation/artifacts/%s/%s.%s', (string) $job->job_uuid, $artifactUuid, $ext);
        if (! Storage::disk('local')->put($storagePath, $binary)) {
            return ['ok' => false, 'errors' => ['artifact_storage_failed']];
        }

        $meta['attempt_uuid'] = $attemptUuid;
        $meta = $this->observability->redactArray($meta);

        $artifact = SaArtifact::query()->create([
            'artifact_uuid' => $artifactUuid,
            'job_id' => $job->id,
            'attempt_id' => $attempt->id,
            'kind' => $kind,
            'mime' => $mime,
            'size_bytes' => strlen($binary),
            'storage_disk' => 'local',
            'storage_path' => $storagePath,
            'checksum_sha256' => $checksum,
            'metadata' => $meta,
            'retain_until' => now()->addDays(30),
        ]);

        return [
            'ok' => true,
            'idempotent' => false,
            'artifact' => [
                'artifact_uuid' => (string) $artifact->artifact_uuid,
                'job_uuid' => (string) $job->job_uuid,
                'attempt_uuid' => (string) $attempt->attempt_uuid,
                'kind' => (string) $artifact->kind,
                'mime' => (string) $artifact->mime,
                'size_bytes' => (int) $artifact->size_bytes,
                'checksum_sha256' => (string) $artifact->checksum_sha256,
                'metadata' => is_array($artifact->metadata) ? $artifact->metadata : null,
            ],
        ];
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

                $campaign = SaCampaign::query()->notDeleted()->where('id', $locked->campaign_id)->lockForUpdate()->first();
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

                $isV2 = (int) ($campaign->plan_version ?? 1) >= 2;
                $status = CampaignContentStatus::tryFrom((string) $locked->status);
                $canRetryPendingGated = $status === CampaignContentStatus::Pending
                    && $isV2
                    && $this->isPendingContentEligibleForGateRecovery($campaign, $locked);
                if ($status !== CampaignContentStatus::FailedLocked
                    && $status !== CampaignContentStatus::Unknown
                    && ! $canRetryPendingGated) {
                    return ['ok' => false, 'errors' => ['content_not_retryable']];
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
                $locked->last_error_meta = [
                    'manual_recovery_origin' => 'manual_retry',
                    'gate_recovery_authorized' => true,
                    'gate_recovery_token' => (string) Str::uuid(),
                    'gate_recovery_authorized_at' => now()->toIso8601String(),
                    'idempotency_key' => $idempotencyKey,
                ];
                $locked->current_job_id = null;
                $locked->admin_action_idempotency = $idempotencyKey;
                $locked->admin_action_at = now();
                $locked->save();

                if ($isV2 && SocialAutomationSchema::campaignV2Ready()) {
                    SaCampaignContentAssignment::query()
                        ->where('campaign_content_id', $locked->id)
                        ->whereIn('status', [
                            CampaignContentStatus::FailedLocked->value,
                            CampaignContentStatus::Unknown->value,
                        ])
                        ->update(['status' => CampaignContentStatus::Pending->value]);
                }

                if (! $isV2 && (string) $campaign->status !== CampaignStatus::Running->value) {
                    $campaign->status = CampaignStatus::Running->value;
                    $campaign->paused_at = null;
                }
                if ((string) $campaign->status === CampaignStatus::Running->value) {
                    $campaign->next_recheck_at = now();
                }
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
     * Allow re-authorizing an already-Pending V2 queue-head Content only when its Action Tag gate is blocked
     * and no active Job exists (e.g. a legacy manual_retry without one-shot gate authorization).
     */
    private function isPendingContentEligibleForGateRecovery(SaCampaign $campaign, SaCampaignContent $content): bool
    {
        if (! SocialAutomationSchema::campaignV2Ready()) {
            return false;
        }
        if ($content->current_job_id !== null) {
            return false;
        }
        $hasActiveJob = SaJob::query()
            ->where('campaign_content_id', $content->id)
            ->whereIn('status', AccountLockPolicy::ACTIVE_STATUSES)
            ->exists();
        if ($hasActiveJob) {
            return false;
        }

        $assignments = SaCampaignContentAssignment::query()
            ->where('campaign_content_id', $content->id)
            ->get();
        if ($assignments->count() !== 1) {
            return false;
        }
        $planTagId = (int) $assignments->first()->campaign_action_tag_id;

        $meta = is_array($campaign->meta) ? $campaign->meta : [];
        $gates = is_array($meta['action_tag_gates'] ?? null) ? $meta['action_tag_gates'] : [];
        $gateKey = (string) $planTagId;

        $tagContentIds = SaCampaignContentAssignment::query()
            ->where('campaign_action_tag_id', $planTagId)
            ->pluck('campaign_content_id')
            ->all();
        if ($tagContentIds === []) {
            return false;
        }

        $isGateBlocked = is_array($gates[$gateKey] ?? null) && (($gates[$gateKey]['blocked'] ?? false) === true);
        if (! $isGateBlocked && ! array_key_exists($gateKey, $gates)) {
            $isGateBlocked = SaCampaignContent::query()
                ->where('campaign_id', $campaign->id)
                ->whereIn('id', $tagContentIds)
                ->whereIn('status', [
                    CampaignContentStatus::FailedLocked->value,
                    CampaignContentStatus::Unknown->value,
                ])
                ->exists();
        }
        if (! $isGateBlocked) {
            return false;
        }

        // Only the queue-head non-completed content for this Action Tag may be authorized.
        $headContentId = SaCampaignContent::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('id', $tagContentIds)
            ->whereIn('status', [
                CampaignContentStatus::Pending->value,
                CampaignContentStatus::WaitingResources->value,
                CampaignContentStatus::FailedLocked->value,
                CampaignContentStatus::Unknown->value,
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('id');

        return $headContentId !== null && (int) $headContentId === (int) $content->id;
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

                $campaign = SaCampaign::query()->notDeleted()->find($locked->campaign_id);
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
                        // Never claimed — release locks & reservation assignment, content back to waiting_resources (not failed).
                        $attempt = $this->activeAttempt($locked);
                        if ($attempt instanceof SaJobAttempt && (string) $attempt->status === CampaignAttemptStatus::Queued->value) {
                            $attempt->status = CampaignAttemptStatus::Cancelled->value;
                            $attempt->finished_at = now();
                            $attempt->error_code = 'lease_expired_unclaimed';
                            $attempt->save();
                        }

                        $this->releaseJobLocks($locked);
                        $locked->status = JobStatus::Cancelled->value;
                        $locked->finished_at = now();
                        $locked->error_code = 'lease_expired_unclaimed';
                        $locked->save();

                        $content = SaCampaignContent::query()->where('id', $locked->campaign_content_id)->lockForUpdate()->first();
                        if ($content instanceof SaCampaignContent
                            && (string) $content->status === CampaignContentStatus::Running->value) {
                            $hasOtherExecutionForAccount = $locked->campaign_id && $locked->account_id
                                ? SaJob::query()
                                    ->where('campaign_id', $locked->campaign_id)
                                    ->where('account_id', $locked->account_id)
                                    ->where('id', '!=', $locked->id)
                                    ->where(function ($q): void {
                                        $q->whereIn('status', [
                                            JobStatus::Reserved->value,
                                            JobStatus::Claimed->value,
                                            JobStatus::Running->value,
                                            JobStatus::Completed->value,
                                            JobStatus::Unknown->value,
                                        ])->orWhereNotNull('claimed_at');
                                    })
                                    ->exists()
                                : false;

                            if (! $hasOtherExecutionForAccount && $locked->campaign_id && $locked->account_id) {
                                SaCampaignAccountAssignment::query()
                                    ->where('campaign_id', $locked->campaign_id)
                                    ->where('account_id', $locked->account_id)
                                    ->where('content_id', $content->id)
                                    ->lockForUpdate()
                                    ->delete();

                                $campaign = SaCampaign::query()->notDeleted()->find($locked->campaign_id);
                                $meta = $campaign instanceof SaCampaign && is_array($campaign->meta) ? $campaign->meta : [];
                                $hasCampaignPin = trim((string) ($meta['pinned_account_id'] ?? '')) !== '';
                                if (! $hasCampaignPin && (int) $content->assigned_account_id === (int) $locked->account_id) {
                                    $content->assigned_account_id = null;
                                }
                            }

                            $content->status = CampaignContentStatus::WaitingResources->value;
                            $content->current_job_id = null;
                            $content->save();
                            if (SocialAutomationSchema::campaignV2Ready()) {
                                SaCampaignContentAssignment::query()
                                    ->where('campaign_content_id', $content->id)
                                    ->update(['status' => CampaignContentStatus::WaitingResources->value]);
                            }
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
                        if (SocialAutomationSchema::campaignV2Ready()) {
                            SaCampaignContentAssignment::query()
                                ->where('campaign_content_id', $content->id)
                                ->update(['status' => CampaignContentStatus::Unknown->value]);
                        }
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
     * Resolve hard Account pin for a content row.
     * Precedence: content.assigned_account_id → campaign.meta.pinned_account_id (UUID→id).
     */
    /**
     * Pre-dispatch validation: stored typed input must cover ALL required Script variables.
     * Does not reparse raw_source (frozen input for reserved/executed safety).
     *
     * @return array{ok: bool, errors?: list<string>}
     */
    private function assertContentInputForDispatch(SaCampaign $campaign, SaCampaignContent $content): array
    {
        $input = is_array($content->input) ? $content->input : [];
        if ($input === []) {
            return ['ok' => false, 'errors' => ['content_input_empty']];
        }

        $fmt = (string) ($content->source_format ?? 'legacy_input');
        if ($fmt === 'shortcode') {
            $campaignVersion = (int) ($campaign->shortcode_mapping_version ?? 0);
            $contentVersion = (int) ($content->mapping_version ?? 0);
            if ($campaignVersion > 0 && $contentVersion !== $campaignVersion) {
                return ['ok' => false, 'errors' => ['mapping_version_stale']];
            }
        }

        $scriptPin = $this->actionScripts->resolvePublishedPin(
            (string) $campaign->script_id,
            (string) $campaign->script_version,
            $campaign->script_content_digest !== null ? (string) $campaign->script_content_digest : null,
        );
        if ($scriptPin === null) {
            return ['ok' => false, 'errors' => ['script_not_found']];
        }
        $release = SaPackageRelease::query()->find($scriptPin['release_id'] ?? null);
        $manifest = $release instanceof SaPackageRelease && is_array($release->manifest)
            ? $release->manifest
            : [];
        if ($manifest === []) {
            return ['ok' => true];
        }
        $schema = $this->variableSchema->fromManifest($manifest);
        if (! ($schema['ok'] ?? false)) {
            return ['ok' => false, 'errors' => $schema['errors'] ?? ['script_variables_invalid']];
        }
        $variables = $schema['variables'] ?? [];
        if ($variables === []) {
            return ['ok' => true];
        }

        return $this->shortcodePipeline->validateStoredInput($input, $variables);
    }

    private function resolveRequiredAccountId(SaCampaign $campaign, SaCampaignContent $content): ?int
    {
        if ($content->assigned_account_id) {
            return (int) $content->assigned_account_id;
        }
        $meta = is_array($campaign->meta) ? $campaign->meta : [];
        $pinnedUuid = trim((string) ($meta['pinned_account_id'] ?? ''));
        if ($pinnedUuid === '') {
            return null;
        }
        $account = SaAccount::query()
            ->where('account_uuid', $pinnedUuid)
            ->where('installation_id', (string) $campaign->installation_id)
            ->first();
        if (! $account instanceof SaAccount) {
            return null;
        }
        // Persist onto content so retries stay pinned without re-resolving meta.
        $content->assigned_account_id = (int) $account->id;
        $content->save();

        return (int) $account->id;
    }

    /**
     * @param  list<int|string>  $assignedIds
     * @param  list<int|string>  $busyInCampaign
     * @param  int|null  $requiredAccountId  Hard pin: only this account; null = auto-select by platform
     * @return array{selected: list<array<string, mixed>>, skip_reasons: list<string>}
     */
    private function selectEligibleForCampaign(
        SaCampaign $campaign,
        CampaignExecutionMode $mode,
        array $assignedIds,
        array $busyInCampaign,
        int $target,
        ?int $requiredAccountId = null,
        ?SaCampaignActionTag $planTag = null,
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
        $prefilterSkipReasons = [];
        foreach (SaBrowserSession::query()->get() as $session) {
            $agent = null;
            foreach ($agents as $a) {
                if ((int) $a['agent_id'] === (int) $session->agent_id) {
                    $agent = $a;
                    break;
                }
            }
            if ($agent === null) {
                $prefilterSkipReasons[] = 'agent_installation_mismatch';
                continue;
            }
            if (! (bool) ($session->dispatch_enabled ?? true)) {
                $prefilterSkipReasons[] = 'session_dispatch_disabled';
                continue;
            }
            if (! in_array((string) ($session->session_state ?? 'candidate'), ['candidate', 'verified'], true)) {
                $prefilterSkipReasons[] = 'session_state_ineligible';
                continue;
            }

            $activeBind = SaSessionAccount::query()
                ->where('browser_session_id', $session->id)
                ->where('is_active', true)
                ->first();
            $accountId = $activeBind?->account_id ?? $session->account_id;
            if ($accountId === null || (int) $accountId <= 0) {
                $prefilterSkipReasons[] = 'account_unbound';
                continue;
            }
            $account = SaAccount::query()->find($accountId);
            if (! $account instanceof SaAccount) {
                $prefilterSkipReasons[] = 'account_unbound';
                continue;
            }
            if ((string) $account->installation_id !== (string) $campaign->installation_id) {
                $prefilterSkipReasons[] = 'account_installation_mismatch';
                continue;
            }
            $targetPlatform = $planTag instanceof SaCampaignActionTag
                ? trim((string) ($planTag->platform ?: $planTag->site_key))
                : (string) $campaign->platform;
            $matchesPlatform = strcasecmp((string) $account->platform, $targetPlatform) === 0
                || ($planTag instanceof SaCampaignActionTag && strcasecmp((string) $account->platform, (string) $planTag->site_key) === 0);
            if (! $matchesPlatform) {
                $prefilterSkipReasons[] = 'platform_mismatch';
                continue;
            }
            if ($requiredAccountId !== null && (int) $accountId !== $requiredAccountId) {
                $prefilterSkipReasons[] = 'account_pin_mismatch';
                continue;
            }
            if (! $this->modePolicy->canAssignAccount($mode, (int) $accountId, $assignedIds, $busyInCampaign)) {
                $prefilterSkipReasons[] = $mode === CampaignExecutionMode::OneAccountOneJob
                    ? 'account_already_assigned_in_campaign'
                    : 'account_busy_in_campaign';
                continue;
            }

            $affinity = SaAccountDeviceAffinity::query()->where('account_id', $accountId)->first();
            if ($affinity instanceof SaAccountDeviceAffinity && $affinity->affinity_until?->isFuture()
                && (int) $affinity->agent_id !== (int) $session->agent_id) {
                $prefilterSkipReasons[] = 'account_device_affinity_mismatch';
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
                'capabilities' => (is_array($session->capabilities) && $session->capabilities !== [])
                    ? $session->capabilities
                    : $agent['capabilities'],
                'account_id' => $accountId,
            ];
        }

        $criteria = [
            'platform' => $planTag instanceof SaCampaignActionTag
                ? trim((string) ($planTag->platform ?: $planTag->site_key))
                : $campaign->platform,
            'target_count' => $target,
            // Hard pin already enforces exact account_id + installation + platform + bind.
            // Inventory/site accounts often lack social platform_account_id.
            'require_strong_identity' => $requiredAccountId === null && $planTag === null,
        ];
        if ($planTag instanceof SaCampaignActionTag) {
            $compat = $this->resolveV2RuntimeCompatibility($planTag);
            if ($compat['runtime_min'] !== null) {
                $criteria['runtime_min'] = $compat['runtime_min'];
            }
            if ($compat['required_capabilities'] !== []) {
                $criteria['required_capabilities'] = $compat['required_capabilities'];
            }
            $criteria['strict_runtime_check'] = true;
        }

        $result = $this->eligibility->select($agents, $sessions, $criteria);
        $skipReasons = array_values(array_unique(array_merge($result['skip_reasons'], $prefilterSkipReasons)));

        return [
            'selected' => $result['selected'],
            'skip_reasons' => $skipReasons,
        ];
    }

    /**
     * Format a safe, specific waiting_resources diagnostic message from selector skip reasons.
     *
     * @param  list<string>  $skipReasons
     */
    private function formatWaitingResourcesMessage(string $siteKey, array $skipReasons): string
    {
        $priorityMap = [
            'account_already_assigned_in_campaign' => "Account already consumed in this campaign (execution_mode=one_account_one_job) for target site {$siteKey}",
            'account_busy_in_campaign' => "Account is currently busy with another job in this campaign for target site {$siteKey}",
            'account_locked' => "Account lease/job is currently occupied for target site {$siteKey}",
            'session_dispatch_disabled' => "Browser session is not dispatch-enabled for target site {$siteKey}",
            'session_not_ready' => "No ready browser session for target site {$siteKey}",
            'session_busy' => "Browser session is currently busy for target site {$siteKey}",
            'session_state_ineligible' => "Browser session state is not eligible for target site {$siteKey}",
            'agent_offline' => "No active client heartbeat (client offline) for target site {$siteKey}",
            'agent_unavailable' => "Client is paused, disabled, or in error state for target site {$siteKey}",
            'agent_at_capacity' => "Client concurrency capacity is full for target site {$siteKey}",
            'admin_not_runner' => "Only admin client online (runner required) for target site {$siteKey}",
            'runtime_incompatible' => "Client runtime version incompatible for target site {$siteKey}",
            'runtime_version_missing' => "Client runtime version missing for target site {$siteKey}",
            'capability_mismatch' => "Client/browser missing required capabilities for target site {$siteKey}",
            'account_device_affinity_mismatch' => "Account device affinity pinned to another client for target site {$siteKey}",
            'account_unbound' => "No matching account bound to browser session for target site {$siteKey}",
            'account_installation_mismatch' => "Account installation mismatch for target site {$siteKey}",
            'agent_installation_mismatch' => "Client installation mismatch for target site {$siteKey}",
            'account_pin_mismatch' => "Pinned account does not match available session for target site {$siteKey}",
            'platform_mismatch' => "No matching account/browser assignment for target site {$siteKey}",
        ];

        foreach ($priorityMap as $reason => $message) {
            if (in_array($reason, $skipReasons, true)) {
                return $message;
            }
        }

        return 'No eligible online client/account/browser for target site '.$siteKey;
    }

    /**
     * Extract documented runtime_compatibility fields from the pinned Script Release.
     *
     * @return array{runtime_min: string|null, required_capabilities: list<string>}
     */
    private function resolveV2RuntimeCompatibility(SaCampaignActionTag $planTag): array
    {
        $compat = [];
        if ($planTag->release_id > 0) {
            $rel = SaPackageRelease::query()->find((int) $planTag->release_id);
            if ($rel instanceof SaPackageRelease && is_array($rel->runtime_compatibility)) {
                $compat = $rel->runtime_compatibility;
            }
        }
        if ($compat === [] && is_array($planTag->manifest_snapshot['runtime_compatibility'] ?? null)) {
            $compat = $planTag->manifest_snapshot['runtime_compatibility'];
        }

        $runtimeMinRaw = $compat['runtime_min'] ?? $compat['min_runtime_version'] ?? null;
        $runtimeMin = is_string($runtimeMinRaw) && trim($runtimeMinRaw) !== '' ? trim($runtimeMinRaw) : null;

        $capsRaw = $compat['required_capabilities'] ?? [];
        $requiredCaps = [];
        if (is_array($capsRaw)) {
            foreach ($capsRaw as $cap) {
                $c = trim((string) $cap);
                if ($c !== '') {
                    $requiredCaps[] = $c;
                }
            }
        }

        return [
            'runtime_min' => $runtimeMin,
            'required_capabilities' => array_values(array_unique($requiredCaps)),
        ];
    }

    /**
     * @param  array<string, mixed>  $sessionRow
     * @param  array<string, mixed>|null  $v2Override
     */
    private function reserveCampaignJob(
        SaCampaign $campaign,
        SaCampaignContent $content,
        array $sessionRow,
        CampaignExecutionMode $mode,
        ?array $v2Override = null,
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

        $attemptNo = (int) SaJob::query()->where('campaign_content_id', $content->id)->count() + 1;
        $idem = JobIdempotencyKey::forCampaignContent(
            (string) $campaign->campaign_uuid,
            (string) $content->content_uuid,
            $attemptNo,
        );

        $existing = SaJob::query()->where('idempotency_key', $idem)->lockForUpdate()->first();
        if ($existing instanceof SaJob) {
            return $existing;
        }

        $leaseToken = Str::random(40);
        $fencing = 1;
        $input = is_array($v2Override['input'] ?? null)
            ? $v2Override['input']
            : (is_array($content->input) ? $content->input : []);
        if ($v2Override === null && $input === []) {
            return null;
        }
        $pinScriptId = (string) ($v2Override['script_id'] ?? $campaign->script_id);
        $pinScriptVersion = (string) ($v2Override['script_version'] ?? $campaign->script_version);
        $pinDigest = array_key_exists('script_content_digest', $v2Override ?? [])
            ? ($v2Override['script_content_digest'] !== null ? (string) $v2Override['script_content_digest'] : null)
            : ($campaign->script_content_digest !== null ? (string) $campaign->script_content_digest : null);

        $scriptPin = $this->actionScripts->resolvePublishedPin(
            $pinScriptId,
            $pinScriptVersion,
            $pinDigest,
        );
        if ($scriptPin === null) {
            return null;
        }

        $campaignMeta = is_array($campaign->meta) ? $campaign->meta : [];
        $jobPayload = [
            'input' => $input,
            'campaign_uuid' => $campaign->campaign_uuid,
            'content_uuid' => $content->content_uuid,
            'run_mode' => (string) ($campaign->run_mode ?: CampaignRunMode::Live->value),
            'execution_mode' => (string) $campaign->execution_mode,
            'installation_id' => (string) $campaign->installation_id,
            'script_pin' => $scriptPin,
        ];
        if ($v2Override !== null) {
            $jobPayload['plan_version'] = 2;
            $jobPayload['campaign_action_tag_id'] = $v2Override['campaign_action_tag_id'] ?? null;
            $jobPayload['plan_tag_id'] = $v2Override['plan_tag_id'] ?? null;
            $jobPayload['action_tag_id'] = $v2Override['action_tag_id'] ?? null;
            $jobPayload['action_tag_key'] = $v2Override['action_tag_key'] ?? null;
            $jobPayload['site_key'] = $v2Override['site_key'] ?? null;
            $jobPayload['release_id'] = $v2Override['release_id'] ?? null;
            $jobPayload['execution_origin'] = $v2Override['execution_origin'] ?? 'scheduler';
            if (! empty($v2Override['script_variants']) && is_array($v2Override['script_variants'])) {
                $vgUuid = isset($v2Override['variant_group_id']) ? (string) $v2Override['variant_group_id'] : null;
                $ordered = $this->variantPreferences->applyPreferenceToVariants(
                    array_values($v2Override['script_variants']),
                    (string) $campaign->installation_id,
                    $accountId,
                    (string) ($v2Override['action_tag_key'] ?? ''),
                    $vgUuid,
                );
                $jobPayload['variant_group_id'] = $vgUuid;
                $jobPayload['script_variants'] = $ordered['script_variants'];
                if ($ordered['preferred_variant'] !== null) {
                    $jobPayload['preferred_variant'] = $ordered['preferred_variant'];
                }
                $jobPayload['fallback_policy'] = [
                    'allowed_only_on' => 'first_step_not_found_before_mutation',
                    'single_attempt_identity' => true,
                    'success_screenshots' => 1,
                    'failure_screenshots_per_attempted_variant' => 1,
                ];
            }
        }
        if (is_array($campaignMeta['resolved_script_context'] ?? null)) {
            $jobPayload['resolved_script_context'] = $campaignMeta['resolved_script_context'];
        }

        $job = SaJob::query()->create([
            'job_uuid' => (string) Str::uuid(),
            'task_id' => null,
            'campaign_id' => $campaign->id,
            'campaign_content_id' => $content->id,
            'agent_id' => $agent->id,
            'browser_session_id' => $session->id,
            'account_id' => $accountId,
            'package_key' => $scriptPin['package_key'],
            'package_version' => $scriptPin['version'],
            'content_digest' => $scriptPin['content_digest'],
            'status' => JobStatus::Reserved->value,
            'idempotency_key' => $idem,
            'lease_token' => $leaseToken,
            'fencing_generation' => $fencing,
            'payload' => $jobPayload,
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

        $wakeCampaignId = null;

        try {
            $tx = DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use (
                $jobUuid, $payload, $clientApiKeyId, $installationId, $attemptStatus, $to, $event, $attrs, $requireFence, $releaseLock, $terminalContent, $artifactRefs, &$wakeCampaignId
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
                if ($attemptStatus === CampaignAttemptStatus::Succeeded) {
                    $jobPayloadArr = is_array($job->payload) ? $job->payload : [];
                    $pinnedVariants = is_array($jobPayloadArr['script_variants'] ?? null) ? $jobPayloadArr['script_variants'] : [];
                    if ($pinnedVariants !== []) {
                        $succeededVariant = $this->variantPreferences->extractSucceededVariant($payload, $pinnedVariants, $artifactRefs);
                        if ($succeededVariant !== null) {
                            $existingRes = is_array($attrs['result'] ?? null) ? $attrs['result'] : [];
                            $existingRes['succeeded_variant'] = $succeededVariant;
                            $attrs['result'] = $existingRes;
                        }
                    }
                }
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
                    $wakeCampaignId = $this->applyTerminalContent($job, $attempt, $attemptStatus, $attrs);
                } elseif ($terminalContent && $job->campaign_id) {
                    $wakeCampaignId = $this->handleQueueGateOnTerminal($job, $attempt, null, $attemptStatus, $attrs);
                }

                return ['ok' => true, 'data' => ['job_id' => $job->job_uuid, 'status' => $job->status]];
            });

            if (($tx['ok'] ?? false) && $wakeCampaignId !== null) {
                $wakeCampaign = SaCampaign::query()->notDeleted()->find($wakeCampaignId);
                if ($wakeCampaign instanceof SaCampaign
                    && (string) $wakeCampaign->status === CampaignStatus::Running->value) {
                    $this->dispatchCampaign($wakeCampaign);
                }
            }

            return $tx;
        } catch (Throwable) {
            return ['ok' => false, 'errors' => ['advance_failed']];
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function applyTerminalContent(
        SaJob $job,
        ?SaJobAttempt $attempt,
        CampaignAttemptStatus $attemptStatus,
        array $attrs,
    ): ?int {
        $content = SaCampaignContent::query()->where('id', $job->campaign_content_id)->lockForUpdate()->first();
        if (! $content instanceof SaCampaignContent) {
            return null;
        }

        $contentStatus = match ($attemptStatus) {
            CampaignAttemptStatus::Succeeded => CampaignContentStatus::Success,
            CampaignAttemptStatus::Failed => CampaignContentStatus::FailedLocked,
            CampaignAttemptStatus::Unknown => CampaignContentStatus::Unknown,
            CampaignAttemptStatus::Cancelled => CampaignContentStatus::Cancelled,
            default => null,
        };
        if ($contentStatus === null) {
            return null;
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

        if (SocialAutomationSchema::campaignV2Ready()) {
            SaCampaignContentAssignment::query()
                ->where('campaign_content_id', $content->id)
                ->update(['status' => $contentStatus->value]);
        }

        $wakeCampaignId = $this->handleQueueGateOnTerminal($job, $attempt, $content, $attemptStatus, $attrs);

        $campaign = SaCampaign::query()->notDeleted()->find($content->campaign_id);
        if ($campaign instanceof SaCampaign) {
            $this->maybeCompleteCampaign($campaign);
        }

        return $wakeCampaignId;
    }

    /**
     * Block Action Tag queue on terminal Failed/Unknown; release ONLY on explicit manual Succeeded.
     *
     * @param  array<string, mixed>  $attrs
     */
    private function handleQueueGateOnTerminal(
        SaJob $job,
        ?SaJobAttempt $attempt,
        ?SaCampaignContent $content,
        CampaignAttemptStatus $attemptStatus,
        array $attrs,
    ): ?int {
        if ($job->campaign_id === null || ! SocialAutomationSchema::campaignV2Ready()) {
            return null;
        }

        $campaign = SaCampaign::query()->notDeleted()->where('id', $job->campaign_id)->lockForUpdate()->first();
        if (! $campaign instanceof SaCampaign || (int) ($campaign->plan_version ?? 1) < 2) {
            return null;
        }

        $jobPayload = is_array($job->payload) ? $job->payload : [];
        $planTagId = isset($jobPayload['campaign_action_tag_id']) ? (int) $jobPayload['campaign_action_tag_id'] : 0;
        if ($planTagId <= 0 && $content instanceof SaCampaignContent) {
            $assign = SaCampaignContentAssignment::query()
                ->where('campaign_content_id', $content->id)
                ->first();
            $planTagId = $assign instanceof SaCampaignContentAssignment ? (int) $assign->campaign_action_tag_id : 0;
        }
        if ($planTagId <= 0) {
            return null;
        }

        $planTag = SaCampaignActionTag::query()
            ->where('id', $planTagId)
            ->where('campaign_id', $campaign->id)
            ->first();
        if (! $planTag instanceof SaCampaignActionTag) {
            return null;
        }

        $meta = is_array($campaign->meta) ? $campaign->meta : [];
        $gates = is_array($meta['action_tag_gates'] ?? null) ? $meta['action_tag_gates'] : [];
        $gateKey = (string) $planTagId;

        if ($attemptStatus === CampaignAttemptStatus::Failed || $attemptStatus === CampaignAttemptStatus::Unknown) {
            $gates[$gateKey] = [
                'blocked' => true,
                'reason' => isset($attrs['error_code']) && $attrs['error_code'] !== ''
                    ? (string) $attrs['error_code']
                    : $attemptStatus->value,
                'message' => isset($attrs['error_message']) ? (string) $attrs['error_message'] : null,
                'job_id' => (int) $job->id,
                'job_uuid' => (string) $job->job_uuid,
                'attempt_id' => $attempt?->id ? (int) $attempt->id : null,
                'attempt_uuid' => $attempt?->attempt_uuid ? (string) $attempt->attempt_uuid : null,
                'content_id' => $content?->id ? (int) $content->id : null,
                'content_uuid' => $content?->content_uuid ? (string) $content->content_uuid : null,
                'blocked_at' => now()->toIso8601String(),
                'action_tag_id' => (int) $planTag->action_tag_id,
                'action_tag_key' => (string) $planTag->action_tag_key,
                'plan_tag_id' => $planTagId,
            ];
            $meta['action_tag_gates'] = $gates;
            $campaign->meta = $meta;
            $campaign->save();

            return null;
        }

        if ($attemptStatus === CampaignAttemptStatus::Succeeded) {
            $origin = (string) ($jobPayload['execution_origin'] ?? 'scheduler');
            $isManual = in_array($origin, ['manual_retry', 'manual_test'], true);
            $wasBlocked = is_array($gates[$gateKey] ?? null) && (($gates[$gateKey]['blocked'] ?? false) === true);

            if ($isManual && $wasBlocked) {
                $gates[$gateKey] = array_merge($gates[$gateKey], [
                    'blocked' => false,
                    'released_at' => now()->toIso8601String(),
                    'released_by_job_uuid' => (string) $job->job_uuid,
                    'released_by_attempt_uuid' => $attempt?->attempt_uuid ? (string) $attempt->attempt_uuid : null,
                    'released_by_origin' => $origin,
                ]);
                $meta['action_tag_gates'] = $gates;
                $campaign->meta = $meta;
                $intervalMinutes = max(0, (int) ($planTag->interval_minutes ?? 0));
                $succeededAt = $job->finished_at ?? now();
                $nextEligible = $intervalMinutes > 0
                    ? \Carbon\CarbonImmutable::instance($succeededAt)->addMinutes($intervalMinutes)
                    : now();
                if ((string) $campaign->status === CampaignStatus::Running->value) {
                    $campaign->next_recheck_at = $nextEligible;
                }
                $campaign->save();

                return (string) $campaign->status === CampaignStatus::Running->value && $intervalMinutes === 0
                    ? (int) $campaign->id
                    : null;
            }

            // Automatic Succeeded on a Running campaign marks campaign due at next eligible time.
            if ((string) $campaign->status === CampaignStatus::Running->value) {
                $intervalMinutes = max(0, (int) ($planTag->interval_minutes ?? 0));
                $succeededAt = $job->finished_at ?? now();
                $campaign->next_recheck_at = $intervalMinutes > 0
                    ? \Carbon\CarbonImmutable::instance($succeededAt)->addMinutes($intervalMinutes)
                    : now();
                $campaign->save();
            }

            return null;
        }

        return null;
    }

    /**
     * Normalize stale non-head waiting_resources rows (from legacy schedulers) back to pending.
     */
    private function normalizeNonHeadWaitingToPending(SaCampaignContent $content): void
    {
        if ((string) $content->status === CampaignContentStatus::WaitingResources->value
            && (string) ($content->last_error_code ?? '') === 'waiting_resources'
            && $content->current_job_id === null) {
            $content->status = CampaignContentStatus::Pending->value;
            $content->last_error_code = null;
            $content->last_error_message = null;
            $content->save();
        }
    }

    /**
     * Compute the latest terminal Success timestamp per Campaign Action Tag from durable SaJob records.
     *
     * @return array<int, \Carbon\CarbonInterface>
     */
    private function lastSuccessByActionTag(SaCampaign $campaign): array
    {
        if (! SocialAutomationSchema::jobsReady()) {
            return [];
        }

        $jobs = SaJob::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', JobStatus::Completed->value)
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->get(['campaign_content_id', 'payload', 'finished_at']);

        if ($jobs->isEmpty()) {
            return [];
        }

        $contentIds = $jobs->pluck('campaign_content_id')->filter()->unique()->values()->all();
        $tagByContentId = $contentIds !== []
            ? SaCampaignContentAssignment::query()
                ->whereIn('campaign_content_id', $contentIds)
                ->pluck('campaign_action_tag_id', 'campaign_content_id')
                ->all()
            : [];

        $out = [];
        foreach ($jobs as $job) {
            $tagId = is_array($job->payload) ? (int) ($job->payload['campaign_action_tag_id'] ?? 0) : 0;
            if ($tagId <= 0 && $job->campaign_content_id !== null) {
                $tagId = (int) ($tagByContentId[(int) $job->campaign_content_id] ?? 0);
            }
            if ($tagId > 0 && ! isset($out[$tagId]) && $job->finished_at !== null) {
                $out[$tagId] = $job->finished_at;
            }
        }

        return $out;
    }

    /**
     * Dispatch an explicit manual test Job for a Campaign Action Tag queue (without retrying failed articles).
     *
     * @return array{ok: bool, job?: array<string, mixed>, errors?: list<string>}
     */
    public function dispatchManualActionTagTest(
        SaCampaign $campaign,
        SaCampaignActionTag $planTag,
        ?int $requiredAccountId = null,
    ): array {
        if (! SocialAutomationSchema::campaignV2Ready()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }

        try {
            return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use (
                $campaign,
                $planTag,
                $requiredAccountId
            ): array {
                $locked = SaCampaign::query()->notDeleted()->where('id', $campaign->id)->lockForUpdate()->first();
                if (! $locked instanceof SaCampaign) {
                    return ['ok' => false, 'errors' => ['campaign_not_found']];
                }
                $cStatus = CampaignStatus::tryFrom((string) $locked->status);
                if ($cStatus === null || $cStatus->isTerminal()) {
                    return ['ok' => false, 'errors' => ['campaign_not_dispatchable']];
                }

                $mode = CampaignExecutionMode::tryFrom((string) $locked->execution_mode)
                    ?? CampaignExecutionMode::OneAccountOneJob;

                $selection = $this->selectEligibleForCampaign(
                    $locked,
                    $mode,
                    [],
                    [],
                    1,
                    $requiredAccountId,
                    $planTag,
                );
                if ($selection['selected'] === []) {
                    return ['ok' => false, 'errors' => ['waiting_resources']];
                }

                $row = $selection['selected'][0];
                $session = SaBrowserSession::query()->lockForUpdate()->find($row['session_id'] ?? null);
                $agent = $session instanceof SaBrowserSession ? SaAgent::query()->lockForUpdate()->find($session->agent_id) : null;
                $accountId = (int) ($row['account_id'] ?? 0);
                $account = $accountId > 0 ? SaAccount::query()->lockForUpdate()->find($accountId) : null;
                if (! $session instanceof SaBrowserSession || ! $agent instanceof SaAgent || ! $account instanceof SaAccount) {
                    return ['ok' => false, 'errors' => ['waiting_resources']];
                }
                if (! $this->sessionLocks->canLock([
                    'locked_job_id' => $session->locked_job_id,
                    'lock_expires_at' => $session->lock_expires_at,
                ], 0)) {
                    return ['ok' => false, 'errors' => ['waiting_resources']];
                }

                $scriptPin = $this->actionScripts->resolvePublishedPin(
                    (string) $planTag->script_id,
                    (string) $planTag->script_version,
                    $planTag->script_content_digest,
                );
                if ($scriptPin === null) {
                    return ['ok' => false, 'errors' => ['script_pin_unavailable']];
                }

                $leaseToken = Str::random(40);
                $fencing = 1;
                $jobPayload = [
                    'input' => [],
                    'campaign_uuid' => $locked->campaign_uuid,
                    'run_mode' => CampaignRunMode::Test->value,
                    'execution_mode' => (string) $locked->execution_mode,
                    'installation_id' => (string) $locked->installation_id,
                    'script_pin' => $scriptPin,
                    'plan_version' => 2,
                    'campaign_action_tag_id' => (int) $planTag->id,
                    'plan_tag_id' => (string) $planTag->plan_tag_uuid,
                    'action_tag_id' => (int) $planTag->action_tag_id,
                    'action_tag_key' => (string) $planTag->action_tag_key,
                    'site_key' => (string) $planTag->site_key,
                    'release_id' => (int) $planTag->release_id,
                    'execution_origin' => 'manual_test',
                ];
                if (is_array($planTag->pinned_variants ?? null) && $planTag->pinned_variants !== []) {
                    $vgUuid = $planTag->variant_group_uuid ? (string) $planTag->variant_group_uuid : null;
                    $ordered = $this->variantPreferences->applyPreferenceToVariants(
                        array_values($planTag->pinned_variants),
                        (string) $locked->installation_id,
                        $accountId,
                        (string) $planTag->action_tag_key,
                        $vgUuid,
                    );
                    $jobPayload['variant_group_id'] = $vgUuid;
                    $jobPayload['script_variants'] = $ordered['script_variants'];
                    if ($ordered['preferred_variant'] !== null) {
                        $jobPayload['preferred_variant'] = $ordered['preferred_variant'];
                    }
                    $jobPayload['fallback_policy'] = [
                        'allowed_only_on' => 'first_step_not_found_before_mutation',
                        'single_attempt_identity' => true,
                        'success_screenshots' => 1,
                        'failure_screenshots_per_attempted_variant' => 1,
                    ];
                }

                $job = SaJob::query()->create([
                    'job_uuid' => (string) Str::uuid(),
                    'task_id' => null,
                    'campaign_id' => $locked->id,
                    'campaign_content_id' => null,
                    'agent_id' => $agent->id,
                    'browser_session_id' => $session->id,
                    'account_id' => $accountId,
                    'package_key' => $scriptPin['package_key'],
                    'package_version' => $scriptPin['version'],
                    'content_digest' => $scriptPin['content_digest'],
                    'status' => JobStatus::Reserved->value,
                    'idempotency_key' => 'manual-test:'.$locked->id.':'.$planTag->id.':'.Str::uuid(),
                    'lease_token' => $leaseToken,
                    'fencing_generation' => $fencing,
                    'payload' => $jobPayload,
                    'reserved_at' => now(),
                    'lease_expires_at' => now()->addSeconds(self::DEFAULT_LEASE_SECONDS),
                    'dispatch_source' => 'manual_test',
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

                $session->fill([
                    'locked_job_id' => $job->id,
                    'locked_at' => now(),
                    'lock_expires_at' => now()->addSeconds(self::DEFAULT_LEASE_SECONDS),
                ])->save();

                return [
                    'ok' => true,
                    'job' => [
                        'job_uuid' => (string) $job->job_uuid,
                        'campaign_action_tag_id' => (int) $planTag->id,
                        'action_tag_key' => (string) $planTag->action_tag_key,
                        'execution_origin' => 'manual_test',
                    ],
                ];
            });
        } catch (Throwable $e) {
            Log::warning('social_automation.manual_test_dispatch_failed', [
                'campaign_id' => $campaign->id ?? null,
                'plan_tag_id' => $planTag->id ?? null,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return ['ok' => false, 'errors' => ['manual_test_dispatch_failed']];
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
        $q = SaJob::query()->where('job_uuid', $jobUuid)->where(function ($sub): void {
            $sub->whereNotNull('campaign_content_id')->orWhereNotNull('campaign_id');
        });
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

        $scriptPin = $this->actionScripts->jobScriptPin($job);
        $packageKey = $scriptPin['package_key'] ?? (string) $job->package_key;
        $version = $scriptPin['version'] ?? (string) $job->package_version;
        // content_digest = package content integrity pin (not execution_digest).
        $contentDigest = $scriptPin['content_digest'] ?? ($job->content_digest !== null ? (string) $job->content_digest : null);
        $executionDigest = $scriptPin['execution_digest'] ?? null;

        $executionContext = $this->actionEntryUrls->resolveForJob($job, $account, $campaign);

        $out = [
            'job_id' => $job->job_uuid,
            'attempt_id' => $attempt?->attempt_uuid,
            'campaign_id' => $campaign?->campaign_uuid,
            'content_id' => $content?->content_uuid,
            'browser_id' => $session?->session_uuid,
            'account_id' => $account?->account_uuid,
            // Legacy aliases kept for older clients.
            'script_id' => $packageKey,
            'script_version' => $version,
            // Exact Action Script pin for pull/asset (no latest inference).
            'package_key' => $packageKey,
            'package_id' => $scriptPin['package_id'] ?? null,
            'release_id' => $scriptPin['release_id'] ?? ($payload['release_id'] ?? null),
            'version' => $version,
            'content_digest' => $contentDigest,
            'execution_digest' => $executionDigest,
            'run_mode' => $runMode,
            'execution_mode' => (string) ($payload['execution_mode'] ?? $campaign?->execution_mode),
            'input' => $input,
            'execution_context' => $executionContext,
            'lease_token' => $job->lease_token ?? $attempt?->lease_token,
            'lease_expires_at' => ($job->lease_expires_at ?? $attempt?->lease_expires_at)?->toIso8601String(),
        ];

        if (($payload['plan_version'] ?? null) === 2 || ($campaign !== null && (int) ($campaign->plan_version ?? 1) >= 2)) {
            $out['plan_version'] = 2;
            $out['plan_tag_id'] = $payload['plan_tag_id'] ?? null;
            $out['action_tag_key'] = $payload['action_tag_key'] ?? null;
            $out['site_key'] = $payload['site_key'] ?? null;
            if (! empty($payload['script_variants']) && is_array($payload['script_variants'])) {
                $out['variant_group_id'] = $payload['variant_group_id'] ?? null;
                $out['script_variants'] = array_values($payload['script_variants']);
                if (is_array($payload['preferred_variant'] ?? null)) {
                    $out['preferred_variant'] = $payload['preferred_variant'];
                }
                $out['fallback_policy'] = $payload['fallback_policy'] ?? [
                    'allowed_only_on' => 'first_step_not_found_before_mutation',
                    'single_attempt_identity' => true,
                    'success_screenshots' => 1,
                    'failure_screenshots_per_attempted_variant' => 1,
                ];
            }
        }

        return $out;
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

            foreach (['variant_id', 'language_label', 'package_key', 'screenshot_role'] as $strField) {
                if (isset($row[$strField]) && ! isset($meta[$strField])) {
                    $meta[$strField] = (string) $row[$strField];
                }
            }
            foreach (['release_id', 'priority'] as $intField) {
                if (isset($row[$intField]) && ! isset($meta[$intField])) {
                    $meta[$intField] = (int) $row[$intField];
                }
            }
            if (array_key_exists('failed_step', $row) && ! array_key_exists('failed_step', $meta)) {
                $meta['failed_step'] = $row['failed_step'];
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
