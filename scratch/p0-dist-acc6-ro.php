<?php

declare(strict_types=1);

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$db = DB::connection('omi_social_automation');

$sandbox = $db->table('sa_accounts')
    ->where('installation_id', 'ins_4Zn6M7cHZIQI2UbS')
    ->where('platform', 'qa_sandbox_facebook')
    ->orderBy('id')
    ->get();

$out = ['mutated' => false, 'accounts' => []];
foreach ($sandbox as $acc) {
    $binds = $db->table('sa_session_accounts as sa')
        ->join('sa_browser_sessions as bs', 'bs.id', '=', 'sa.browser_session_id')
        ->join('sa_agents as ag', 'ag.id', '=', 'bs.agent_id')
        ->where('sa.account_id', $acc->id)
        ->get([
            'sa.id as bind_id', 'sa.is_active', 'sa.created_at as bind_created_at', 'sa.updated_at as bind_updated_at',
            'bs.id as session_id', 'bs.session_uuid', 'bs.ready_status', 'bs.session_state', 'bs.dispatch_enabled',
            'ag.id as agent_id', 'ag.client_api_key_id', 'ag.last_heartbeat_at',
        ]);
    // also any historical bind rows even inactive
    $allBinds = $db->table('sa_session_accounts')->where('account_id', $acc->id)->get();
    $aff = $db->table('sa_account_device_affinities')->where('account_id', $acc->id)->first();
    $out['accounts'][] = [
        'account' => $acc,
        'active_binds_joined' => $binds,
        'all_bind_rows' => $allBinds,
        'affinity' => $aff,
    ];
}

// Jobs timing: were any concurrent?
$jobs = $db->table('sa_jobs')->where('campaign_id', 18)->orderBy('id')->get(['id', 'agent_id', 'account_id', 'reserved_at', 'claimed_at', 'finished_at']);
$out['jobs_timing'] = $jobs;
$out['overlap_pairs'] = [];
$arr = $jobs->all();
for ($i = 0; $i < count($arr); $i++) {
    for ($j = $i + 1; $j < count($arr); $j++) {
        $a = $arr[$i];
        $b = $arr[$j];
        $aStart = strtotime((string) ($a->claimed_at ?? $a->reserved_at));
        $aEnd = strtotime((string) $a->finished_at);
        $bStart = strtotime((string) ($b->claimed_at ?? $b->reserved_at));
        $bEnd = strtotime((string) $b->finished_at);
        if ($aStart && $aEnd && $bStart && $bEnd && $aStart < $bEnd && $bStart < $aEnd) {
            $out['overlap_pairs'][] = [$a->id, $b->id];
        }
    }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
