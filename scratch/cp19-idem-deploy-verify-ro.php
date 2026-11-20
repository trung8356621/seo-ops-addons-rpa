<?php

declare(strict_types=1);

/**
 * READ-ONLY post-deploy verify for campaign idempotency key fix.
 * No Start/Resume/tick/claim.
 */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SocialAutomation\Domain\JobIdempotencyKey;
use Omnichannel\Addons\SocialAutomation\Orchestration\CampaignOrchestrator;

$addon = '/www/wwwroot/seo-ops-addons/social-automation';
$uuid = 'cf0d8be1-5475-4c84-a788-600a6a8ae17f';
$db = DB::connection('omi_social_automation');

$expected = [
    // git cat-file -p 2fd56e4:<path> (LF)
    'src/Domain/JobIdempotencyKey.php' => 'd6054f572ba634e9f4ee7bd0e0fe5d7966e94cca480c20410a90e7a7880569a1',
    'src/Orchestration/CampaignOrchestrator.php' => '88854dd2a6a7b561fc3c320bfbaeea7a138089580b353425ee3abf597a0e9cc7',
];

$files = [];
foreach ($expected as $rel => $want) {
    $path = $addon.'/'.$rel;
    $got = is_file($path) ? hash_file('sha256', $path) : null;
    $blob = is_file($path) ? (string) file_get_contents($path) : '';
    $files[$rel] = [
        'path' => $path,
        'exists' => is_file($path),
        'sha256' => $got,
        'matches_2fd56e4' => $got === $want,
        'has_forCampaignContent' => str_contains($blob, 'function forCampaignContent'),
        'uses_helper' => str_contains($blob, 'JobIdempotencyKey::forCampaignContent'),
        'no_legacy_key' => ! str_contains($blob, "'campaign:'.\$campaign->campaign_uuid.':content:'"),
        'safe_log' => ! str_contains($blob, "'message' => \$e->getMessage()"),
    ];
}

$campaign = $db->table('sa_campaigns')->where('campaign_uuid', $uuid)->first();
$running = (int) $db->table('sa_campaigns')->where('status', 'running')->count();
$jobs = $campaign ? (int) $db->table('sa_jobs')->where('campaign_id', $campaign->id)->count() : -1;

$key = JobIdempotencyKey::forCampaignContent(
    'cf0d8be1-5475-4c84-a788-600a6a8ae17f',
    '7ee8aabb-bf02-46ca-a867-14a839fc0f0e',
    1
);

$orcRef = new ReflectionClass(CampaignOrchestrator::class);
$scheduleList = trim((string) shell_exec(
    'cd /www/wwwroot/seo-ops-client && /www/server/php/83/bin/php artisan schedule:list 2>&1 | grep -c social-automation:schedule-tick || true'
));

// Recent dispatch failure log lines (should be empty after pause; RO grep)
$logHits = trim((string) shell_exec(
    "grep -F 'social_automation.campaign_dispatch_failed' /www/wwwroot/seo-ops-client/storage/logs/laravel.log 2>/dev/null | tail -n 5"
));

$out = [
    'probe' => 'idem_deploy_verify_ro',
    'mutated' => false,
    'now' => now()->toIso8601String(),
    'baseline' => '2fd56e4',
    'files' => $files,
    'autoload' => [
        'JobIdempotencyKey' => class_exists(JobIdempotencyKey::class),
        'CampaignOrchestrator' => class_exists(CampaignOrchestrator::class),
        'JobIdempotencyKey_file' => $orcRef->getFileName() ? (new ReflectionClass(JobIdempotencyKey::class))->getFileName() : null,
        'CampaignOrchestrator_file' => $orcRef->getFileName(),
        'forCampaignContent_len' => strlen($key),
        'forCampaignContent_key' => $key,
    ],
    'campaign' => $campaign ? [
        'status' => $campaign->status,
        'paused_at' => $campaign->paused_at,
        'next_recheck_at' => $campaign->next_recheck_at,
    ] : null,
    'running_count' => $running,
    'campaign_jobs' => $jobs,
    'scheduler_tick_registrations' => (int) $scheduleList,
    'recent_dispatch_failed_logs' => $logHits,
];

$out['pass'] =
    ($files['src/Domain/JobIdempotencyKey.php']['matches_2fd56e4'] ?? false)
    && ($files['src/Orchestration/CampaignOrchestrator.php']['matches_2fd56e4'] ?? false)
    && ($out['autoload']['JobIdempotencyKey'] ?? false)
    && ($out['autoload']['CampaignOrchestrator'] ?? false)
    && ($out['autoload']['forCampaignContent_len'] === 64)
    && ((string) ($out['campaign']['status'] ?? '') === 'paused')
    && $running === 0
    && $jobs === 0
    && ((int) $scheduleList === 1);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($out['pass'] ? 0 : 2);
