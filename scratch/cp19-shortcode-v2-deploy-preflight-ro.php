<?php

declare(strict_types=1);

/**
 * READ-ONLY Campaign Shortcode V2 deploy preflight.
 * No DDL/DML, no Start/Resume, no Job claim, no schedule-tick invoke.
 */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$conn = 'omi_social_automation';
$db = DB::connection($conn);
$schema = Schema::connection($conn);
$now = now();
$addonRoot = '/www/wwwroot/seo-ops-addons/social-automation';
$migrationRel = 'database/migrations/2026_11_20_100000_add_campaign_shortcode_v2_columns.php';
$migrationPath = $addonRoot.'/'.$migrationRel;

$out = [
    'probe' => 'shortcode_v2_deploy_preflight_ro',
    'now' => $now->toIso8601String(),
    'app_env' => (string) config('app.env'),
    'mutated' => false,
    'connection' => [
        'name' => $conn,
        'database' => $db->getDatabaseName(),
        'driver' => $db->getDriverName(),
        'matches_expected' => $db->getDatabaseName() === 'omi_social_automation',
    ],
];

// --- Campaign inventory ---
$byStatus = $db->table('sa_campaigns')
    ->selectRaw('status, COUNT(*) as c')
    ->groupBy('status')
    ->pluck('c', 'status')
    ->all();

$running = $db->table('sa_campaigns')->where('status', 'running')->orderBy('id')->get([
    'id', 'campaign_uuid', 'name', 'status', 'platform', 'next_recheck_at', 'updated_at', 'started_at',
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
        'platform' => $c->platform,
        'next_recheck_at' => $c->next_recheck_at,
        'next_recheck_due' => $due,
        'waiting_or_pending' => $waiting,
        'dispatch_risk_if_tick' => ($waiting > 0 && $due) ? 'high' : (($waiting > 0) ? 'medium' : 'low'),
    ];
}

// "test wp" by name (case-insensitive) + known POC uuid
$testWp = $db->table('sa_campaigns')
    ->where(function ($q): void {
        $q->whereRaw('LOWER(name) LIKE ?', ['%test wp%'])
            ->orWhereRaw('LOWER(name) LIKE ?', ['%testwp%'])
            ->orWhere('campaign_uuid', '13f41a46-aefb-4343-b495-3ef99b5176d6');
    })
    ->orderBy('id')
    ->get([
        'id', 'campaign_uuid', 'name', 'status', 'platform', 'paused_at', 'next_recheck_at', 'updated_at',
        'script_id', 'script_version',
    ]);

$testWpRows = [];
foreach ($testWp as $c) {
    $contentCounts = $db->table('sa_campaign_contents')
        ->selectRaw('status, COUNT(*) as c')
        ->where('campaign_id', $c->id)
        ->groupBy('status')
        ->pluck('c', 'status')
        ->all();
    $jobCount = (int) $db->table('sa_jobs')->where('campaign_id', $c->id)->count();
    $testWpRows[] = [
        'id' => (int) $c->id,
        'uuid' => $c->campaign_uuid,
        'name' => $c->name,
        'status' => $c->status,
        'paused_at' => $c->paused_at,
        'next_recheck_at' => $c->next_recheck_at,
        'script_id' => $c->script_id,
        'script_version' => $c->script_version,
        'content_counts' => $contentCounts,
        'job_count' => $jobCount,
        'is_paused' => (string) $c->status === 'paused',
    ];
}

$contentTotals = $db->table('sa_campaign_contents')
    ->selectRaw('status, COUNT(*) as c')
    ->groupBy('status')
    ->pluck('c', 'status')
    ->all();
$jobTotals = [
    'campaign_jobs' => (int) $db->table('sa_jobs')->whereNotNull('campaign_id')->count(),
    'campaign_jobs_by_status' => $db->table('sa_jobs')
        ->selectRaw('status, COUNT(*) as c')
        ->whereNotNull('campaign_id')
        ->groupBy('status')
        ->pluck('c', 'status')
        ->all(),
    'all_jobs' => (int) $db->table('sa_jobs')->count(),
];

$out['campaigns'] = [
    'by_status' => $byStatus,
    'running_count' => count($runningRows),
    'running' => $runningRows,
    'test_wp_matches' => $testWpRows,
    'test_wp_all_paused' => $testWpRows !== [] && collect($testWpRows)->every(static fn ($r) => $r['is_paused'] === true),
    'content_totals_by_status' => $contentTotals,
    'content_total' => array_sum(array_map('intval', $contentTotals)),
    'jobs' => $jobTotals,
];

// --- Schema conflict check for Shortcode V2 columns ---
$desired = [
    'sa_campaigns' => ['shortcode_mapping', 'shortcode_mapping_version'],
    'sa_campaign_contents' => ['source_format', 'raw_source', 'mapping_version'],
];
$out['schema'] = [
    'tables' => [
        'sa_campaigns' => $schema->hasTable('sa_campaigns'),
        'sa_campaign_contents' => $schema->hasTable('sa_campaign_contents'),
    ],
    'columns' => [],
    'conflicts' => [],
];
foreach ($desired as $table => $cols) {
    if (! $schema->hasTable($table)) {
        $out['schema']['conflicts'][] = "table_missing:{$table}";
        continue;
    }
    foreach ($cols as $col) {
        $exists = $schema->hasColumn($table, $col);
        $out['schema']['columns'][$table][$col] = $exists;
        if ($exists) {
            try {
                $info = $db->selectOne(
                    'SELECT DATA_TYPE AS t, IS_NULLABLE AS n, COLUMN_TYPE AS ct, COLUMN_DEFAULT AS d
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$table, $col]
                );
                $out['schema']['columns'][$table][$col.'_meta'] = [
                    'data_type' => $info->t ?? null,
                    'nullable' => $info->n ?? null,
                    'column_type' => $info->ct ?? null,
                    'default' => $info->d ?? null,
                ];
                // Already present = migration may be partially applied / conflict if types differ
                $out['schema']['conflicts'][] = "column_already_exists:{$table}.{$col}";
            } catch (Throwable $e) {
                $out['schema']['columns'][$table][$col.'_meta_error'] = $e->getMessage();
            }
        }
    }
}

// Laravel migrations table for this connection (if present)
$out['migrations_table'] = [
    'exists' => $schema->hasTable('migrations'),
];
if ($schema->hasTable('migrations')) {
    $hits = $db->table('migrations')
        ->where('migration', 'like', '%campaign_shortcode%')
        ->orWhere('migration', 'like', '%2026_11_20_100000%')
        ->orWhere('migration', 'like', '%create_sa_campaign%')
        ->orderBy('id')
        ->get(['id', 'migration', 'batch'])
        ->all();
    $out['migrations_table']['campaign_related'] = $hits;
}

// --- Addon filesystem (RO) ---
$keyFiles = [
    $migrationRel,
    'src/Domain/CampaignShortcodePipeline.php',
    'src/Services/CampaignService.php',
    'src/Orchestration/CampaignOrchestrator.php',
    'src/Support/SocialAutomationSchema.php',
    'src/Http/Controllers/StaffCampaignContentController.php',
];
$fs = [];
foreach ($keyFiles as $rel) {
    $path = $addonRoot.'/'.$rel;
    $fs[$rel] = [
        'exists' => is_file($path),
        'bytes' => is_file($path) ? filesize($path) : null,
        'sha256' => is_file($path) ? hash_file('sha256', $path) : null,
        'mtime' => is_file($path) ? gmdate('c', filemtime($path)) : null,
    ];
}
$out['addon_fs'] = [
    'root' => $addonRoot,
    'root_exists' => is_dir($addonRoot),
    'files' => $fs,
    'migration_on_vps' => $fs[$migrationRel]['exists'] ?? false,
    'pipeline_on_vps' => $fs['src/Domain/CampaignShortcodePipeline.php']['exists'] ?? false,
    'schema_gate_mentions_shortcode' => is_file($addonRoot.'/src/Support/SocialAutomationSchema.php')
        && str_contains((string) file_get_contents($addonRoot.'/src/Support/SocialAutomationSchema.php'), 'campaignShortcodeReady'),
];

// Migration file local content checks if present
if (is_file($migrationPath)) {
    $src = (string) file_get_contents($migrationPath);
    $out['migration_file_checks'] = [
        'connection_omi' => str_contains($src, "omi_social_automation"),
        'has_shortcode_mapping' => str_contains($src, 'shortcode_mapping'),
        'has_source_format' => str_contains($src, 'source_format'),
        'additive_only' => ! str_contains($src, "dropIfExists('sa_campaigns')")
            && ! str_contains($src, 'dropIfExists("sa_campaigns")'),
        'uses_hasColumn_guards' => str_contains($src, 'hasColumn'),
    ];
}

// --- Scheduler evidence (RO; do not invoke tick) ---
$schedule = [
    'kernel_mentions_campaign' => false,
    'crontab_schedule_run_lines' => [],
];
$kernelCandidates = [
    '/www/wwwroot/seo-ops-client/app/Console/Kernel.php',
    '/www/wwwroot/seo-ops-client/routes/console.php',
];
foreach ($kernelCandidates as $kp) {
    if (! is_file($kp)) {
        continue;
    }
    $txt = (string) file_get_contents($kp);
    if (str_contains($txt, 'campaign') || str_contains($txt, 'social-automation:schedule-tick') || str_contains($txt, 'ScheduleTick')) {
        $schedule['kernel_mentions_campaign'] = true;
        $schedule['kernel_hit'] = $kp;
    }
}
$cron = trim((string) shell_exec('crontab -l 2>/dev/null || true'));
foreach (preg_split('/\r?\n/', $cron) ?: [] as $line) {
    if ($line === '' || str_starts_with(trim($line), '#')) {
        continue;
    }
    if (str_contains($line, 'schedule:run') || str_contains($line, 'artisan')) {
        $schedule['crontab_schedule_run_lines'][] = $line;
    }
}
$schedule['has_every_minute_schedule_run'] = false;
foreach ($schedule['crontab_schedule_run_lines'] as $line) {
    if (preg_match('/^\*\s+\*\s+\*\s+\*\s+\*/', trim($line)) === 1 && str_contains($line, 'schedule:run')) {
        $schedule['has_every_minute_schedule_run'] = true;
    }
}
$out['scheduler'] = $schedule;

// Gate summary
$out['gates'] = [
    'no_running_campaigns' => count($runningRows) === 0,
    'test_wp_paused' => ($out['campaigns']['test_wp_all_paused'] ?? false) === true
        || ($testWpRows === [] ? null : false),
    'shortcode_columns_absent' => ($out['schema']['conflicts'] ?? []) === []
        || ! array_filter($out['schema']['conflicts'] ?? [], static fn ($c) => str_starts_with((string) $c, 'column_already_exists:')),
    'migration_not_yet_on_vps_addon' => ! ($out['addon_fs']['migration_on_vps'] ?? false),
    'pipeline_not_yet_on_vps' => ! ($out['addon_fs']['pipeline_on_vps'] ?? false),
    'db_name_ok' => $out['connection']['matches_expected'] === true,
];

$out['preflight_pass'] = ($out['gates']['no_running_campaigns'] === true)
    && ($out['gates']['db_name_ok'] === true)
    && ($out['gates']['test_wp_paused'] === true || $out['gates']['test_wp_paused'] === null);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
