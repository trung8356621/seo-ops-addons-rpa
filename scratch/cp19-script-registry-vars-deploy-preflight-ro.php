<?php

declare(strict_types=1);

/** READ-ONLY preflight for Script Registry variables projection deploy. */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;

$db = DB::connection('omi_social_automation');
$uuid = '13f41a46-aefb-4343-b495-3ef99b5176d6';
$c = $db->table('sa_campaigns')->where('campaign_uuid', $uuid)->first();
$backupGz = '/www/backup/database/omi_social_automation/omi_social_automation_20261120T025556Z.sql.gz';
$backupSha = $backupGz.'.sha256';

$out = [
    'probe' => 'script_registry_vars_deploy_preflight_ro',
    'mutated' => false,
    'test_wp' => $c ? [
        'status' => $c->status,
        'paused' => (string) $c->status === 'paused',
    ] : null,
    'running_campaigns' => (int) $db->table('sa_campaigns')->where('status', 'running')->count(),
    'campaign_jobs' => (int) $db->table('sa_jobs')->whereNotNull('campaign_id')->count(),
    'all_jobs' => (int) $db->table('sa_jobs')->count(),
    'campaign_shortcode_ready' => SocialAutomationSchema::campaignShortcodeReady(),
    'backup' => [
        'gz_exists' => is_file($backupGz),
        'sha_file_exists' => is_file($backupSha),
        'sha_check' => null,
    ],
];

if (is_file($backupSha) && is_file($backupGz)) {
    $line = trim((string) file_get_contents($backupSha));
    $actual = hash_file('sha256', $backupGz);
    $expected = preg_match('/^([a-f0-9]{64})/', $line, $m) ? $m[1] : null;
    $out['backup']['expected'] = $expected;
    $out['backup']['actual'] = $actual;
    $out['backup']['sha_check'] = $expected !== null && hash_equals($expected, $actual);
}

$out['pass'] = ($out['test_wp']['paused'] ?? false) === true
    && $out['running_campaigns'] === 0
    && $out['campaign_jobs'] === 0
    && $out['campaign_shortcode_ready'] === true
    && ($out['backup']['sha_check'] ?? false) === true;

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($out['pass'] ? 0 : 1);
