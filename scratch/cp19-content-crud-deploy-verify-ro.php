<?php

declare(strict_types=1);

/** READ-ONLY post-deploy Content CRUD verify. No POST/PUT invoke. No DB writes. */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\StaffCampaignContentController;
use Omnichannel\Addons\SocialAutomation\Services\CampaignService;

$addon = '/www/wwwroot/seo-ops-addons/social-automation';
$now = now();
$db = DB::connection('omi_social_automation');
$uuid = '13f41a46-aefb-4343-b495-3ef99b5176d6';
$c = $db->table('sa_campaigns')->where('campaign_uuid', $uuid)->first();
$contents = $c
    ? $db->table('sa_campaign_contents')->where('campaign_id', $c->id)->orderBy('id')->get(['id', 'content_uuid', 'status', 'external_ref', 'assigned_account_id'])
    : collect();
$jobCount = $c ? (int) $db->table('sa_jobs')->where('campaign_id', $c->id)->count() : 0;
$running = (int) $db->table('sa_campaigns')->where('status', 'running')->count();

$wanted = [
    'social-automation.campaigns.contents.index' => 'GET',
    'social-automation.campaigns.contents.store' => 'POST',
    'social-automation.campaigns.contents.update' => 'PUT',
    'social-automation.campaign-contents.retry' => 'POST',
    'social-automation.campaign-contents.skip' => 'POST',
    'social-automation.campaign-contents.cancel' => 'POST',
];
$routeHits = [];
foreach (Route::getRoutes() as $r) {
    $name = (string) $r->getName();
    if ($name !== '' && isset($wanted[$name])) {
        $routeHits[$name] = [
            'uri' => $r->uri(),
            'methods' => $r->methods(),
            'action' => $r->getActionName(),
        ];
    }
}

$openapi = (string) file_get_contents($addon.'/openapi/openapi.yaml');
$svc = (string) file_get_contents($addon.'/src/Services/CampaignService.php');
$ctrl = (string) file_get_contents($addon.'/src/Http/Controllers/StaffCampaignContentController.php');

$pass = [
    'routes_get' => isset($routeHits['social-automation.campaigns.contents.index']),
    'routes_post' => isset($routeHits['social-automation.campaigns.contents.store']),
    'routes_put' => isset($routeHits['social-automation.campaigns.contents.update']),
    'admin_actions_preserved' => isset($routeHits['social-automation.campaign-contents.retry'])
        && isset($routeHits['social-automation.campaign-contents.skip'])
        && isset($routeHits['social-automation.campaign-contents.cancel']),
    'openapi_create' => str_contains($openapi, 'staffCampaignContentsCreate'),
    'openapi_list' => str_contains($openapi, 'staffCampaignContentsList'),
    'openapi_update' => str_contains($openapi, 'staffCampaignContentsUpdate'),
    'service_methods' => str_contains($svc, 'function createContent')
        && str_contains($svc, 'function listContents')
        && str_contains($svc, 'function updateContent'),
    'controller_methods' => str_contains($ctrl, 'function store')
        && str_contains($ctrl, 'function index')
        && str_contains($ctrl, 'function update'),
    'campaign_paused' => $c && (string) $c->status === 'paused',
    'content_preserved' => $contents->count() === 1,
    'no_new_jobs' => $jobCount === 0,
    'no_running' => $running === 0,
    'loaded_service' => str_ends_with((string) (new ReflectionClass(CampaignService::class))->getFileName(), 'CampaignService.php'),
    'loaded_controller' => str_ends_with((string) (new ReflectionClass(StaffCampaignContentController::class))->getFileName(), 'StaffCampaignContentController.php'),
];

echo json_encode([
    'probe' => 'content_crud_deploy_verify_ro',
    'now' => $now->toIso8601String(),
    'mutated' => false,
    'campaign' => $c ? [
        'uuid' => $c->campaign_uuid,
        'status' => $c->status,
        'updated_at' => $c->updated_at ?? null,
    ] : null,
    'contents' => $contents,
    'job_count' => $jobCount,
    'running_campaigns' => $running,
    'routes' => $routeHits,
    'pass' => $pass,
    'all_pass' => ! in_array(false, $pass, true),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
