<?php

declare(strict_types=1);

/** READ-ONLY: sandbox accounts, binds, affinities, schedule-tick evidence. No writes. */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$db = DB::connection('omi_social_automation');
$inst = 'ins_4Zn6M7cHZIQI2UbS';

$accounts = $db->table('sa_accounts')
    ->where('installation_id', $inst)
    ->where('platform', 'qa_sandbox_facebook')
    ->orderBy('id')
    ->get();

$rows = [];
foreach ($accounts as $a) {
    $binds = $db->table('sa_session_accounts as sa')
        ->join('sa_browser_sessions as bs', 'bs.id', '=', 'sa.browser_session_id')
        ->join('sa_agents as ag', 'ag.id', '=', 'bs.agent_id')
        ->where('sa.account_id', $a->id)
        ->select([
            'sa.id as bind_id', 'sa.is_active', 'sa.browser_session_id',
            'bs.session_uuid', 'bs.ready_status', 'bs.dispatch_enabled', 'bs.session_state',
            'ag.id as agent_id', 'ag.agent_uuid', 'ag.display_name', 'ag.client_api_key_id',
            'ag.last_heartbeat_at', 'ag.active_jobs',
        ])
        ->get();
    $affinity = $db->table('sa_account_device_affinities')->where('account_id', $a->id)->first();
    $rows[] = [
        'account_id' => (int) $a->id,
        'account_uuid' => $a->account_uuid,
        'username' => $a->username ?? null,
        'verified' => (bool) $a->verified,
        'platform_account_id' => $a->platform_account_id,
        'status' => $a->status ?? null,
        'binds' => $binds,
        'affinity' => $affinity,
    ];
}

$keys = $db->table('sa_client_api_keys')->whereIn('id', [7, 11])->get();
$agentsByKey = $db->table('sa_agents')->whereIn('client_api_key_id', [7, 11])->get();

// All session binds for agents tied to keys 7/11
$agentIds = $agentsByKey->pluck('id')->all();
$allSessions = $agentIds === [] ? collect() : $db->table('sa_browser_sessions')->whereIn('agent_id', $agentIds)->get();
$sessionDetail = [];
foreach ($allSessions as $s) {
    $binds = $db->table('sa_session_accounts')->where('browser_session_id', $s->id)->get();
    $accs = [];
    foreach ($binds as $b) {
        $acc = $db->table('sa_accounts')->find($b->account_id);
        $accs[] = [
            'bind_active' => (bool) $b->is_active,
            'account_id' => (int) $b->account_id,
            'platform' => $acc->platform ?? null,
            'username' => $acc->username ?? null,
        ];
    }
    $sessionDetail[] = [
        'agent_id' => (int) $s->agent_id,
        'session_id' => (int) $s->id,
        'session_uuid' => $s->session_uuid,
        'ready_status' => $s->ready_status,
        'account_id_column' => $s->account_id,
        'binds' => $accs,
    ];
}

$cronTail = [];
exec('tail -n 80 /www/wwwroot/seo-ops-client/storage/logs/cron-schedule.log 2>/dev/null | grep -F social-automation:schedule-tick || true', $cronTail);

$scheduleList = [];
exec('cd /www/wwwroot/seo-ops-client && /usr/bin/php artisan schedule:list 2>/dev/null | grep -F social-automation || true', $scheduleList);

echo json_encode([
    'probe' => 'p0_multi_client_accounts_ro',
    'mutated' => false,
    'sandbox_accounts' => $rows,
    'keys_7_11' => $keys,
    'agents_for_keys_7_11' => $agentsByKey,
    'sessions_for_keys_7_11' => $sessionDetail,
    'schedule_list' => $scheduleList,
    'cron_tail_filtered' => $cronTail,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
