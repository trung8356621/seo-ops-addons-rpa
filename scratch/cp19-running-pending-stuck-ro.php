<?php

declare(strict_types=1);

/**
 * READ-ONLY: Campaign running but contents stuck pending.
 * No writes, no Start/Resume, no Job claim, no schedule tick, no dispatch.
 */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Domain\AccountLockPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\AgentKindPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignModePolicy;
use Omnichannel\Addons\SocialAutomation\Domain\EligibilitySelector;
use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignExecutionMode;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaAccountDeviceAffinity;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignAccountAssignment;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContent;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaSessionAccount;
use Omnichannel\Addons\SocialAutomation\Orchestration\CampaignOrchestrator;
use Omnichannel\Addons\SocialAutomation\Services\ActionScriptDistributionService;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignScriptVariableSchema;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodePipeline;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;

$db = DB::connection('omi_social_automation');
$uuid = 'cf0d8be1-5475-4c84-a788-600a6a8ae17f';
$now = now();

$out = [
    'probe' => 'running_pending_stuck_ro',
    'now' => $now->toIso8601String(),
    'mutated' => false,
    'campaign_uuid' => $uuid,
];

$campaign = $db->table('sa_campaigns')->where('campaign_uuid', $uuid)->first();
if (! $campaign) {
    $out['error'] = 'campaign_not_found';
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(1);
}

$meta = isset($campaign->meta) ? (is_string($campaign->meta) ? json_decode($campaign->meta, true) : $campaign->meta) : null;
$mapping = isset($campaign->shortcode_mapping)
    ? (is_string($campaign->shortcode_mapping) ? json_decode($campaign->shortcode_mapping, true) : $campaign->shortcode_mapping)
    : null;

$out['campaign'] = [
    'id' => (int) $campaign->id,
    'campaign_uuid' => $campaign->campaign_uuid,
    'name' => $campaign->name,
    'status' => $campaign->status,
    'installation_id' => $campaign->installation_id,
    'platform' => $campaign->platform,
    'execution_mode' => $campaign->execution_mode,
    'run_mode' => $campaign->run_mode ?? null,
    'script_id' => $campaign->script_id,
    'script_version' => $campaign->script_version,
    'script_content_digest' => $campaign->script_content_digest ?? null,
    'resource_recheck_seconds' => (int) ($campaign->resource_recheck_seconds ?? 0),
    'next_recheck_at' => $campaign->next_recheck_at,
    'started_at' => $campaign->started_at,
    'paused_at' => $campaign->paused_at,
    'completed_at' => $campaign->completed_at ?? null,
    'shortcode_mapping_version' => $campaign->shortcode_mapping_version ?? null,
    'shortcode_mapping' => $mapping,
    'meta' => $meta,
    'next_recheck_due' => $campaign->next_recheck_at === null
        || (strtotime((string) $campaign->next_recheck_at) <= $now->getTimestamp()),
];

$contentCols = [
    'id', 'content_uuid', 'campaign_id', 'external_ref', 'sort_order', 'status',
    'assigned_account_id', 'current_job_id', 'last_error_code', 'last_error_message',
    'created_at', 'updated_at',
];
foreach (['source_format', 'raw_source', 'mapping_version', 'input'] as $c) {
    if (Schema::connection('omi_social_automation')->hasColumn('sa_campaign_contents', $c)) {
        $contentCols[] = $c;
    }
}

$contents = $db->table('sa_campaign_contents')
    ->where('campaign_id', $campaign->id)
    ->orderBy('sort_order')
    ->orderBy('id')
    ->get($contentCols);

$statusCounts = [];
$contentRows = [];
foreach ($contents as $r) {
    $st = (string) $r->status;
    $statusCounts[$st] = ($statusCounts[$st] ?? 0) + 1;
    $input = null;
    if (isset($r->input)) {
        $input = is_string($r->input) ? json_decode($r->input, true) : $r->input;
    }
    $contentRows[] = [
        'id' => (int) $r->id,
        'content_uuid' => $r->content_uuid,
        'external_ref' => $r->external_ref,
        'sort_order' => (int) $r->sort_order,
        'status' => $st,
        'assigned_account_id' => $r->assigned_account_id !== null ? (int) $r->assigned_account_id : null,
        'current_job_id' => $r->current_job_id !== null ? (int) $r->current_job_id : null,
        'last_error_code' => $r->last_error_code,
        'last_error_message' => $r->last_error_message,
        'source_format' => $r->source_format ?? null,
        'mapping_version' => isset($r->mapping_version) ? (int) $r->mapping_version : null,
        'raw_source_len' => isset($r->raw_source) ? strlen((string) $r->raw_source) : null,
        'input_keys' => is_array($input) ? array_keys($input) : [],
        'input_title_preview' => is_array($input) && isset($input['title'])
            ? substr((string) $input['title'], 0, 80)
            : null,
        'created_at' => $r->created_at,
        'updated_at' => $r->updated_at,
    ];
}
$out['contents'] = [
    'count' => count($contentRows),
    'status_counts' => $statusCounts,
    'rows' => $contentRows,
];

$jobs = $db->table('sa_jobs')
    ->where('campaign_id', $campaign->id)
    ->orderBy('id')
    ->get([
        'id', 'job_uuid', 'campaign_content_id', 'status', 'agent_id', 'browser_session_id',
        'account_id', 'package_key', 'package_version', 'idempotency_key',
        'lease_token', 'created_at', 'updated_at', 'claimed_at', 'started_at', 'finished_at',
    ]);
$out['jobs'] = [
    'count' => $jobs->count(),
    'rows' => $jobs->map(static fn ($j) => (array) $j)->all(),
];

$attempts = $db->table('sa_job_attempts')
    ->whereIn('job_id', $jobs->pluck('id')->all() ?: [-1])
    ->orderBy('id')
    ->get();
$out['job_attempts'] = [
    'count' => $attempts->count(),
    'rows' => $attempts->map(static fn ($a) => [
        'id' => (int) $a->id,
        'job_id' => (int) $a->job_id,
        'attempt_no' => (int) $a->attempt_no,
        'status' => $a->status,
        'created_at' => $a->created_at,
    ])->all(),
];

$events = $db->table('sa_job_events')
    ->whereIn('job_id', $jobs->pluck('id')->all() ?: [-1])
    ->orderByDesc('id')
    ->limit(20)
    ->get();
$out['job_events_recent'] = $events->map(static fn ($e) => (array) $e)->all();

$assignments = $db->table('sa_campaign_account_assignments')
    ->where('campaign_id', $campaign->id)
    ->get();
$out['account_assignments'] = $assignments->map(static fn ($a) => (array) $a)->all();

// Pinned account
$pinnedUuid = is_array($meta) ? trim((string) ($meta['pinned_account_id'] ?? '')) : '';
$pinnedAccount = null;
if ($pinnedUuid !== '') {
    $pinnedAccount = $db->table('sa_accounts')
        ->where('account_uuid', $pinnedUuid)
        ->where('installation_id', $campaign->installation_id)
        ->first();
}
$out['pinned_account'] = $pinnedAccount ? [
    'id' => (int) $pinnedAccount->id,
    'account_uuid' => $pinnedAccount->account_uuid,
    'platform' => $pinnedAccount->platform,
    'domain' => $pinnedAccount->domain ?? null,
    'verified' => (bool) ($pinnedAccount->verified ?? false),
    'platform_account_id' => $pinnedAccount->platform_account_id ?? null,
    'identity_source' => $pinnedAccount->identity_source ?? null,
] : null;

// Active jobs on pinned account
if ($pinnedAccount) {
    $busy = $db->table('sa_jobs')
        ->where('account_id', $pinnedAccount->id)
        ->whereIn('status', AccountLockPolicy::ACTIVE_STATUSES)
        ->get(['id', 'job_uuid', 'status', 'campaign_id', 'created_at']);
    $out['pinned_account_active_jobs'] = $busy->map(static fn ($j) => (array) $j)->all();

    $lease = $db->table('sa_account_execution_leases')
        ->where('account_id', $pinnedAccount->id)
        ->first();
    $out['pinned_account_lease'] = $lease ? (array) $lease : null;
}

// Agent / session eligibility snapshot (RO simulation, no writes)
$online = new OnlineStateCalculator;
$eligibility = new EligibilitySelector;
$modePolicy = new CampaignModePolicy;
$kindPolicy = new AgentKindPolicy;
$mode = CampaignExecutionMode::tryFrom((string) $campaign->execution_mode)
    ?? CampaignExecutionMode::OneAccountOneJob;
$requiredAccountId = $pinnedAccount ? (int) $pinnedAccount->id : null;

$agents = SaAgent::query()
    ->where('installation_id', $campaign->installation_id)
    ->get();
$agentSnap = [];
foreach ($agents as $a) {
    $isOnline = $online->isOnline($a->last_heartbeat_at);
    $agentSnap[] = [
        'id' => (int) $a->id,
        'agent_uuid' => $a->agent_uuid,
        'agent_kind' => $a->agent_kind ?? null,
        'availability' => (string) $a->availability,
        'active_jobs' => (int) $a->active_jobs,
        'max_concurrency' => (int) $a->max_concurrency,
        'last_heartbeat_at' => optional($a->last_heartbeat_at)?->toIso8601String(),
        'online' => $isOnline,
        'counts_as_online_for_dispatch' => $kindPolicy->countsAsOnlineForDispatch(
            (string) ($a->agent_kind ?? ''),
            $isOnline
        ),
    ];
}
$out['agents'] = $agentSnap;

$busyAccountIds = SaJob::query()
    ->whereIn('status', AccountLockPolicy::ACTIVE_STATUSES)
    ->whereNotNull('account_id')
    ->pluck('account_id')
    ->all();

$sessionSnap = [];
$eligibleSessions = [];
foreach (SaBrowserSession::query()->get() as $session) {
    $agent = $agents->firstWhere('id', $session->agent_id);
    if (! $agent) {
        continue;
    }
    $activeBind = SaSessionAccount::query()
        ->where('browser_session_id', $session->id)
        ->where('is_active', true)
        ->first();
    $accountId = $activeBind?->account_id ?? $session->account_id;
    $account = $accountId ? SaAccount::query()->find($accountId) : null;
    $row = [
        'session_id' => (int) $session->id,
        'session_uuid' => $session->session_uuid,
        'agent_id' => (int) $session->agent_id,
        'dispatch_enabled' => (bool) ($session->dispatch_enabled ?? true),
        'session_state' => (string) ($session->session_state ?? 'candidate'),
        'ready_status' => $session->ready_status,
        'account_id' => $accountId !== null ? (int) $accountId : null,
        'account_platform' => $account?->platform,
        'account_installation' => $account?->installation_id,
        'matches_pin' => $requiredAccountId !== null && (int) $accountId === $requiredAccountId,
        'platform_match' => $account && strcasecmp((string) $account->platform, (string) $campaign->platform) === 0,
        'install_match' => $account && (string) $account->installation_id === (string) $campaign->installation_id,
        'locked_job_id' => $session->locked_job_id,
        'lock_expires_at' => optional($session->lock_expires_at)?->toIso8601String(),
    ];
    $sessionSnap[] = $row;

    if (! (bool) ($session->dispatch_enabled ?? true)) {
        continue;
    }
    if (! in_array((string) ($session->session_state ?? 'candidate'), ['candidate', 'verified'], true)) {
        continue;
    }
    if (! $account instanceof SaAccount) {
        continue;
    }
    if ((string) $account->installation_id !== (string) $campaign->installation_id) {
        continue;
    }
    if (strcasecmp((string) $account->platform, (string) $campaign->platform) !== 0) {
        continue;
    }
    if ($requiredAccountId !== null && (int) $accountId !== $requiredAccountId) {
        continue;
    }
    $eligibleSessions[] = $row;
}
$out['sessions_matching_campaign_filters'] = $eligibleSessions;
$out['sessions_all_installation_relevant_count'] = count($sessionSnap);

// Dry eligibility select (no DB writes)
$agentRows = [];
foreach ($agents as $a) {
    $agentRows[] = [
        'agent_id' => $a->id,
        'online' => $online->isOnline($a->last_heartbeat_at),
        'availability' => (string) $a->availability,
        'active_jobs' => (int) $a->active_jobs,
        'max_concurrency' => max(1, (int) $a->max_concurrency),
        'capabilities' => is_array($a->capabilities) ? $a->capabilities : [],
        'runtime_version' => $a->runtime_version,
        'agent_kind' => $a->agent_kind ?? null,
    ];
}
$sessionRows = [];
foreach (SaBrowserSession::query()->get() as $session) {
    $agent = null;
    foreach ($agentRows as $ar) {
        if ((int) $ar['agent_id'] === (int) $session->agent_id) {
            $agent = $ar;
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
    if ($requiredAccountId !== null && (int) $accountId !== $requiredAccountId) {
        continue;
    }
    if (! $modePolicy->canAssignAccount($mode, (int) $accountId, [], [])) {
        continue;
    }
    $affinity = SaAccountDeviceAffinity::query()->where('account_id', $accountId)->first();
    if ($affinity instanceof SaAccountDeviceAffinity && $affinity->affinity_until?->isFuture()
        && (int) $affinity->agent_id !== (int) $session->agent_id) {
        continue;
    }
    $busy = $session->locked_job_id !== null
        && ($session->lock_expires_at === null || $session->lock_expires_at->isFuture());
    $sessionRows[] = [
        'session_id' => $session->id,
        'agent_id' => $session->agent_id,
        'ready_status' => $session->ready_status,
        'busy' => $busy,
        'account_locked' => in_array((int) $accountId, array_map('intval', $busyAccountIds), true),
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
$selectResult = $eligibility->select($agentRows, $sessionRows, [
    'platform' => $campaign->platform,
    'target_count' => 1,
    'require_strong_identity' => $requiredAccountId === null,
]);
$out['eligibility_dry_run'] = [
    'selected_count' => count($selectResult['selected'] ?? []),
    'selected' => $selectResult['selected'] ?? [],
    'skip_reasons' => $selectResult['skip_reasons'] ?? [],
    'session_candidates_fed' => count($sessionRows),
];

// Pre-dispatch input validation (RO) for each content
$actionScripts = new ActionScriptDistributionService;
$variableSchema = new CampaignScriptVariableSchema;
$shortcodePipeline = new CampaignShortcodePipeline;
$campaignModel = SaCampaign::query()->find($campaign->id);
$inputGates = [];
$scriptPin = null;
if ($campaignModel instanceof SaCampaign) {
    $scriptPin = $actionScripts->resolvePublishedPin(
        (string) $campaignModel->script_id,
        (string) $campaignModel->script_version,
        $campaignModel->script_content_digest !== null ? (string) $campaignModel->script_content_digest : null,
    );
}
$out['script_pin'] = $scriptPin;
$manifestVars = null;
if (is_array($scriptPin) && isset($scriptPin['release_id'])) {
    $release = SaPackageRelease::query()->find($scriptPin['release_id']);
    $manifest = $release instanceof SaPackageRelease && is_array($release->manifest) ? $release->manifest : [];
    $schema = $variableSchema->fromManifest($manifest);
    $manifestVars = [
        'schema_ok' => (bool) ($schema['ok'] ?? false),
        'errors' => $schema['errors'] ?? [],
        'variable_names' => array_map(
            static fn ($v) => is_array($v) ? ($v['name'] ?? null) : null,
            $schema['variables'] ?? []
        ),
    ];
}
$out['script_variables'] = $manifestVars;

foreach ($contents as $r) {
    $input = isset($r->input) ? (is_string($r->input) ? json_decode($r->input, true) : $r->input) : [];
    $input = is_array($input) ? $input : [];
    $gate = ['ok' => true, 'errors' => []];
    if ($input === []) {
        $gate = ['ok' => false, 'errors' => ['content_input_empty']];
    } else {
        $fmt = (string) ($r->source_format ?? 'legacy_input');
        if ($fmt === 'shortcode') {
            $campaignVersion = (int) ($campaign->shortcode_mapping_version ?? 0);
            $contentVersion = (int) ($r->mapping_version ?? 0);
            if ($campaignVersion > 0 && $contentVersion !== $campaignVersion) {
                $gate = ['ok' => false, 'errors' => ['mapping_version_stale']];
            }
        }
        if ($gate['ok'] && $scriptPin === null) {
            $gate = ['ok' => false, 'errors' => ['script_not_found']];
        } elseif ($gate['ok'] && is_array($manifestVars) && ($manifestVars['schema_ok'] ?? false)) {
            $release = SaPackageRelease::query()->find($scriptPin['release_id'] ?? null);
            $manifest = $release instanceof SaPackageRelease && is_array($release->manifest) ? $release->manifest : [];
            $schema = $variableSchema->fromManifest($manifest);
            $variables = $schema['variables'] ?? [];
            if ($variables !== []) {
                $gate = $shortcodePipeline->validateStoredInput($input, $variables);
            }
        }
    }
    $inputGates[] = [
        'content_uuid' => $r->content_uuid,
        'gate' => $gate,
    ];
}
$out['input_gates'] = $inputGates;

// Schema + recheckDue query mirror
$out['schema'] = [
    'campaigns_ready' => SocialAutomationSchema::campaignsReady(),
];
$dueQuery = $db->table('sa_campaigns')
    ->where('status', 'running')
    ->where(function ($q) use ($now): void {
        $q->whereNull('next_recheck_at')->orWhere('next_recheck_at', '<=', $now);
    })
    ->orderBy('id')
    ->limit(50)
    ->get(['id', 'campaign_uuid', 'name', 'status', 'next_recheck_at']);
$out['due_running_campaigns'] = $dueQuery->map(static fn ($c) => (array) $c)->all();
$out['this_campaign_in_due_set'] = $dueQuery->contains(fn ($c) => (int) $c->id === (int) $campaign->id);

// Scheduler evidence from logs (RO)
$logPaths = [
    '/www/wwwroot/seo-ops-client/storage/logs/laravel.log',
    '/www/wwwroot/seo-ops-client/storage/logs/laravel-'.date('Y-m-d').'.log',
];
$logHits = [];
foreach ($logPaths as $lp) {
    if (! is_file($lp)) {
        $logHits[$lp] = ['exists' => false];
        continue;
    }
    $size = filesize($lp);
    $logHits[$lp] = [
        'exists' => true,
        'bytes' => $size,
        'mtime' => date('c', (int) filemtime($lp)),
    ];
    // Tail-ish search via shell is better; use file_get_contents with limit for small, or grep via exec RO
}
$out['log_files'] = $logHits;

// cron / schedule:list evidence
$cronHint = [];
$crontab = @shell_exec('crontab -l 2>/dev/null') ?: '';
$cronHint['crontab_has_schedule_tick'] = str_contains($crontab, 'social-automation:schedule-tick')
    || str_contains($crontab, 'schedule:run');
$cronHint['crontab_schedule_lines'] = array_values(array_filter(
    explode("\n", $crontab),
    static fn ($l) => str_contains($l, 'schedule') || str_contains($l, 'social-automation')
));
$out['cron'] = $cronHint;

// Code path markers on VPS
$orcPath = '/www/wwwroot/seo-ops-addons/social-automation/src/Orchestration/CampaignOrchestrator.php';
$svcPath = '/www/wwwroot/seo-ops-addons/social-automation/src/Services/CampaignService.php';
$tickPath = '/www/wwwroot/seo-ops-addons/social-automation/src/Console/SocialAutomationScheduleTickCommand.php';
$out['vps_code_markers'] = [
    'orchestrator_sha256' => is_file($orcPath) ? hash_file('sha256', $orcPath) : null,
    'start_calls_dispatch' => is_file($svcPath) && str_contains((string) file_get_contents($svcPath), '$this->orchestrator->dispatchCampaign($campaign)'),
    'recheck_includes_pending' => is_file($orcPath) && str_contains(
        (string) file_get_contents($orcPath),
        "CampaignContentStatus::Pending->value"
    ),
    'tick_calls_recheck' => is_file($tickPath) && str_contains(
        (string) file_get_contents($tickPath),
        'recheckDueCampaigns'
    ),
];

// Recent log lines mentioning schedule-tick / campaign / this uuid (via grep - RO)
$grepOut = [];
foreach ([
    'schedule-tick',
    'campaign_rechecks',
    'dispatch_failed',
    $uuid,
    'test wp 3',
] as $pat) {
    $cmd = 'grep -F '.escapeshellarg($pat).' '
        .escapeshellarg('/www/wwwroot/seo-ops-client/storage/logs/laravel.log')
        .' 2>/dev/null | tail -n 15';
    $grepOut[$pat] = trim((string) shell_exec($cmd));
}
// Also dated log
$dated = '/www/wwwroot/seo-ops-client/storage/logs/laravel-'.date('Y-m-d').'.log';
if (is_file($dated)) {
    foreach (['schedule-tick', 'campaign_rechecks', $uuid] as $pat) {
        $cmd = 'grep -F '.escapeshellarg($pat).' '.escapeshellarg($dated).' 2>/dev/null | tail -n 15';
        $grepOut['dated:'.$pat] = trim((string) shell_exec($cmd));
    }
}
$out['log_grep'] = $grepOut;

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
