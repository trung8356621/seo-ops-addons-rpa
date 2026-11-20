<?php

declare(strict_types=1);

/** READ-ONLY claim/lease probe. No claim mutations. */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$db = DB::connection('omi_social_automation');
$out = [
    'probe' => 'shortcode_v2_claim_ro',
    'mutated' => false,
    'claim_status' => 'UNKNOWN',
];

try {
    $out['active_lease_tokens'] = Schema::connection('omi_social_automation')->hasTable('sa_account_execution_leases')
        ? (int) $db->table('sa_account_execution_leases')
            ->whereNotNull('lease_token')
            ->where('lease_expires_at', '>', now())
            ->count()
        : null;
    $out['jobs_reserved_or_running'] = (int) $db->table('sa_jobs')
        ->whereIn('status', ['reserved', 'running', 'leased', 'claimed'])
        ->count();
    $out['attempts_active'] = Schema::connection('omi_social_automation')->hasTable('sa_job_attempts')
        ? (int) $db->table('sa_job_attempts')
            ->whereIn('status', ['queued', 'running', 'leased'])
            ->count()
        : null;
    $out['claim_status'] = (
        ($out['active_lease_tokens'] ?? 0) === 0
        && ($out['jobs_reserved_or_running'] ?? 0) === 0
        && ($out['attempts_active'] ?? 0) === 0
    ) ? 'IDLE_NO_ACTIVE_LEASES' : 'ACTIVE_OR_UNKNOWN';
} catch (Throwable $e) {
    $out['error'] = $e->getMessage();
    $out['claim_status'] = 'UNKNOWN';
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
