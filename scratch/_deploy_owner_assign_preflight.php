<?php

declare(strict_types=1);

/**
 * READ-ONLY preflight for Owner-assigned Client Keys deploy.
 * No raw credentials. No mutations.
 */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$conn = 'omi_social_automation';
$schema = DB::connection($conn)->getSchemaBuilder();

echo 'APP_URL='.config('app.url').PHP_EOL;
echo 'APP_ENV='.config('app.env').PHP_EOL;
echo 'TS='.gmdate('c').PHP_EOL;

echo 'HAS_ISSUED_BY='.($schema->hasColumn('sa_client_api_keys', 'issued_by') ? 'yes' : 'no').PHP_EOL;

$cols = ['id', 'name', 'key_prefix', 'created_by', 'revoked_at', 'last_used_at', 'created_at'];
if ($schema->hasColumn('sa_client_api_keys', 'issued_by')) {
    $cols[] = 'issued_by';
}

$keys = DB::connection($conn)->table('sa_client_api_keys')->select($cols)->orderBy('id')->get();
echo 'KEY_COUNT='.count($keys).PHP_EOL;
echo 'KEY_ACTIVE='.$keys->whereNull('revoked_at')->count().PHP_EOL;
echo 'KEY_REVOKED='.$keys->whereNotNull('revoked_at')->count().PHP_EOL;

$qa = $keys->firstWhere('id', 10);
if ($qa) {
    echo 'QA10_NAME='.$qa->name.PHP_EOL;
    echo 'QA10_PREFIX='.$qa->key_prefix.PHP_EOL;
    echo 'QA10_CREATED_BY='.($qa->created_by ?? 'null').PHP_EOL;
    echo 'QA10_REVOKED='.($qa->revoked_at ? 'yes' : 'no').PHP_EOL;
    echo 'QA10_ACTIVE='.($qa->revoked_at ? 'no' : 'yes').PHP_EOL;
} else {
    echo "QA10_MISSING=yes\n";
}

foreach ($keys as $k) {
    echo implode('|', [
        'id='.$k->id,
        'name='.$k->name,
        'prefix='.$k->key_prefix,
        'created_by='.($k->created_by ?? 'null'),
        'issued_by='.(property_exists($k, 'issued_by') ? ($k->issued_by ?? 'null') : 'n/a'),
        'revoked='.($k->revoked_at ? 'yes' : 'no'),
    ]).PHP_EOL;
}

// Agents / connections
$agentCols = ['id', 'agent_uuid', 'display_name', 'client_api_key_id', 'last_heartbeat_at'];
foreach (['agent_kind', 'online_state', 'paused_at'] as $c) {
    if ($schema->hasColumn('sa_agents', $c)) {
        $agentCols[] = $c;
    }
}
$agents = DB::connection($conn)->table('sa_agents')->select($agentCols)->orderBy('id')->get();
echo 'AGENT_COUNT='.count($agents).PHP_EOL;
$now = time();
foreach ($agents as $a) {
    $hb = $a->last_heartbeat_at ?? null;
    $age = $hb ? ($now - strtotime((string) $hb)) : null;
    echo implode('|', [
        'id='.$a->id,
        'name='.($a->display_name ?? ''),
        'key_id='.($a->client_api_key_id ?? 'null'),
        'hb='.($hb ?? 'null'),
        'hb_age_s='.($age === null ? 'null' : $age),
        'uuid='.substr((string) $a->agent_uuid, 0, 8).'…',
    ]).PHP_EOL;
}

// Campaign jobs / leases (counts only)
foreach ([
    'sa_campaigns' => "select status, count(*) c from sa_campaigns group by status",
    'sa_jobs' => "select status, count(*) c from sa_jobs group by status",
] as $label => $sql) {
    try {
        $rows = DB::connection($conn)->select($sql);
        echo strtoupper($label).'_BY_STATUS='.json_encode($rows).PHP_EOL;
    } catch (Throwable $e) {
        echo strtoupper($label).'_ERR='.$e->getMessage().PHP_EOL;
    }
}

try {
    $leases = DB::connection($conn)->table('sa_jobs')
        ->whereNotNull('lease_expires_at')
        ->where('lease_expires_at', '>', now())
        ->count();
    echo 'ACTIVE_JOB_LEASES='.$leases.PHP_EOL;
} catch (Throwable $e) {
    echo 'LEASE_ERR='.$e->getMessage().PHP_EOL;
}

// Migration status for issued_by migration
try {
    $mig = DB::connection($conn)->table('migrations')
        ->where('migration', 'like', '%issued_by_to_sa_client_api_keys%')
        ->get(['migration', 'batch']);
    echo 'MIGRATION_ROWS='.json_encode($mig).PHP_EOL;
} catch (Throwable $e) {
    // migrations may live on default connection
    try {
        $mig = DB::table('migrations')
            ->where('migration', 'like', '%issued_by_to_sa_client_api_keys%')
            ->get(['migration', 'batch']);
        echo 'MIGRATION_ROWS_DEFAULT='.json_encode($mig).PHP_EOL;
    } catch (Throwable $e2) {
        echo 'MIGRATION_ERR='.$e2->getMessage().PHP_EOL;
    }
}

// Addon file fingerprints (paths only + sha)
$files = [
    '/www/wwwroot/seo-ops-addons/social-automation/src/Http/Controllers/RpaClientApiKeyManagementController.php',
    '/www/wwwroot/seo-ops-addons/social-automation/src/Domain/ClientApiKeyAssignmentPolicy.php',
    '/www/wwwroot/seo-ops-addons/social-automation/database/migrations/2026_11_25_130000_add_issued_by_to_sa_client_api_keys_table.php',
];
foreach ($files as $f) {
    if (! is_file($f)) {
        echo 'FILE_MISSING='.$f.PHP_EOL;
        continue;
    }
    echo 'FILE='.basename($f).'|sha256='.hash_file('sha256', $f).'|bytes='.filesize($f).PHP_EOL;
}
