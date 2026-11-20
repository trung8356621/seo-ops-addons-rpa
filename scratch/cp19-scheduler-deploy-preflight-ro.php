<?php

declare(strict_types=1);

/**
 * READ-ONLY scheduler deploy preflight. No writes. No schedule-tick invoke.
 */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$conn = 'omi_social_automation';
$db = DB::connection($conn);
$now = now();
$campaignUuid = '13f41a46-aefb-4343-b495-3ef99b5176d6';

$out = [
    'probe' => 'scheduler_deploy_preflight_ro',
    'now' => $now->toIso8601String(),
    'mutated' => false,
];

$campaign = $db->table('sa_campaigns')->where('campaign_uuid', $campaignUuid)->first();
$content = $campaign
    ? $db->table('sa_campaign_contents')->where('campaign_id', $campaign->id)->orderBy('id')->first()
    : null;

$running = $db->table('sa_campaigns')->where('status', 'running')->orderBy('id')->get([
    'id', 'campaign_uuid', 'name', 'status', 'platform', 'next_recheck_at', 'updated_at',
]);

$runningRows = [];
foreach ($running as $c) {
    $waiting = (int) $db->table('sa_campaign_contents')
        ->where('campaign_id', $c->id)
        ->whereIn('status', ['pending', 'waiting_resources'])
        ->count();
    $due = $c->next_recheck_at === null || strtotime((string) $c->next_recheck_at) <= $now->getTimestamp();
    $runningRows[] = [
        'id' => (int) $c->id,
        'uuid' => $c->campaign_uuid,
        'name' => $c->name,
        'status' => $c->status,
        'platform' => $c->platform,
        'next_recheck_at' => $c->next_recheck_at,
        'next_recheck_due' => $due,
        'waiting_or_pending' => $waiting,
        'dispatch_risk_if_tick_live' => ($waiting > 0 && $due) ? 'high' : (($waiting > 0) ? 'medium' : 'low'),
    ];
}

$jobCount = $campaign
    ? (int) $db->table('sa_jobs')->where('campaign_id', $campaign->id)->count()
    : 0;

$out['poc_campaign'] = $campaign ? [
    'uuid' => $campaign->campaign_uuid,
    'name' => $campaign->name,
    'status' => $campaign->status,
    'next_recheck_at' => $campaign->next_recheck_at,
    'next_recheck_due' => $campaign->next_recheck_at === null
        || strtotime((string) $campaign->next_recheck_at) <= $now->getTimestamp(),
    'updated_at' => $campaign->updated_at ?? null,
] : null;

$out['poc_content'] = $content ? [
    'status' => $content->status,
    'last_error_code' => $content->last_error_code,
    'current_job_id' => $content->current_job_id ?? null,
    'updated_at' => $content->updated_at ?? null,
] : null;

$out['poc_job_count'] = $jobCount;
$out['running_campaigns'] = $runningRows;
$out['running_count'] = count($runningRows);

$gate = [
    'poc_is_running' => $campaign && (string) $campaign->status === 'running',
    'poc_is_paused' => $campaign && (string) $campaign->status === 'paused',
    'deploy_allowed_by_campaign_gate' => $campaign && (string) $campaign->status === 'paused',
];
$out['deployment_gate'] = $gate;
$out['action_required'] = $gate['poc_is_running']
    ? 'STOP — operator must Pause Campaign test wp via RPA Web before deploy'
    : ($gate['poc_is_paused']
        ? 'Campaign paused — await explicit deploy approval'
        : 'Unexpected campaign status — review before deploy');

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
