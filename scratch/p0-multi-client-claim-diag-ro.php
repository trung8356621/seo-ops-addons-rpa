<?php

declare(strict_types=1);

/**
 * READ-ONLY multi-client claim/dispatch diagnosis for QA Stage 2 campaign.
 * No writes. No resume. No job/lease mutation.
 */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SocialAutomation\Domain\AccountLockPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;

$db = DB::connection('omi_social_automation');
$now = now();
$online = new OnlineStateCalculator;

$campaign = $db->table('sa_campaigns')
    ->where('name', 'like', '%QA Stage 2%')
    ->orWhere('name', 'like', '%2 Clients 10 Jobs%')
    ->orderByDesc('id')
    ->first();

if (! $campaign) {
    $campaign = $db->table('sa_campaigns')
        ->where('name', 'like', '%QA%')
        ->orderByDesc('id')
        ->limit(5)
        ->get();
    echo json_encode(['error' => 'campaign_not_found', 'qa_candidates' => $campaign], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(0);
}

$allAgents = $db->table('sa_agents')
    ->where('installation_id', $campaign->installation_id)
    ->orderBy('id')
    ->get();

$keys = $db->table('sa_client_api_keys')
    ->where('installation_id', $campaign->installation_id)
    ->orderBy('id')
    ->get()
    ->map(static function ($k): array {
        return [
            'id' => (int) $k->id,
            'label' => $k->label ?? $k->name ?? null,
            'issued_by' => $k->issued_by ?? null,
            'created_by' => $k->created_by ?? null,
            'status' => $k->status ?? null,
            'revoked_at' => $k->revoked_at ?? null,
            'last_used_at' => $k->last_used_at ?? null,
        ];
    })->all();

$planTags = $db->table('sa_campaign_action_tags')
    ->where('campaign_id', $campaign->id)
    ->get();

$contents = $db->table('sa_campaign_contents')
    ->where('campaign_id', $campaign->id)
    ->orderBy('sort_order')
    ->orderBy('id')
    ->get();

$assignments = $db->table('sa_campaign_content_assignments')
    ->whereIn('campaign_content_id', $contents->pluck('id')->all())
    ->get()
    ->groupBy('campaign_content_id');

$jobs = $db->table('sa_jobs')
    ->where('campaign_id', $campaign->id)
    ->orderBy('id')
    ->get();

$activeLeases = $db->table('sa_account_execution_leases')
    ->where('status', 'active')
    ->whereIn('account_id', $jobs->pluck('account_id')->filter()->unique()->all() ?: [0])
    ->get();

$setting = $db->table('sa_settings')
    ->where('installation_id', $campaign->installation_id)
    ->where('setting_key', 'campaign_scheduler_interval_seconds')
    ->first();

$agentRows = [];
foreach ($allAgents as $a) {
    $hb = $a->last_heartbeat_at ? new DateTimeImmutable((string) $a->last_heartbeat_at) : null;
    $isOnline = $online->isOnline($hb, $now->toDateTimeImmutable());
    $sessions = $db->table('sa_browser_sessions')->where('agent_id', $a->id)->get();
    $sessionRows = [];
    foreach ($sessions as $s) {
        $bind = $db->table('sa_session_accounts')
            ->where('browser_session_id', $s->id)
            ->where('is_active', 1)
            ->first();
        $accId = $bind->account_id ?? $s->account_id;
        $acc = $accId ? $db->table('sa_accounts')->find($accId) : null;
        $affinity = $accId
            ? $db->table('sa_account_device_affinities')->where('account_id', $accId)->first()
            : null;
        $sessionRows[] = [
            'session_id' => (int) $s->id,
            'session_uuid' => $s->session_uuid,
            'ready_status' => $s->ready_status,
            'session_state' => $s->session_state,
            'dispatch_enabled' => (bool) ($s->dispatch_enabled ?? true),
            'locked_job_id' => $s->locked_job_id,
            'lock_expires_at' => $s->lock_expires_at,
            'account_id' => $accId ? (int) $accId : null,
            'account_uuid' => $acc->account_uuid ?? null,
            'account_platform' => $acc->platform ?? null,
            'account_domain' => $acc->domain ?? null,
            'account_verified' => isset($acc->verified) ? (bool) $acc->verified : null,
            'platform_account_id' => $acc->platform_account_id ?? null,
            'affinity_agent_id' => $affinity->agent_id ?? null,
            'affinity_until' => $affinity->affinity_until ?? null,
        ];
    }
    $agentRows[] = [
        'id' => (int) $a->id,
        'agent_uuid' => $a->agent_uuid,
        'name' => $a->name ?? $a->display_name ?? null,
        'availability' => $a->availability,
        'agent_kind' => $a->agent_kind ?? null,
        'active_jobs' => (int) $a->active_jobs,
        'max_concurrency' => (int) $a->max_concurrency,
        'client_api_key_id' => $a->client_api_key_id ?? null,
        'runtime_version' => $a->runtime_version ?? null,
        'last_heartbeat_at' => $a->last_heartbeat_at,
        'online' => $isOnline,
        'sessions' => $sessionRows,
    ];
}

$contentRows = [];
foreach ($contents as $c) {
    $asg = $assignments->get($c->id, collect());
    $contentRows[] = [
        'id' => (int) $c->id,
        'content_uuid' => $c->content_uuid,
        'status' => $c->status,
        'sort_order' => (int) $c->sort_order,
        'assigned_account_id' => $c->assigned_account_id,
        'current_job_id' => $c->current_job_id,
        'last_error_code' => $c->last_error_code,
        'last_error_message' => $c->last_error_message,
        'action_tag_ids' => $asg->pluck('campaign_action_tag_id')->map(fn ($v) => (int) $v)->values()->all(),
    ];
}

$jobRows = [];
foreach ($jobs as $j) {
    $payload = json_decode((string) ($j->payload ?? '{}'), true);
    $jobRows[] = [
        'id' => (int) $j->id,
        'job_uuid' => $j->job_uuid,
        'status' => $j->status,
        'agent_id' => $j->agent_id,
        'browser_session_id' => $j->browser_session_id,
        'account_id' => $j->account_id,
        'campaign_content_id' => $j->campaign_content_id,
        'reserved_at' => $j->reserved_at ?? null,
        'claimed_at' => $j->claimed_at ?? null,
        'finished_at' => $j->finished_at ?? null,
        'lease_expires_at' => $j->lease_expires_at,
        'action_tag_id' => is_array($payload) ? ($payload['campaign_action_tag_id'] ?? null) : null,
        'site_key' => is_array($payload) ? ($payload['site_key'] ?? null) : null,
    ];
}

// Job timing gaps for cadence evidence
$finished = $jobs->where('status', 'completed')->sortBy('finished_at')->values();
$gaps = [];
for ($i = 1; $i < $finished->count(); $i++) {
    $prev = strtotime((string) $finished[$i - 1]->finished_at);
    $cur = strtotime((string) $finished[$i]->finished_at);
    if ($prev && $cur) {
        $gaps[] = $cur - $prev;
    }
}

echo json_encode([
    'probe' => 'p0_multi_client_claim_diag_ro',
    'mutated' => false,
    'now' => $now->toIso8601String(),
    'campaign' => [
        'id' => (int) $campaign->id,
        'uuid' => $campaign->campaign_uuid,
        'name' => $campaign->name,
        'status' => $campaign->status,
        'plan_version' => $campaign->plan_version ?? null,
        'execution_mode' => $campaign->execution_mode,
        'platform' => $campaign->platform,
        'installation_id' => $campaign->installation_id,
        'resource_recheck_seconds' => $campaign->resource_recheck_seconds ?? null,
        'next_recheck_at' => $campaign->next_recheck_at,
        'meta' => json_decode((string) ($campaign->meta ?? '{}'), true),
        'owner_user_id' => $campaign->owner_user_id ?? $campaign->created_by_user_id ?? null,
    ],
    'scheduler_setting' => $setting ? json_decode((string) $setting->value_json, true) : null,
    'plan_tags' => $planTags->map(static fn ($t): array => [
        'id' => (int) $t->id,
        'action_tag_key' => $t->action_tag_key,
        'site_key' => $t->site_key,
        'platform' => $t->platform ?? null,
        'interval_minutes' => (int) ($t->interval_minutes ?? 0),
        'is_enabled' => (bool) $t->is_enabled,
        'release_id' => (int) ($t->release_id ?? 0),
    ])->all(),
    'api_keys' => $keys,
    'agents' => $agentRows,
    'contents' => $contentRows,
    'jobs' => $jobRows,
    'active_leases' => $activeLeases,
    'completed_finish_gaps_seconds' => $gaps,
    'active_job_statuses' => AccountLockPolicy::ACTIVE_STATUSES,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
