<?php

declare(strict_types=1);

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = Illuminate\Support\Facades\DB::connection('omi_social_automation');

$pkgs = $db->table('sa_packages')
    ->where(function ($q): void {
        $q->where('package_key', 'like', '%mayhopphat%')
            ->orWhere('package_key', 'like', '%create_post%')
            ->orWhere('name', 'like', '%create_post%')
            ->orWhere('name', 'like', '%mayhopphat%');
    })
    ->get(['id', 'package_key', 'kind', 'name', 'is_enabled', 'is_authentication']);

$out = ['packages' => $pkgs];

foreach ($pkgs as $pkg) {
    $rels = $db->table('sa_package_releases')
        ->where('package_id', $pkg->id)
        ->orderByDesc('id')
        ->limit(5)
        ->get(['id', 'version', 'status', 'published_at', 'content_digest']);
    $out['releases_by_key'][$pkg->package_key] = $rels;

    $pub = $db->table('sa_package_releases')
        ->where('package_id', $pkg->id)
        ->where('status', 'published')
        ->orderByDesc('published_at')
        ->orderByDesc('id')
        ->first();
    if ($pub) {
        $man = json_decode((string) $pub->manifest, true);
        $steps = [];
        if (is_array($man['steps'] ?? null)) {
            foreach ($man['steps'] as $s) {
                if (! is_array($s)) {
                    continue;
                }
                $steps[] = [
                    'action' => $s['action'] ?? $s['type'] ?? null,
                    'name' => $s['name'] ?? null,
                ];
            }
        }
        $out['published_detail'][$pkg->package_key] = [
            'version' => $pub->version,
            'status' => $pub->status,
            'content_digest' => $pub->content_digest,
            'variables' => $man['variables'] ?? null,
            'test_flags' => [
                'test_mode_compatible' => $man['test_mode_compatible'] ?? null,
                'campaign_test_compatible' => $man['campaign_test_compatible'] ?? null,
                'stops_before_publish' => $man['stops_before_publish'] ?? null,
                'terminal_intent' => $man['terminal_intent'] ?? null,
            ],
            'steps' => $steps,
            'manifest_keys' => is_array($man) ? array_keys($man) : [],
        ];
    }
}

// Also check remote code presence for campaign classes
$remote = '/www/wwwroot/seo-ops-addons/social-automation';
$out['remote_code'] = [
    'CampaignService' => is_file($remote.'/src/Services/CampaignService.php'),
    'CampaignOrchestrator' => is_file($remote.'/src/Orchestration/CampaignOrchestrator.php'),
    'StaffCampaignController' => is_file($remote.'/src/Http/Controllers/StaffCampaignController.php'),
    'ClientCampaignJobController' => is_file($remote.'/src/Http/Controllers/ClientCampaignJobController.php'),
    'migration' => is_file($remote.'/database/migrations/2026_11_02_150000_create_sa_campaign_tables.php'),
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
