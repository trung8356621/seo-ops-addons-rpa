<?php

declare(strict_types=1);

/**
 * READ-ONLY step-through of dispatch private methods inside outer ROLLBACK.
 * Captures the real Throwable that CampaignOrchestrator::dispatchCampaign swallows.
 */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignContentStatus;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignExecutionMode;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignAccountAssignment;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContent;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Orchestration\CampaignOrchestrator;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

$uuid = 'cf0d8be1-5475-4c84-a788-600a6a8ae17f';
$conn = SocialAutomationServiceConfig::CONNECTION;
$db = DB::connection($conn);

$out = [
    'probe' => 'dispatch_step_exception_ro',
    'intended_commit' => false,
    'mutated' => false,
];

$campaign = SaCampaign::query()->where('campaign_uuid', $uuid)->first();
if (! $campaign instanceof SaCampaign) {
    $out['error'] = 'campaign_not_found';
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(1);
}

$beforeJobs = (int) SaJob::query()->where('campaign_id', $campaign->id)->count();
$beforeStatuses = SaCampaignContent::query()
    ->where('campaign_id', $campaign->id)
    ->pluck('status', 'id')
    ->all();

$orchestrator = app(CampaignOrchestrator::class);
$ref = new ReflectionClass($orchestrator);
$call = static function (string $method, array $args) use ($ref, $orchestrator) {
    $m = $ref->getMethod($method);
    $m->setAccessible(true);

    return $m->invokeArgs($orchestrator, $args);
};

$steps = [];
$db->beginTransaction();
try {
    $locked = SaCampaign::query()->where('id', $campaign->id)->lockForUpdate()->first();
    $mode = CampaignExecutionMode::tryFrom((string) $locked->execution_mode)
        ?? CampaignExecutionMode::OneAccountOneJob;

    $contents = SaCampaignContent::query()
        ->where('campaign_id', $locked->id)
        ->whereIn('status', [
            CampaignContentStatus::Pending->value,
            CampaignContentStatus::WaitingResources->value,
        ])
        ->orderBy('sort_order')
        ->orderBy('id')
        ->lockForUpdate()
        ->get();

    $steps['contents_locked'] = $contents->count();

    $assignedIds = SaCampaignAccountAssignment::query()
        ->where('campaign_id', $locked->id)
        ->pluck('account_id')
        ->all();
    $busyInCampaign = SaCampaignContent::query()
        ->where('campaign_id', $locked->id)
        ->whereIn('status', [
            CampaignContentStatus::Running->value,
            CampaignContentStatus::Unknown->value,
        ])
        ->whereNotNull('assigned_account_id')
        ->pluck('assigned_account_id')
        ->all();

    // Only first content — enough to surface reservation exception
    $content = $contents->first();
    $steps['first_content'] = [
        'id' => (int) $content->id,
        'uuid' => $content->content_uuid,
        'status' => $content->status,
    ];

    try {
        $requiredAccountId = $call('resolveRequiredAccountId', [$locked, $content]);
        $steps['required_account_id'] = $requiredAccountId;

        $blockedAssigned = $assignedIds;
        if ($mode === CampaignExecutionMode::OneAccountOneJob && $requiredAccountId !== null) {
            $blockedAssigned = array_values(array_filter(
                $assignedIds,
                static fn ($id): bool => (int) $id !== $requiredAccountId
            ));
        }

        $selection = $call('selectEligibleForCampaign', [
            $locked, $mode, $blockedAssigned, $busyInCampaign, 1, $requiredAccountId,
        ]);
        $steps['selection'] = [
            'selected_count' => count($selection['selected'] ?? []),
            'skip_reasons' => $selection['skip_reasons'] ?? [],
            'selected0' => ($selection['selected'][0] ?? null),
        ];

        if (($selection['selected'] ?? []) === []) {
            $steps['stop'] = 'no_selection';
        } else {
            $inputGate = $call('assertContentInputForDispatch', [$locked, $content]);
            $steps['input_gate'] = $inputGate;

            if (! ($inputGate['ok'] ?? false)) {
                $steps['stop'] = 'input_gate_failed';
            } else {
                $row = $selection['selected'][0];
                try {
                    $job = $call('reserveCampaignJob', [$locked, $content, $row, $mode]);
                    $steps['reserve'] = [
                        'job_null' => $job === null,
                        'job_id' => $job?->id,
                        'job_uuid' => $job?->job_uuid,
                        'job_status' => $job?->status,
                    ];
                } catch (Throwable $e) {
                    $steps['reserve_exception'] = [
                        'class' => $e::class,
                        'message' => $e->getMessage(),
                        'code' => $e->getCode(),
                        'file' => $e->getFile().':'.$e->getLine(),
                        'sql' => method_exists($e, 'getSql') ? $e->getSql() : null,
                        'bindings' => method_exists($e, 'getBindings') ? $e->getBindings() : null,
                        'trace_top' => array_slice(array_map(
                            static fn ($f) => ($f['class'] ?? '').($f['type'] ?? '').($f['function'] ?? '').' @ '.basename((string) ($f['file'] ?? '')).':'.($f['line'] ?? ''),
                            $e->getTrace()
                        ), 0, 15),
                    ];
                }
            }
        }
    } catch (Throwable $e) {
        $steps['pre_reserve_exception'] = [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile().':'.$e->getLine(),
        ];
    }
} finally {
    $db->rollBack();
}

$afterJobs = (int) SaJob::query()->where('campaign_id', $campaign->id)->count();
$afterStatuses = SaCampaignContent::query()
    ->where('campaign_id', $campaign->id)
    ->pluck('status', 'id')
    ->all();

$out['steps'] = $steps;
$out['residual_mutation'] = $beforeJobs !== $afterJobs || $beforeStatuses !== $afterStatuses;
$out['mutated'] = (bool) $out['residual_mutation'];
$out['after'] = ['jobs' => $afterJobs, 'statuses' => $afterStatuses];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
