<?php

declare(strict_types=1);

/**
 * READ-ONLY: infer why dispatchCampaign would fail without mutating.
 * Inspects schema columns vs reserveCampaignJob writes; does NOT call dispatch.
 */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignAttemptStatus;
use Omnichannel\Addons\SocialAutomation\Enums\JobStatus;

$out = [
    'probe' => 'dispatch_exception_infer_ro',
    'mutated' => false,
];

$tables = [
    'sa_jobs' => [
        'job_uuid', 'task_id', 'campaign_id', 'campaign_content_id', 'agent_id',
        'browser_session_id', 'account_id', 'package_key', 'package_version',
        'content_digest', 'status', 'idempotency_key', 'lease_token',
        'fencing_generation', 'payload', 'reserved_at', 'lease_expires_at',
        'dispatch_source', 'claimed_at', 'started_at', 'finished_at',
    ],
    'sa_job_attempts' => [
        'attempt_uuid', 'lease_token', 'job_id', 'attempt_no', 'fencing_generation',
        'status', 'agent_id', 'browser_session_id', 'account_id', 'lease_expires_at',
    ],
    'sa_account_execution_leases' => [
        'account_id', 'agent_id', 'browser_session_id', 'job_id', 'lease_token',
        'fencing_generation', 'status', 'acquired_at', 'heartbeat_at', 'expires_at',
    ],
    'sa_account_device_affinities' => [
        'account_id', 'agent_id', 'last_used_at', 'affinity_until',
    ],
    'sa_job_events' => [
        'job_id', 'event_type', 'from_status', 'to_status', 'payload', 'occurred_at',
    ],
    'sa_campaign_account_assignments' => [
        'campaign_id', 'account_id', 'content_id', 'reason',
    ],
];

$missing = [];
foreach ($tables as $table => $cols) {
    $exists = Schema::connection('omi_social_automation')->hasTable($table);
    $miss = [];
    if ($exists) {
        foreach ($cols as $c) {
            if (! Schema::connection('omi_social_automation')->hasColumn($table, $c)) {
                $miss[] = $c;
            }
        }
    }
    $missing[$table] = [
        'exists' => $exists,
        'missing_columns' => $miss,
    ];
}
$out['schema_vs_reserve_writes'] = $missing;

$out['enums'] = [
    'job_reserved' => JobStatus::Reserved->value,
    'attempt_queued' => CampaignAttemptStatus::Queued->value,
];

// Compare local orchestrator catch: does VPS swallow Throwable?
$orc = (string) file_get_contents('/www/wwwroot/seo-ops-addons/social-automation/src/Orchestration/CampaignOrchestrator.php');
$out['orchestrator_markers'] = [
    'has_catch_throwable' => str_contains($orc, 'catch (Throwable)'),
    'catch_returns_dispatch_failed' => str_contains($orc, "'dispatch_failed'"),
    'logs_exception' => str_contains($orc, 'Log::') && str_contains($orc, 'dispatch'),
    'sha256' => hash('sha256', $orc),
];

// start() ignores dispatch result?
$svc = (string) file_get_contents('/www/wwwroot/seo-ops-addons/social-automation/src/Services/CampaignService.php');
$startPos = strpos($svc, 'function start(');
$startSlice = $startPos !== false ? substr($svc, $startPos, 1200) : '';
$out['start_markers'] = [
    'calls_dispatch' => str_contains($startSlice, 'dispatchCampaign'),
    'checks_dispatch_ok' => str_contains($startSlice, "['ok']") || str_contains($startSlice, 'dispatched'),
    'ignores_dispatch_return' => preg_match('/\$this->orchestrator->dispatchCampaign\(\$campaign\);\s*\n\s*return/', $startSlice) === 1,
];

// Cron schedule log evidence
$cronLog = '/www/wwwroot/seo-ops-client/storage/logs/cron-schedule.log';
$out['cron_schedule_log'] = [
    'exists' => is_file($cronLog),
    'bytes' => is_file($cronLog) ? filesize($cronLog) : null,
    'mtime' => is_file($cronLog) ? date('c', (int) filemtime($cronLog)) : null,
    'tail' => is_file($cronLog) ? trim((string) shell_exec('tail -n 40 '.escapeshellarg($cronLog))) : null,
];

// Search for SQLSTATE / Throwable near campaign start window in laravel.log using ripgrep-like
$out['laravel_error_window'] = trim((string) shell_exec(
    "awk '/2026-11-20 04:0[2-9]/,/2026-11-20 04:10:/' /www/wwwroot/seo-ops-client/storage/logs/laravel.log"
    ." | grep -E 'ERROR|CRITICAL|SQLSTATE|dispatch_failed|CampaignOrchestrator|Undefined|Exception' | head -n 60"
));

// schedule:list for social-automation
$out['schedule_list_social'] = trim((string) shell_exec(
    'cd /www/wwwroot/seo-ops-client && /www/server/php/83/bin/php artisan schedule:list 2>&1 | grep -i social-automation || true'
));

// Confirm command registered
$out['command_registered'] = trim((string) shell_exec(
    'cd /www/wwwroot/seo-ops-client && /www/server/php/83/bin/php artisan list --raw 2>&1 | grep social-automation:schedule-tick || true'
));

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
