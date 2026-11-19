<?php

declare(strict_types=1);

/**
 * READ-ONLY diagnosis for pinned_account_unavailable.
 * Campaign 13f41a46-aefb-4343-b495-3ef99b5176d6 / Account ff0035e0-a6d8-42af-8370-f5be9e453f2d
 * No mutations. No secrets.
 */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Domain\AgentKindPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;
use Omnichannel\Addons\SocialAutomation\Domain\AccountIdentityKey;
use Omnichannel\Addons\SocialAutomation\Domain\ClientConnectionLease;

$conn = 'omi_social_automation';
$db = DB::connection($conn);
$schema = Schema::connection($conn);
$now = now();
$onlineCalc = new OnlineStateCalculator;
$kindPolicy = new AgentKindPolicy;
$lease = new ClientConnectionLease;

$campaignUuid = '13f41a46-aefb-4343-b495-3ef99b5176d6';
$accountUuid = 'ff0035e0-a6d8-42af-8370-f5be9e453f2d';

$out = [
    'probe' => 'pinned_account_unavailable_ro',
    'now' => $now->toIso8601String(),
    'db' => $db->getDatabaseName(),
];

$campaign = $db->table('sa_campaigns')->where('campaign_uuid', $campaignUuid)->first();
if (! $campaign) {
    $out['error'] = 'campaign_not_found';
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(0);
}

$meta = json_decode((string) ($campaign->meta ?? '{}'), true);
if (! is_array($meta)) {
    $meta = [];
}

$out['campaign'] = [
    'id' => (int) $campaign->id,
    'campaign_uuid' => $campaign->campaign_uuid,
    'name' => $campaign->name ?? null,
    'status' => $campaign->status,
    'installation_id' => $campaign->installation_id,
    'platform' => $campaign->platform,
    'execution_mode' => $campaign->execution_mode ?? null,
    'resource_recheck_seconds' => (int) ($campaign->resource_recheck_seconds ?? 0),
    'next_recheck_at' => $campaign->next_recheck_at,
    'next_recheck_due' => $campaign->next_recheck_at === null
        || strtotime((string) $campaign->next_recheck_at) <= $now->getTimestamp(),
    'meta_pinned_account_id' => $meta['pinned_account_id'] ?? null,
    'script_id' => $campaign->script_id ?? null,
];

$contentCols = $schema->getColumnListing('sa_campaign_contents');
$wantContentCols = array_values(array_intersect($contentCols, [
    'id', 'content_uuid', 'status', 'assigned_account_id', 'job_id', 'assigned_job_id',
    'last_error_code', 'last_error_message', 'sort_order', 'updated_at',
]));
$contents = $db->table('sa_campaign_contents')
    ->where('campaign_id', $campaign->id)
    ->orderBy('sort_order')
    ->orderBy('id')
    ->get($wantContentCols !== [] ? $wantContentCols : ['*']);
$out['content_columns'] = $contentCols;
$out['contents'] = $contents;

$account = $db->table('sa_accounts')->where('account_uuid', $accountUuid)->first();
$accountSafe = null;
if ($account) {
    $accountSafe = [
        'id' => (int) $account->id,
        'account_uuid' => $account->account_uuid,
        'installation_id' => $account->installation_id,
        'platform' => $account->platform,
        'domain' => $account->domain ?? null,
        'name' => $account->name ?? null,
        'username' => $account->username ?? null,
        'verified' => (bool) ($account->verified ?? false),
        'identity_source' => $account->identity_source ?? null,
        'platform_account_id' => $account->platform_account_id ?? null,
        'site_url' => $account->site_url ?? null,
        'installation_match' => (string) $account->installation_id === (string) $campaign->installation_id,
        'platform_match' => strcasecmp((string) $account->platform, (string) $campaign->platform) === 0,
    ];
    $identityMeta = [
        'platform' => $account->platform,
        'domain' => $account->domain ?? null,
        'platform_account_id' => $account->platform_account_id ?? null,
        'verified' => (bool) ($account->verified ?? false),
        'identity_source' => $account->identity_source ?? null,
    ];
    $accountSafe['strong_identity_key'] = AccountIdentityKey::strongKey($identityMeta);
    $accountSafe['identity_complete'] = $accountSafe['strong_identity_key'] !== null;
}
$out['pinned_account'] = $accountSafe;

$accountId = $account ? (int) $account->id : 0;

// Assignments / busy
$out['campaign_account_assignments'] = $accountId > 0
    ? $db->table('sa_campaign_account_assignments')->where('campaign_id', $campaign->id)->get()
    : [];

$busyJobs = $accountId > 0
    ? $db->table('sa_jobs')
        ->where('account_id', $accountId)
        ->whereIn('status', ['queued', 'leased', 'running', 'unknown'])
        ->get(['id', 'job_uuid', 'status', 'agent_id', 'campaign_id', 'lease_expires_at', 'updated_at'])
    : [];
$out['account_busy_jobs'] = $busyJobs;

$leases = [];
if ($accountId > 0 && $schema->hasTable('sa_account_execution_leases')) {
    $leases = $db->table('sa_account_execution_leases')
        ->where('account_id', $accountId)
        ->orderByDesc('id')
        ->limit(10)
        ->get();
}
$out['account_execution_leases'] = $leases;

// Session bindings
$sessionAccounts = $accountId > 0 && $schema->hasTable('sa_session_accounts')
    ? $db->table('sa_session_accounts')->where('account_id', $accountId)->get()
    : collect();
$out['session_accounts'] = $sessionAccounts;

$browserIds = $sessionAccounts->pluck('browser_session_id')->filter()->unique()->values()->all();
$directBrowsers = $accountId > 0
    ? $db->table('sa_browser_sessions')->where('account_id', $accountId)->pluck('id')->all()
    : [];
$browserIds = array_values(array_unique(array_merge($browserIds, $directBrowsers)));

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
    $busy = $b->locked_job_id !== null
        && ($b->lock_expires_at === null || strtotime((string) $b->lock_expires_at) > $now->getTimestamp());
    $activeBind = $sessionAccounts->first(static fn ($sa) => (int) $sa->browser_session_id === (int) $b->id && (bool) $sa->is_active);
    $resolvedAccountId = $activeBind->account_id ?? $b->account_id;
    $browserRows[] = [
        'id' => (int) $b->id,
        'session_uuid' => $b->session_uuid,
        'agent_id' => (int) $b->agent_id,
        'account_id' => $b->account_id !== null ? (int) $b->account_id : null,
        'resolved_account_id' => $resolvedAccountId !== null ? (int) $resolvedAccountId : null,
        'active_bind' => $activeBind ? [
            'id' => (int) $activeBind->id,
            'is_active' => (bool) $activeBind->is_active,
            'account_id' => (int) $activeBind->account_id,
            'domain' => $activeBind->domain ?? null,
        ] : null,
        'platform' => $b->platform,
        'domain' => $b->domain,
        'ready_status' => $b->ready_status,
        'session_state' => $b->session_state,
        'dispatch_enabled' => (bool) ($b->dispatch_enabled ?? true),
        'dispatch_pause_reason' => $b->dispatch_pause_reason ?? null,
        'locked_job_id' => $b->locked_job_id,
        'lock_expires_at' => $b->lock_expires_at,
        'session_busy' => $busy,
        'last_confirmed_at' => $b->last_confirmed_at ?? null,
        'caps_status' => $caps['status'] ?? null,
        'caps_name' => $caps['name'] ?? null,
        'session_signal' => $signal,
        'passes_prefilter_dispatch_enabled' => (bool) ($b->dispatch_enabled ?? true),
        'passes_prefilter_session_state' => in_array((string) ($b->session_state ?? 'candidate'), ['candidate', 'verified'], true),
        'passes_prefilter_account_match' => $resolvedAccountId !== null && (int) $resolvedAccountId === $accountId,
    ];
}
$out['browsers'] = $browserRows;

// Agents for campaign installation + agents owning browsers
$agentIds = collect($browserRows)->pluck('agent_id')->unique()->values()->all();
$agents = $db->table('sa_agents')
    ->where('installation_id', $campaign->installation_id)
    ->orderByDesc('last_heartbeat_at')
    ->get();

$agentRows = [];
foreach ($agents as $a) {
    $kind = $kindPolicy->normalize(isset($a->agent_kind) ? (string) $a->agent_kind : null);
    $hbOnline = $onlineCalc->isOnline(
        $a->last_heartbeat_at ? new DateTimeImmutable((string) $a->last_heartbeat_at) : null,
        $now->toDateTimeImmutable()
    );
    $leaseActive = $lease->isActive($a->connection_token ?? null, $a->connection_lease_expires_at
        ? new DateTimeImmutable((string) $a->connection_lease_expires_at)
        : null);
    $hbAge = $a->last_heartbeat_at
        ? ($now->getTimestamp() - strtotime((string) $a->last_heartbeat_at))
        : null;
    $agentRows[] = [
        'id' => (int) $a->id,
        'agent_uuid' => $a->agent_uuid,
        'device_id' => $a->device_id ?? null,
        'display_name' => $a->display_name ?? null,
        'agent_kind' => $kind,
        'raw_agent_kind' => $a->agent_kind,
        'availability' => $a->availability,
        'active_jobs' => (int) $a->active_jobs,
        'max_concurrency' => (int) $a->max_concurrency,
        'last_heartbeat_at' => $a->last_heartbeat_at,
        'heartbeat_age_seconds' => $hbAge,
        'device_online_ui' => $hbOnline,
        'dispatch_eligible' => $kindPolicy->countsAsOnlineForDispatch($kind, $hbOnline),
        'connection_lease_expires_at' => $a->connection_lease_expires_at,
        'connection_lease_active' => $leaseActive,
        'has_connection_token' => is_string($a->connection_token ?? null) && $a->connection_token !== '',
        'owns_pinned_browser' => in_array((int) $a->id, $agentIds, true),
        'last_error_code' => $a->last_error_code ?? null,
    ];
}
$out['agents_in_installation'] = $agentRows;

// Affinity
$affinity = $accountId > 0 && $schema->hasTable('sa_account_device_affinities')
    ? $db->table('sa_account_device_affinities')->where('account_id', $accountId)->first()
    : null;
$out['device_affinity'] = $affinity;

// Predicate evaluation mirroring CampaignOrchestrator::selectEligibleForCampaign + EligibilitySelector
$predicates = [];
$predicates[] = [
    'name' => 'campaign_allows_dispatch',
    'pass' => in_array((string) $campaign->status, ['running'], true),
    'detail' => $campaign->status,
    'source' => 'CampaignOrchestrator::dispatchCampaign / CampaignStatus::allowsDispatch',
];
$predicates[] = [
    'name' => 'pinned_account_resolves_in_installation',
    'pass' => $accountSafe !== null && ($accountSafe['installation_match'] ?? false),
    'detail' => $accountSafe === null ? 'account_missing' : [
        'account_installation' => $accountSafe['installation_id'],
        'campaign_installation' => $campaign->installation_id,
        'match' => $accountSafe['installation_match'],
    ],
    'source' => 'CampaignOrchestrator::resolveRequiredAccountId',
];
$predicates[] = [
    'name' => 'platform_match_campaign_vs_account',
    'pass' => $accountSafe !== null && ($accountSafe['platform_match'] ?? false),
    'detail' => $accountSafe === null ? null : [
        'account_platform' => $accountSafe['platform'],
        'campaign_platform' => $campaign->platform,
    ],
    'source' => 'CampaignOrchestrator::selectEligibleForCampaign platform strcasecmp',
];
$predicates[] = [
    'name' => 'account_strong_identity',
    'pass' => $accountSafe !== null && ($accountSafe['identity_complete'] ?? false),
    'detail' => $accountSafe['strong_identity_key'] ?? null,
    'source' => 'EligibilitySelector + AccountIdentityKey::strongKey',
];
$predicates[] = [
    'name' => 'account_not_busy_on_active_jobs',
    'pass' => count($busyJobs) === 0,
    'detail' => ['busy_job_count' => count($busyJobs)],
    'source' => 'AccountLockPolicy via selectEligibleForCampaign account_locked',
];

$browserPass = false;
$browserFailReasons = [];
foreach ($browserRows as $b) {
    $reasons = [];
    if (! $b['passes_prefilter_dispatch_enabled']) {
        $reasons[] = 'dispatch_disabled';
    }
    if (! $b['passes_prefilter_session_state']) {
        $reasons[] = 'session_state_not_candidate_or_verified';
    }
    if (! $b['passes_prefilter_account_match']) {
        $reasons[] = 'account_not_bound';
    }
    if ($b['ready_status'] !== 'ready') {
        $reasons[] = 'session_not_ready';
    }
    if ($b['session_busy']) {
        $reasons[] = 'session_busy';
    }
    $agent = collect($agentRows)->firstWhere('id', $b['agent_id']);
    if ($agent === null) {
        $reasons[] = 'agent_missing_in_installation';
    } else {
        if (! $agent['dispatch_eligible']) {
            $reasons[] = 'agent_not_dispatch_eligible(kind='.$agent['agent_kind'].',hb='.($agent['device_online_ui'] ? 'online' : 'offline').')';
        }
        if (in_array((string) $agent['availability'], ['paused', 'disabled', 'error'], true)) {
            $reasons[] = 'agent_unavailable:'.$agent['availability'];
        }
        if ((int) $agent['active_jobs'] >= max(1, (int) $agent['max_concurrency'])) {
            $reasons[] = 'agent_at_capacity';
        }
    }
    if ($reasons === []) {
        $browserPass = true;
    } else {
        $browserFailReasons[] = [
            'browser_id' => $b['id'],
            'session_uuid' => $b['session_uuid'],
            'reasons' => $reasons,
        ];
    }
}
$predicates[] = [
    'name' => 'browser_session_eligible_for_pinned_account',
    'pass' => $browserPass,
    'detail' => [
        'browser_count' => count($browserRows),
        'failures' => $browserFailReasons,
    ],
    'source' => 'CampaignOrchestrator::selectEligibleForCampaign prefilter + EligibilitySelector',
];

$clientOnlineAgents = array_values(array_filter($agentRows, static fn ($a) => $a['agent_kind'] === 'client' && $a['device_online_ui']));
$predicates[] = [
    'name' => 'client_online_equals_dispatch_eligible',
    'pass' => $clientOnlineAgents === [] || collect($clientOnlineAgents)->contains(static fn ($a) => $a['dispatch_eligible']),
    'detail' => [
        'note' => 'Client Online UI uses heartbeat; dispatch uses AgentKindPolicy::countsAsOnlineForDispatch (runner-only)',
        'client_online_agents' => array_map(static fn ($a) => [
            'id' => $a['id'],
            'device_online_ui' => $a['device_online_ui'],
            'dispatch_eligible' => $a['dispatch_eligible'],
            'kind' => $a['agent_kind'],
        ], $clientOnlineAgents),
    ],
    'source' => 'AgentKindPolicy::countsAsOnlineForDispatch + AgentRegistryService::toArray',
];

$predicates[] = [
    'name' => 'next_recheck_at_due_now',
    'pass' => (bool) $out['campaign']['next_recheck_due'],
    'detail' => [
        'next_recheck_at' => $campaign->next_recheck_at,
        'resource_recheck_seconds' => (int) ($campaign->resource_recheck_seconds ?? 0),
        'note' => 'Even if account becomes eligible, recheckDueCampaigns waits until next_recheck_at',
    ],
    'source' => 'CampaignOrchestrator::recheckDueCampaigns + CampaignRecheckPolicy',
];

$predicates[] = [
    'name' => 'session_signal_required_for_campaign_eligibility',
    'pass' => true,
    'detail' => [
        'required_by_campaign_orchestrator' => false,
        'note' => 'session_signal is NOT checked in selectEligibleForCampaign/EligibilitySelector; used by ClientNotificationService only',
    ],
    'source' => 'CampaignOrchestrator::selectEligibleForCampaign (absent check)',
];

$predicates[] = [
    'name' => 'connection_lease_required_for_campaign_eligibility',
    'pass' => true,
    'detail' => [
        'required_by_campaign_orchestrator' => false,
        'note' => 'connection lease gates Client API middleware, not EligibilitySelector',
    ],
    'source' => 'EnsureClientConnectionLease vs EligibilitySelector',
];

$out['predicates'] = $predicates;
$out['failed_predicates'] = array_values(array_filter($predicates, static fn ($p) => ! $p['pass']));

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
