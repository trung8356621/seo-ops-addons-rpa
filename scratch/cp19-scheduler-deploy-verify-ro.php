<?php

declare(strict_types=1);

/** READ-ONLY post-deploy verify. No schedule-tick invoke. No DB writes. */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SocialAutomation\Scheduling\ScheduleTickRegistrar;

$now = now();
$db = DB::connection('omi_social_automation');
$campaignUuid = '13f41a46-aefb-4343-b495-3ef99b5176d6';

$schedule = app(Schedule::class);
$registrar = new ScheduleTickRegistrar;
$matches = $registrar->matchingEvents($schedule);

$campaign = $db->table('sa_campaigns')->where('campaign_uuid', $campaignUuid)->first();
$content = $campaign
    ? $db->table('sa_campaign_contents')->where('campaign_id', $campaign->id)->first()
    : null;
$jobCount = $campaign
    ? (int) $db->table('sa_jobs')->where('campaign_id', $campaign->id)->count()
    : 0;
$runningCount = (int) $db->table('sa_campaigns')->where('status', 'running')->count();

$providerPath = '/www/wwwroot/seo-ops-addons/social-automation/src/SocialAutomationServiceProvider.php';
$registrarPath = '/www/wwwroot/seo-ops-addons/social-automation/src/Scheduling/ScheduleTickRegistrar.php';

echo json_encode([
    'probe' => 'scheduler_deploy_verify_ro',
    'now' => $now->toIso8601String(),
    'mutated' => false,
    'files' => [
        'ScheduleTickRegistrar' => [
            'exists' => is_file($registrarPath),
            'sha256' => is_file($registrarPath) ? hash_file('sha256', $registrarPath) : null,
            'loaded' => (new ReflectionClass(ScheduleTickRegistrar::class))->getFileName(),
        ],
        'SocialAutomationServiceProvider' => [
            'exists' => is_file($providerPath),
            'sha256' => is_file($providerPath) ? hash_file('sha256', $providerPath) : null,
            'has_registerScheduleTick' => is_file($providerPath) && str_contains((string) file_get_contents($providerPath), 'registerScheduleTick'),
        ],
    ],
    'schedule_registration' => [
        'matching_count' => count($matches),
        'expressions' => array_map(static fn ($e) => $e->getExpression(), $matches),
        'commands' => array_map(static fn ($e) => (string) ($e->command ?? ''), $matches),
        'exactly_one' => count($matches) === 1,
    ],
    'campaign' => $campaign ? [
        'uuid' => $campaign->campaign_uuid,
        'status' => $campaign->status,
        'next_recheck_at' => $campaign->next_recheck_at,
        'updated_at' => $campaign->updated_at ?? null,
    ] : null,
    'content' => $content ? [
        'status' => $content->status,
        'last_error_code' => $content->last_error_code,
        'current_job_id' => $content->current_job_id ?? null,
    ] : null,
    'job_count' => $jobCount,
    'running_campaigns' => $runningCount,
    'pass' => [
        'campaign_paused' => $campaign && (string) $campaign->status === 'paused',
        'exactly_one_tick' => count($matches) === 1,
        'no_new_jobs' => $jobCount === 0,
        'no_running_campaigns' => $runningCount === 0,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
