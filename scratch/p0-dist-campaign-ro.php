<?php

declare(strict_types=1);

/** READ-ONLY: multi-client distribution forensics for campaign d70d1999. No writes. */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;

$db = DB::connection('omi_social_automation');
$online = new OnlineStateCalculator;
$campaignUuid = 'd70d1999-5c71-46f8-a3a4-b0f04014aa46';
$now = now();

$c = $db->table('sa_campaigns')->where('campaign_uuid', $campaignUuid)->first();
if (! $c) {
    echo json_encode(['error' => 'campaign_not_found', 'mutated' => false]).PHP_EOL;
    exit(0);
}

$meta = json_decode((string) ($c->meta ?? '{}'), true) ?: [];
$planTags = $db->table('sa_campaign_action_tags')->where('campaign_id', $c->id)->get();
$assignments = $db->table('sa_campaign_account_assignments')->where('campaign_id', $c->id)->get();
$contents = $db->table('sa_campaign_contents')->where('campaign_id', $c->id)->orderBy('sort_order')->orderBy('id')->get();
$contentAssign = $db->table('sa_campaign_content_assignments')
    ->whereIn('campaign_content_id', $contents->pluck('id')->all() ?: [0])
    ->get();
$jobs = $db->table('sa_jobs')->where('campaign_id', $c->id)->orderBy('id')->get();

$agents = $db->table('sa_agents')->where('installation_id', $c->installation_id)->orderBy('id')->get();
$agentRows = [];
foreach ($agents as $a) {
    $hb = $a->last_heartbeat_at ? new DateTimeImmutable((string) $a->last_heartbeat_at) : null;
    $sessions = $db->table('sa_browser_sessions')->where('agent_id', $a->id)->get();
    $sess = [];
    foreach ($sessions as $s) {
        $binds = $db->table('sa_session_accounts as sa')
            ->leftJoin('sa_accounts as acc', 'acc.id', '=', 'sa.account_id')
            ->where('sa.browser_session_id', $s->id)
            ->get([
                'sa.id as bind_id', 'sa.is_active', 'sa.account_id',
                'acc.account_uuid', 'acc.platform', 'acc.username', 'acc.platform_account_id',
                'acc.verified', 'acc.installation_id',
            ]);
        $affinity = null;
        foreach ($binds as $b) {
            if ($b->account_id) {
                $aff = $db->table('sa_account_device_affinities')->where('account_id', $b->account_id)->first();
                if ($aff) {
                    $affinity = $aff;
                }
            }
        }
        $sess[] = [
            'session_id' => (int) $s->id,
            'session_uuid' => $s->session_uuid,
            'ready_status' => $s->ready_status,
            'session_state' => $s->session_state ?? null,
            'dispatch_enabled' => $s->dispatch_enabled ?? null,
            'account_id_col' => $s->account_id,
            'locked_job_id' => $s->locked_job_id,
            'binds' => $binds,
            'affinity_on_bound' => $affinity,
        ];
    }
    $agentRows[] = [
        'id' => (int) $a->id,
        'agent_uuid' => $a->agent_uuid,
        'client_api_key_id' => $a->client_api_key_id,
        'availability' => $a->availability,
        'max_concurrency' => (int) $a->max_concurrency,
        'active_jobs' => (int) $a->active_jobs,
        'agent_kind' => $a->agent_kind,
        'runtime_version' => $a->runtime_version,
        'last_heartbeat_at' => $a->last_heartbeat_at,
        'online_now' => $online->isOnline($hb, $now->toDateTimeImmutable()),
        'sessions' => $sess,
    ];
}

$sandboxAccounts = $db->table('sa_accounts')
    ->where('installation_id', $c->installation_id)
    ->where('platform', 'qa_sandbox_facebook')
    ->orderBy('id')
    ->get()
    ->map(static function ($acc) use ($db): array {
        $binds = $db->table('sa_session_accounts as sa')
            ->join('sa_browser_sessions as bs', 'bs.id', '=', 'sa.browser_session_id')
            ->join('sa_agents as ag', 'ag.id', '=', 'bs.agent_id')
            ->where('sa.account_id', $acc->id)
            ->get([
                'sa.is_active', 'bs.id as session_id', 'bs.session_uuid', 'bs.ready_status',
                'ag.id as agent_id', 'ag.client_api_key_id',
            ]);
        $aff = $db->table('sa_account_device_affinities')->where('account_id', $acc->id)->first();

        return [
            'id' => (int) $acc->id,
            'uuid' => $acc->account_uuid,
            'username' => $acc->username,
            'verified' => (bool) $acc->verified,
            'platform_account_id' => $acc->platform_account_id,
            'binds' => $binds,
            'affinity' => $aff,
        ];
    });

$jobRows = $jobs->map(static function ($j): array {
    $p = json_decode((string) ($j->payload ?? '{}'), true) ?: [];

    return [
        'id' => (int) $j->id,
        'uuid' => $j->job_uuid,
        'status' => $j->status,
        'agent_id' => $j->agent_id,
        'account_id' => $j->account_id,
        'browser_session_id' => $j->browser_session_id,
        'content_id' => $j->campaign_content_id,
        'reserved_at' => $j->reserved_at,
        'claimed_at' => $j->claimed_at,
        'finished_at' => $j->finished_at,
        'site_key' => $p['site_key'] ?? null,
        'action_tag_id' => $p['campaign_action_tag_id'] ?? null,
    ];
})->all();

$contentRows = $contents->map(static function ($row) use ($contentAssign): array {
    $asg = $contentAssign->where('campaign_content_id', $row->id)->values();

    return [
        'id' => (int) $row->id,
        'status' => $row->status,
        'sort_order' => (int) $row->sort_order,
        'assigned_account_id' => $row->assigned_account_id,
        'current_job_id' => $row->current_job_id,
        'last_error_code' => $row->last_error_code,
        'tag_ids' => $asg->pluck('campaign_action_tag_id')->map(fn ($v) => (int) $v)->all(),
    ];
})->all();

echo json_encode([
    'probe' => 'p0_dist_campaign_ro',
    'mutated' => false,
    'now' => $now->toIso8601String(),
    'campaign' => [
        'id' => (int) $c->id,
        'uuid' => $c->campaign_uuid,
        'name' => $c->name,
        'status' => $c->status,
        'execution_mode' => $c->execution_mode,
        'plan_version' => $c->plan_version,
        'platform' => $c->platform,
        'installation_id' => $c->installation_id,
        'owner_user_id' => $c->owner_user_id ?? $c->created_by_user_id ?? null,
        'pinned_account_id_meta' => $meta['pinned_account_id'] ?? null,
        'meta_keys' => array_keys($meta),
        'meta' => $meta,
    ],
    'plan_tags' => $planTags,
    'durable_account_assignments' => $assignments,
    'contents' => $contentRows,
    'jobs' => $jobRows,
    'job_account_distribution' => $jobs->groupBy('account_id')->map->count(),
    'job_agent_distribution' => $jobs->groupBy('agent_id')->map->count(),
    'sandbox_accounts' => $sandboxAccounts,
    'agents' => $agentRows,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
