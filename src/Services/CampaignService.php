<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignInputSanitizer;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignRecheckPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignTestScriptPolicy;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignContentStatus;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignExecutionMode;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignRunMode;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignStatus;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContent;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Orchestration\CampaignOrchestrator;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use Throwable;

final class CampaignService
{
    public function __construct(
        private readonly CampaignOrchestrator $orchestrator = new CampaignOrchestrator,
        private readonly CampaignInputSanitizer $sanitizer = new CampaignInputSanitizer,
        private readonly CampaignRecheckPolicy $recheck = new CampaignRecheckPolicy,
        private readonly CampaignTestScriptPolicy $testScripts = new CampaignTestScriptPolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, data?: array<string, mixed>, errors?: list<string>, limitations?: list<string>}
     */
    public function create(array $input, string $installationId, ?int $userId): array
    {
        if (! SocialAutomationSchema::campaignsReady()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }

        $name = trim((string) ($input['name'] ?? ''));
        $platform = trim((string) ($input['platform'] ?? ''));
        $mode = CampaignExecutionMode::tryFromInput($input['execution_mode'] ?? null);
        $runMode = CampaignRunMode::tryFromInput($input['run_mode'] ?? null);
        $scriptId = trim((string) ($input['script_id'] ?? ''));
        $recheckSeconds = $this->recheck->normalizeInterval((int) ($input['resource_recheck_seconds'] ?? CampaignRecheckPolicy::DEFAULT_INTERVAL_SECONDS));
        $contents = $input['contents'] ?? null;

        if ($name === '' || $platform === '' || $mode === null || $runMode === null || $scriptId === '') {
            return ['ok' => false, 'errors' => ['validation_failed']];
        }
        if (! is_array($contents) || $contents === []) {
            return ['ok' => false, 'errors' => ['contents_required']];
        }

        $script = $this->resolveScriptPin($scriptId, isset($input['script_version']) ? (string) $input['script_version'] : null);
        if ($script === null) {
            return ['ok' => false, 'errors' => ['script_not_found']];
        }

        $manifest = is_array($script['manifest'] ?? null) ? $script['manifest'] : [];
        $testGate = null;
        if ($runMode->requiresTestCompatibleScript()) {
            $testGate = $this->testScripts->assertCompatibleForTestMode($manifest);
            if (! ($testGate['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'errors' => $testGate['errors'] ?? ['script_not_test_compatible'],
                    'limitations' => $testGate['limitations'] ?? [],
                ];
            }
        }

        $variableNames = $this->scriptVariableNames($manifest);
        $prepared = [];
        foreach ($contents as $i => $row) {
            if (! is_array($row)) {
                return ['ok' => false, 'errors' => ['content_invalid:'.$i]];
            }
            $externalRef = trim((string) ($row['external_ref'] ?? ''));
            $rawInput = is_array($row['input'] ?? null) ? $row['input'] : [];
            $sanitized = $this->sanitizer->sanitize($rawInput, $variableNames);
            if (! ($sanitized['ok'] ?? false)) {
                return ['ok' => false, 'errors' => $sanitized['errors'] ?? ['content_input_invalid']];
            }
            $prepared[] = [
                'external_ref' => $externalRef !== '' ? $externalRef : null,
                'input' => $sanitized['input'] ?? [],
                'sort_order' => $i,
            ];
        }

        try {
            return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use (
                $name, $platform, $mode, $runMode, $script, $recheckSeconds, $prepared, $installationId, $userId, $testGate, $manifest
            ): array {
                $meta = [];
                if (is_array($testGate)) {
                    $meta['test_script_gate'] = [
                        'terminal_action' => $testGate['terminal_action'] ?? null,
                        'stops_before_publish' => (bool) ($testGate['stops_before_publish'] ?? false),
                        'limitations' => $testGate['limitations'] ?? [],
                    ];
                }
                $campaign = SaCampaign::query()->create([
                    'campaign_uuid' => (string) Str::uuid(),
                    'installation_id' => $installationId,
                    'name' => $name,
                    'platform' => $platform,
                    'execution_mode' => $mode->value,
                    'run_mode' => $runMode->value,
                    'status' => CampaignStatus::Draft->value,
                    'script_id' => $script['script_id'],
                    'script_version' => $script['version'],
                    'script_content_digest' => $script['content_digest'],
                    'resource_recheck_seconds' => $recheckSeconds,
                    'created_by_user_id' => $userId,
                    'meta' => $meta !== [] ? $meta : null,
                ]);

                foreach ($prepared as $row) {
                    SaCampaignContent::query()->create([
                        'content_uuid' => (string) Str::uuid(),
                        'campaign_id' => $campaign->id,
                        'external_ref' => $row['external_ref'],
                        'sort_order' => $row['sort_order'],
                        'status' => CampaignContentStatus::Pending->value,
                        'input' => $row['input'],
                    ]);
                }

                unset($manifest);

                return ['ok' => true, 'data' => $this->summaryDto($campaign->fresh() ?? $campaign)];
            });
        } catch (Throwable) {
            return ['ok' => false, 'errors' => ['create_failed']];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $installationId, int $limit = 100): array
    {
        if (! SocialAutomationSchema::campaignsReady()) {
            return [];
        }

        return SaCampaign::query()
            ->where('installation_id', $installationId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (SaCampaign $c): array => $this->summaryDto($c))
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detail(string $campaignUuid, string $installationId): ?array
    {
        if (! SocialAutomationSchema::campaignsReady()) {
            return null;
        }
        $campaign = SaCampaign::query()
            ->where('campaign_uuid', $campaignUuid)
            ->where('installation_id', $installationId)
            ->first();
        if (! $campaign instanceof SaCampaign) {
            return null;
        }

        return $this->detailDto($campaign);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, data?: array<string, mixed>, errors?: list<string>}
     */
    public function update(string $campaignUuid, string $installationId, array $input): array
    {
        $campaign = $this->findOwned($campaignUuid, $installationId);
        if ($campaign === null) {
            return ['ok' => false, 'errors' => ['campaign_not_found']];
        }
        if ((string) $campaign->status !== CampaignStatus::Draft->value) {
            // After start: only name is mutable; execution_mode/script immutable.
            if (isset($input['execution_mode']) || isset($input['script_id']) || isset($input['contents'])) {
                return ['ok' => false, 'errors' => ['campaign_immutable_after_start']];
            }
            if (isset($input['name'])) {
                $campaign->name = trim((string) $input['name']);
                $campaign->save();
            }
            if (isset($input['resource_recheck_seconds'])) {
                $campaign->resource_recheck_seconds = $this->recheck->normalizeInterval((int) $input['resource_recheck_seconds']);
                $campaign->save();
            }

            return ['ok' => true, 'data' => $this->summaryDto($campaign)];
        }

        if (isset($input['name'])) {
            $campaign->name = trim((string) $input['name']);
        }
        if (isset($input['platform'])) {
            $campaign->platform = trim((string) $input['platform']);
        }
        if (isset($input['execution_mode'])) {
            $mode = CampaignExecutionMode::tryFromInput($input['execution_mode']);
            if ($mode === null) {
                return ['ok' => false, 'errors' => ['invalid_execution_mode']];
            }
            $campaign->execution_mode = $mode->value;
        }
        if (isset($input['resource_recheck_seconds'])) {
            $campaign->resource_recheck_seconds = $this->recheck->normalizeInterval((int) $input['resource_recheck_seconds']);
        }
        $campaign->save();

        return ['ok' => true, 'data' => $this->summaryDto($campaign)];
    }

    /**
     * @return array{ok: bool, data?: array<string, mixed>, errors?: list<string>}
     */
    public function start(string $campaignUuid, string $installationId): array
    {
        $campaign = $this->findOwned($campaignUuid, $installationId);
        if ($campaign === null) {
            return ['ok' => false, 'errors' => ['campaign_not_found']];
        }
        $status = CampaignStatus::tryFrom((string) $campaign->status);
        if ($status !== CampaignStatus::Draft && $status !== CampaignStatus::Paused) {
            if ($status === CampaignStatus::Running) {
                return ['ok' => true, 'data' => $this->detailDto($campaign)];
            }

            return ['ok' => false, 'errors' => ['invalid_campaign_status']];
        }

        $campaign->status = CampaignStatus::Running->value;
        $campaign->started_at = $campaign->started_at ?? now();
        $campaign->paused_at = null;
        $campaign->next_recheck_at = now();
        $campaign->save();

        $this->orchestrator->dispatchCampaign($campaign);

        return ['ok' => true, 'data' => $this->detailDto($campaign->fresh() ?? $campaign)];
    }

    /**
     * @return array{ok: bool, data?: array<string, mixed>, errors?: list<string>}
     */
    public function pause(string $campaignUuid, string $installationId): array
    {
        $campaign = $this->findOwned($campaignUuid, $installationId);
        if ($campaign === null) {
            return ['ok' => false, 'errors' => ['campaign_not_found']];
        }
        if ((string) $campaign->status !== CampaignStatus::Running->value) {
            return ['ok' => false, 'errors' => ['invalid_campaign_status']];
        }
        $campaign->status = CampaignStatus::Paused->value;
        $campaign->paused_at = now();
        $campaign->next_recheck_at = null;
        $campaign->save();

        return ['ok' => true, 'data' => $this->detailDto($campaign)];
    }

    /**
     * @return array{ok: bool, data?: array<string, mixed>, errors?: list<string>}
     */
    public function resume(string $campaignUuid, string $installationId): array
    {
        $campaign = $this->findOwned($campaignUuid, $installationId);
        if ($campaign === null) {
            return ['ok' => false, 'errors' => ['campaign_not_found']];
        }
        if ((string) $campaign->status !== CampaignStatus::Paused->value) {
            return ['ok' => false, 'errors' => ['invalid_campaign_status']];
        }
        $campaign->status = CampaignStatus::Running->value;
        $campaign->paused_at = null;
        $campaign->next_recheck_at = now();
        $campaign->save();
        $this->orchestrator->dispatchCampaign($campaign);

        return ['ok' => true, 'data' => $this->detailDto($campaign->fresh() ?? $campaign)];
    }

    /**
     * @return array{ok: bool, data?: array<string, mixed>, errors?: list<string>}
     */
    public function cancel(string $campaignUuid, string $installationId): array
    {
        $campaign = $this->findOwned($campaignUuid, $installationId);
        if ($campaign === null) {
            return ['ok' => false, 'errors' => ['campaign_not_found']];
        }
        if (CampaignStatus::tryFrom((string) $campaign->status)?->isTerminal()) {
            return ['ok' => false, 'errors' => ['campaign_already_terminal']];
        }

        return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($campaign): array {
            $locked = SaCampaign::query()->where('id', $campaign->id)->lockForUpdate()->first();
            if (! $locked instanceof SaCampaign) {
                return ['ok' => false, 'errors' => ['campaign_not_found']];
            }
            $locked->status = CampaignStatus::Cancelled->value;
            $locked->cancelled_at = now();
            $locked->next_recheck_at = null;
            $locked->save();

            SaCampaignContent::query()
                ->where('campaign_id', $locked->id)
                ->whereIn('status', [
                    CampaignContentStatus::Pending->value,
                    CampaignContentStatus::WaitingResources->value,
                ])
                ->update(['status' => CampaignContentStatus::Cancelled->value]);

            return ['ok' => true, 'data' => $this->detailDto($locked)];
        });
    }

    public function findContent(string $contentUuid, string $installationId): ?SaCampaignContent
    {
        if (! SocialAutomationSchema::campaignsReady()) {
            return null;
        }
        $content = SaCampaignContent::query()->where('content_uuid', $contentUuid)->first();
        if (! $content instanceof SaCampaignContent) {
            return null;
        }
        $campaign = SaCampaign::query()->find($content->campaign_id);
        if (! $campaign instanceof SaCampaign || (string) $campaign->installation_id !== $installationId) {
            return null;
        }

        return $content;
    }

    private function findOwned(string $campaignUuid, string $installationId): ?SaCampaign
    {
        if (! SocialAutomationSchema::campaignsReady()) {
            return null;
        }
        $campaign = SaCampaign::query()
            ->where('campaign_uuid', $campaignUuid)
            ->where('installation_id', $installationId)
            ->first();

        return $campaign instanceof SaCampaign ? $campaign : null;
    }

    /**
     * @return array{script_id: string, version: string, content_digest: string|null, manifest: array<string, mixed>}|null
     */
    private function resolveScriptPin(string $scriptId, ?string $version): ?array
    {
        $package = SaPackage::query()->where('package_key', $scriptId)->first();
        if (! $package instanceof SaPackage) {
            // Fallback: scan manifests for script_id
            $releases = SaPackageRelease::query()->orderByDesc('id')->limit(200)->get();
            foreach ($releases as $release) {
                $manifest = is_array($release->manifest) ? $release->manifest : [];
                $mid = trim((string) ($manifest['script_id'] ?? ''));
                if ($mid !== '' && $mid === $scriptId) {
                    $package = SaPackage::query()->find($release->package_id);
                    if ($package instanceof SaPackage && ($version === null || $version === '' || (string) $release->version === $version)) {
                        return [
                            'script_id' => $package->package_key,
                            'version' => (string) $release->version,
                            'content_digest' => $release->content_digest,
                            'manifest' => $manifest,
                        ];
                    }
                }
            }

            return null;
        }

        $q = SaPackageRelease::query()->where('package_id', $package->id);
        if ($version !== null && $version !== '') {
            $q->where('version', $version);
        } else {
            $q->orderByDesc('id');
        }
        $release = $q->first();
        if (! $release instanceof SaPackageRelease) {
            return null;
        }

        return [
            'script_id' => $package->package_key,
            'version' => (string) $release->version,
            'content_digest' => $release->content_digest,
            'manifest' => is_array($release->manifest) ? $release->manifest : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function scriptVariableNames(array $manifest): array
    {
        $vars = $manifest['variables'] ?? [];
        if (! is_array($vars)) {
            return [];
        }
        $names = [];
        foreach ($vars as $var) {
            if (is_string($var)) {
                $names[] = $var;
                continue;
            }
            if (is_array($var)) {
                $n = trim((string) ($var['name'] ?? $var['key'] ?? ''));
                if ($n !== '') {
                    $names[] = $n;
                }
            }
        }

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryDto(SaCampaign $campaign): array
    {
        $counts = $this->contentCounts($campaign);

        return [
            'id' => $campaign->campaign_uuid,
            'name' => $campaign->name,
            'platform' => $campaign->platform,
            'execution_mode' => $campaign->execution_mode,
            'run_mode' => $campaign->run_mode ?: CampaignRunMode::Live->value,
            'status' => $campaign->status,
            'script_id' => $campaign->script_id,
            'script_version' => $campaign->script_version,
            'resource_recheck_seconds' => (int) $campaign->resource_recheck_seconds,
            'total_contents' => $counts['total'],
            'completed_contents' => $counts['success'],
            'failed_contents' => $counts['failed_locked'],
            'waiting_contents' => $counts['pending'] + $counts['waiting_resources'],
            'next_recheck_at' => $campaign->next_recheck_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detailDto(SaCampaign $campaign): array
    {
        $counts = $this->contentCounts($campaign);
        $contents = SaCampaignContent::query()
            ->where('campaign_id', $campaign->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static function (SaCampaignContent $c): array {
                return [
                    'id' => $c->content_uuid,
                    'external_ref' => $c->external_ref,
                    'status' => $c->status,
                    'assigned_account_id' => $c->assigned_account_id,
                    'last_error_code' => $c->last_error_code,
                    'last_error_message' => $c->last_error_message,
                    'input' => is_array($c->input) ? $c->input : [],
                ];
            })->all();

        return [
            'id' => $campaign->campaign_uuid,
            'name' => $campaign->name,
            'platform' => $campaign->platform,
            'status' => $campaign->status,
            'execution_mode' => $campaign->execution_mode,
            'run_mode' => $campaign->run_mode ?: CampaignRunMode::Live->value,
            'script_id' => $campaign->script_id,
            'script_version' => $campaign->script_version,
            'resource_recheck_seconds' => (int) $campaign->resource_recheck_seconds,
            'summary' => [
                'total' => $counts['total'],
                'pending' => $counts['pending'],
                'waiting_resources' => $counts['waiting_resources'],
                'running' => $counts['running'],
                'success' => $counts['success'],
                'failed_locked' => $counts['failed_locked'],
                'unknown' => $counts['unknown'],
                'skipped' => $counts['skipped'],
                'cancelled' => $counts['cancelled'],
            ],
            'next_recheck_at' => $campaign->next_recheck_at?->toIso8601String(),
            'contents' => $contents,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function contentCounts(SaCampaign $campaign): array
    {
        $base = [
            'total' => 0,
            'pending' => 0,
            'waiting_resources' => 0,
            'running' => 0,
            'success' => 0,
            'failed_locked' => 0,
            'unknown' => 0,
            'skipped' => 0,
            'cancelled' => 0,
        ];
        if (! SocialAutomationSchema::campaignsReady()) {
            return $base;
        }
        $rows = SaCampaignContent::query()
            ->where('campaign_id', $campaign->id)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->get();
        foreach ($rows as $row) {
            $status = (string) $row->status;
            $c = (int) $row->c;
            $base['total'] += $c;
            if (array_key_exists($status, $base)) {
                $base[$status] = $c;
            }
        }

        return $base;
    }
}
