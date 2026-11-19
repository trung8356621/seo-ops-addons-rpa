<?php

declare(strict_types=1);

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = Illuminate\Support\Facades\DB::connection('omi_social_automation');

$pkg = $db->table('sa_packages')->where('package_key', 'mayhopphat.login')->first();
echo 'PACKAGE='.json_encode($pkg ? [
    'id' => (int) $pkg->id,
    'package_key' => $pkg->package_key,
    'kind' => $pkg->kind,
    'is_authentication' => (bool) ($pkg->is_authentication ?? false),
    'is_enabled' => (bool) ($pkg->is_enabled ?? false),
    'name' => $pkg->name,
], JSON_UNESCAPED_UNICODE).PHP_EOL;

$releases = [];
if ($pkg) {
    $releases = $db->table('sa_package_releases')
        ->where('package_id', $pkg->id)
        ->orderByDesc('id')
        ->get(['id', 'version', 'status', 'published_at', 'content_digest'])
        ->map(static function ($r) {
            $manifestBytes = null;
            $raw = $db->table('sa_package_releases')->where('id', $r->id)->value('manifest');
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $manifestBytes = [
                        'variables_count' => is_array($decoded['variables'] ?? null) ? count($decoded['variables']) : null,
                        'steps_count' => is_array($decoded['steps'] ?? null) ? count($decoded['steps']) : null,
                        'assets_count' => is_array($decoded['assets'] ?? null) ? count($decoded['assets']) : null,
                        'keys' => array_keys($decoded),
                    ];
                }
            }

            return [
                'id' => (int) $r->id,
                'version' => $r->version,
                'status' => $r->status,
                'published_at' => $r->published_at,
                'content_digest' => $r->content_digest,
                'manifest_summary' => $manifestBytes,
            ];
        })
        ->all();
}
echo 'RELEASES='.json_encode($releases, JSON_UNESCAPED_UNICODE).PHP_EOL;

$path = $db->table('sa_paths')->where('site_key', 'mayhopphat')->where('key', 'login')->first();
echo 'PATH='.json_encode($path ? [
    'id' => (int) $path->id,
    'site_id' => (int) $path->site_id,
    'site_key' => $path->site_key,
    'key' => $path->key,
    'name' => $path->name,
    'is_authentication' => (bool) ($path->is_authentication ?? false),
], JSON_UNESCAPED_UNICODE).PHP_EOL;

$tags = $db->table('sa_action_tags')
    ->where('tag_key', 'mayhopphat.login')
    ->get(['id', 'tag_key', 'site_id', 'path_id', 'path_key', 'action_key', 'auth_script_id', 'auth_release_id'])
    ->map(static fn ($t) => [
        'id' => (int) $t->id,
        'tag_key' => $t->tag_key,
        'site_id' => $t->site_id !== null ? (int) $t->site_id : null,
        'path_id' => $t->path_id !== null ? (int) $t->path_id : null,
        'path_key' => $t->path_key,
        'action_key' => $t->action_key,
        'auth_script_id' => $t->auth_script_id !== null ? (int) $t->auth_script_id : null,
        'auth_release_id' => $t->auth_release_id !== null ? (int) $t->auth_release_id : null,
    ])
    ->all();
echo 'TAGS='.json_encode($tags, JSON_UNESCAPED_UNICODE).PHP_EOL;

$reqs = $db->table('sa_script_requests')
    ->where('action_tag_key', 'mayhopphat.login')
    ->orderByDesc('id')
    ->limit(5)
    ->get(['id', 'request_id', 'action_tag_id', 'action_tag_key', 'path_key', 'url_pattern', 'status', 'script_id', 'script_version', 'request_type'])
    ->map(static fn ($r) => [
        'id' => (int) $r->id,
        'request_id' => $r->request_id,
        'action_tag_id' => $r->action_tag_id !== null ? (int) $r->action_tag_id : null,
        'action_tag_key' => $r->action_tag_key,
        'path_key' => $r->path_key,
        'url_pattern' => $r->url_pattern,
        'status' => $r->status,
        'script_id' => $r->script_id,
        'script_version' => $r->script_version,
        'request_type' => $r->request_type,
    ])
    ->all();
echo 'REQUESTS='.json_encode($reqs, JSON_UNESCAPED_UNICODE).PHP_EOL;

$svc = app(\Omnichannel\Addons\SocialAutomation\Services\AuthScriptService::class);
$catalog = $svc->listAuthenticationCatalog();
$keys = array_values(array_map(static fn ($r) => $r['package_key'] ?? null, $catalog));
echo 'SCHEMA_READY='.($svc->schemaReady() ? 'yes' : 'no').PHP_EOL;
echo 'CATALOG_COUNT='.count($catalog).PHP_EOL;
echo 'CATALOG_KEYS='.json_encode($keys, JSON_UNESCAPED_UNICODE).PHP_EOL;

$hit = null;
foreach ($catalog as $row) {
    if (($row['package_key'] ?? '') === 'mayhopphat.login') {
        $hit = [
            'package_id' => $row['package_id'] ?? null,
            'package_key' => $row['package_key'] ?? null,
            'is_authentication' => $row['is_authentication'] ?? null,
            'is_enabled' => $row['is_enabled'] ?? null,
            'releases_count' => is_array($row['releases'] ?? null) ? count($row['releases']) : 0,
        ];
        break;
    }
}
echo 'CATALOG_HIT='.json_encode($hit, JSON_UNESCAPED_UNICODE).PHP_EOL;

$authPkgs = $db->table('sa_packages')->where('is_authentication', true)->orderBy('package_key')->get(['id', 'package_key', 'kind', 'is_enabled']);
echo 'ALL_AUTH_PACKAGES='.json_encode($authPkgs, JSON_UNESCAPED_UNICODE).PHP_EOL;
