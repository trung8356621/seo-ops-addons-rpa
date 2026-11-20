<?php

declare(strict_types=1);

/**
 * DIAGNOSTIC with outer transaction ROLLBACK.
 * Calls dispatchCampaign once inside an uncommitted outer transaction, then rolls back.
 * Verifies no residual mutations. Does not claim jobs / start / tick.
 */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContent;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Orchestration\CampaignOrchestrator;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceResolver;
use Throwable;

$uuid = 'cf0d8be1-5475-4c84-a788-600a6a8ae17f';
$conn = SocialAutomationServiceConfig::CONNECTION;
$db = DB::connection($conn);

$out = [
    'probe' => 'dispatch_rollback_diag',
    'campaign_uuid' => $uuid,
    'intended_commit' => false,
];

$resolver = app(SocialAutomationServiceResolver::class);
$out['service_is_active'] = $resolver->isActive();
$out['service_row'] = [
    'exists' => $resolver->service() !== null,
    'is_active' => $resolver->service()?->is_active,
];

$campaign = SaCampaign::query()->where('campaign_uuid', $uuid)->first();
if (! $campaign instanceof SaCampaign) {
    $out['error'] = 'campaign_not_found';
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(1);
}

$beforeContents = SaCampaignContent::query()
    ->where('campaign_id', $campaign->id)
    ->orderBy('id')
    ->get(['id', 'content_uuid', 'status', 'current_job_id', 'last_error_code', 'updated_at'])
    ->map(static fn ($c) => $c->toArray())
    ->all();
$beforeJobs = (int) SaJob::query()->where('campaign_id', $campaign->id)->count();
$beforeCampaign = [
    'status' => (string) $campaign->status,
    'next_recheck_at' => optional($campaign->next_recheck_at)?->toDateTimeString(),
    'updated_at' => optional($campaign->updated_at)?->toDateTimeString(),
];
$out['before'] = [
    'campaign' => $beforeCampaign,
    'contents' => $beforeContents,
    'jobs' => $beforeJobs,
];

$result = null;
$caught = null;
$mid = null;

$db->beginTransaction();
try {
    $orchestrator = app(CampaignOrchestrator::class);
    try {
        $result = $orchestrator->dispatchCampaign($campaign->fresh() ?? $campaign);
    } catch (Throwable $e) {
        $caught = [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile().':'.$e->getLine(),
            'trace_top' => array_slice(array_map(
                static fn ($f) => ($f['file'] ?? '?').':'.($f['line'] ?? '?').' '.($f['function'] ?? ''),
                $e->getTrace()
            ), 0, 12),
        ];
    }

    $mid = [
        'dispatch_result' => $result,
        'caught' => $caught,
        'contents' => SaCampaignContent::query()
            ->where('campaign_id', $campaign->id)
            ->orderBy('id')
            ->get(['id', 'content_uuid', 'status', 'current_job_id', 'last_error_code', 'last_error_message', 'updated_at'])
            ->map(static fn ($c) => $c->toArray())
            ->all(),
        'jobs' => SaJob::query()
            ->where('campaign_id', $campaign->id)
            ->get(['id', 'job_uuid', 'status', 'campaign_content_id', 'account_id', 'idempotency_key'])
            ->map(static fn ($j) => $j->toArray())
            ->all(),
        'campaign' => [
            'status' => (string) ($campaign->fresh()->status ?? ''),
            'next_recheck_at' => optional($campaign->fresh()?->next_recheck_at)?->toDateTimeString(),
        ],
    ];
} finally {
    $db->rollBack();
}

// Post-verify residual state
$afterContents = SaCampaignContent::query()
    ->where('campaign_id', $campaign->id)
    ->orderBy('id')
    ->get(['id', 'content_uuid', 'status', 'current_job_id', 'last_error_code', 'updated_at'])
    ->map(static fn ($c) => $c->toArray())
    ->all();
$afterJobs = (int) SaJob::query()->where('campaign_id', $campaign->id)->count();
$fresh = $campaign->fresh();
$afterCampaign = [
    'status' => (string) ($fresh->status ?? ''),
    'next_recheck_at' => optional($fresh?->next_recheck_at)?->toDateTimeString(),
    'updated_at' => optional($fresh?->updated_at)?->toDateTimeString(),
];

$out['during_uncommitted'] = $mid;
$out['after_rollback'] = [
    'campaign' => $afterCampaign,
    'contents' => $afterContents,
    'jobs' => $afterJobs,
];
$out['residual_mutation'] = json_encode($beforeContents) !== json_encode($afterContents)
    || $beforeJobs !== $afterJobs
    || json_encode($beforeCampaign) !== json_encode($afterCampaign);
$out['mutated'] = (bool) $out['residual_mutation'];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
