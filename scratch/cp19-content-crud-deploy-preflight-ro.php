<?php

declare(strict_types=1);

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

$db = DB::connection('omi_social_automation');
$now = now();
$uuid = '13f41a46-aefb-4343-b495-3ef99b5176d6';
$c = $db->table('sa_campaigns')->where('campaign_uuid', $uuid)->first();
$contentCount = $c ? (int) $db->table('sa_campaign_contents')->where('campaign_id', $c->id)->count() : 0;
$jobCount = $c ? (int) $db->table('sa_jobs')->where('campaign_id', $c->id)->count() : 0;
$running = (int) $db->table('sa_campaigns')->where('status', 'running')->count();

$routes = collect(Route::getRoutes())->map(static fn ($r) => [
    'uri' => $r->uri(),
    'methods' => $r->methods(),
    'name' => $r->getName(),
])->filter(static function (array $r): bool {
    $u = $r['uri'];
    return str_contains($u, 'campaign') && str_contains($u, 'content');
})->values()->all();

echo json_encode([
    'now' => $now->toIso8601String(),
    'campaign' => $c ? [
        'uuid' => $c->campaign_uuid,
        'status' => $c->status,
        'next_recheck_at' => $c->next_recheck_at,
        'updated_at' => $c->updated_at ?? null,
    ] : null,
    'content_count' => $contentCount,
    'job_count' => $jobCount,
    'running_campaigns' => $running,
    'content_routes_registered' => $routes,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
