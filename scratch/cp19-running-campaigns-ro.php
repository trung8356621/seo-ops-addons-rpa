<?php

declare(strict_types=1);

/** READ-ONLY: list running campaigns that could dispatch after schedule-tick is wired. No writes. */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$db = DB::connection('omi_social_automation');
$now = now();

if (! Schema::connection('omi_social_automation')->hasTable('sa_campaigns')) {
    echo json_encode(['error' => 'sa_campaigns_missing']).PHP_EOL;
    exit(0);
}

$campaigns = $db->table('sa_campaigns')->where('status', 'running')->orderBy('id')->get();
$rows = [];
foreach ($campaigns as $c) {
    $counts = $db->table('sa_campaign_contents')
        ->selectRaw('status, COUNT(*) as c')
        ->where('campaign_id', $c->id)
        ->groupBy('status')
        ->pluck('c', 'status');
    $waiting = (int) ($counts['waiting_resources'] ?? 0) + (int) ($counts['pending'] ?? 0);
    $due = $c->next_recheck_at === null || strtotime((string) $c->next_recheck_at) <= $now->getTimestamp();
    $meta = json_decode((string) ($c->meta ?? '{}'), true);
    $rows[] = [
        'id' => (int) $c->id,
        'uuid' => $c->campaign_uuid,
        'name' => $c->name ?? null,
        'platform' => $c->platform,
        'next_recheck_at' => $c->next_recheck_at,
        'next_recheck_due' => $due,
        'resource_recheck_seconds' => (int) ($c->resource_recheck_seconds ?? 0),
        'pinned_account_id' => is_array($meta) ? ($meta['pinned_account_id'] ?? null) : null,
        'content_counts' => $counts,
        'dispatch_risk' => $waiting > 0 && $due ? 'high_immediate_on_tick' : ($waiting > 0 ? 'waiting_not_yet_due' : 'running_no_waiting'),
    ];
}

echo json_encode([
    'now' => $now->toIso8601String(),
    'running_count' => count($rows),
    'campaigns' => $rows,
    'note' => 'RO only — schedule-tick NOT invoked',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
