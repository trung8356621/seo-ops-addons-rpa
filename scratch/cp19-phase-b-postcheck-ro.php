<?php

declare(strict_types=1);

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$conn = 'omi_social_automation';
$db = Illuminate\Support\Facades\DB::connection($conn);
$schema = Illuminate\Support\Facades\Schema::connection($conn);

$out = [
    'connection' => $db->getDatabaseName(),
    'tables' => [
        'sa_campaigns' => $schema->hasTable('sa_campaigns'),
        'sa_campaign_contents' => $schema->hasTable('sa_campaign_contents'),
        'sa_campaign_account_assignments' => $schema->hasTable('sa_campaign_account_assignments'),
    ],
    'columns' => [],
    'task_id' => null,
    'migration_rows' => [],
    'campaigns_ready' => false,
    'smoke' => [],
];

foreach ([
    'sa_jobs' => ['campaign_id', 'campaign_content_id', 'lease_token', 'fencing_generation'],
    'sa_job_attempts' => ['lease_token', 'lease_expires_at', 'agent_id', 'browser_session_id', 'account_id', 'progress', 'error_message', 'fencing_generation'],
    'sa_account_execution_leases' => ['lease_token', 'attempt_id', 'fencing_generation'],
] as $table => $cols) {
    foreach ($cols as $col) {
        $out['columns'][$table][$col] = $schema->hasColumn($table, $col);
    }
}

$col = $db->selectOne(
    "SELECT IS_NULLABLE AS n, COLUMN_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sa_jobs' AND COLUMN_NAME = 'task_id'"
);
$out['task_id'] = [
    'nullable' => strtoupper((string) ($col->n ?? '')) === 'YES',
    'type' => $col->t ?? null,
];

$out['migration_rows'] = $db->table('migrations')
    ->where('migration', 'like', '%2026_11_02_150000_create_sa_campaign_tables%')
    ->get();

$out['campaigns_ready'] = \Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema::campaignsReady();

// RO smoke: Staff campaign list service path without creating data
try {
    $count = $db->table('sa_campaigns')->count();
    $out['smoke']['sa_campaigns_count'] = $count;
    $out['smoke']['list_ok'] = true;
} catch (Throwable $e) {
    $out['smoke']['list_ok'] = false;
    $out['smoke']['error'] = $e->getMessage();
}

// Route presence (code)
$provider = (string) @file_get_contents('/www/wwwroot/seo-ops-addons/social-automation/src/SocialAutomationServiceProvider.php');
$out['smoke']['routes_staff_campaigns'] = str_contains($provider, "prefix('api/campaigns')");
$out['smoke']['routes_client_jobs'] = str_contains($provider, "/jobs/claim");

$allCols = true;
foreach ($out['columns'] as $cols) {
    foreach ($cols as $ok) {
        $allCols = $allCols && $ok;
    }
}
$out['go'] = ($out['tables']['sa_campaigns'] ?? false)
    && ($out['tables']['sa_campaign_contents'] ?? false)
    && ($out['tables']['sa_campaign_account_assignments'] ?? false)
    && $allCols
    && ($out['task_id']['nullable'] ?? false)
    && count($out['migration_rows']) > 0
    && ($out['campaigns_ready'] ?? false)
    && ($out['smoke']['list_ok'] ?? false);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($out['go'] ? 0 : 11);
