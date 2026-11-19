<?php

declare(strict_types=1);

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$conn = 'omi_social_automation';
$db = Illuminate\Support\Facades\DB::connection($conn);
$schema = Illuminate\Support\Facades\Schema::connection($conn);

$gz = '/www/backup/database/omi_social_automation/omi_social_automation_20261119T124132Z.sql.gz';
$sha = $gz.'.sha256';

$out = [
    'app_env' => (string) config('app.env'),
    'connection' => [
        'name' => $conn,
        'database' => $db->getDatabaseName(),
        'driver' => $db->getDriverName(),
        'matches_expected' => $db->getDatabaseName() === 'omi_social_automation',
    ],
    'backup' => [
        'gz_exists' => is_file($gz),
        'sha_exists' => is_file($sha),
        'gz_bytes' => is_file($gz) ? filesize($gz) : 0,
        'gz_mode' => is_file($gz) ? substr(sprintf('%o', fileperms($gz)), -3) : null,
        'expected_sha256' => '53b70b891bfb5af0772a2c389d89045861a9695464498a38f257995d711d47bf',
    ],
];

if (is_file($gz) && is_file($sha)) {
    $line = trim((string) file_get_contents($sha));
    $actual = hash_file('sha256', $gz);
    $out['backup']['sha_file_line'] = preg_replace('/\s+.*/', '', $line);
    $out['backup']['sha_actual'] = $actual;
    $out['backup']['sha_match_file'] = hash_equals($out['backup']['sha_file_line'], $actual);
    $out['backup']['sha_match_expected'] = hash_equals($out['backup']['expected_sha256'], $actual);
    passthru('gzip -t '.escapeshellarg($gz).' 2>/dev/null', $gzTest);
    $out['backup']['gzip_test_exit'] = $gzTest;
}

$out['campaign_tables_before'] = [
    'sa_campaigns' => $schema->hasTable('sa_campaigns'),
    'sa_campaign_contents' => $schema->hasTable('sa_campaign_contents'),
    'sa_campaign_account_assignments' => $schema->hasTable('sa_campaign_account_assignments'),
];

$out['migration_applied'] = false;
try {
    $rows = $db->table('migrations')
        ->where('migration', 'like', '%2026_11_02_150000_create_sa_campaign_tables%')
        ->get();
    $out['migration_rows'] = $rows;
    $out['migration_applied'] = $rows->count() > 0;
} catch (Throwable $e) {
    $out['migration_history_error'] = $e->getMessage();
}

$migFile = '/www/wwwroot/seo-ops-client/addons/social-automation/database/migrations/2026_11_02_150000_create_sa_campaign_tables.php';
$out['migration_file'] = [
    'exists' => is_file($migFile),
    'sha256' => is_file($migFile) ? hash_file('sha256', $migFile) : null,
    'expected' => 'a94dae63c807df03b4813388aade447248a5a3c01c31d7cdc099c9de44d82433',
];
$out['migration_file']['sha_match'] = ($out['migration_file']['sha256'] === $out['migration_file']['expected']);

$out['restore_ready'] = ($out['backup']['gz_exists'] ?? false)
    && ($out['backup']['sha_match_expected'] ?? false)
    && (($out['backup']['gzip_test_exit'] ?? 1) === 0);

$out['precheck_go'] = ($out['connection']['matches_expected'] ?? false)
    && $out['restore_ready']
    && ! ($out['migration_applied'] ?? true)
    && ! ($out['campaign_tables_before']['sa_campaigns'] ?? true)
    && ($out['migration_file']['sha_match'] ?? false);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($out['precheck_go'] ? 0 : 10);
