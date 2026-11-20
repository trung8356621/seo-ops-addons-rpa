<?php

declare(strict_types=1);

/**
 * READ-ONLY safety gate before idempotency-key fix deploy.
 * No writes, no Start/Resume, no tick, no claim.
 */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$db = DB::connection('omi_social_automation');
$uuid = 'cf0d8be1-5475-4c84-a788-600a6a8ae17f';

$campaign = $db->table('sa_campaigns')->where('campaign_uuid', $uuid)->first();
$runningCount = (int) $db->table('sa_campaigns')->where('status', 'running')->count();
$campaignJobs = $campaign
    ? (int) $db->table('sa_jobs')->where('campaign_id', $campaign->id)->count()
    : -1;
$allCampaignJobs = (int) $db->table('sa_jobs')->whereNotNull('campaign_id')->count();

$backupDir = '/www/backup/database/omi_social_automation';
$backups = [];
if (is_dir($backupDir)) {
    $files = glob($backupDir.'/omi_social_automation_*.sql.gz') ?: [];
    rsort($files);
    foreach (array_slice($files, 0, 3) as $f) {
        $backups[] = [
            'path' => $f,
            'bytes' => filesize($f),
            'mtime' => date('c', (int) filemtime($f)),
            'sha256_sidecar' => is_file($f.'.sha256'),
        ];
    }
}

$gate = [
    'campaign_paused' => $campaign && (string) $campaign->status === 'paused',
    'running_count_zero' => $runningCount === 0,
    'campaign_jobs_zero' => $campaignJobs === 0,
    'backup_available' => $backups !== [],
];

$out = [
    'probe' => 'idem_deploy_preflight_ro',
    'mutated' => false,
    'now' => now()->toIso8601String(),
    'campaign' => $campaign ? [
        'id' => (int) $campaign->id,
        'campaign_uuid' => $campaign->campaign_uuid,
        'name' => $campaign->name,
        'status' => $campaign->status,
        'next_recheck_at' => $campaign->next_recheck_at,
        'paused_at' => $campaign->paused_at,
    ] : null,
    'running_count' => $runningCount,
    'campaign_jobs' => $campaignJobs,
    'all_campaign_jobs' => $allCampaignJobs,
    'backups_recent' => $backups,
    'gate' => $gate,
    'preflight_pass' => ! in_array(false, $gate, true),
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($out['preflight_pass'] ? 0 : 2);
