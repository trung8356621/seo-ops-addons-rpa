<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignInputSanitizer;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignRecheckPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignScriptVariableSchema;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodeMappingValidator;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodeMockGenerator;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodePipeline;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignTestScriptPolicy;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignContentStatus;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignExecutionMode;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignRunMode;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignStatus;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTagScript;
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
        private readonly CampaignShortcodePipeline $shortcodePipeline = new CampaignShortcodePipeline,
        private readonly CampaignShortcodeMappingValidator $mappingValidator = new CampaignShortcodeMappingValidator,
        private readonly CampaignScriptVariableSchema $variableSchema = new CampaignScriptVariableSchema,
        private readonly CampaignShortcodeMockGenerator $mockGenerator = new CampaignShortcodeMockGenerator,
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
        $contents = $input['contents'] ?? [];

        if ($name === '' || $platform === '' || $mode === null || $runMode === null || $scriptId === '') {
            return ['ok' => false, 'errors' => ['validation_failed']];
        }
        // Zero contents allowed (Shortcode V2). Contents may be added later before Start.
        if (! is_array($contents)) {
            return ['ok' => false, 'errors' => ['contents_invalid']];
        }

        $script = $this->resolveScriptPin($scriptId, isset($input['script_version']) ? (string) $input['script_version'] : null);
        if ($script === null) {
            return ['ok' => false, 'errors' => ['script_not_found']];
        }

        $pinnedAccount = null;
        $accountUuid = trim((string) ($input['account_id'] ?? ''));
        if ($accountUuid !== '') {
            $pinnedAccount = $this->resolvePinnedAccount($accountUuid, $installationId, $platform);
            if ($pinnedAccount === null) {
                return ['ok' => false, 'errors' => ['account_not_found_or_platform_mismatch']];
            }
        }

        $manifest = is_array($script['manifest'] ?? null) ? $script['manifest'] : [];
        $schema = $this->variableSchema->fromManifest($manifest);
        if (! ($schema['ok'] ?? false)) {
            return ['ok' => false, 'errors' => $schema['errors'] ?? ['script_variables_invalid']];
        }
        /** @var array<string, array<string, mixed>> $variables */
        $variables = $schema['variables'] ?? [];

        $mappingDoc = null;
        $mappingVersion = 0;
        if (array_key_exists('shortcode_mapping', $input)) {
            if (! SocialAutomationSchema::campaignShortcodeReady()) {
                return ['ok' => false, 'errors' => ['schema_unavailable']];
            }
            if (! is_array($input['shortcode_mapping'])) {
                return ['ok' => false, 'errors' => ['shortcode_mapping_invalid']];
            }
            $mapped = $this->mappingValidator->validate($input['shortcode_mapping'], $variables);
            if (! ($mapped['ok'] ?? false)) {
                return ['ok' => false, 'errors' => $mapped['errors'] ?? ['shortcode_mapping_invalid']];
            }
            $mappingDoc = $mapped['mapping'] ?? null;
            $mappingVersion = 1;
        }

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

        $variableNames = array_keys($variables);
        $prepared = [];
        foreach ($contents as $i => $row) {
            if (! is_array($row)) {
                return ['ok' => false, 'errors' => ['content_invalid:'.$i]];
            }
            $built = $this->buildContentFromRequestRow(
                $row,
                $variables,
                $variableNames,
                $mappingDoc,
                $mappingVersion,
                $pinnedAccount,
                $installationId,
                $platform,
                $i,
            );
            if (! ($built['ok'] ?? false)) {
                return ['ok' => false, 'errors' => $built['errors'] ?? ['content_invalid:'.$i]];
            }
            $prepared[] = $built;
        }

        try {
            return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use (
                $name, $platform, $mode, $runMode, $script, $recheckSeconds, $prepared, $installationId, $userId, $testGate, $pinnedAccount, $mappingDoc, $mappingVersion
            ): array {
                $meta = [];
                if (is_array($testGate)) {
                    $meta['test_script_gate'] = [
                        'terminal_action' => $testGate['terminal_action'] ?? null,
                        'stops_before_publish' => (bool) ($testGate['stops_before_publish'] ?? false),
                        'limitations' => $testGate['limitations'] ?? [],
                    ];
                }
                if ($pinnedAccount instanceof SaAccount) {
                    $meta['pinned_account_id'] = $pinnedAccount->account_uuid;
                }
                $attrs = [
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
                ];
                if (SocialAutomationSchema::campaignShortcodeReady()) {
                    $attrs['shortcode_mapping'] = $mappingDoc;
                    $attrs['shortcode_mapping_version'] = $mappingVersion;
                }
                $campaign = SaCampaign::query()->create($attrs);

                foreach ($prepared as $row) {
                    $contentAttrs = [
                        'content_uuid' => (string) Str::uuid(),
                        'campaign_id' => $campaign->id,
                        'external_ref' => $row['external_ref'],
                        'sort_order' => $row['sort_order'],
                        'status' => CampaignContentStatus::Pending->value,
                        'input' => $row['input'],
                        'assigned_account_id' => $row['assigned_account_id'],
                    ];
                    if (SocialAutomationSchema::campaignShortcodeReady()) {
                        $contentAttrs['source_format'] = $row['source_format'] ?? 'legacy_input';
                        $contentAttrs['raw_source'] = $row['raw_source'] ?? null;
                        $contentAttrs['mapping_version'] = $row['mapping_version'] ?? null;
                    }
                    SaCampaignContent::query()->create($contentAttrs);
                }

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
            // After start: only name is mutable; execution_mode/script/mapping immutable.
            if (isset($input['execution_mode']) || isset($input['script_id']) || isset($input['contents']) || isset($input['shortcode_mapping'])) {
                return ['ok' => false, 'errors' => isset($input['shortcode_mapping'])
                    ? ['mapping_immutable_after_start']
                    : ['campaign_immutable_after_start']];
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
        if (isset($input['shortcode_mapping'])) {
            if (! SocialAutomationSchema::campaignShortcodeReady()) {
                return ['ok' => false, 'errors' => ['schema_unavailable']];
            }
            if (! is_array($input['shortcode_mapping'])) {
                return ['ok' => false, 'errors' => ['shortcode_mapping_invalid']];
            }
            $script = $this->resolveScriptPin((string) $campaign->script_id, (string) $campaign->script_version);
            if ($script === null) {
                return ['ok' => false, 'errors' => ['script_not_found']];
            }
            $manifest = is_array($script['manifest'] ?? null) ? $script['manifest'] : [];
            $schema = $this->variableSchema->fromManifest($manifest);
            if (! ($schema['ok'] ?? false)) {
                return ['ok' => false, 'errors' => $schema['errors'] ?? ['script_variables_invalid']];
            }
            $mapped = $this->mappingValidator->validate($input['shortcode_mapping'], $schema['variables'] ?? []);
            if (! ($mapped['ok'] ?? false)) {
                return ['ok' => false, 'errors' => $mapped['errors'] ?? ['shortcode_mapping_invalid']];
            }
            $campaign->shortcode_mapping = $mapped['mapping'] ?? null;
            $campaign->shortcode_mapping_version = ((int) ($campaign->shortcode_mapping_version ?? 0)) + 1;
            if ((int) $campaign->shortcode_mapping_version < 1) {
                $campaign->shortcode_mapping_version = 1;
            }
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

        $gate = $this->assertActionableContents($campaign);
        if (! ($gate['ok'] ?? false)) {
            return ['ok' => false, 'errors' => $gate['errors'] ?? ['no_actionable_content']];
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

    /**
     * List contents for a campaign (installation-scoped).
     *
     * @return array{ok: bool, data?: list<array<string, mixed>>, errors?: list<string>}
     */
    public function listContents(string $campaignUuid, string $installationId): array
    {
        $campaign = $this->findOwned($campaignUuid, $installationId);
        if ($campaign === null) {
            return ['ok' => false, 'errors' => ['campaign_not_found']];
        }

        $accountUuids = $this->accountUuidMapForCampaign($campaign);
        $rows = SaCampaignContent::query()
            ->where('campaign_id', $campaign->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (SaCampaignContent $c): array => $this->contentDto($c, $accountUuids))
            ->all();

        return ['ok' => true, 'data' => $rows];
    }

    /**
     * Create one content under an existing campaign. Does not create Jobs.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, data?: array<string, mixed>, errors?: list<string>}
     */
    public function createContent(string $campaignUuid, string $installationId, array $input): array
    {
        $campaign = $this->findOwned($campaignUuid, $installationId);
        if ($campaign === null) {
            return ['ok' => false, 'errors' => ['campaign_not_found']];
        }
        $status = CampaignStatus::tryFrom((string) $campaign->status);
        if ($status === null || $status->isTerminal()) {
            return ['ok' => false, 'errors' => ['campaign_terminal']];
        }

        $prepared = $this->prepareContentRow($campaign, $installationId, $input, null);
        if (! ($prepared['ok'] ?? false)) {
            return ['ok' => false, 'errors' => $prepared['errors'] ?? ['content_invalid']];
        }

        try {
            return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($campaign, $prepared): array {
                $locked = SaCampaign::query()->where('id', $campaign->id)->lockForUpdate()->first();
                if (! $locked instanceof SaCampaign) {
                    return ['ok' => false, 'errors' => ['campaign_not_found']];
                }
                $status = CampaignStatus::tryFrom((string) $locked->status);
                if ($status === null || $status->isTerminal()) {
                    return ['ok' => false, 'errors' => ['campaign_terminal']];
                }

                $externalRef = $prepared['external_ref'] ?? null;
                if (is_string($externalRef) && $externalRef !== '') {
                    $dup = SaCampaignContent::query()
                        ->where('campaign_id', $locked->id)
                        ->where('external_ref', $externalRef)
                        ->exists();
                    if ($dup) {
                        return ['ok' => false, 'errors' => ['external_ref_duplicate']];
                    }
                }

                $maxSort = (int) SaCampaignContent::query()->where('campaign_id', $locked->id)->max('sort_order');
                $contentAttrs = [
                    'content_uuid' => (string) Str::uuid(),
                    'campaign_id' => $locked->id,
                    'external_ref' => $externalRef,
                    'sort_order' => $maxSort + 1,
                    'status' => CampaignContentStatus::Pending->value,
                    'input' => $prepared['input'] ?? [],
                    'assigned_account_id' => $prepared['assigned_account_id'] ?? null,
                    'last_error_code' => null,
                    'last_error_message' => null,
                    'last_error_meta' => null,
                ];
                if (SocialAutomationSchema::campaignShortcodeReady()) {
                    $contentAttrs['source_format'] = $prepared['source_format'] ?? 'legacy_input';
                    $contentAttrs['raw_source'] = $prepared['raw_source'] ?? null;
                    $contentAttrs['mapping_version'] = $prepared['mapping_version'] ?? null;
                }
                $content = SaCampaignContent::query()->create($contentAttrs);

                // Running campaigns: nudge scheduler only — never dispatch/create Job from CRUD.
                if ($status === CampaignStatus::Running) {
                    $locked->next_recheck_at = now();
                    $locked->save();
                }

                $accountUuids = $this->accountUuidMapForCampaign($locked);

                return ['ok' => true, 'data' => $this->contentDto($content, $accountUuids)];
            });
        } catch (Throwable) {
            return ['ok' => false, 'errors' => ['content_create_failed']];
        }
    }

    /**
     * Update content only before execution (pending / waiting_resources).
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, data?: array<string, mixed>, errors?: list<string>}
     */
    public function updateContent(string $campaignUuid, string $contentUuid, string $installationId, array $input): array
    {
        $campaign = $this->findOwned($campaignUuid, $installationId);
        if ($campaign === null) {
            return ['ok' => false, 'errors' => ['campaign_not_found']];
        }
        $content = SaCampaignContent::query()
            ->where('content_uuid', $contentUuid)
            ->where('campaign_id', $campaign->id)
            ->first();
        if (! $content instanceof SaCampaignContent) {
            return ['ok' => false, 'errors' => ['content_not_found']];
        }

        $contentStatus = CampaignContentStatus::tryFrom((string) $content->status);
        if ($contentStatus === null || ! $contentStatus->isDispatchable() || $content->current_job_id) {
            return ['ok' => false, 'errors' => ['content_not_editable']];
        }

        $prepared = $this->prepareContentRow($campaign, $installationId, $input, $content);
        if (! ($prepared['ok'] ?? false)) {
            return ['ok' => false, 'errors' => $prepared['errors'] ?? ['content_invalid']];
        }

        try {
            return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($campaign, $content, $prepared): array {
                $locked = SaCampaignContent::query()->where('id', $content->id)->lockForUpdate()->first();
                if (! $locked instanceof SaCampaignContent) {
                    return ['ok' => false, 'errors' => ['content_not_found']];
                }
                $contentStatus = CampaignContentStatus::tryFrom((string) $locked->status);
                if ($contentStatus === null || ! $contentStatus->isDispatchable() || $locked->current_job_id) {
                    return ['ok' => false, 'errors' => ['content_not_editable']];
                }

                $externalRef = $prepared['external_ref'] ?? null;
                if (is_string($externalRef) && $externalRef !== '') {
                    $dup = SaCampaignContent::query()
                        ->where('campaign_id', $campaign->id)
                        ->where('external_ref', $externalRef)
                        ->where('id', '!=', $locked->id)
                        ->exists();
                    if ($dup) {
                        return ['ok' => false, 'errors' => ['external_ref_duplicate']];
                    }
                }

                $locked->external_ref = $externalRef;
                $locked->input = $prepared['input'] ?? [];
                $locked->assigned_account_id = $prepared['assigned_account_id'] ?? null;
                if (SocialAutomationSchema::campaignShortcodeReady()) {
                    $locked->source_format = $prepared['source_format'] ?? $locked->source_format ?? 'legacy_input';
                    $locked->raw_source = $prepared['raw_source'] ?? $locked->raw_source;
                    $locked->mapping_version = $prepared['mapping_version'] ?? $locked->mapping_version;
                }
                // Re-queue for normal scheduler evaluation after edit.
                $locked->status = CampaignContentStatus::Pending->value;
                $locked->last_error_code = null;
                $locked->last_error_message = null;
                $locked->last_error_meta = null;
                $locked->save();

                $camp = SaCampaign::query()->where('id', $campaign->id)->lockForUpdate()->first();
                if ($camp instanceof SaCampaign && (string) $camp->status === CampaignStatus::Running->value) {
                    $camp->next_recheck_at = now();
                    $camp->save();
                }

                $accountUuids = $this->accountUuidMapForCampaign($campaign);

                return ['ok' => true, 'data' => $this->contentDto($locked, $accountUuids)];
            });
        } catch (Throwable) {
            return ['ok' => false, 'errors' => ['content_update_failed']];
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, external_ref?: string|null, input?: array<string, mixed>, source_format?: string, raw_source?: string|null, mapping_version?: int|null, assigned_account_id?: int|null, errors?: list<string>}
     */
    private function prepareContentRow(SaCampaign $campaign, string $installationId, array $input, ?SaCampaignContent $existing): array
    {
        $script = $this->resolveScriptPin((string) $campaign->script_id, (string) $campaign->script_version);
        if ($script === null) {
            return ['ok' => false, 'errors' => ['script_not_found']];
        }
        $manifest = is_array($script['manifest'] ?? null) ? $script['manifest'] : [];
        $schema = $this->variableSchema->fromManifest($manifest);
        if (! ($schema['ok'] ?? false)) {
            return ['ok' => false, 'errors' => $schema['errors'] ?? ['script_variables_invalid']];
        }
        /** @var array<string, array<string, mixed>> $variables */
        $variables = $schema['variables'] ?? [];
        $variableNames = array_keys($variables);

        $mappingDoc = is_array($campaign->shortcode_mapping ?? null) ? $campaign->shortcode_mapping : null;
        $mappingVersion = (int) ($campaign->shortcode_mapping_version ?? 0);

        $hasRaw = array_key_exists('raw_source', $input) || array_key_exists('body', $input);
        $hasInputKey = array_key_exists('input', $input);

        $sourceFormat = 'legacy_input';
        $rawSource = null;
        $contentMappingVersion = null;
        $typedInput = [];

        if ($hasRaw) {
            if (! SocialAutomationSchema::campaignShortcodeReady()) {
                return ['ok' => false, 'errors' => ['schema_unavailable']];
            }
            if (! is_array($mappingDoc) || $mappingVersion < 1) {
                return ['ok' => false, 'errors' => ['shortcode_mapping_required']];
            }
            $raw = (string) ($input['raw_source'] ?? $input['body'] ?? '');
            $processed = $this->shortcodePipeline->process($raw, $mappingDoc, $variables);
            if (! ($processed['ok'] ?? false)) {
                return ['ok' => false, 'errors' => $processed['errors'] ?? ['content_input_invalid']];
            }
            $typedInput = $processed['input'] ?? [];
            $rawSource = $processed['raw_source'] ?? $raw;
            $sourceFormat = 'shortcode';
            $contentMappingVersion = $mappingVersion;
        } elseif ($hasInputKey || $existing === null) {
            $rawInput = $hasInputKey
                ? (is_array($input['input'] ?? null) ? $input['input'] : [])
                : [];
            // Legacy path: still enforce ALL required Script variables.
            $validated = $this->shortcodePipeline->validateStoredInput($rawInput, $variables);
            if (! ($validated['ok'] ?? false)) {
                // Allow legacy create with partial input only when script has zero required vars;
                // otherwise fail. Also keep sanitizer-only path for backward compat when variables empty.
                if ($variables === []) {
                    $sanitized = $this->sanitizer->sanitize($rawInput, $variableNames);
                    if (! ($sanitized['ok'] ?? false)) {
                        return ['ok' => false, 'errors' => $sanitized['errors'] ?? ['content_input_invalid']];
                    }
                    $typedInput = $sanitized['input'] ?? [];
                } else {
                    return ['ok' => false, 'errors' => $validated['errors'] ?? ['content_input_invalid']];
                }
            } else {
                $typedInput = $validated['input'] ?? [];
            }
            $sourceFormat = 'legacy_input';
            if ($existing !== null && ! $hasInputKey) {
                $typedInput = is_array($existing->input) ? $existing->input : [];
                $sourceFormat = (string) ($existing->source_format ?? 'legacy_input');
                $rawSource = $existing->raw_source;
                $contentMappingVersion = $existing->mapping_version !== null ? (int) $existing->mapping_version : null;
            }
        } else {
            // Update without input/raw: keep existing payload, re-validate.
            $typedInput = is_array($existing->input) ? $existing->input : [];
            $sourceFormat = (string) ($existing->source_format ?? 'legacy_input');
            $rawSource = $existing->raw_source;
            $contentMappingVersion = $existing->mapping_version !== null ? (int) $existing->mapping_version : null;
            if ($variables !== []) {
                $validated = $this->shortcodePipeline->validateStoredInput($typedInput, $variables);
                if (! ($validated['ok'] ?? false)) {
                    return ['ok' => false, 'errors' => $validated['errors'] ?? ['content_input_invalid']];
                }
                $typedInput = $validated['input'] ?? [];
            }
        }

        $meta = is_array($campaign->meta) ? $campaign->meta : [];
        $pinnedUuid = trim((string) ($meta['pinned_account_id'] ?? ''));
        $assignedAccountId = $existing?->assigned_account_id ? (int) $existing->assigned_account_id : null;

        if ($pinnedUuid !== '') {
            $pinned = $this->resolvePinnedAccount($pinnedUuid, $installationId, (string) $campaign->platform);
            if ($pinned === null) {
                return ['ok' => false, 'errors' => ['pinned_account_unavailable']];
            }
            $rowAccountUuid = trim((string) ($input['account_id'] ?? ''));
            if ($rowAccountUuid !== '' && strcasecmp($rowAccountUuid, $pinnedUuid) !== 0) {
                return ['ok' => false, 'errors' => ['account_pin_mismatch']];
            }
            $assignedAccountId = (int) $pinned->id;
        } elseif (array_key_exists('account_id', $input)) {
            $rowAccountUuid = trim((string) ($input['account_id'] ?? ''));
            if ($rowAccountUuid === '') {
                $assignedAccountId = null;
            } else {
                $rowAccount = $this->resolvePinnedAccount($rowAccountUuid, $installationId, (string) $campaign->platform);
                if ($rowAccount === null) {
                    return ['ok' => false, 'errors' => ['account_not_found_or_platform_mismatch']];
                }
                $assignedAccountId = (int) $rowAccount->id;
            }
        }

        $externalRef = array_key_exists('external_ref', $input)
            ? trim((string) ($input['external_ref'] ?? ''))
            : (string) ($existing?->external_ref ?? '');
        $externalRef = $externalRef !== '' ? $externalRef : null;

        return [
            'ok' => true,
            'external_ref' => $externalRef,
            'input' => $typedInput,
            'source_format' => $sourceFormat,
            'raw_source' => $rawSource,
            'mapping_version' => $contentMappingVersion,
            'assigned_account_id' => $assignedAccountId,
        ];
    }

    /**
     * Preview mock articles — no DB writes, no Jobs.
     *
     * @return array{ok: bool, data?: array<string, mixed>, errors?: list<string>}
     */
    public function mockPreview(string $campaignUuid, string $installationId, int $count): array
    {
        if (! SocialAutomationSchema::campaignShortcodeReady()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }
        $campaign = $this->findOwned($campaignUuid, $installationId);
        if ($campaign === null) {
            return ['ok' => false, 'errors' => ['campaign_not_found']];
        }
        $ctx = $this->mockContext($campaign);
        if (! ($ctx['ok'] ?? false)) {
            return ['ok' => false, 'errors' => $ctx['errors'] ?? ['mock_context_invalid']];
        }
        $generated = $this->mockGenerator->generate($count, $ctx['mapping'], $ctx['variables']);
        if (! ($generated['ok'] ?? false)) {
            return ['ok' => false, 'errors' => $generated['errors'] ?? ['mock_generate_failed']];
        }

        return [
            'ok' => true,
            'data' => [
                'count' => count($generated['articles'] ?? []),
                'articles' => $generated['articles'] ?? [],
                'mapping_version' => (int) ($campaign->shortcode_mapping_version ?? 0),
            ],
        ];
    }

    /**
     * Confirm-save mock articles — atomic batch + idempotency. No Jobs / no Start.
     *
     * @return array{ok: bool, data?: array<string, mixed>, errors?: list<string>}
     */
    public function mockSave(string $campaignUuid, string $installationId, int $count, string $idempotencyKey): array
    {
        if (! SocialAutomationSchema::campaignShortcodeReady()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            return ['ok' => false, 'errors' => ['idempotency_key_invalid']];
        }

        $campaign = $this->findOwned($campaignUuid, $installationId);
        if ($campaign === null) {
            return ['ok' => false, 'errors' => ['campaign_not_found']];
        }
        $status = CampaignStatus::tryFrom((string) $campaign->status);
        if ($status === null || $status->isTerminal()) {
            return ['ok' => false, 'errors' => ['campaign_terminal']];
        }

        try {
            return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($campaign, $count, $idempotencyKey): array {
                $locked = SaCampaign::query()->where('id', $campaign->id)->lockForUpdate()->first();
                if (! $locked instanceof SaCampaign) {
                    return ['ok' => false, 'errors' => ['campaign_not_found']];
                }
                $status = CampaignStatus::tryFrom((string) $locked->status);
                if ($status === null || $status->isTerminal()) {
                    return ['ok' => false, 'errors' => ['campaign_terminal']];
                }

                $meta = is_array($locked->meta) ? $locked->meta : [];
                $prior = is_array($meta['mock_batch_results'][$idempotencyKey] ?? null)
                    ? $meta['mock_batch_results'][$idempotencyKey]
                    : null;
                if (is_array($prior) && ($prior['ok'] ?? false) === true) {
                    return [
                        'ok' => true,
                        'data' => array_merge($prior['data'] ?? [], ['idempotent' => true]),
                    ];
                }

                $ctx = $this->mockContext($locked);
                if (! ($ctx['ok'] ?? false)) {
                    return ['ok' => false, 'errors' => $ctx['errors'] ?? ['mock_context_invalid']];
                }

                $generated = $this->mockGenerator->generate($count, $ctx['mapping'], $ctx['variables']);
                if (! ($generated['ok'] ?? false)) {
                    return ['ok' => false, 'errors' => $generated['errors'] ?? ['mock_generate_failed']];
                }

                $articles = $generated['articles'] ?? [];
                $created = [];
                $maxSort = (int) SaCampaignContent::query()->where('campaign_id', $locked->id)->max('sort_order');
                $mappingVersion = (int) ($locked->shortcode_mapping_version ?? 0);
                $pinnedAccountId = $this->pinnedAccountIdFromMeta($locked);

                foreach ($articles as $i => $article) {
                    $ref = (string) ($article['external_ref'] ?? '');
                    if ($ref !== '') {
                        $dup = SaCampaignContent::query()
                            ->where('campaign_id', $locked->id)
                            ->where('external_ref', $ref)
                            ->exists();
                        if ($dup) {
                            // Atomic: throw to rollback entire batch
                            throw new \RuntimeException('external_ref_duplicate');
                        }
                    }
                    $content = SaCampaignContent::query()->create([
                        'content_uuid' => (string) Str::uuid(),
                        'campaign_id' => $locked->id,
                        'external_ref' => $ref !== '' ? $ref : null,
                        'sort_order' => $maxSort + 1 + $i,
                        'status' => CampaignContentStatus::Pending->value,
                        'input' => $article['input'] ?? [],
                        'source_format' => 'shortcode',
                        'raw_source' => $article['raw_source'] ?? null,
                        'mapping_version' => $mappingVersion,
                        'assigned_account_id' => $pinnedAccountId,
                    ]);
                    $created[] = $this->contentDto($content);
                }

                if ($status === CampaignStatus::Running) {
                    $locked->next_recheck_at = now();
                }

                $resultData = [
                    'count' => count($created),
                    'contents' => $created,
                    'idempotent' => false,
                    'mapping_version' => $mappingVersion,
                ];
                $meta['mock_batch_results'][$idempotencyKey] = [
                    'ok' => true,
                    'data' => $resultData,
                    'saved_at' => now()->toIso8601String(),
                ];
                $locked->meta = $meta;
                $locked->save();

                return ['ok' => true, 'data' => $resultData];
            });
        } catch (Throwable $e) {
            if ($e->getMessage() === 'external_ref_duplicate') {
                return ['ok' => false, 'errors' => ['external_ref_duplicate']];
            }

            return ['ok' => false, 'errors' => ['mock_save_failed']];
        }
    }

    /**
     * @return array<int, string>
     */
    private function accountUuidMapForCampaign(SaCampaign $campaign): array
    {
        $accountIds = SaCampaignContent::query()
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('assigned_account_id')
            ->pluck('assigned_account_id')
            ->unique()
            ->filter()
            ->all();
        if ($accountIds === []) {
            return [];
        }

        return SaAccount::query()
            ->whereIn('id', $accountIds)
            ->pluck('account_uuid', 'id')
            ->all();
    }

    /**
     * @param  array<int, string>  $accountUuids
     * @return array<string, mixed>
     */
    public function contentDto(SaCampaignContent $content, array $accountUuids = []): array
    {
        $aid = $content->assigned_account_id ? (int) $content->assigned_account_id : null;

        $dto = [
            'id' => $content->content_uuid,
            'external_ref' => $content->external_ref,
            'status' => $content->status,
            'sort_order' => (int) $content->sort_order,
            'assigned_account_id' => $aid !== null ? (string) ($accountUuids[$aid] ?? '') : null,
            'last_error_code' => $content->last_error_code,
            'last_error_message' => $content->last_error_message,
            'input' => is_array($content->input) ? $content->input : [],
        ];
        if (SocialAutomationSchema::campaignShortcodeReady()) {
            $dto['source_format'] = (string) ($content->source_format ?? 'legacy_input');
            $dto['raw_source'] = $content->raw_source;
            $dto['mapping_version'] = $content->mapping_version !== null ? (int) $content->mapping_version : null;
        }

        return $dto;
    }

    /**
     * @return array{ok: bool, errors?: list<string>}
     */
    private function assertActionableContents(SaCampaign $campaign): array
    {
        $contents = SaCampaignContent::query()
            ->where('campaign_id', $campaign->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        if ($contents->isEmpty()) {
            return ['ok' => false, 'errors' => ['no_actionable_content']];
        }

        $script = $this->resolveScriptPin((string) $campaign->script_id, (string) $campaign->script_version);
        if ($script === null) {
            return ['ok' => false, 'errors' => ['script_not_found']];
        }
        $manifest = is_array($script['manifest'] ?? null) ? $script['manifest'] : [];
        $schema = $this->variableSchema->fromManifest($manifest);
        if (! ($schema['ok'] ?? false)) {
            return ['ok' => false, 'errors' => $schema['errors'] ?? ['script_variables_invalid']];
        }
        /** @var array<string, array<string, mixed>> $variables */
        $variables = $schema['variables'] ?? [];

        $mappingDoc = is_array($campaign->shortcode_mapping ?? null) ? $campaign->shortcode_mapping : null;
        $mappingVersion = (int) ($campaign->shortcode_mapping_version ?? 0);
        if ($variables !== [] && $this->variableSchema->requiredKeys($variables) !== []) {
            if (! is_array($mappingDoc) || $mappingVersion < 1) {
                // Legacy-only campaigns may still start if every content is legacy_input with full required values.
                $allLegacy = true;
                foreach ($contents as $c) {
                    $fmt = (string) ($c->source_format ?? 'legacy_input');
                    if ($fmt === 'shortcode') {
                        $allLegacy = false;
                        break;
                    }
                }
                if (! $allLegacy) {
                    return ['ok' => false, 'errors' => ['shortcode_mapping_required']];
                }
            } else {
                $mapCheck = $this->mappingValidator->validate($mappingDoc, $variables);
                if (! ($mapCheck['ok'] ?? false)) {
                    return ['ok' => false, 'errors' => $mapCheck['errors'] ?? ['shortcode_mapping_invalid']];
                }
            }
        }

        $actionable = 0;
        foreach ($contents as $content) {
            $st = CampaignContentStatus::tryFrom((string) $content->status);
            if ($st === null || ! $st->isDispatchable()) {
                continue;
            }
            $fmt = (string) ($content->source_format ?? 'legacy_input');
            if ($fmt === 'shortcode') {
                if ((int) ($content->mapping_version ?? 0) !== $mappingVersion) {
                    return ['ok' => false, 'errors' => ['mapping_version_stale']];
                }
            }
            $input = is_array($content->input) ? $content->input : [];
            if ($input === []) {
                return ['ok' => false, 'errors' => ['content_input_empty']];
            }
            if ($variables !== []) {
                $validated = $this->shortcodePipeline->validateStoredInput($input, $variables);
                if (! ($validated['ok'] ?? false)) {
                    return ['ok' => false, 'errors' => $validated['errors'] ?? ['content_input_invalid']];
                }
            }
            $actionable++;
        }

        if ($actionable < 1) {
            return ['ok' => false, 'errors' => ['no_actionable_content']];
        }

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, mapping?: array{entries: list<array{shortcode: string, variable: string}}}, variables?: array<string, array<string, mixed>>, errors?: list<string>}
     */
    private function mockContext(SaCampaign $campaign): array
    {
        $mappingDoc = is_array($campaign->shortcode_mapping ?? null) ? $campaign->shortcode_mapping : null;
        $mappingVersion = (int) ($campaign->shortcode_mapping_version ?? 0);
        if (! is_array($mappingDoc) || $mappingVersion < 1) {
            return ['ok' => false, 'errors' => ['shortcode_mapping_required']];
        }
        $script = $this->resolveScriptPin((string) $campaign->script_id, (string) $campaign->script_version);
        if ($script === null) {
            return ['ok' => false, 'errors' => ['script_not_found']];
        }
        $manifest = is_array($script['manifest'] ?? null) ? $script['manifest'] : [];
        $schema = $this->variableSchema->fromManifest($manifest);
        if (! ($schema['ok'] ?? false)) {
            return ['ok' => false, 'errors' => $schema['errors'] ?? ['script_variables_invalid']];
        }
        $variables = $schema['variables'] ?? [];
        $mapped = $this->mappingValidator->validate($mappingDoc, $variables);
        if (! ($mapped['ok'] ?? false)) {
            return ['ok' => false, 'errors' => $mapped['errors'] ?? ['shortcode_mapping_invalid']];
        }

        return [
            'ok' => true,
            'mapping' => $mapped['mapping'] ?? $mappingDoc,
            'variables' => $variables,
        ];
    }

    private function pinnedAccountIdFromMeta(SaCampaign $campaign): ?int
    {
        $meta = is_array($campaign->meta) ? $campaign->meta : [];
        $uuid = trim((string) ($meta['pinned_account_id'] ?? ''));
        if ($uuid === '') {
            return null;
        }
        $account = $this->resolvePinnedAccount($uuid, (string) $campaign->installation_id, (string) $campaign->platform);

        return $account instanceof SaAccount ? (int) $account->id : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, array<string, mixed>>  $variables
     * @param  list<string>  $variableNames
     * @param  array{entries: list<array{shortcode: string, variable: string}}}|null  $mappingDoc
     * @return array{ok: bool, external_ref?: string|null, input?: array<string, mixed>, source_format?: string, raw_source?: string|null, mapping_version?: int|null, sort_order?: int, assigned_account_id?: int|null, errors?: list<string>}
     */
    private function buildContentFromRequestRow(
        array $row,
        array $variables,
        array $variableNames,
        ?array $mappingDoc,
        int $mappingVersion,
        ?SaAccount $pinnedAccount,
        string $installationId,
        string $platform,
        int $sortOrder,
    ): array {
        $externalRef = trim((string) ($row['external_ref'] ?? ''));
        $hasRaw = array_key_exists('raw_source', $row) || array_key_exists('body', $row);
        $sourceFormat = 'legacy_input';
        $rawSource = null;
        $contentMappingVersion = null;

        if ($hasRaw) {
            if (! is_array($mappingDoc) || $mappingVersion < 1) {
                return ['ok' => false, 'errors' => ['shortcode_mapping_required']];
            }
            $raw = (string) ($row['raw_source'] ?? $row['body'] ?? '');
            $processed = $this->shortcodePipeline->process($raw, $mappingDoc, $variables);
            if (! ($processed['ok'] ?? false)) {
                return ['ok' => false, 'errors' => $processed['errors'] ?? ['content_input_invalid']];
            }
            $typedInput = $processed['input'] ?? [];
            $rawSource = $processed['raw_source'] ?? $raw;
            $sourceFormat = 'shortcode';
            $contentMappingVersion = $mappingVersion;
        } else {
            $rawInput = is_array($row['input'] ?? null) ? $row['input'] : [];
            if ($variables !== []) {
                $validated = $this->shortcodePipeline->validateStoredInput($rawInput, $variables);
                if (! ($validated['ok'] ?? false)) {
                    return ['ok' => false, 'errors' => $validated['errors'] ?? ['content_input_invalid']];
                }
                $typedInput = $validated['input'] ?? [];
            } else {
                $sanitized = $this->sanitizer->sanitize($rawInput, $variableNames);
                if (! ($sanitized['ok'] ?? false)) {
                    return ['ok' => false, 'errors' => $sanitized['errors'] ?? ['content_input_invalid']];
                }
                $typedInput = $sanitized['input'] ?? [];
            }
        }

        $rowAccountId = $pinnedAccount?->id;
        $rowAccountUuid = trim((string) ($row['account_id'] ?? ''));
        if ($rowAccountUuid !== '') {
            $rowAccount = $this->resolvePinnedAccount($rowAccountUuid, $installationId, $platform);
            if ($rowAccount === null) {
                return ['ok' => false, 'errors' => ['content_account_invalid:'.$sortOrder]];
            }
            $rowAccountId = $rowAccount->id;
        }

        return [
            'ok' => true,
            'external_ref' => $externalRef !== '' ? $externalRef : null,
            'input' => $typedInput,
            'source_format' => $sourceFormat,
            'raw_source' => $rawSource,
            'mapping_version' => $contentMappingVersion,
            'sort_order' => $sortOrder,
            'assigned_account_id' => $rowAccountId,
        ];
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
     * Resolve and pin an exact published Script release.
     * Accepts package_key, Action Tag key (e.g. mayhopphat.create_post), or manifest script_id.
     *
     * @return array{script_id: string, version: string, content_digest: string|null, manifest: array<string, mixed>}|null
     */
    private function resolveScriptPin(string $scriptId, ?string $version): ?array
    {
        $wantedVersion = ($version !== null && $version !== '') ? $version : null;
        $package = SaPackage::query()->where('package_key', $scriptId)->first();
        $preferredReleaseId = null;

        if (! $package instanceof SaPackage) {
            $link = SaActionTagScript::query()
                ->where('action_tag_key', $scriptId)
                ->orderByDesc('id')
                ->first();
            if ($link instanceof SaActionTagScript && $link->package_id) {
                $package = SaPackage::query()->find($link->package_id);
                if ($wantedVersion === null && is_string($link->script_version) && $link->script_version !== '') {
                    $wantedVersion = (string) $link->script_version;
                }
                if ($link->release_id) {
                    $preferredReleaseId = (int) $link->release_id;
                }
            }
        }

        if ($package instanceof SaPackage) {
            if ($preferredReleaseId !== null && $wantedVersion === null) {
                $preferred = SaPackageRelease::query()
                    ->where('id', $preferredReleaseId)
                    ->where('package_id', $package->id)
                    ->where('status', 'published')
                    ->first();
                if ($preferred instanceof SaPackageRelease) {
                    return $this->scriptPinDto($package, $preferred);
                }
            }

            $q = SaPackageRelease::query()
                ->where('package_id', $package->id)
                ->where('status', 'published');
            if ($wantedVersion !== null) {
                $q->where('version', $wantedVersion);
            } else {
                $q->orderByDesc('published_at')->orderByDesc('id');
            }
            $release = $q->first();
            if ($release instanceof SaPackageRelease) {
                return $this->scriptPinDto($package, $release);
            }

            return null;
        }

        // Fallback: scan published manifests for script_id / action_tag_key.
        $releases = SaPackageRelease::query()
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();
        foreach ($releases as $release) {
            $manifest = is_array($release->manifest) ? $release->manifest : [];
            $mid = trim((string) ($manifest['script_id'] ?? ''));
            $tag = trim((string) ($manifest['action_tag_key'] ?? ''));
            if (($mid !== '' && $mid === $scriptId) || ($tag !== '' && $tag === $scriptId)) {
                if ($wantedVersion !== null && (string) $release->version !== $wantedVersion) {
                    continue;
                }
                $pkg = SaPackage::query()->find($release->package_id);
                if ($pkg instanceof SaPackage) {
                    return $this->scriptPinDto($pkg, $release, $manifest);
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $manifest
     * @return array{script_id: string, version: string, content_digest: string|null, manifest: array<string, mixed>}
     */
    private function scriptPinDto(SaPackage $package, SaPackageRelease $release, ?array $manifest = null): array
    {
        return [
            'script_id' => $package->package_key,
            'version' => (string) $release->version,
            'content_digest' => $release->content_digest,
            'manifest' => $manifest ?? (is_array($release->manifest) ? $release->manifest : []),
        ];
    }

    private function resolvePinnedAccount(string $accountUuid, string $installationId, string $platform): ?SaAccount
    {
        $account = SaAccount::query()
            ->where('account_uuid', $accountUuid)
            ->where('installation_id', $installationId)
            ->first();
        if (! $account instanceof SaAccount) {
            return null;
        }
        if (strcasecmp((string) $account->platform, $platform) !== 0) {
            return null;
        }

        return $account;
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
        $meta = is_array($campaign->meta) ? $campaign->meta : [];

        return [
            'id' => $campaign->campaign_uuid,
            'name' => $campaign->name,
            'platform' => $campaign->platform,
            'execution_mode' => $campaign->execution_mode,
            'run_mode' => $campaign->run_mode ?: CampaignRunMode::Live->value,
            'status' => $campaign->status,
            'script_id' => $campaign->script_id,
            'script_version' => $campaign->script_version,
            'script_content_digest' => $campaign->script_content_digest,
            'account_id' => isset($meta['pinned_account_id']) ? (string) $meta['pinned_account_id'] : null,
            'resource_recheck_seconds' => (int) $campaign->resource_recheck_seconds,
            'total_contents' => $counts['total'],
            'completed_contents' => $counts['success'],
            'failed_contents' => $counts['failed_locked'],
            'waiting_contents' => $counts['pending'] + $counts['waiting_resources'],
            'next_recheck_at' => $campaign->next_recheck_at?->toIso8601String(),
            'shortcode_mapping' => SocialAutomationSchema::campaignShortcodeReady()
                ? (is_array($campaign->shortcode_mapping ?? null) ? $campaign->shortcode_mapping : null)
                : null,
            'shortcode_mapping_version' => SocialAutomationSchema::campaignShortcodeReady()
                ? (int) ($campaign->shortcode_mapping_version ?? 0)
                : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detailDto(SaCampaign $campaign): array
    {
        $counts = $this->contentCounts($campaign);
        $meta = is_array($campaign->meta) ? $campaign->meta : [];
        $accountUuids = $this->accountUuidMapForCampaign($campaign);
        $contents = SaCampaignContent::query()
            ->where('campaign_id', $campaign->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (SaCampaignContent $c): array => $this->contentDto($c, $accountUuids))
            ->all();

        return [
            'id' => $campaign->campaign_uuid,
            'name' => $campaign->name,
            'platform' => $campaign->platform,
            'status' => $campaign->status,
            'execution_mode' => $campaign->execution_mode,
            'run_mode' => $campaign->run_mode ?: CampaignRunMode::Live->value,
            'script_id' => $campaign->script_id,
            'script_version' => $campaign->script_version,
            'script_content_digest' => $campaign->script_content_digest,
            'account_id' => isset($meta['pinned_account_id']) ? (string) $meta['pinned_account_id'] : null,
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
            'shortcode_mapping' => SocialAutomationSchema::campaignShortcodeReady()
                ? (is_array($campaign->shortcode_mapping ?? null) ? $campaign->shortcode_mapping : null)
                : null,
            'shortcode_mapping_version' => SocialAutomationSchema::campaignShortcodeReady()
                ? (int) ($campaign->shortcode_mapping_version ?? 0)
                : 0,
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
