<?php

declare(strict_types=1);

/**
 * READ-ONLY VPS runtime verification for Campaign dispatch stuck state.
 * No DB writes, no dispatch/recheck/claim, no service restarts.
 */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Domain\AccountDeduplicator;
use Omnichannel\Addons\SocialAutomation\Domain\AccountIdentityKey;
use Omnichannel\Addons\SocialAutomation\Domain\AccountLockPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\AgentKindPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\EligibilitySelector;
use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;

$addonRoot = '/www/wwwroot/seo-ops-addons/social-automation';
$files = [
    'src/Domain/AgentKindPolicy.php',
    'src/Domain/EligibilitySelector.php',
    'src/Orchestration/CampaignOrchestrator.php',
    'src/Domain/CampaignResourceRecheckNudge.php',
    'src/Registry/AgentRegistryService.php',
    'src/Registry/SessionSyncService.php',
];

$now = now();
$out = [
    'probe' => 'campaign_dispatch_runtime_verify_ro',
    'now' => $now->toIso8601String(),
    'mutated' => false,
];

// --- Phase 1: source on disk ---
$source = [];
foreach ($files as $rel) {
    $path = $addonRoot.'/'.$rel;
    if (! is_file($path)) {
        $source[$rel] = ['exists' => false];
        continue;
    }
    $blob = file_get_contents($path);
    $rf = new ReflectionClass(match (true) {
        str_ends_with($rel, 'AgentKindPolicy.php') => AgentKindPolicy::class,
        str_ends_with($rel, 'EligibilitySelector.php') => EligibilitySelector::class,
        str_ends_with($rel, 'OnlineStateCalculator.php') => OnlineStateCalculator::class,
        default => AgentKindPolicy::class,
    });
    $source[$rel] = [
        'exists' => true,
        'path' => $path,
        'realpath' => realpath($path) ?: null,
        'sha256' => hash('sha256', (string) $blob),
        'bytes' => strlen((string) $blob),
        'mtime' => date('c', (int) filemtime($path)),
        'markers' => [
            'client_or_runner_dispatch' => str_contains((string) $blob, 'KIND_CLIENT')
                && (str_contains((string) $blob, 'KIND_RUNNER || $normalized === self::KIND_CLIENT')
                    || str_contains((string) $blob, 'normalized === self::KIND_CLIENT')),
            'require_strong_identity' => str_contains((string) $blob, 'require_strong_identity'),
            'resource_nudge_class' => str_contains((string) $blob, 'class CampaignResourceRecheckNudge')
                || str_contains((string) $blob, 'CampaignResourceRecheckNudge'),
            'nudge_on_heartbeat' => str_contains((string) $blob, 'nudgeWaitingCampaignsForAgent')
                || str_contains((string) $blob, '!$wasOnline')
                || str_contains((string) $blob, '! $wasOnline'),
            'nudge_on_session_sync' => str_contains((string) $blob, '$eligibleNow && $changed'),
            'hard_pin_false_strong' => str_contains((string) $blob, "'require_strong_identity' => \$requiredAccountId === null"),
        ],
    ];
}

// Loaded class file paths (autoload / junction)
$loaded = [
    'AgentKindPolicy' => (new ReflectionClass(AgentKindPolicy::class))->getFileName(),
    'EligibilitySelector' => (new ReflectionClass(EligibilitySelector::class))->getFileName(),
    'CampaignOrchestrator' => (new ReflectionClass(\Omnichannel\Addons\SocialAutomation\Orchestration\CampaignOrchestrator::class))->getFileName(),
    'AgentRegistryService' => (new ReflectionClass(\Omnichannel\Addons\SocialAutomation\Registry\AgentRegistryService::class))->getFileName(),
    'SessionSyncService' => (new ReflectionClass(\Omnichannel\Addons\SocialAutomation\Registry\SessionSyncService::class))->getFileName(),
    'CampaignResourceRecheckNudge' => class_exists(\Omnichannel\Addons\SocialAutomation\Domain\CampaignResourceRecheckNudge::class)
        ? (new ReflectionClass(\Omnichannel\Addons\SocialAutomation\Domain\CampaignResourceRecheckNudge::class))->getFileName()
        : null,
    'SocialAutomationScheduleTickCommand' => class_exists(\Omnichannel\Addons\SocialAutomation\Console\SocialAutomationScheduleTickCommand::class)
        ? (new ReflectionClass(\Omnichannel\Addons\SocialAutomation\Console\SocialAutomationScheduleTickCommand::class))->getFileName()
        : null,
];

$policy = new AgentKindPolicy;
$runtimePolicy = [
    'countsAsOnlineForDispatch_client_true' => $policy->countsAsOnlineForDispatch(AgentKindPolicy::KIND_CLIENT, true),
    'countsAsOnlineForDispatch_client_false' => $policy->countsAsOnlineForDispatch(AgentKindPolicy::KIND_CLIENT, false),
    'countsAsOnlineForDispatch_admin_true' => $policy->countsAsOnlineForDispatch(AgentKindPolicy::KIND_ADMIN, true),
    'countsAsOnlineForDispatch_runner_true' => $policy->countsAsOnlineForDispatch(AgentKindPolicy::KIND_RUNNER, true),
];

$opcache = [
    'extension_loaded' => extension_loaded('Zend OPcache') || extension_loaded('opcache'),
    'cli_enable' => (bool) ini_get('opcache.enable_cli'),
    'enable' => (bool) ini_get('opcache.enable'),
    'revalidate_freq' => ini_get('opcache.revalidate_freq'),
    'validate_timestamps' => ini_get('opcache.validate_timestamps'),
];
if (function_exists('opcache_get_status')) {
    $st = @opcache_get_status(false);
    $opcache['status_available'] = is_array($st);
    $opcache['opcache_enabled'] = is_array($st) ? (bool) ($st['opcache_enabled'] ?? false) : null;
} else {
    $opcache['status_available'] = false;
}

$out['phase1'] = [
    'addon_root' => $addonRoot,
    'source_files' => $source,
    'loaded_class_paths' => $loaded,
    'runtime_policy_probe' => $runtimePolicy,
    'opcache' => $opcache,
];

// --- Phase 2: LIVE rows ---
$conn = 'omi_social_automation';
$db = DB::connection($conn);
$onlineCalc = new OnlineStateCalculator;
$kinds = new AgentKindPolicy;
$selector = new EligibilitySelector(new AccountDeduplicator, $kinds);
$locks = new AccountLockPolicy;

$campaignUuid = '13f41a46-aefb-4343-b495-3ef99b5176d6';
$accountUuid = 'ff0035e0-a6d8-42af-8370-f5be9e453f2d';

$campaign = $db->table('sa_campaigns')->where('campaign_uuid', $campaignUuid)->first();
$account = $db->table('sa_accounts')->where('account_uuid', $accountUuid)->first();
$content = $campaign
    ? $db->table('sa_campaign_contents')->where('campaign_id', $campaign->id)->orderBy('id')->first()
    : null;

$agents = $campaign
    ? $db->table('sa_agents')->where('installation_id', $campaign->installation_id)->orderBy('id')->get()
    : collect();

$agentRows = [];
foreach ($agents as $a) {
    $kind = $kinds->normalize((string) ($a->agent_kind ?? 'runner'));
    $hb = $a->last_heartbeat_at ? new DateTimeImmutable((string) $a->last_heartbeat_at) : null;
    $hbOnline = $onlineCalc->isOnline($hb, $now->toDateTimeImmutable());
    $agentRows[] = [
        'id' => (int) $a->id,
        'agent_uuid' => $a->agent_uuid,
        'agent_kind' => $kind,
        'availability' => $a->availability,
        'active_jobs' => (int) $a->active_jobs,
        'max_concurrency' => (int) $a->max_concurrency,
        'last_heartbeat_at' => $a->last_heartbeat_at,
        'heartbeat_age_seconds' => $a->last_heartbeat_at
            ? ($now->getTimestamp() - strtotime((string) $a->last_heartbeat_at))
            : null,
        'device_online' => $hbOnline,
        'dispatch_eligible' => $kinds->countsAsOnlineForDispatch($kind, $hbOnline),
        'connection_lease_expires_at' => $a->connection_lease_expires_at,
        'has_connection_token' => is_string($a->connection_token ?? null) && $a->connection_token !== '',
    ];
}

$sessionAccounts = $account
    ? $db->table('sa_session_accounts')->where('account_id', $account->id)->get()
    : collect();
$browserIds = $sessionAccounts->pluck('browser_session_id')->merge(
    $account ? $db->table('sa_browser_sessions')->where('account_id', $account->id)->pluck('id') : []
)->unique()->filter()->values()->all();

$browsers = $browserIds !== []
    ? $db->table('sa_browser_sessions')->whereIn('id', $browserIds)->get()
    : collect();

$browserRows = [];
foreach ($browsers as $b) {
    $caps = json_decode((string) ($b->capabilities ?? '{}'), true);
    if (! is_array($caps)) {
        $caps = [];
    }
    $signal = is_array($caps['session_signal'] ?? null) ? $caps['session_signal'] : null;
    $activeBind = $sessionAccounts->first(static fn ($sa) => (int) $sa->browser_session_id === (int) $b->id && (int) $sa->is_active === 1);
    $browserRows[] = [
        'id' => (int) $b->id,
        'session_uuid' => $b->session_uuid,
        'agent_id' => (int) $b->agent_id,
        'account_id' => $b->account_id !== null ? (int) $b->account_id : null,
        'active_bind_account_id' => $activeBind ? (int) $activeBind->account_id : null,
        'ready_status' => $b->ready_status,
        'session_state' => $b->session_state,
        'dispatch_enabled' => (bool) ($b->dispatch_enabled ?? true),
        'locked_job_id' => $b->locked_job_id,
        'lock_expires_at' => $b->lock_expires_at,
        'last_confirmed_at' => $b->last_confirmed_at ?? null,
        'caps_status' => $caps['status'] ?? null,
        'session_signal_status' => is_array($signal) ? ($signal['status'] ?? null) : null,
        'session_signal_present' => is_array($signal) ? (bool) ($signal['present'] ?? false) : null,
        'session_signal_checked_at' => is_array($signal) ? ($signal['checked_at'] ?? null) : null,
    ];
}

$leases = ($account && Schema::connection($conn)->hasTable('sa_account_execution_leases'))
    ? $db->table('sa_account_execution_leases')->where('account_id', $account->id)->orderByDesc('id')->limit(5)->get()
    : [];

$busyJobs = $account
    ? $db->table('sa_jobs')
        ->where('account_id', $account->id)
        ->whereIn('status', AccountLockPolicy::ACTIVE_STATUSES)
        ->get(['id', 'job_uuid', 'status', 'agent_id', 'campaign_id', 'campaign_content_id', 'browser_session_id', 'lease_expires_at', 'updated_at'])
    : [];

$campaignJobs = $campaign
    ? $db->table('sa_jobs')->where('campaign_id', $campaign->id)->orderByDesc('id')->limit(10)
        ->get(['id', 'job_uuid', 'status', 'agent_id', 'account_id', 'campaign_content_id', 'browser_session_id', 'created_at', 'updated_at', 'claimed_at', 'lease_expires_at'])
    : [];

$attempts = [];
if ($campaignJobs !== [] && Schema::connection($conn)->hasTable('sa_job_attempts')) {
    $jobIds = collect($campaignJobs)->pluck('id')->all();
    if ($jobIds !== []) {
        $attempts = $db->table('sa_job_attempts')->whereIn('job_id', $jobIds)->orderByDesc('id')->limit(10)
            ->get(['id', 'attempt_uuid', 'job_id', 'status', 'agent_id', 'started_at', 'lease_expires_at']);
    }
}

// Simulate eligibility (RO) — same gates as orchestrator hard-pin path
$requiredAccountId = $content && $content->assigned_account_id
    ? (int) $content->assigned_account_id
    : ($account ? (int) $account->id : 0);

$simAgents = array_map(static function (array $a): array {
    return [
        'agent_id' => $a['id'],
        'online' => $a['device_online'],
        'availability' => $a['availability'],
        'active_jobs' => $a['active_jobs'],
        'max_concurrency' => max(1, $a['max_concurrency']),
        'agent_kind' => $a['agent_kind'],
    ];
}, $agentRows);

$busyAccountIds = $db->table('sa_jobs')
    ->whereIn('status', AccountLockPolicy::ACTIVE_STATUSES)
    ->whereNotNull('account_id')
    ->pluck('account_id')
    ->all();

$simSessions = [];
foreach ($browserRows as $b) {
    $accId = $b['active_bind_account_id'] ?? $b['account_id'];
    if ($accId === null || (int) $accId !== $requiredAccountId) {
        continue;
    }
    if (! $b['dispatch_enabled'] || ! in_array((string) $b['session_state'], ['candidate', 'verified'], true)) {
        continue;
    }
    $agentOk = false;
    foreach ($simAgents as $sa) {
        if ((int) $sa['agent_id'] === (int) $b['agent_id']) {
            $agentOk = true;
            break;
        }
    }
    if (! $agentOk || ! $account) {
        continue;
    }
    $busy = $b['locked_job_id'] !== null
        && ($b['lock_expires_at'] === null || strtotime((string) $b['lock_expires_at']) > $now->getTimestamp());
    $simSessions[] = [
        'session_id' => $b['id'],
        'agent_id' => $b['agent_id'],
        'ready_status' => $b['ready_status'],
        'busy' => $busy,
        'account_locked' => $locks->isAccountBusy($requiredAccountId, $busyAccountIds),
        'platform' => $account->platform,
        'account' => [
            'platform' => $account->platform,
            'domain' => $account->domain ?? null,
            'platform_account_id' => $account->platform_account_id,
            'verified' => (bool) $account->verified,
            'identity_source' => $account->identity_source,
        ],
        'account_id' => $requiredAccountId,
    ];
}

$selection = $selector->select($simAgents, $simSessions, [
    'platform' => $campaign->platform ?? '',
    'target_count' => 1,
    'require_strong_identity' => false,
]);

$out['phase2'] = [
    'campaign' => $campaign ? [
        'id' => (int) $campaign->id,
        'uuid' => $campaign->campaign_uuid,
        'name' => $campaign->name ?? null,
        'status' => $campaign->status,
        'installation_id' => $campaign->installation_id,
        'platform' => $campaign->platform,
        'execution_mode' => $campaign->execution_mode ?? null,
        'resource_recheck_seconds' => (int) ($campaign->resource_recheck_seconds ?? 0),
        'next_recheck_at' => $campaign->next_recheck_at,
        'next_recheck_due' => $campaign->next_recheck_at === null
            || strtotime((string) $campaign->next_recheck_at) <= $now->getTimestamp(),
        'meta' => json_decode((string) ($campaign->meta ?? '{}'), true),
        'updated_at' => $campaign->updated_at ?? null,
    ] : null,
    'content' => $content ? [
        'id' => (int) $content->id,
        'uuid' => $content->content_uuid ?? null,
        'status' => $content->status,
        'assigned_account_id' => $content->assigned_account_id,
        'current_job_id' => $content->current_job_id ?? null,
        'last_error_code' => $content->last_error_code,
        'last_error_message' => $content->last_error_message,
        'updated_at' => $content->updated_at ?? null,
    ] : null,
    'pinned_account' => $account ? [
        'id' => (int) $account->id,
        'uuid' => $account->account_uuid,
        'installation_id' => $account->installation_id,
        'platform' => $account->platform,
        'name' => $account->name ?? null,
        'username' => $account->username ?? null,
        'verified' => (bool) ($account->verified ?? false),
        'identity_source' => $account->identity_source ?? null,
        'platform_account_id' => $account->platform_account_id ?? null,
        'installation_match' => (string) $account->installation_id === (string) $campaign->installation_id,
        'platform_match' => strcasecmp((string) $account->platform, (string) $campaign->platform) === 0,
        'strong_key' => AccountIdentityKey::strongKey([
            'platform' => $account->platform,
            'platform_account_id' => $account->platform_account_id,
            'verified' => (bool) $account->verified,
            'identity_source' => $account->identity_source,
        ]),
    ] : null,
    'agents' => $agentRows,
    'browsers' => $browserRows,
    'execution_leases' => $leases,
    'busy_jobs_for_account' => $busyJobs,
    'campaign_jobs' => $campaignJobs,
    'attempts' => $attempts,
    'eligibility_simulation' => [
        'required_account_id' => $requiredAccountId,
        'sessions_considered' => count($simSessions),
        'selected_count' => count($selection['selected']),
        'selected_account_ids' => array_map(static fn ($r) => $r['account_id'] ?? null, $selection['selected']),
        'skip_reasons' => $selection['skip_reasons'],
    ],
];

// --- Phase 3: scheduler config/logs (read-only) ---
$scheduleTickPath = $addonRoot.'/src/Console/SocialAutomationScheduleTickCommand.php';
$providerPath = $addonRoot.'/src/SocialAutomationServiceProvider.php';
$tickSrc = is_file($scheduleTickPath) ? (string) file_get_contents($scheduleTickPath) : '';
$providerSrc = is_file($providerPath) ? (string) file_get_contents($providerPath) : '';

$kernelPaths = [
    '/www/wwwroot/seo-ops-client/app/Console/Kernel.php',
    '/www/wwwroot/seo-ops-client/routes/console.php',
    '/www/wwwroot/seo-ops-client/bootstrap/app.php',
];
$kernelSnippets = [];
foreach ($kernelPaths as $kp) {
    if (! is_file($kp)) {
        $kernelSnippets[$kp] = ['exists' => false];
        continue;
    }
    $body = (string) file_get_contents($kp);
    $kernelSnippets[$kp] = [
        'exists' => true,
        'mentions_social_automation_schedule' => str_contains($body, 'social-automation:schedule-tick')
            || str_contains($body, 'SocialAutomationScheduleTick'),
        'mentions_schedule' => str_contains($body, '->schedule') || str_contains($body, 'Schedule::'),
        'mtime' => date('c', (int) filemtime($kp)),
    ];
}

// crontab / cron.d (read)
$cronEvidence = [];
foreach ([
    '/etc/crontab',
    '/var/spool/cron/root',
    '/var/spool/cron/crontabs/root',
] as $cronFile) {
    if (is_file($cronFile) && is_readable($cronFile)) {
        $lines = file($cronFile, FILE_IGNORE_NEW_LINES) ?: [];
        $hits = array_values(array_filter($lines, static function (string $line): bool {
            $l = strtolower($line);
            return str_contains($l, 'artisan') || str_contains($l, 'schedule:run') || str_contains($l, 'social-automation');
        }));
        $cronEvidence[$cronFile] = ['exists' => true, 'matching_lines' => $hits];
    } else {
        $cronEvidence[$cronFile] = ['exists' => false];
    }
}

$cronD = [];
$cronDDir = '/etc/cron.d';
if (is_dir($cronDDir)) {
    foreach (scandir($cronDDir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $p = $cronDDir.'/'.$name;
        if (! is_file($p) || ! is_readable($p)) {
            continue;
        }
        $body = strtolower((string) file_get_contents($p));
        if (str_contains($body, 'artisan') || str_contains($body, 'schedule') || str_contains($body, 'seo-ops')) {
            $lines = file($p, FILE_IGNORE_NEW_LINES) ?: [];
            $cronD[$name] = array_values(array_filter($lines, static fn ($l) => ! str_starts_with(trim($l), '#') && trim($l) !== ''));
        }
    }
}

// Log greps (tail-like, read only)
$logHits = [];
$logCandidates = [
    '/www/wwwroot/seo-ops-client/storage/logs/laravel.log',
    '/www/wwwroot/seo-ops-client/storage/logs/laravel-'.date('Y-m-d').'.log',
];
foreach ($logCandidates as $log) {
    if (! is_file($log) || ! is_readable($log)) {
        $logHits[$log] = ['exists' => false];
        continue;
    }
    $size = filesize($log);
    // Read last ~256KB only
    $fh = fopen($log, 'rb');
    if ($fh === false) {
        $logHits[$log] = ['exists' => true, 'error' => 'open_failed'];
        continue;
    }
    $read = 262144;
    if ($size > $read) {
        fseek($fh, -$read, SEEK_END);
    }
    $chunk = stream_get_contents($fh) ?: '';
    fclose($fh);
    $patterns = [
        'schedule-tick' => 'social-automation:schedule-tick',
        'recheckDueCampaigns' => 'recheckDueCampaigns',
        'schedule:run' => 'schedule:run',
        'pinned_account_unavailable' => 'pinned_account_unavailable',
        'CampaignOrchestrator' => 'CampaignOrchestrator',
    ];
    $counts = [];
    $lastLines = [];
    foreach ($patterns as $key => $needle) {
        $counts[$key] = substr_count($chunk, $needle);
    }
    foreach (array_reverse(explode("\n", $chunk)) as $line) {
        foreach ($patterns as $key => $needle) {
            if (str_contains($line, $needle) && count($lastLines) < 20) {
                $lastLines[] = substr($line, 0, 400);
                break;
            }
        }
        if (count($lastLines) >= 20) {
            break;
        }
    }
    $logHits[$log] = [
        'exists' => true,
        'bytes' => $size,
        'mtime' => date('c', (int) filemtime($log)),
        'tail_match_counts' => $counts,
        'recent_matching_lines' => array_reverse($lastLines),
    ];
}

// systemd / supervisor hints (read)
$svcHints = [];
foreach ([
    '/etc/systemd/system',
    '/usr/lib/systemd/system',
] as $dir) {
    if (! is_dir($dir)) {
        continue;
    }
    foreach (scandir($dir) ?: [] as $name) {
        $lname = strtolower($name);
        if (! str_ends_with($lname, '.service') && ! str_ends_with($lname, '.timer')) {
            continue;
        }
        if (str_contains($lname, 'seo') || str_contains($lname, 'laravel') || str_contains($lname, 'schedule') || str_contains($lname, 'cron')) {
            $svcHints[] = $dir.'/'.$name;
        }
    }
}

$out['phase3'] = [
    'tick_command' => [
        'signature_present' => str_contains($tickSrc, 'social-automation:schedule-tick'),
        'calls_recheckDueCampaigns' => str_contains($tickSrc, 'recheckDueCampaigns'),
        'path' => $scheduleTickPath,
        'mtime' => is_file($scheduleTickPath) ? date('c', (int) filemtime($scheduleTickPath)) : null,
    ],
    'provider_registers_command' => str_contains($providerSrc, 'SocialAutomationScheduleTickCommand'),
    'kernel_snippets' => $kernelSnippets,
    'cron_files' => $cronEvidence,
    'cron_d' => $cronD,
    'systemd_hints' => $svcHints,
    'logs' => $logHits,
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
