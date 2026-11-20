<?php

declare(strict_types=1);

/**
 * READ-ONLY: Campaign Detail vs Content List discrepancy for a specific Campaign UUID.
 * No writes, no Start/Resume, no Job claim, no schedule tick.
 */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Services\CampaignService;

$db = DB::connection('omi_social_automation');
$uuid = 'cf0d8be1-5475-4c84-a788-600a6a8ae17f';

$out = [
    'probe' => 'campaign_detail_content_discrepancy_ro',
    'mutated' => false,
    'campaign_uuid' => $uuid,
];

$campaign = $db->table('sa_campaigns')->where('campaign_uuid', $uuid)->first();
if (! $campaign) {
    // Fallback by name
    $campaign = $db->table('sa_campaigns')->where('name', 'like', '%test wp 3%')->orderByDesc('id')->first();
}
$out['campaign_row'] = $campaign ? [
    'id' => (int) $campaign->id,
    'campaign_uuid' => $campaign->campaign_uuid,
    'name' => $campaign->name,
    'status' => $campaign->status,
    'installation_id' => $campaign->installation_id,
    'script_id' => $campaign->script_id,
    'script_version' => $campaign->script_version,
    'shortcode_mapping_version' => $campaign->shortcode_mapping_version ?? null,
    'shortcode_mapping' => isset($campaign->shortcode_mapping)
        ? json_decode((string) $campaign->shortcode_mapping, true)
        : null,
    'meta' => isset($campaign->meta) ? json_decode((string) $campaign->meta, true) : null,
] : null;

if (! $campaign) {
    $out['error'] = 'campaign_not_found';
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(1);
}

$cols = [
    'id', 'content_uuid', 'campaign_id', 'external_ref', 'sort_order', 'status',
    'assigned_account_id', 'current_job_id', 'last_error_code', 'last_error_message',
    'created_at', 'updated_at',
];
foreach (['source_format', 'raw_source', 'mapping_version', 'input'] as $c) {
    if (Schema::connection('omi_social_automation')->hasColumn('sa_campaign_contents', $c)) {
        $cols[] = $c;
    }
}

$rows = $db->table('sa_campaign_contents')
    ->where('campaign_id', $campaign->id)
    ->orderBy('sort_order')
    ->orderBy('id')
    ->get($cols);

$contentRows = [];
foreach ($rows as $r) {
    $input = null;
    if (isset($r->input)) {
        $input = is_string($r->input) ? json_decode($r->input, true) : $r->input;
    }
    $contentRows[] = [
        'id' => (int) $r->id,
        'content_uuid' => $r->content_uuid,
        'external_ref' => $r->external_ref,
        'sort_order' => (int) $r->sort_order,
        'status' => $r->status,
        'source_format' => $r->source_format ?? null,
        'mapping_version' => isset($r->mapping_version) ? (int) $r->mapping_version : null,
        'raw_source_len' => isset($r->raw_source) && $r->raw_source !== null ? strlen((string) $r->raw_source) : 0,
        'raw_source_preview' => isset($r->raw_source) && is_string($r->raw_source)
            ? substr($r->raw_source, 0, 120)
            : null,
        'input_keys' => is_array($input) ? array_keys($input) : [],
        'input' => $input,
        'assigned_account_id' => $r->assigned_account_id,
        'current_job_id' => $r->current_job_id,
        'last_error_code' => $r->last_error_code,
        'last_error_message' => $r->last_error_message,
    ];
}

$statusCounts = [];
foreach ($contentRows as $cr) {
    $s = (string) $cr['status'];
    $statusCounts[$s] = ($statusCounts[$s] ?? 0) + 1;
}

$out['db_ground_truth'] = [
    'content_count' => count($contentRows),
    'status_counts' => $statusCounts,
    'contents' => $contentRows,
];

// Service DTO projections (same as Staff API)
$svc = app(CampaignService::class);
$installationId = (string) $campaign->installation_id;
$detail = $svc->detail((string) $campaign->campaign_uuid, $installationId);
$list = $svc->listContents((string) $campaign->campaign_uuid, $installationId);

$out['service_detail'] = $detail;
$out['service_list_contents'] = $list;

$out['comparison'] = [
    'detail_summary' => is_array($detail) ? ($detail['summary'] ?? null) : null,
    'detail_contents_count' => is_array($detail) && isset($detail['contents']) && is_array($detail['contents'])
        ? count($detail['contents'])
        : null,
    'detail_has_contents_key' => is_array($detail) && array_key_exists('contents', $detail),
    'list_ok' => (bool) ($list['ok'] ?? false),
    'list_count' => is_array($list['data'] ?? null) ? count($list['data']) : null,
    'db_count' => count($contentRows),
    'waiting_vs_pending_db' => [
        'waiting_resources' => $statusCounts['waiting_resources'] ?? 0,
        'pending' => $statusCounts['pending'] ?? 0,
    ],
    'summary_waiting_formula_note' => 'summaryDto waiting_contents = pending + waiting_resources; detailDto summary keeps them separate',
];

// Code ownership hints from source on VPS
$svcSrc = (string) file_get_contents('/www/wwwroot/seo-ops-addons/social-automation/src/Services/CampaignService.php');
$out['code_hints'] = [
    'detailDto_includes_contents' => str_contains($svcSrc, "'contents' => \$contents"),
    'summaryDto_waiting_formula' => str_contains($svcSrc, "'waiting_contents' => \$counts['pending'] + \$counts['waiting_resources']"),
    'contentDto_exists' => str_contains($svcSrc, 'function contentDto'),
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
