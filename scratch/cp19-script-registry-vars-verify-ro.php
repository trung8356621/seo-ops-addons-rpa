<?php

declare(strict_types=1);

/**
 * READ-ONLY verify: Staff Script Registry detail projection for mayhopphat.create_post v1.
 * Uses the same controller mapper + UI service as the Staff GET (no HTTP session; no writes).
 */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ScriptRegistry\StaffScriptRegistryController;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTagScript;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptRegistryUiService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;

$db = DB::connection('omi_social_automation');
$out = [
    'probe' => 'script_registry_vars_verify_ro',
    'mutated' => false,
];

$tag = 'mayhopphat.create_post';
$link = SaActionTagScript::query()->where('action_tag_key', $tag)->orderByDesc('id')->first();
$package = null;
if ($link && $link->package_id) {
    $package = SaPackage::query()->find($link->package_id);
}
if (! $package) {
    $package = SaPackage::query()->where('package_key', $tag)->first();
}
if (! $package) {
    // Fallback: scan published manifests
    $releases = SaPackageRelease::query()->where('status', 'published')->orderByDesc('id')->limit(200)->get();
    foreach ($releases as $r) {
        $m = is_array($r->manifest) ? $r->manifest : [];
        if (($m['action_tag_key'] ?? null) === $tag || ($m['script_id'] ?? null) === $tag) {
            $package = SaPackage::query()->find($r->package_id);
            break;
        }
    }
}

$out['package_found'] = $package instanceof SaPackage;
$out['package_key'] = $package?->package_key;

if ($package instanceof SaPackage) {
    $ui = app(ScriptRegistryUiService::class);
    $detail = $ui->scriptDetail((string) $package->package_key);
    $mapped = is_array($detail) ? StaffScriptRegistryController::mapDetail($detail) : null;
    $out['mapped_ok'] = is_array($mapped);

    $v1 = null;
    foreach (($mapped['releases'] ?? []) as $rel) {
        if (! is_array($rel)) {
            continue;
        }
        if ((string) ($rel['version'] ?? '') === '1' || (string) ($rel['version'] ?? '') === '1.0.0') {
            $v1 = $rel;
            break;
        }
    }
    // Prefer exact published pin from action tag if present
    if ($link && is_string($link->script_version) && $link->script_version !== '') {
        foreach (($mapped['releases'] ?? []) as $rel) {
            if (is_array($rel) && (string) ($rel['version'] ?? '') === (string) $link->script_version) {
                $v1 = $rel;
                break;
            }
        }
    }

    $out['script'] = [
        'package_key' => $mapped['package_key'] ?? null,
        'script_id' => $mapped['script_id'] ?? null,
        'version' => $mapped['version'] ?? null,
        'release_id' => $mapped['release_id'] ?? null,
        'status' => $mapped['status'] ?? null,
        'release_count' => is_array($mapped['releases'] ?? null) ? count($mapped['releases']) : 0,
    ];
    $out['target_release'] = $v1 ? [
        'id' => $v1['id'] ?? null,
        'version' => $v1['version'] ?? null,
        'status' => $v1['status'] ?? null,
        'variables_status' => $v1['variables_status'] ?? null,
        'variables_errors' => $v1['variables_errors'] ?? [],
        'variable_count' => $v1['variable_count'] ?? null,
        'variables' => $v1['variables'] ?? [],
        'has_assets_field' => array_key_exists('assets', $v1),
        'has_steps_field' => array_key_exists('steps', $v1),
        'has_video_demo_field' => array_key_exists('video_demo', $v1),
        'content_digest_present' => isset($v1['content_digest']) && $v1['content_digest'] !== '',
    ] : null;

    $title = null;
    foreach (($v1['variables'] ?? []) as $var) {
        if (is_array($var) && ($var['key'] ?? null) === 'title') {
            $title = $var;
            break;
        }
    }
    $out['title_variable'] = $title;
    $json = json_encode($mapped, JSON_THROW_ON_ERROR);
    $out['leak_checks'] = [
        'has_password' => stripos($json, 'password') !== false,
        'has_internal_path' => str_contains($json, '/internal/'),
        'has_storage_path_field' => str_contains($json, '"storage_path"'),
    ];
}

$campaignUuid = '13f41a46-aefb-4343-b495-3ef99b5176d6';
$c = $db->table('sa_campaigns')->where('campaign_uuid', $campaignUuid)->first();
$out['campaign'] = [
    'test_wp_paused' => $c && (string) $c->status === 'paused',
    'running' => (int) $db->table('sa_campaigns')->where('status', 'running')->count(),
    'campaign_jobs' => (int) $db->table('sa_jobs')->whereNotNull('campaign_id')->count(),
    'shortcode_ready' => SocialAutomationSchema::campaignShortcodeReady(),
];

$provider = (string) file_get_contents('/www/wwwroot/seo-ops-addons/social-automation/src/SocialAutomationServiceProvider.php');
$cron = trim((string) shell_exec('crontab -l 2>/dev/null || true'));
$out['scheduler'] = [
    'registerScheduleTick_once' => substr_count($provider, 'function registerScheduleTick') === 1,
    'crontab_schedule_run_every_minute' => (bool) preg_match('/^\*\s+\*\s+\*\s+\*\s+\*.*schedule:run/m', $cron),
];

$out['autoload'] = [
    'CampaignScriptVariableSchema' => class_exists(\Omnichannel\Addons\SocialAutomation\Domain\CampaignScriptVariableSchema::class),
    'StaffScriptRegistryController' => class_exists(StaffScriptRegistryController::class),
    'has_projectReleaseVariables' => method_exists(StaffScriptRegistryController::class, 'projectReleaseVariables'),
];

$out['pass'] = ($out['package_found'] ?? false)
    && ($out['target_release']['variables_status'] ?? null) === 'ok'
    && is_array($out['title_variable'] ?? null)
    && ($out['title_variable']['type'] ?? null) === 'string'
    && ($out['title_variable']['required'] ?? null) === true
    && ($out['target_release']['has_assets_field'] ?? true) === false
    && ($out['leak_checks']['has_storage_path_field'] ?? true) === false
    && ($out['campaign']['test_wp_paused'] ?? false) === true
    && ($out['campaign']['running'] ?? -1) === 0
    && ($out['campaign']['campaign_jobs'] ?? -1) === 0
    && ($out['scheduler']['crontab_schedule_run_every_minute'] ?? false) === true
    && ($out['autoload']['has_projectReleaseVariables'] ?? false) === true;

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($out['pass'] ? 0 : 1);
