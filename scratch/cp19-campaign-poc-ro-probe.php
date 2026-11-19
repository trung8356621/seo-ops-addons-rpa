<?php

declare(strict_types=1);

/**
 * Read-only VPS probe for Campaign POC + mayhopphat.create_post.
 * No mutations.
 */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = Illuminate\Support\Facades\DB::connection('omi_social_automation');
$schema = Illuminate\Support\Facades\Schema::connection('omi_social_automation');

$out = [
    'app_env' => (string) config('app.env'),
    'schema' => [
        'sa_campaigns' => $schema->hasTable('sa_campaigns'),
        'sa_campaign_contents' => $schema->hasTable('sa_campaign_contents'),
        'sa_campaign_account_assignments' => $schema->hasTable('sa_campaign_account_assignments'),
        'sa_jobs_has_campaign_id' => $schema->hasTable('sa_jobs') && $schema->hasColumn('sa_jobs', 'campaign_id'),
        'sa_jobs_has_lease_token' => $schema->hasTable('sa_jobs') && $schema->hasColumn('sa_jobs', 'lease_token'),
    ],
];

$pkg = $db->table('sa_packages')->where('package_key', 'mayhopphat.create_post')->first();
$out['package'] = $pkg ? [
    'id' => (int) $pkg->id,
    'package_key' => $pkg->package_key,
    'kind' => $pkg->kind ?? null,
    'is_enabled' => (bool) ($pkg->is_enabled ?? false),
    'name' => $pkg->name ?? null,
] : null;

if ($pkg) {
    $releases = $db->table('sa_package_releases')
        ->where('package_id', $pkg->id)
        ->orderByDesc('id')
        ->get(['id', 'version', 'status', 'published_at', 'content_digest', 'execution_digest']);
    $out['releases'] = $releases;

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
        $blob = strtolower(json_encode($man) ?: '');
        $out['published'] = [
            'id' => (int) $pub->id,
            'version' => $pub->version,
            'status' => $pub->status,
            'content_digest' => $pub->content_digest,
            'variables' => $man['variables'] ?? null,
            'test_mode_compatible' => $man['test_mode_compatible'] ?? $man['campaign_test_compatible'] ?? null,
            'stops_before_publish' => $man['stops_before_publish'] ?? null,
            'terminal_intent' => $man['terminal_intent'] ?? null,
            'steps' => $steps,
            'assets_in_manifest' => is_array($man['assets'] ?? null) ? count($man['assets']) : null,
            'manifest_keys' => is_array($man) ? array_keys($man) : [],
            'mentions_save_draft' => str_contains($blob, 'save draft') || str_contains($blob, 'save_draft') || str_contains($blob, 'draft'),
            'mentions_publish' => str_contains($blob, 'publish') || str_contains($blob, '"post"'),
        ];

        if ($schema->hasTable('sa_package_release_files')) {
            $out['release_files'] = $db->table('sa_package_release_files')
                ->where('release_id', $pub->id)
                ->get(['id', 'path', 'sha256', 'size_bytes', 'mime']);
        } elseif ($schema->hasTable('sa_package_blobs')) {
            $out['release_files_table'] = 'sa_package_blobs';
        } else {
            $out['release_files_table'] = 'unknown';
            $tables = $db->select("SHOW TABLES LIKE 'sa_package%'");
            $out['package_tables'] = $tables;
        }
    } else {
        $out['published'] = null;
    }
}

$out['accounts'] = $db->table('sa_accounts')
    ->where(function ($q): void {
        $q->where('username', 'mayhopphat')
            ->orWhere('name', 'like', '%mayhopphat%')
            ->orWhere('platform', 'mayhopphat');
    })
    ->limit(5)
    ->get(['id', 'account_uuid', 'platform', 'name', 'username', 'verified']);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
