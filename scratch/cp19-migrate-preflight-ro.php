<?php

declare(strict_types=1);

/**
 * READ-ONLY Campaign migration preflight. No DDL/DML mutations.
 */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$connName = 'omi_social_automation';
$db = Illuminate\Support\Facades\DB::connection($connName);
$schema = Illuminate\Support\Facades\Schema::connection($connName);

$out = [
    'timestamp' => gmdate('c'),
    'app_env' => (string) config('app.env'),
    'connection' => [
        'name' => $connName,
        'driver' => $db->getDriverName(),
        'database' => $db->getDatabaseName(),
        'configured' => (array) config("database.connections.{$connName}"),
    ],
];

// Never print passwords
unset(
    $out['connection']['configured']['password'],
    $out['connection']['configured']['username']
);
$out['connection']['configured']['username_set'] = (string) config("database.connections.{$connName}.username") !== '';
$out['connection']['configured']['password_set'] = (string) config("database.connections.{$connName}.password") !== '';

$migrationFile = '/www/wwwroot/seo-ops-addons/social-automation/database/migrations/2026_11_02_150000_create_sa_campaign_tables.php';
$out['migration_file'] = [
    'path' => $migrationFile,
    'exists' => is_file($migrationFile),
    'sha256' => is_file($migrationFile) ? hash_file('sha256', $migrationFile) : null,
    'connection_literal' => is_file($migrationFile) && str_contains((string) file_get_contents($migrationFile), "omi_social_automation"),
];

$deps = [
    'sa_accounts' => 'FK target for assigned_account_id / account_id',
    'sa_jobs' => 'ALTER add campaign_id/campaign_content_id/lease_token/fencing_generation + task_id nullable',
    'sa_job_attempts' => 'ALTER add lease/agent/browser/account/progress/error_message/fencing columns',
    'sa_account_execution_leases' => 'ALTER add lease_token/attempt_id/fencing_generation',
    'sa_tasks' => 'jobsReady() dependency (SocialAutomationSchema::campaignsReady)',
];

$out['prerequisite_tables'] = [];
foreach ($deps as $table => $why) {
    $exists = $schema->hasTable($table);
    $row = ['exists' => $exists, 'why' => $why];
    if ($exists) {
        try {
            $row['engine'] = $db->selectOne("SELECT ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$table])->e ?? null;
            $row['rows_approx'] = (int) ($db->table($table)->count());
        } catch (Throwable $e) {
            $row['meta_error'] = $e->getMessage();
        }
    }
    $out['prerequisite_tables'][$table] = $row;
}

$out['campaign_tables_present'] = [
    'sa_campaigns' => $schema->hasTable('sa_campaigns'),
    'sa_campaign_contents' => $schema->hasTable('sa_campaign_contents'),
    'sa_campaign_account_assignments' => $schema->hasTable('sa_campaign_account_assignments'),
];

$checkCols = [
    'sa_jobs' => ['task_id', 'campaign_id', 'campaign_content_id', 'lease_token', 'fencing_generation', 'account_id', 'package_key', 'package_version', 'content_digest', 'payload'],
    'sa_job_attempts' => ['lease_token', 'lease_expires_at', 'agent_id', 'browser_session_id', 'account_id', 'progress', 'error_message', 'fencing_generation', 'attempt_uuid'],
    'sa_account_execution_leases' => ['lease_token', 'attempt_id', 'fencing_generation', 'job_id', 'account_id'],
    'sa_accounts' => ['id', 'account_uuid', 'installation_id', 'platform'],
];
$out['columns'] = [];
foreach ($checkCols as $table => $cols) {
    if (! $schema->hasTable($table)) {
        $out['columns'][$table] = ['table_missing' => true];
        continue;
    }
    $map = [];
    foreach ($cols as $col) {
        $map[$col] = $schema->hasColumn($table, $col);
    }
    $out['columns'][$table] = $map;

    // task_id nullability
    if ($table === 'sa_jobs' && $schema->hasColumn('sa_jobs', 'task_id')) {
        try {
            $col = $db->selectOne(
                "SELECT IS_NULLABLE AS n, COLUMN_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sa_jobs' AND COLUMN_NAME = 'task_id'"
            );
            $out['columns']['sa_jobs']['task_id_nullable'] = strtoupper((string) ($col->n ?? '')) === 'YES';
            $out['columns']['sa_jobs']['task_id_type'] = $col->t ?? null;
        } catch (Throwable $e) {
            $out['columns']['sa_jobs']['task_id_null_check_error'] = $e->getMessage();
        }
    }
}

// migrations table / batch history (RO)
$out['migrations_table'] = [
    'exists' => $schema->hasTable('migrations'),
];
try {
    if ($schema->hasTable('migrations')) {
        $campaignRows = $db->table('migrations')
            ->where('migration', 'like', '%create_sa_campaign_tables%')
            ->orWhere('migration', 'like', '%2026_11_02_150000%')
            ->get();
        $out['migrations_table']['campaign_migration_rows'] = $campaignRows;
        $out['migrations_table']['recent'] = $db->table('migrations')->orderByDesc('id')->limit(8)->get(['id', 'migration', 'batch']);
        $out['migrations_table']['count'] = (int) $db->table('migrations')->count();
    }
} catch (Throwable $e) {
    $out['migrations_table']['error'] = $e->getMessage();
}

// Note: Laravel may use default connection migrations table, not omi_
try {
    $default = Illuminate\Support\Facades\DB::connection();
    $out['default_connection'] = [
        'name' => $default->getName(),
        'database' => $default->getDatabaseName(),
        'has_migrations' => Illuminate\Support\Facades\Schema::connection($default->getName())->hasTable('migrations'),
    ];
    if ($out['default_connection']['has_migrations']) {
        $out['default_connection']['campaign_rows'] = Illuminate\Support\Facades\DB::table('migrations')
            ->where('migration', 'like', '%create_sa_campaign_tables%')
            ->orWhere('migration', 'like', '%2026_11_02_150000%')
            ->get();
        $out['default_connection']['omi_related_recent'] = Illuminate\Support\Facades\DB::table('migrations')
            ->where('migration', 'like', '%sa_%')
            ->orderByDesc('id')
            ->limit(15)
            ->get(['id', 'migration', 'batch']);
    }
} catch (Throwable $e) {
    $out['default_connection'] = ['error' => $e->getMessage()];
}

// Backup indicators (RO filesystem + known backup tables)
$out['backup'] = [
    'seo_db_backups_dir' => is_dir('/www/wwwroot/seo-ops-client/storage/app/seo-db-backups'),
    'seo_db_backups_recent' => [],
    'sa_rpa_admin_backups_table' => $schema->hasTable('sa_rpa_admin_backups'),
];
if (is_dir('/www/wwwroot/seo-ops-client/storage/app/seo-db-backups')) {
    $files = glob('/www/wwwroot/seo-ops-client/storage/app/seo-db-backups/*') ?: [];
    usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($files, 0, 8) as $f) {
        $out['backup']['seo_db_backups_recent'][] = [
            'name' => basename($f),
            'size' => filesize($f),
            'mtime' => gmdate('c', (int) filemtime($f)),
        ];
    }
}
if ($schema->hasTable('sa_rpa_admin_backups')) {
    try {
        $out['backup']['sa_rpa_admin_backups_recent'] = $db->table('sa_rpa_admin_backups')
            ->orderByDesc('id')->limit(5)
            ->get(['id', 'installation_id', 'draft_revision', 'backed_up_at', 'size_bytes']);
    } catch (Throwable $e) {
        $out['backup']['sa_rpa_admin_backups_error'] = $e->getMessage();
    }
}

// Code routes + claim pin (RO file inspect)
$provider = '/www/wwwroot/seo-ops-addons/social-automation/src/SocialAutomationServiceProvider.php';
$orc = '/www/wwwroot/seo-ops-addons/social-automation/src/Orchestration/CampaignOrchestrator.php';
$providerSrc = is_file($provider) ? (string) file_get_contents($provider) : '';
$orcSrc = is_file($orc) ? (string) file_get_contents($orc) : '';
$out['routes_code'] = [
    'staff_campaigns' => str_contains($providerSrc, "prefix('api/campaigns')"),
    'staff_campaign_contents' => str_contains($providerSrc, 'campaign-contents'),
    'client_jobs_claim' => str_contains($providerSrc, "/jobs/claim"),
    'client_jobs_result' => str_contains($providerSrc, "/jobs/{id}/result"),
    'client_scripts_pull' => str_contains($providerSrc, "/scripts/pull"),
    'client_scripts_asset' => str_contains($providerSrc, "/scripts/asset"),
];
$out['claim_pin_code'] = [
    'package_key' => str_contains($orcSrc, "'package_key'"),
    'release_id' => str_contains($orcSrc, "'release_id'"),
    'content_digest' => str_contains($orcSrc, "'content_digest'"),
    'execution_digest' => str_contains($orcSrc, "'execution_digest'"),
    'script_pin' => str_contains($orcSrc, 'script_pin'),
    'resolveRequiredAccountId' => str_contains($orcSrc, 'resolveRequiredAccountId'),
];

// FK risk: sa_accounts must exist before create with constrained()
$out['fk_plan'] = [
    'sa_campaign_contents.campaign_id -> sa_campaigns CASCADE',
    'sa_campaign_contents.assigned_account_id -> sa_accounts NULL ON DELETE',
    'sa_campaign_account_assignments.campaign_id -> sa_campaigns CASCADE',
    'sa_campaign_account_assignments.account_id -> sa_accounts CASCADE',
    'sa_campaign_account_assignments.content_id -> sa_campaign_contents CASCADE',
    'sa_jobs.campaign_id / campaign_content_id indexes only (no FK in migration)',
];

// Rollback caveats from reading down()
$out['rollback_notes'] = [
    'down_drops_new_campaign_tables' => true,
    'down_drops_sa_jobs_campaign_columns' => true,
    'down_may_drop_preexisting_attempt_columns' => [
        'agent_id', 'browser_session_id', 'account_id', 'lease_token', 'lease_expires_at', 'progress', 'error_message', 'fencing_generation',
    ],
    'down_does_not_restore_task_id_NOT_NULL' => true,
    'recommendation' => 'Prefer forward-fix; if rollback needed, restore from DB backup rather than migrate:rollback for this migration',
];

// Disk free (backup space)
try {
    $out['disk'] = [
        'root_free_bytes' => @disk_free_space('/'),
        'www_free_bytes' => @disk_free_space('/www'),
    ];
} catch (Throwable) {
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
