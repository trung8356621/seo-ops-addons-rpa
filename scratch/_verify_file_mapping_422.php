<?php

declare(strict_types=1);

/**
 * LIVE verify: Campaign file/files mapping against published Script release.
 * Read-only for Script/release; does not mutate QA Script or release v3.
 */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignScriptVariableSchema;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodeMappingValidator;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodeTypedValidator;

$scriptId = 'ea7822b3-ed2b-48e9-ab55-7ec7fe5349a1';
$releaseIdWanted = 22;
$conn = 'omi_social_automation';
$out = [
    'script_id' => $scriptId,
    'wanted_release_id' => $releaseIdWanted,
];

$schemaSrc = (string) file_get_contents('/www/wwwroot/seo-ops-addons/social-automation/src/Domain/CampaignScriptVariableSchema.php');
$typedSrc = (string) file_get_contents('/www/wwwroot/seo-ops-addons/social-automation/src/Domain/CampaignShortcodeTypedValidator.php');
$out['deployed_has_files_supported'] = str_contains($schemaSrc, "'file', 'files'");
$out['deployed_has_asFile'] = str_contains($typedSrc, 'function asFile');
$out['deployed_has_asFiles'] = str_contains($typedSrc, 'function asFiles');

$db = DB::connection($conn);
$pkg = null;
foreach (['script_id', 'package_key', 'package_uuid'] as $col) {
    if (! Schema::connection($conn)->hasColumn('sa_packages', $col)) {
        continue;
    }
    $pkg = $db->table('sa_packages')->where($col, $scriptId)->first();
    if ($pkg) {
        $out['package_lookup_col'] = $col;
        break;
    }
}
$out['package_found'] = (bool) $pkg;
if (! $pkg) {
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

$out['package_id'] = $pkg->id ?? null;
$out['package_key'] = $pkg->package_key ?? null;

$rel = $db->table('sa_package_releases')->where('package_id', $pkg->id)->where('id', $releaseIdWanted)->first();
if (! $rel) {
    $rel = $db->table('sa_package_releases')->where('package_id', $pkg->id)->orderByDesc('id')->first();
}
$out['release_id'] = $rel->id ?? null;
$out['release_version'] = $rel->version ?? null;
$out['release_status'] = $rel->status ?? null;

$manifest = null;
foreach (['manifest_json', 'manifest', 'content_json'] as $field) {
    if (! isset($rel->$field) || $rel->$field === null || $rel->$field === '') {
        continue;
    }
    $raw = $rel->$field;
    $manifest = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
    if (is_array($manifest)) {
        $out['manifest_field'] = $field;
        break;
    }
}
if (! is_array($manifest) && isset($rel->variables)) {
    $varsRaw = is_string($rel->variables) ? json_decode($rel->variables, true) : $rel->variables;
    if (is_array($varsRaw)) {
        $manifest = ['variables' => $varsRaw];
        $out['manifest_field'] = 'variables';
    }
}

$out['manifest_keys'] = is_array($manifest) ? array_keys($manifest) : null;
$schema = (new CampaignScriptVariableSchema)->fromManifest(is_array($manifest) ? $manifest : []);
$out['schema_ok'] = (bool) ($schema['ok'] ?? false);
$out['schema_errors'] = $schema['errors'] ?? null;
$vars = $schema['variables'] ?? [];
$out['variable_types'] = array_values(array_map(
    static fn (array $v): array => [
        'key' => $v['key'],
        'type' => $v['type'],
        'required' => (bool) $v['required'],
    ],
    $vars
));

$map = (new CampaignShortcodeMappingValidator)->validate([
    'entries' => [
        ['shortcode' => 'text', 'variable' => 'text'],
        ['shortcode' => 'files', 'variable' => 'files'],
    ],
], $vars);
$out['mapping_ok'] = (bool) ($map['ok'] ?? false);
$out['mapping_errors'] = $map['errors'] ?? null;

$typed = (new CampaignShortcodeTypedValidator)->validate([
    'text' => 'QA Sandbox caption',
    'files' => ['D:\\qa\\sandbox\\photo.jpg'],
], $vars);
$out['typed_ok'] = (bool) ($typed['ok'] ?? false);
$out['typed_errors'] = $typed['errors'] ?? null;
$out['typed_files_is_array'] = is_array($typed['input']['files'] ?? null);
$out['typed_files'] = $typed['input']['files'] ?? null;

$reject = (new CampaignShortcodeTypedValidator)->validate([
    'text' => 'QA',
    'files' => 'not a path',
], $vars);
$out['reject_prose_ok'] = (bool) ($reject['ok'] ?? false);
$out['reject_prose_errors'] = $reject['errors'] ?? null;

$out['live_pass'] = ($out['deployed_has_files_supported'] && $out['deployed_has_asFile']
    && ($out['mapping_ok'] ?? false) && ($out['typed_ok'] ?? false)
    && ($out['typed_files_is_array'] ?? false)
    && ! ($out['reject_prose_ok'] ?? true));

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
