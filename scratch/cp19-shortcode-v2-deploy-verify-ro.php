<?php

declare(strict_types=1);

/**
 * READ-ONLY post-deploy verification for Campaign Shortcode V2.
 * No POST/PUT/Start/Resume/Claim.
 */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;

$conn = 'omi_social_automation';
$db = DB::connection($conn);
$schema = Schema::connection($conn);
$addon = '/www/wwwroot/seo-ops-addons/social-automation';
$uuid = '13f41a46-aefb-4343-b495-3ef99b5176d6';

$out = [
    'probe' => 'shortcode_v2_deploy_verify_ro',
    'now' => now()->toIso8601String(),
    'mutated' => false,
];

$cols = [
    'sa_campaigns' => ['shortcode_mapping', 'shortcode_mapping_version'],
    'sa_campaign_contents' => ['source_format', 'raw_source', 'mapping_version'],
];
$out['columns'] = [];
$allPresent = true;
foreach ($cols as $table => $list) {
    foreach ($list as $col) {
        $ok = $schema->hasColumn($table, $col);
        $out['columns']["{$table}.{$col}"] = $ok;
        $allPresent = $allPresent && $ok;
    }
}
$out['all_five_columns_present'] = $allPresent;

$migRows = $db->table('migrations')
    ->where('migration', 'like', '%2026_11_20_100000_add_campaign_shortcode_v2_columns%')
    ->get(['id', 'migration', 'batch'])
    ->all();
$out['migration_rows'] = $migRows;
$out['migration_recorded_exactly_once'] = count($migRows) === 1;

$out['campaign_shortcode_ready'] = SocialAutomationSchema::campaignShortcodeReady();
$out['campaigns_ready'] = SocialAutomationSchema::campaignsReady();

// Autoload / class availability
$classes = [
    \Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodePipeline::class,
    \Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodeMockGenerator::class,
    \Omnichannel\Addons\SocialAutomation\Services\CampaignService::class,
];
$out['autoload'] = [];
foreach ($classes as $class) {
    $out['autoload'][$class] = class_exists($class);
}

// Routes
$want = [
    'social-automation.campaigns.store',
    'social-automation.campaigns.update',
    'social-automation.campaigns.contents.index',
    'social-automation.campaigns.contents.store',
    'social-automation.campaigns.contents.update',
    'social-automation.campaigns.contents.mock-preview',
    'social-automation.campaigns.contents.mock-save',
];
$named = [];
foreach (Route::getRoutes() as $r) {
    $n = $r->getName();
    if (is_string($n) && $n !== '') {
        $named[$n] = $r->uri();
    }
}
$out['routes'] = [];
foreach ($want as $name) {
    $out['routes'][$name] = $named[$name] ?? null;
}
$out['routes_ok'] = ! in_array(null, $out['routes'], true);

// OpenAPI markers
$openapi = (string) @file_get_contents($addon.'/openapi/openapi.yaml');
$out['openapi'] = [
    'exists' => $openapi !== '',
    'CampaignShortcodeMapping' => str_contains($openapi, 'CampaignShortcodeMapping'),
    'staffCampaignContentsMockPreview' => str_contains($openapi, 'staffCampaignContentsMockPreview'),
    'staffCampaignContentsMockSave' => str_contains($openapi, 'staffCampaignContentsMockSave'),
    'empty_campaign_shortcode_v2' => str_contains($openapi, 'empty_campaign_shortcode_v2'),
    'raw_source' => str_contains($openapi, 'raw_source'),
];
$out['openapi_ok'] = ($out['openapi']['CampaignShortcodeMapping'] ?? false)
    && ($out['openapi']['staffCampaignContentsMockPreview'] ?? false)
    && ($out['openapi']['staffCampaignContentsMockSave'] ?? false);

// Campaign / content preservation
$c = $db->table('sa_campaigns')->where('campaign_uuid', $uuid)->first();
$contents = $c
    ? $db->table('sa_campaign_contents')->where('campaign_id', $c->id)->orderBy('id')->get()
    : collect();
$contentRows = [];
foreach ($contents as $row) {
    $input = json_decode((string) ($row->input ?? 'null'), true);
    $contentRows[] = [
        'id' => (int) $row->id,
        'uuid' => $row->content_uuid,
        'status' => $row->status,
        'source_format' => $row->source_format ?? null,
        'mapping_version' => $row->mapping_version ?? null,
        'raw_source_null' => ($row->raw_source ?? null) === null,
        'has_legacy_input' => is_array($input) && $input !== [],
        'input_keys' => is_array($input) ? array_keys($input) : [],
    ];
}
$out['test_wp'] = $c ? [
    'status' => $c->status,
    'is_paused' => (string) $c->status === 'paused',
    'shortcode_mapping_null' => ($c->shortcode_mapping ?? null) === null,
    'shortcode_mapping_version' => (int) ($c->shortcode_mapping_version ?? -1),
] : null;
$out['contents'] = [
    'count' => count($contentRows),
    'rows' => $contentRows,
    'all_four_preserved' => count($contentRows) === 4,
    'all_legacy_format' => collect($contentRows)->every(static fn ($r) => ($r['source_format'] ?? null) === 'legacy_input'),
    'all_have_input' => collect($contentRows)->every(static fn ($r) => $r['has_legacy_input'] === true),
];
$out['jobs'] = [
    'campaign_jobs' => (int) $db->table('sa_jobs')->whereNotNull('campaign_id')->count(),
    'all_jobs' => (int) $db->table('sa_jobs')->count(),
];
$out['running_campaigns'] = (int) $db->table('sa_campaigns')->where('status', 'running')->count();

// Scheduler: ScheduleTickRegistrar still present once
$provider = (string) @file_get_contents($addon.'/src/SocialAutomationServiceProvider.php');
$out['scheduler'] = [
    'registerScheduleTick_count' => substr_count($provider, 'function registerScheduleTick'),
    'ScheduleTickRegistrar_count' => substr_count($provider, 'ScheduleTickRegistrar'),
    'crontab_every_minute' => false,
];
$cron = trim((string) shell_exec('crontab -l 2>/dev/null || true'));
$scheduleLines = [];
foreach (preg_split('/\r?\n/', $cron) ?: [] as $line) {
    if (str_contains($line, 'schedule:run') && ! str_starts_with(trim($line), '#')) {
        $scheduleLines[] = $line;
        if (preg_match('/^\*\s+\*\s+\*\s+\*\s+\*/', trim($line)) === 1) {
            $out['scheduler']['crontab_every_minute'] = true;
        }
    }
}
$out['scheduler']['schedule_run_lines'] = $scheduleLines;
$out['scheduler']['schedule_run_line_count'] = count($scheduleLines);

// Recent laravel log errors (tail RO)
$logPath = '/www/wwwroot/seo-ops-client/storage/logs/laravel.log';
$out['logs'] = [
    'laravel_log_exists' => is_file($logPath),
    'recent_error_lines' => [],
];
if (is_file($logPath)) {
    $tail = trim((string) shell_exec('tail -n 200 '.escapeshellarg($logPath).' 2>/dev/null || true'));
    $hits = [];
    foreach (preg_split('/\r?\n/', $tail) ?: [] as $line) {
        if (stripos($line, 'shortcode') !== false
            || stripos($line, 'CampaignService') !== false
            || stripos($line, 'ERROR') !== false
            || stripos($line, 'Exception') !== false) {
            $hits[] = substr($line, 0, 240);
        }
    }
    $out['logs']['recent_error_lines'] = array_slice($hits, -15);
}

$out['verify_pass'] = ($out['all_five_columns_present'] === true)
    && ($out['migration_recorded_exactly_once'] === true)
    && ($out['campaign_shortcode_ready'] === true)
    && ($out['routes_ok'] === true)
    && ($out['openapi_ok'] === true)
    && (($out['test_wp']['is_paused'] ?? false) === true)
    && (($out['contents']['all_four_preserved'] ?? false) === true)
    && (($out['contents']['all_legacy_format'] ?? false) === true)
    && (($out['jobs']['campaign_jobs'] ?? -1) === 0)
    && (($out['running_campaigns'] ?? -1) === 0)
    && (($out['scheduler']['crontab_every_minute'] ?? false) === true)
    && (($out['scheduler']['registerScheduleTick_count'] ?? 0) === 1);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($out['verify_pass'] ? 0 : 1);
