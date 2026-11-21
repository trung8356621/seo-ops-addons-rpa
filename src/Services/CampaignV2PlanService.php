<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignInputSanitizer;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignRecheckPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignScriptVariableSchema;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodeMappingValidator;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodePipeline;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignTestScriptPolicy;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignContentStatus;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignExecutionMode;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignRunMode;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignStatus;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContent;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContentAssignment;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use Throwable;

final class CampaignV2PlanService
{
    public function __construct(
        private readonly ScriptResolutionService $scriptResolver = new ScriptResolutionService,
        private readonly CampaignRecheckPolicy $recheck = new CampaignRecheckPolicy,
        private readonly CampaignTestScriptPolicy $testScripts = new CampaignTestScriptPolicy,
        private readonly CampaignShortcodePipeline $shortcodePipeline = new CampaignShortcodePipeline,
        private readonly CampaignShortcodeMappingValidator $mappingValidator = new CampaignShortcodeMappingValidator,
        private readonly CampaignScriptVariableSchema $variableSchema = new CampaignScriptVariableSchema,
        private readonly CampaignInputSanitizer $sanitizer = new CampaignInputSanitizer,
    ) {}

    public function isV2CreatePayload(array $input): bool
    {
        if (array_key_exists('plan_version', $input) && (int) $input['plan_version'] === 2) {
            return true;
        }

        return array_key_exists('action_tags', $input);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *   ok: bool,
     *   http?: int,
     *   data?: array<string, mixed>,
     *   errors?: list<string>,
     *   field_errors?: array<string, list<string>>,
     *   message?: string
     * }
     */
    public function createDraft(array $input, string $installationId, ?int $userId): array
    {
        if (! SocialAutomationSchema::campaignV2Ready()) {
            return $this->fail(503, ['schema_unavailable'], ['schema' => ['Campaign V2 schema is not migrated.']]);
        }

        if (array_key_exists('plan_version', $input)) {
            $pv = (int) $input['plan_version'];
            if ($pv !== 2) {
                $code = $pv === 1 && array_key_exists('action_tags', $input)
                    ? 'plan_version_conflict'
                    : 'invalid_plan_version';

                return $this->fail(422, [$code], ['plan_version' => ['plan_version must be 2 for multi-tag Campaign V2 payloads.']]);
            }
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return $this->fail(422, ['name_required'], ['name' => ['Campaign name is required.']]);
        }

        $runMode = CampaignRunMode::tryFromInput($input['run_mode'] ?? CampaignRunMode::Live->value);
        if ($runMode === null) {
            return $this->fail(422, ['invalid_run_mode'], ['run_mode' => ['Invalid run_mode.']]);
        }

        $mode = CampaignExecutionMode::tryFromInput($input['execution_mode'] ?? CampaignExecutionMode::OneAccountOneJob->value);
        if ($mode === null) {
            return $this->fail(422, ['invalid_execution_mode'], ['execution_mode' => ['Invalid execution_mode.']]);
        }

        $recheckSeconds = $this->recheck->normalizeInterval(
            (int) ($input['resource_recheck_seconds'] ?? CampaignRecheckPolicy::DEFAULT_INTERVAL_SECONDS)
        );

        $rawTags = $input['action_tags'] ?? null;
        if (! is_array($rawTags) || ! array_is_list($rawTags) || $rawTags === []) {
            return $this->fail(422, ['action_tags_required'], ['action_tags' => ['At least one Action Tag is required.']]);
        }

        $preparedTags = [];
        $tagsByKey = [];
        $tagsById = [];
        $platforms = [];

        foreach ($rawTags as $i => $tagRow) {
            if (! is_array($tagRow)) {
                return $this->fail(422, ['action_tag_invalid:'.$i], ["action_tags.{$i}" => ['Action Tag entry must be an object.']]);
            }

            $resolvedTag = $this->resolveAndValidateTagEntry($tagRow, $i, $runMode);
            if (! ($resolvedTag['ok'] ?? false)) {
                return $resolvedTag;
            }

            /** @var array<string, mixed> $tagData */
            $tagData = $resolvedTag['tag'];
            $tagId = (int) $tagData['action_tag_id'];
            $tagKey = (string) $tagData['action_tag_key'];

            if (isset($tagsById[$tagId]) || isset($tagsByKey[$tagKey])) {
                return $this->fail(
                    422,
                    ['duplicate_action_tag:'.$tagKey],
                    ["action_tags.{$i}.action_tag_key" => ["Duplicate Action Tag '{$tagKey}' in campaign plan."]]
                );
            }

            $idx = count($preparedTags);
            $preparedTags[] = $tagData;
            $tagsByKey[$tagKey] = $idx;
            $tagsById[$tagId] = $idx;
            $platforms[(string) $tagData['site_key']] = true;
        }

        $rawContents = $input['contents'] ?? [];
        if (! is_array($rawContents) || ! array_is_list($rawContents)) {
            return $this->fail(422, ['contents_invalid'], ['contents' => ['contents must be an array.']]);
        }

        $preparedContents = [];
        $seenExternalRefs = [];

        foreach ($rawContents as $ci => $contentRow) {
            if (! is_array($contentRow)) {
                return $this->fail(422, ['content_invalid:'.$ci], ["contents.{$ci}" => ['Content item must be an object.']]);
            }

            $externalRef = trim((string) ($contentRow['external_ref'] ?? ''));
            $externalRef = $externalRef !== '' ? $externalRef : null;
            if ($externalRef !== null) {
                if (isset($seenExternalRefs[$externalRef])) {
                    return $this->fail(
                        422,
                        ['external_ref_duplicate'],
                        ["contents.{$ci}.external_ref" => ["Duplicate external_ref '{$externalRef}'."]]
                    );
                }
                $seenExternalRefs[$externalRef] = true;
            }

            $builtContent = $this->prepareContentWithAssignments(
                $contentRow,
                $ci,
                $preparedTags,
                $tagsByKey,
                $tagsById,
                $externalRef,
            );
            if (! ($builtContent['ok'] ?? false)) {
                return $builtContent;
            }

            $preparedContents[] = $builtContent['content'];
        }

        $campaignPlatform = count($platforms) === 1 ? (string) array_key_first($platforms) : 'multi';

        try {
            $detail = DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use (
                $name,
                $campaignPlatform,
                $mode,
                $runMode,
                $recheckSeconds,
                $installationId,
                $userId,
                $preparedTags,
                $preparedContents,
            ): array {
                $campaign = SaCampaign::query()->create([
                    'campaign_uuid' => (string) Str::uuid(),
                    'installation_id' => $installationId,
                    'name' => $name,
                    'platform' => $campaignPlatform,
                    'execution_mode' => $mode->value,
                    'run_mode' => $runMode->value,
                    'status' => CampaignStatus::Draft->value,
                    'plan_version' => 2,
                    'script_id' => 'v2:multi-tag',
                    'script_version' => '2',
                    'script_content_digest' => null,
                    'resource_recheck_seconds' => $recheckSeconds,
                    'created_by_user_id' => $userId,
                    'meta' => [
                        'v2_plan' => true,
                        'tag_count' => count($preparedTags),
                    ],
                ]);

                $tagModelsByIdx = [];
                foreach ($preparedTags as $idx => $tagData) {
                    $tagModelsByIdx[$idx] = SaCampaignActionTag::query()->create([
                        'plan_tag_uuid' => (string) Str::uuid(),
                        'campaign_id' => $campaign->id,
                        'action_tag_id' => $tagData['action_tag_id'],
                        'action_tag_key' => $tagData['action_tag_key'],
                        'site_id' => $tagData['site_id'],
                        'site_key' => $tagData['site_key'],
                        'cms_type' => $tagData['cms_type'],
                        'platform' => $tagData['platform'],
                        'package_id' => $tagData['package_id'],
                        'script_id' => $tagData['script_id'],
                        'script_name' => $tagData['script_name'],
                        'release_id' => $tagData['release_id'],
                        'script_version' => $tagData['script_version'],
                        'script_content_digest' => $tagData['script_content_digest'],
                        'script_execution_digest' => $tagData['script_execution_digest'],
                        'resolution_source' => $tagData['resolution_source'],
                        'source_action_tag_key' => $tagData['source_action_tag_key'],
                        'cms_scope' => $tagData['cms_scope'],
                        'manifest_snapshot' => $tagData['manifest_snapshot'],
                        'shortcode_mapping' => $tagData['shortcode_mapping'],
                        'shortcode_mapping_version' => $tagData['shortcode_mapping_version'],
                        'is_enabled' => true,
                        'sort_order' => $idx,
                    ]);
                }

                foreach ($preparedContents as $cRow) {
                    $contentAttrs = [
                        'content_uuid' => (string) Str::uuid(),
                        'campaign_id' => $campaign->id,
                        'external_ref' => $cRow['external_ref'],
                        'sort_order' => $cRow['sort_order'],
                        'status' => CampaignContentStatus::Pending->value,
                        'input' => [],
                        'assigned_account_id' => null,
                    ];
                    if (SocialAutomationSchema::campaignShortcodeReady()) {
                        $contentAttrs['source_format'] = $cRow['source_format'];
                        $contentAttrs['raw_source'] = $cRow['raw_source'];
                        $contentAttrs['mapping_version'] = null;
                    }
                    $content = SaCampaignContent::query()->create($contentAttrs);

                    foreach ($cRow['assignments'] as $aRow) {
                        /** @var SaCampaignActionTag $planTag */
                        $planTag = $tagModelsByIdx[$aRow['tag_idx']];
                        SaCampaignContentAssignment::query()->create([
                            'assignment_uuid' => (string) Str::uuid(),
                            'campaign_id' => $campaign->id,
                            'campaign_content_id' => $content->id,
                            'campaign_action_tag_id' => $planTag->id,
                            'package_id' => $planTag->package_id,
                            'release_id' => $planTag->release_id,
                            'script_id' => $planTag->script_id,
                            'script_version' => $planTag->script_version,
                            'source_format' => $aRow['source_format'],
                            'shortcode_mapping' => $aRow['shortcode_mapping'],
                            'mapping_version' => $aRow['mapping_version'],
                            'input' => $aRow['input'],
                            'status' => CampaignContentStatus::Pending->value,
                        ]);
                    }
                }

                return $this->detailDto($campaign->fresh() ?? $campaign);
            });

            return ['ok' => true, 'http' => 201, 'data' => $detail];
        } catch (Throwable) {
            return $this->fail(500, ['create_failed'], ['campaign' => ['Failed to persist Campaign V2 draft.']]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function detailDto(SaCampaign $campaign, ?array $baseDetail = null): array
    {
        $planTags = SaCampaignActionTag::query()
            ->where('campaign_id', $campaign->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $planTagUuidById = [];
        $actionTagsDto = [];
        foreach ($planTags as $pt) {
            $planTagUuidById[(int) $pt->id] = (string) $pt->plan_tag_uuid;
            $manifest = is_array($pt->manifest_snapshot) ? $pt->manifest_snapshot : [];
            $projectedVars = $manifest !== []
                ? $this->variableSchema->projectStaffList($manifest)
                : ['status' => 'empty', 'variables' => [], 'errors' => []];

            $actionTagsDto[] = [
                'id' => (string) $pt->plan_tag_uuid,
                'action_tag_id' => (int) $pt->action_tag_id,
                'action_tag_key' => (string) $pt->action_tag_key,
                'site_id' => $pt->site_id !== null ? (int) $pt->site_id : null,
                'site_key' => (string) $pt->site_key,
                'cms_type' => $pt->cms_type,
                'platform' => $pt->platform,
                'package_id' => (int) $pt->package_id,
                'script_id' => (string) $pt->script_id,
                'script_name' => $pt->script_name,
                'release_id' => (int) $pt->release_id,
                'script_version' => (string) $pt->script_version,
                'script_content_digest' => $pt->script_content_digest,
                'script_execution_digest' => $pt->script_execution_digest,
                'resolution_source' => (string) $pt->resolution_source,
                'source_action_tag_key' => $pt->source_action_tag_key,
                'cms_scope' => $pt->cms_scope,
                'shortcode_mapping' => $pt->shortcode_mapping,
                'shortcode_mapping_version' => (int) $pt->shortcode_mapping_version,
                'variables' => $projectedVars['variables'] ?? [],
                'is_enabled' => (bool) $pt->is_enabled,
                'sort_order' => (int) $pt->sort_order,
            ];
        }

        $assignmentsByContentId = [];
        $allAssignments = SaCampaignContentAssignment::query()
            ->where('campaign_id', $campaign->id)
            ->orderBy('id')
            ->get();
        $planTagById = $planTags->keyBy('id');

        foreach ($allAssignments as $a) {
            /** @var SaCampaignActionTag|null $pt */
            $pt = $planTagById->get($a->campaign_action_tag_id);
            $assignmentsByContentId[(int) $a->campaign_content_id][] = [
                'id' => (string) $a->assignment_uuid,
                'plan_tag_id' => $planTagUuidById[(int) $a->campaign_action_tag_id] ?? null,
                'action_tag_id' => $pt ? (int) $pt->action_tag_id : null,
                'action_tag_key' => $pt ? (string) $pt->action_tag_key : null,
                'package_id' => (int) $a->package_id,
                'release_id' => (int) $a->release_id,
                'script_id' => (string) $a->script_id,
                'script_version' => (string) $a->script_version,
                'source_format' => (string) $a->source_format,
                'shortcode_mapping' => $a->shortcode_mapping,
                'mapping_version' => (int) $a->mapping_version,
                'input' => is_array($a->input) ? $a->input : [],
                'status' => (string) $a->status,
            ];
        }

        $contents = SaCampaignContent::query()
            ->where('campaign_id', $campaign->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $counts = [
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
        $contentsDto = [];
        foreach ($contents as $c) {
            $counts['total']++;
            $st = (string) $c->status;
            if (array_key_exists($st, $counts)) {
                $counts[$st]++;
            }
            $row = [
                'id' => (string) $c->content_uuid,
                'external_ref' => $c->external_ref,
                'status' => $st,
                'sort_order' => (int) $c->sort_order,
                'assigned_account_id' => null,
                'last_error_code' => $c->last_error_code,
                'last_error_message' => $c->last_error_message,
                'input' => is_array($c->input) ? $c->input : [],
                'assignments' => $assignmentsByContentId[(int) $c->id] ?? [],
            ];
            if (SocialAutomationSchema::campaignShortcodeReady()) {
                $row['source_format'] = (string) ($c->source_format ?? 'shortcode');
                $row['raw_source'] = $c->raw_source;
                $row['mapping_version'] = $c->mapping_version !== null ? (int) $c->mapping_version : null;
            }
            $contentsDto[] = $row;
        }

        $dto = $baseDetail ?? [
            'id' => (string) $campaign->campaign_uuid,
            'name' => (string) $campaign->name,
            'platform' => (string) $campaign->platform,
            'status' => (string) $campaign->status,
            'execution_mode' => (string) $campaign->execution_mode,
            'run_mode' => (string) ($campaign->run_mode ?: CampaignRunMode::Live->value),
            'script_id' => (string) $campaign->script_id,
            'script_version' => (string) $campaign->script_version,
            'script_content_digest' => $campaign->script_content_digest,
            'account_id' => null,
            'resource_recheck_seconds' => (int) $campaign->resource_recheck_seconds,
            'summary' => $counts,
            'next_recheck_at' => $campaign->next_recheck_at?->toIso8601String(),
            'shortcode_mapping' => null,
            'shortcode_mapping_version' => 0,
        ];

        $dto['plan_version'] = (int) ($campaign->plan_version ?? 2);
        $dto['action_tags'] = $actionTagsDto;
        $dto['contents'] = $contentsDto;

        return $dto;
    }

    /**
     * @param  array<string, mixed>  $tagRow
     * @return array<string, mixed>
     */
    private function resolveAndValidateTagEntry(array $tagRow, int $index, CampaignRunMode $runMode): array
    {
        $tagKey = strtolower(trim((string) ($tagRow['action_tag_key'] ?? $tagRow['tag_key'] ?? '')));
        $tagId = isset($tagRow['action_tag_id']) && $tagRow['action_tag_id'] !== '' ? (int) $tagRow['action_tag_id'] : null;

        if ($tagKey === '' && ($tagId === null || $tagId <= 0)) {
            return $this->fail(
                422,
                ['action_tag_required:'.$index],
                ["action_tags.{$index}.action_tag_key" => ['action_tag_key or action_tag_id is required.']]
            );
        }

        $tag = null;
        if ($tagId !== null && $tagId > 0) {
            $tag = SaActionTag::query()->with(['site', 'path'])->find($tagId);
            if (! $tag instanceof SaActionTag) {
                return $this->fail(
                    422,
                    ['action_tag_not_found'],
                    ["action_tags.{$index}.action_tag_id" => ["Action Tag #{$tagId} not found."]]
                );
            }
            if ($tagKey !== '' && strcasecmp((string) $tag->tag_key, $tagKey) !== 0) {
                return $this->fail(
                    422,
                    ['action_tag_mismatch'],
                    ["action_tags.{$index}.action_tag_key" => ["action_tag_key '{$tagKey}' does not match action_tag_id {$tagId}."]]
                );
            }
        } else {
            $tag = SaActionTag::query()->with(['site', 'path'])->where('tag_key', $tagKey)->first();
            if (! $tag instanceof SaActionTag) {
                return $this->fail(
                    422,
                    ['action_tag_not_found'],
                    ["action_tags.{$index}.action_tag_key" => ["Action Tag '{$tagKey}' not found."]]
                );
            }
        }

        $site = $this->resolveSiteForTag($tag);
        if (! $site instanceof SaSite) {
            return $this->fail(
                422,
                ['action_tag_site_missing'],
                ["action_tags.{$index}.action_tag_key" => ["Action Tag '{$tag->tag_key}' has no valid Site association."]]
            );
        }

        if (isset($tagRow['site_id']) && $tagRow['site_id'] !== '' && (int) $tagRow['site_id'] !== (int) $site->id) {
            return $this->fail(
                422,
                ['action_tag_site_mismatch'],
                ["action_tags.{$index}.site_id" => ['Submitted site_id does not match the Action Tag Site.']]
            );
        }
        if (isset($tagRow['site_key']) && trim((string) $tagRow['site_key']) !== '' && strcasecmp(trim((string) $tagRow['site_key']), (string) $site->key) !== 0) {
            return $this->fail(
                422,
                ['action_tag_site_mismatch'],
                ["action_tags.{$index}.site_key" => ['Submitted site_key does not match the Action Tag Site.']]
            );
        }

        if (! $tag->isEnabled()) {
            return $this->fail(
                422,
                ['action_tag_disabled'],
                ["action_tags.{$index}.action_tag_key" => ["Action Tag '{$tag->tag_key}' is disabled."]]
            );
        }

        $res = $this->scriptResolver->resolveForTag($tag, null);
        if (! ($res['ok'] ?? false)) {
            $reason = (string) ($res['reason'] ?? 'script_not_found');

            return $this->fail(
                422,
                [$reason],
                ["action_tags.{$index}.action_tag_key" => ["Script resolution failed for '{$tag->tag_key}': {$reason}."]]
            );
        }

        /** @var SaPackage $package */
        $package = $res['package'];
        /** @var SaPackageRelease $release */
        $release = $res['release'];
        $source = (string) ($res['source'] ?? ScriptResolutionService::SOURCE_SITE_SPECIFIC);

        if (array_key_exists('release_id', $tagRow) && $tagRow['release_id'] !== null && $tagRow['release_id'] !== '') {
            $expectedReleaseId = (int) $tagRow['release_id'];
            if ($expectedReleaseId !== (int) $release->id) {
                return $this->fail(
                    409,
                    ['release_pin_mismatch'],
                    ["action_tags.{$index}.release_id" => ["Submitted release_id {$expectedReleaseId} is stale or does not match authoritative release {$release->id}."]]
                );
            }
        }

        if (array_key_exists('script_id', $tagRow) && trim((string) $tagRow['script_id']) !== '') {
            $expectedScriptId = trim((string) $tagRow['script_id']);
            if ($expectedScriptId !== (string) $package->package_key) {
                return $this->fail(
                    409,
                    ['script_pin_mismatch'],
                    ["action_tags.{$index}.script_id" => ["Submitted script_id '{$expectedScriptId}' does not match authoritative script '{$package->package_key}'."]]
                );
            }
        }

        if (array_key_exists('script_version', $tagRow) && trim((string) $tagRow['script_version']) !== '') {
            $expectedVersion = trim((string) $tagRow['script_version']);
            if ($expectedVersion !== (string) $release->version) {
                return $this->fail(
                    409,
                    ['script_version_mismatch'],
                    ["action_tags.{$index}.script_version" => ["Submitted script_version '{$expectedVersion}' does not match authoritative version '{$release->version}'."]]
                );
            }
        }

        if (array_key_exists('resolution_source', $tagRow) && trim((string) $tagRow['resolution_source']) !== '') {
            $expectedSource = trim((string) $tagRow['resolution_source']);
            if ($expectedSource !== $source) {
                return $this->fail(
                    409,
                    ['resolution_source_mismatch'],
                    ["action_tags.{$index}.resolution_source" => ["Submitted resolution_source '{$expectedSource}' does not match authoritative source '{$source}'."]]
                );
            }
        }

        $manifest = is_array($release->manifest) ? $release->manifest : [];
        $schema = $this->variableSchema->fromManifest($manifest);
        if (! ($schema['ok'] ?? false)) {
            $errs = $schema['errors'] ?? ['script_variables_invalid'];

            return $this->fail(
                422,
                $errs,
                ["action_tags.{$index}" => ['Resolved script manifest has invalid variable schema.']]
            );
        }
        /** @var array<string, array<string, mixed>> $variables */
        $variables = $schema['variables'] ?? [];

        if ($runMode->requiresTestCompatibleScript()) {
            $testGate = $this->testScripts->assertCompatibleForTestMode($manifest);
            if (! ($testGate['ok'] ?? false)) {
                $errs = $testGate['errors'] ?? ['script_not_test_compatible'];

                return $this->fail(
                    422,
                    $errs,
                    ["action_tags.{$index}" => ['Resolved script is not compatible with test run_mode.']]
                );
            }
        }

        $mappingDoc = null;
        $mappingVersion = 0;
        if (array_key_exists('shortcode_mapping', $tagRow) && $tagRow['shortcode_mapping'] !== null) {
            if (! is_array($tagRow['shortcode_mapping'])) {
                return $this->fail(
                    422,
                    ['shortcode_mapping_invalid'],
                    ["action_tags.{$index}.shortcode_mapping" => ['shortcode_mapping must be an object.']]
                );
            }
            $mapped = $this->mappingValidator->validate($tagRow['shortcode_mapping'], $variables);
            if (! ($mapped['ok'] ?? false)) {
                $errs = $mapped['errors'] ?? ['shortcode_mapping_invalid'];

                return $this->fail(
                    422,
                    $errs,
                    ["action_tags.{$index}.shortcode_mapping" => ['Invalid shortcode_mapping for resolved Script schema: '.implode(', ', $errs)]]
                );
            }
            $mappingDoc = $mapped['mapping'] ?? null;
            $mappingVersion = 1;
        }

        $cmsScope = is_array($res['cms_scope'] ?? null) ? $res['cms_scope'] : null;
        $cmsType = $cmsScope['cms_type'] ?? SaSite::normalizeCmsType($site->cms_type ?? null);

        return [
            'ok' => true,
            'tag' => [
                'action_tag_id' => (int) $tag->id,
                'action_tag_key' => (string) $tag->tag_key,
                'site_id' => (int) $site->id,
                'site_key' => (string) $site->key,
                'cms_type' => $cmsType,
                'platform' => trim((string) ($tag->platform ?: $site->key)),
                'package_id' => (int) $package->id,
                'script_id' => (string) $package->package_key,
                'script_name' => (string) $package->name,
                'release_id' => (int) $release->id,
                'script_version' => (string) $release->version,
                'script_content_digest' => $release->content_digest,
                'script_execution_digest' => $release->execution_digest,
                'resolution_source' => $source,
                'source_action_tag_key' => $res['source_action_tag_key'] ?? null,
                'cms_scope' => $cmsScope,
                'manifest_snapshot' => $manifest,
                'variables' => $variables,
                'shortcode_mapping' => $mappingDoc,
                'shortcode_mapping_version' => $mappingVersion,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $contentRow
     * @param  list<array<string, mixed>>  $preparedTags
     * @param  array<string, int>  $tagsByKey
     * @param  array<int, int>  $tagsById
     * @return array<string, mixed>
     */
    private function prepareContentWithAssignments(
        array $contentRow,
        int $ci,
        array $preparedTags,
        array $tagsByKey,
        array $tagsById,
        ?string $externalRef,
    ): array {
        $hasRaw = array_key_exists('raw_source', $contentRow) || array_key_exists('body', $contentRow);
        $rawSource = $hasRaw ? (string) ($contentRow['raw_source'] ?? $contentRow['body'] ?? '') : null;
        $sortOrder = isset($contentRow['sort_order']) ? (int) $contentRow['sort_order'] : $ci;

        $rawAssignments = $contentRow['assignments'] ?? $contentRow['tag_assignments'] ?? null;
        if ($rawAssignments === null && is_array($contentRow['action_tag_keys'] ?? null)) {
            $rawAssignments = array_map(
                static fn ($k): array => ['action_tag_key' => $k],
                $contentRow['action_tag_keys']
            );
        }

        if (! is_array($rawAssignments) || ! array_is_list($rawAssignments) || $rawAssignments === []) {
            return $this->fail(
                422,
                ['content_assignments_required'],
                ["contents.{$ci}.assignments" => ['Each Content item must be assigned to at least one planned Action Tag.']]
            );
        }

        $preparedAssignments = [];
        $seenTagIndices = [];
        $contentSourceFormat = $hasRaw ? 'shortcode' : 'legacy_input';

        foreach ($rawAssignments as $ai => $assignRow) {
            if (! is_array($assignRow)) {
                return $this->fail(
                    422,
                    ['assignment_invalid'],
                    ["contents.{$ci}.assignments.{$ai}" => ['Assignment must be an object.']]
                );
            }

            $tagIdx = $this->resolvePlannedTagIndex($assignRow, $tagsByKey, $tagsById);
            if ($tagIdx === null) {
                return $this->fail(
                    422,
                    ['assigned_tag_not_in_campaign'],
                    ["contents.{$ci}.assignments.{$ai}.action_tag_key" => ['Assigned Action Tag is not part of this Campaign plan.']]
                );
            }
            if (isset($seenTagIndices[$tagIdx])) {
                return $this->fail(
                    422,
                    ['duplicate_content_tag_assignment'],
                    ["contents.{$ci}.assignments.{$ai}.action_tag_key" => ['Content is assigned to the same Action Tag more than once.']]
                );
            }
            $seenTagIndices[$tagIdx] = true;

            $plannedTag = $preparedTags[$tagIdx];
            /** @var array<string, array<string, mixed>> $variables */
            $variables = $plannedTag['variables'];

            $builtAssign = $this->buildAssignmentPayload(
                $assignRow,
                $plannedTag,
                $variables,
                $hasRaw,
                $rawSource,
                $ci,
                $ai,
            );
            if (! ($builtAssign['ok'] ?? false)) {
                return $builtAssign;
            }

            $preparedAssignments[] = [
                'tag_idx' => $tagIdx,
                'source_format' => $builtAssign['source_format'],
                'shortcode_mapping' => $builtAssign['shortcode_mapping'],
                'mapping_version' => $builtAssign['mapping_version'],
                'input' => $builtAssign['input'],
            ];
        }

        return [
            'ok' => true,
            'content' => [
                'external_ref' => $externalRef,
                'sort_order' => $sortOrder,
                'source_format' => $contentSourceFormat,
                'raw_source' => $rawSource,
                'assignments' => $preparedAssignments,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $assignRow
     * @param  array<string, mixed>  $plannedTag
     * @param  array<string, array<string, mixed>>  $variables
     * @return array<string, mixed>
     */
    private function buildAssignmentPayload(
        array $assignRow,
        array $plannedTag,
        array $variables,
        bool $hasContentRaw,
        ?string $contentRawSource,
        int $ci,
        int $ai,
    ): array {
        $hasInput = array_key_exists('input', $assignRow) && $assignRow['input'] !== null;
        if ($hasInput) {
            if (! is_array($assignRow['input'])) {
                return $this->fail(
                    422,
                    ['content_input_invalid'],
                    ["contents.{$ci}.assignments.{$ai}.input" => ['Assignment input must be an object.']]
                );
            }
            if ($variables !== []) {
                $validated = $this->shortcodePipeline->validateStoredInput($assignRow['input'], $variables);
                if (! ($validated['ok'] ?? false)) {
                    $errs = $validated['errors'] ?? ['content_input_invalid'];

                    return $this->fail(
                        422,
                        $errs,
                        ["contents.{$ci}.assignments.{$ai}.input" => ['Assignment input does not match Script variable schema: '.implode(', ', $errs)]]
                    );
                }
                $typedInput = $validated['input'] ?? [];
            } else {
                $sanitized = $this->sanitizer->sanitize($assignRow['input'], []);
                $typedInput = $sanitized['input'] ?? [];
            }

            return [
                'ok' => true,
                'source_format' => 'legacy_input',
                'shortcode_mapping' => null,
                'mapping_version' => 0,
                'input' => $typedInput,
            ];
        }

        $hasAssignRaw = array_key_exists('raw_source', $assignRow) || array_key_exists('body', $assignRow);
        $rawSource = $hasAssignRaw
            ? (string) ($assignRow['raw_source'] ?? $assignRow['body'] ?? '')
            : ($hasContentRaw ? (string) $contentRawSource : null);

        $mappingDoc = $plannedTag['shortcode_mapping'] ?? null;
        $mappingVersion = (int) ($plannedTag['shortcode_mapping_version'] ?? 0);

        if (array_key_exists('shortcode_mapping', $assignRow) && $assignRow['shortcode_mapping'] !== null) {
            if (! is_array($assignRow['shortcode_mapping'])) {
                return $this->fail(
                    422,
                    ['shortcode_mapping_invalid'],
                    ["contents.{$ci}.assignments.{$ai}.shortcode_mapping" => ['shortcode_mapping must be an object.']]
                );
            }
            $mapped = $this->mappingValidator->validate($assignRow['shortcode_mapping'], $variables);
            if (! ($mapped['ok'] ?? false)) {
                $errs = $mapped['errors'] ?? ['shortcode_mapping_invalid'];

                return $this->fail(
                    422,
                    $errs,
                    ["contents.{$ci}.assignments.{$ai}.shortcode_mapping" => ['Invalid shortcode_mapping for Script schema: '.implode(', ', $errs)]]
                );
            }
            $mappingDoc = $mapped['mapping'] ?? null;
            $mappingVersion = 1;
        }

        if ($rawSource !== null) {
            if (! is_array($mappingDoc) || $mappingVersion < 1) {
                if ($variables === []) {
                    return [
                        'ok' => true,
                        'source_format' => 'shortcode',
                        'shortcode_mapping' => null,
                        'mapping_version' => 0,
                        'input' => [],
                    ];
                }

                return $this->fail(
                    422,
                    ['shortcode_mapping_required'],
                    ["contents.{$ci}.assignments.{$ai}.shortcode_mapping" => ['shortcode_mapping is required to map raw_source to Script variables.']]
                );
            }

            $processed = $this->shortcodePipeline->process($rawSource, $mappingDoc, $variables, true);
            if (! ($processed['ok'] ?? false)) {
                $errs = $processed['errors'] ?? ['content_input_invalid'];

                return $this->fail(
                    422,
                    $errs,
                    ["contents.{$ci}.assignments.{$ai}" => ['Failed to resolve content shortcodes for Script schema: '.implode(', ', $errs)]]
                );
            }

            return [
                'ok' => true,
                'source_format' => 'shortcode',
                'shortcode_mapping' => $mappingDoc,
                'mapping_version' => $mappingVersion,
                'input' => $processed['input'] ?? [],
            ];
        }

        if ($this->variableSchema->requiredKeys($variables) !== []) {
            return $this->fail(
                422,
                ['content_input_required'],
                ["contents.{$ci}.assignments.{$ai}" => ['Either input or raw_source with shortcode_mapping is required.']]
            );
        }

        return [
            'ok' => true,
            'source_format' => 'shortcode',
            'shortcode_mapping' => $mappingDoc,
            'mapping_version' => $mappingVersion,
            'input' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $assignRow
     * @param  array<string, int>  $tagsByKey
     * @param  array<int, int>  $tagsById
     */
    private function resolvePlannedTagIndex(array $assignRow, array $tagsByKey, array $tagsById): ?int
    {
        if (isset($assignRow['action_tag_id']) && $assignRow['action_tag_id'] !== '') {
            $id = (int) $assignRow['action_tag_id'];
            if (isset($tagsById[$id])) {
                return $tagsById[$id];
            }
        }
        $key = strtolower(trim((string) ($assignRow['action_tag_key'] ?? $assignRow['tag_key'] ?? '')));
        if ($key !== '' && isset($tagsByKey[$key])) {
            return $tagsByKey[$key];
        }

        return null;
    }

    private function resolveSiteForTag(SaActionTag $tag): ?SaSite
    {
        if ($tag->relationLoaded('site') && $tag->site instanceof SaSite) {
            return $tag->site;
        }
        if ($tag->site_id) {
            $site = SaSite::query()->find((int) $tag->site_id);
            if ($site instanceof SaSite) {
                return $site;
            }
        }
        $siteKey = trim((string) ($tag->site_key ?: $tag->platform));

        return $siteKey !== '' ? SaSite::query()->where('key', $siteKey)->first() : null;
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string, list<string>>  $fieldErrors
     * @return array{ok: false, http: int, errors: list<string>, field_errors: array<string, list<string>>, message: string}
     */
    private function fail(int $http, array $errors, array $fieldErrors): array
    {
        $firstMessage = 'Campaign V2 validation failed.';
        foreach ($fieldErrors as $msgs) {
            if ($msgs !== []) {
                $firstMessage = $msgs[0];
                break;
            }
        }

        return [
            'ok' => false,
            'http' => $http,
            'errors' => array_values(array_unique($errors)),
            'field_errors' => $fieldErrors,
            'message' => $firstMessage,
        ];
    }
}
