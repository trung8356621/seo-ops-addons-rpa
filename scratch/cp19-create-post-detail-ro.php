<?php

declare(strict_types=1);

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = Illuminate\Support\Facades\DB::connection('omi_social_automation');
$schema = Illuminate\Support\Facades\Schema::connection('omi_social_automation');

$key = '00c6ca04-3d37-4dbf-a4a3-5fc83e9b17a5';
$pkg = $db->table('sa_packages')->where('package_key', $key)->first();
$pub = $db->table('sa_package_releases')->where('package_id', $pkg->id)->where('status', 'published')->first();
$man = json_decode((string) $pub->manifest, true);

$out = [
    'action_tag_key' => $man['action_tag_key'] ?? null,
    'script_id_in_manifest' => $man['script_id'] ?? null,
    'assets' => $man['assets'] ?? null,
    'video_demo' => isset($man['video_demo']) ? (is_array($man['video_demo']) ? array_keys($man['video_demo']) : gettype($man['video_demo'])) : null,
    'outputs' => $man['outputs'] ?? null,
    'runtime_requirements' => $man['runtime_requirements'] ?? null,
];

// action tag linkage
if (! empty($man['action_tag_key'])) {
    $tag = $db->table('sa_action_tags')->where('tag_key', $man['action_tag_key'])->first();
    $out['action_tag'] = $tag ? [
        'id' => (int) $tag->id,
        'tag_key' => $tag->tag_key,
        'site_key' => $tag->site_key ?? null,
        'name' => $tag->name ?? null,
    ] : null;
    if ($schema->hasTable('sa_action_tag_scripts')) {
        $out['action_tag_scripts'] = $db->table('sa_action_tag_scripts')
            ->where('action_tag_id', $tag->id ?? 0)
            ->get();
    }
}

// search any package_key / tag resembling mayhopphat.create_post
$out['tag_like'] = $db->table('sa_action_tags')
    ->where('tag_key', 'like', '%create_post%')
    ->orWhere('tag_key', 'like', '%mayhopphat%')
    ->get(['id', 'tag_key', 'site_key', 'name']);

// deps
$out['deps'] = $db->table('sa_package_dependencies')->where('release_id', $pub->id)->get();

// check steps raw for draft vs publish wording
$out['step_names'] = array_map(static fn ($s) => $s['name'] ?? null, $man['steps'] ?? []);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
