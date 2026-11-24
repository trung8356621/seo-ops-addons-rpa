<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Domain\AccountLockPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignInputSanitizer;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignRecheckPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignScriptVariableSchema;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodeMappingValidator;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodePipeline;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignTestScriptPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\VariantPreferenceService;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignContentStatus;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignExecutionMode;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignRunMode;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignStatus;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContent;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContentAssignment;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use Throwable;

final class CampaignV2PlanService
{
    public const MAX_INTERVAL_MINUTES = 10_080;

    public function __construct(
        private readonly ScriptResolutionService $scriptResolver = new ScriptResolutionService,
        private readonly CampaignRecheckPolicy $recheck = new CampaignRecheckPolicy,
        private readonly CampaignTestScriptPolicy $testScripts = new CampaignTestScriptPolicy,
        private readonly CampaignShortcodePipeline $shortcodePipeline = new CampaignShortcodePipeline,
        private readonly CampaignShortcodeMappingValidator $mappingValidator = new CampaignShortcodeMappingValidator,
        private readonly CampaignScriptVariableSchema $variableSchema = new CampaignScriptVariableSchema,
        private readonly CampaignInputSanitizer $sanitizer = new CampaignInputSanitizer,
        private readonly CampaignContentHistoryService $contentHistory = new CampaignContentHistoryService,
        private readonly VariantPreferenceService $variantPreferences = new VariantPreferenceService,
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

        $mode = CampaignExecutionMode::tryFromInput($input['execution_mode'] ?? CampaignExecutionMode::OneAccountManyJobs->value);
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
                        'draft_revision' => 1,
                    ],
                ]);

                $tagModelsByIdx = [];
                foreach ($preparedTags as $idx => $tagData) {
                    $tagAttrs = [
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
                    ];
                    if (Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasColumn('sa_campaign_action_tags', 'interval_minutes')) {
                        $tagAttrs['interval_minutes'] = (int) ($tagData['interval_minutes'] ?? 0);
                    }
                    if (SocialAutomationSchema::scriptVariantGroupsReady()) {
                        $tagAttrs['variant_group_id'] = $tagData['variant_group_id'] ?? null;
                        $tagAttrs['variant_group_uuid'] = $tagData['variant_group_uuid'] ?? null;
                        $tagAttrs['pinned_variants'] = $tagData['pinned_variants'] ?? null;
                    }
                    $tagModelsByIdx[$idx] = SaCampaignActionTag::query()->create($tagAttrs);
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
    public function updateDraft(SaCampaign $campaign, array $input): array
    {
        if (! SocialAutomationSchema::campaignV2Ready()) {
            return $this->fail(503, ['schema_unavailable'], ['schema' => ['Campaign V2 schema is not migrated.']]);
        }

        $initialStatus = (string) $campaign->status;
        if (! in_array($initialStatus, [CampaignStatus::Draft->value, CampaignStatus::Paused->value], true)) {
            return $this->fail(422, ['v2_campaign_not_draft'], ['status' => ['Only V2 Campaigns in draft or paused status can be edited.']]);
        }
        $isPaused = $initialStatus === CampaignStatus::Paused->value;

        if (! array_key_exists('plan_version', $input) || (int) $input['plan_version'] !== 2) {
            $code = array_key_exists('plan_version', $input) ? 'invalid_plan_version' : 'plan_version_required';

            return $this->fail(422, [$code], ['plan_version' => ['plan_version must be explicitly set to 2 for Campaign V2 updates.']]);
        }

        if ($isPaused) {
            if (array_key_exists('name', $input) && trim((string) $input['name']) !== trim((string) $campaign->name)) {
                return $this->fail(422, ['step1_immutable_after_start'], ['name' => ['Campaign name is immutable after start.']]);
            }
            $name = (string) $campaign->name;
            if (array_key_exists('run_mode', $input) && $input['run_mode'] !== null && $input['run_mode'] !== '') {
                $submittedRunMode = CampaignRunMode::tryFromInput($input['run_mode']);
                if ($submittedRunMode === null || $submittedRunMode->value !== (string) $campaign->run_mode) {
                    return $this->fail(422, ['step1_immutable_after_start'], ['run_mode' => ['run_mode is immutable after start.']]);
                }
            }
            $runMode = CampaignRunMode::tryFromInput($campaign->run_mode ?? CampaignRunMode::Live->value) ?? CampaignRunMode::Live;
        } else {
            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '') {
                return $this->fail(422, ['name_required'], ['name' => ['Campaign name is required.']]);
            }

            $runMode = CampaignRunMode::tryFromInput($input['run_mode'] ?? $campaign->run_mode ?? CampaignRunMode::Live->value);
            if ($runMode === null) {
                return $this->fail(422, ['invalid_run_mode'], ['run_mode' => ['Invalid run_mode.']]);
            }
        }

        $mode = CampaignExecutionMode::tryFromInput($input['execution_mode'] ?? $campaign->execution_mode ?? CampaignExecutionMode::OneAccountManyJobs->value);
        if ($mode === null) {
            return $this->fail(422, ['invalid_execution_mode'], ['execution_mode' => ['Invalid execution_mode.']]);
        }

        $recheckSeconds = $this->recheck->normalizeInterval(
            (int) ($input['resource_recheck_seconds'] ?? $campaign->resource_recheck_seconds ?? CampaignRecheckPolicy::DEFAULT_INTERVAL_SECONDS)
        );

        $hasActionTagsInput = array_key_exists('action_tags', $input);
        if (! $isPaused || $hasActionTagsInput) {
            if (! $hasActionTagsInput || ! is_array($input['action_tags']) || ! array_is_list($input['action_tags']) || $input['action_tags'] === []) {
                return $this->fail(422, ['action_tags_required'], ['action_tags' => ['Complete action_tags array with at least one Action Tag is required.']]);
            }
        }

        $hasContentsInput = array_key_exists('contents', $input);
        if (! $isPaused || $hasContentsInput) {
            if (! $hasContentsInput || ! is_array($input['contents']) || ! array_is_list($input['contents'])) {
                return $this->fail(422, ['contents_required'], ['contents' => ['Complete contents array is required for V2 draft update (pass [] to clear).']]);
            }
        }

        $rawTags = $hasActionTagsInput ? $input['action_tags'] : null;
        $rawContents = $hasContentsInput ? $input['contents'] : null;

        try {
            return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use (
                $campaign,
                $input,
                $name,
                $runMode,
                $mode,
                $recheckSeconds,
                $rawTags,
                $rawContents,
            ): array {
                $locked = SaCampaign::query()->where('id', $campaign->id)->lockForUpdate()->first();
                if (! $locked instanceof SaCampaign) {
                    return $this->fail(404, ['campaign_not_found'], ['campaign' => ['Campaign not found.']]);
                }
                if (! in_array((string) $locked->status, [CampaignStatus::Draft->value, CampaignStatus::Paused->value], true)) {
                    return $this->fail(422, ['v2_campaign_not_draft'], ['status' => ['Only V2 Campaigns in draft or paused status can be edited.']]);
                }
                $isPausedLocked = (string) $locked->status === CampaignStatus::Paused->value;

                $meta = is_array($locked->meta) ? $locked->meta : [];
                $currentRevision = (int) (($meta['draft_revision'] ?? null) ?: 1);

                if (array_key_exists('revision', $input) && $input['revision'] !== null && $input['revision'] !== '') {
                    if ((int) $input['revision'] !== $currentRevision) {
                        return $this->fail(
                            409,
                            ['campaign_revision_conflict'],
                            ['revision' => ["Stale revision {$input['revision']}; current draft revision is {$currentRevision}."]]
                        );
                    }
                }
                if (array_key_exists('updated_at', $input) && trim((string) $input['updated_at']) !== '') {
                    try {
                        $expectedTs = \Illuminate\Support\Carbon::parse((string) $input['updated_at'])->getTimestamp();
                    } catch (Throwable) {
                        return $this->fail(422, ['invalid_updated_at'], ['updated_at' => ['Invalid updated_at timestamp.']]);
                    }
                    $actualTs = $locked->updated_at?->getTimestamp();
                    if ($actualTs !== null && $expectedTs !== $actualTs) {
                        return $this->fail(
                            409,
                            ['campaign_revision_conflict'],
                            ['updated_at' => ['Campaign draft was modified concurrently. Reload and retry.']]
                        );
                    }
                }

                $existingTags = SaCampaignActionTag::query()
                    ->where('campaign_id', $locked->id)
                    ->lockForUpdate()
                    ->get();
                $existingTagsByUuid = $existingTags->keyBy('plan_tag_uuid');
                $existingTagsByActionId = $existingTags->keyBy('action_tag_id');
                $existingTagsByActionKey = $existingTags->keyBy('action_tag_key');

                $preparedTags = [];
                $tagsByKey = [];
                $tagsById = [];
                $tagsByPlanUuid = [];
                $retainedTagIds = [];
                $platforms = [];

                if ($rawTags === null) {
                    foreach ($existingTags->sortBy('sort_order') as $existingPt) {
                        $resolved = $this->validateRetainedTagEntry($existingPt, [
                            'shortcode_mapping' => $existingPt->shortcode_mapping,
                            'interval_minutes' => (int) ($existingPt->interval_minutes ?? 0),
                        ], count($preparedTags), $runMode);
                        if (! ($resolved['ok'] ?? false)) {
                            return $resolved;
                        }
                        $tagData = $resolved['tag'];
                        $retainedTagIds[(int) $existingPt->id] = true;
                        $idx = count($preparedTags);
                        $preparedTags[] = $tagData;
                        $tagsByKey[(string) $tagData['action_tag_key']] = $idx;
                        $tagsById[(int) $tagData['action_tag_id']] = $idx;
                        $tagsByPlanUuid[(string) $existingPt->plan_tag_uuid] = $idx;
                        $platforms[(string) $tagData['site_key']] = true;
                    }
                } else {
                    foreach ($rawTags as $i => $tagRow) {
                        if (! is_array($tagRow)) {
                            return $this->fail(422, ['action_tag_invalid:'.$i], ["action_tags.{$i}" => ['Action Tag entry must be an object.']]);
                        }

                        $planTagUuid = trim((string) ($tagRow['id'] ?? $tagRow['plan_tag_id'] ?? $tagRow['plan_tag_uuid'] ?? ''));
                        $matchedExisting = null;

                        if ($planTagUuid !== '') {
                            $matchedExisting = $existingTagsByUuid->get($planTagUuid);
                            if (! $matchedExisting instanceof SaCampaignActionTag) {
                                return $this->fail(
                                    422,
                                    ['cross_campaign_tag_reference'],
                                    ["action_tags.{$i}.id" => ["Planned Tag '{$planTagUuid}' does not belong to this Campaign."]]
                                );
                            }
                        } else {
                            $inTagId = isset($tagRow['action_tag_id']) && $tagRow['action_tag_id'] !== '' ? (int) $tagRow['action_tag_id'] : null;
                            $inTagKey = strtolower(trim((string) ($tagRow['action_tag_key'] ?? $tagRow['tag_key'] ?? '')));
                            if ($inTagId !== null && $existingTagsByActionId->has($inTagId)) {
                                $matchedExisting = $existingTagsByActionId->get($inTagId);
                            } elseif ($inTagKey !== '' && $existingTagsByActionKey->has($inTagKey)) {
                                $matchedExisting = $existingTagsByActionKey->get($inTagKey);
                            }
                        }

                        if ($matchedExisting instanceof SaCampaignActionTag) {
                            if (isset($retainedTagIds[(int) $matchedExisting->id])) {
                                return $this->fail(
                                    422,
                                    ['duplicate_action_tag:'.$matchedExisting->action_tag_key],
                                    ["action_tags.{$i}.action_tag_key" => ["Duplicate Action Tag '{$matchedExisting->action_tag_key}' in campaign plan."]]
                                );
                            }
                            if ($isPausedLocked) {
                                $step1Violation = $this->validatePausedTagStep1Immutability($matchedExisting, $tagRow, $i);
                                if ($step1Violation !== null) {
                                    return $step1Violation;
                                }
                            }
                            $resolved = $this->validateRetainedTagEntry($matchedExisting, $tagRow, $i, $runMode);
                            if (! ($resolved['ok'] ?? false)) {
                                return $resolved;
                            }
                            $tagData = $resolved['tag'];
                            $retainedTagIds[(int) $matchedExisting->id] = true;
                        } else {
                            if ($isPausedLocked) {
                                return $this->fail(
                                    422,
                                    ['step1_immutable_after_start'],
                                    ["action_tags.{$i}" => ['Action Tags cannot be added after Campaign start.']]
                                );
                            }
                            $resolved = $this->resolveAndValidateTagEntry($tagRow, $i, $runMode);
                            if (! ($resolved['ok'] ?? false)) {
                                return $resolved;
                            }
                            $tagData = $resolved['tag'];
                            $tagData['existing_model'] = null;
                        }

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
                        if ($matchedExisting instanceof SaCampaignActionTag) {
                            $tagsByPlanUuid[(string) $matchedExisting->plan_tag_uuid] = $idx;
                        }
                        $platforms[(string) $tagData['site_key']] = true;
                    }

                    if ($isPausedLocked && count($retainedTagIds) !== $existingTags->count()) {
                        return $this->fail(
                            422,
                            ['step1_immutable_after_start'],
                            ['action_tags' => ['Action Tags cannot be removed after Campaign start.']]
                        );
                    }
                }

                $existingContents = SaCampaignContent::query()
                    ->where('campaign_id', $locked->id)
                    ->lockForUpdate()
                    ->get();
                $existingContentsByUuid = $existingContents->keyBy('content_uuid');

                $existingAssignments = SaCampaignContentAssignment::query()
                    ->where('campaign_id', $locked->id)
                    ->lockForUpdate()
                    ->get();
                $existingAssignByUuid = $existingAssignments->keyBy('assignment_uuid');
                $existingAssignByContentAndTag = [];
                foreach ($existingAssignments as $ea) {
                    $existingAssignByContentAndTag[(int) $ea->campaign_content_id][(int) $ea->campaign_action_tag_id] = $ea;
                }

                $preparedContents = [];
                $seenExternalRefs = [];
                $retainedContentIds = [];
                $executedContentIds = [];

                if ($rawContents !== null) {
                    $jobContentIds = [];
                    if ($isPausedLocked && SocialAutomationSchema::jobsReady()) {
                        $jobContentIds = array_flip(
                            SaJob::query()
                                ->where('campaign_id', $locked->id)
                                ->whereNotNull('campaign_content_id')
                                ->pluck('campaign_content_id')
                                ->map(static fn ($id): int => (int) $id)
                                ->all()
                        );
                    }
                    foreach ($existingContents as $ec) {
                        if ($isPausedLocked && $this->isContentImmutableAfterExecution($ec, $jobContentIds)) {
                            $executedContentIds[(int) $ec->id] = true;
                        }
                    }

                    foreach ($rawContents as $ci => $contentRow) {
                        if (! is_array($contentRow)) {
                            return $this->fail(422, ['content_invalid:'.$ci], ["contents.{$ci}" => ['Content item must be an object.']]);
                        }

                        $contentUuid = trim((string) ($contentRow['id'] ?? $contentRow['content_uuid'] ?? ''));
                        $existingContent = null;
                        if ($contentUuid !== '') {
                            $existingContent = $existingContentsByUuid->get($contentUuid);
                            if (! $existingContent instanceof SaCampaignContent) {
                                return $this->fail(
                                    422,
                                    ['cross_campaign_content_reference'],
                                    ["contents.{$ci}.id" => ["Content '{$contentUuid}' does not belong to this Campaign."]]
                                );
                            }
                            if (isset($retainedContentIds[(int) $existingContent->id])) {
                                return $this->fail(
                                    422,
                                    ['duplicate_content_id'],
                                    ["contents.{$ci}.id" => ["Duplicate Content '{$contentUuid}' in payload."]]
                                );
                            }
                            $retainedContentIds[(int) $existingContent->id] = true;
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
                            $tagsByPlanUuid,
                        );
                        if (! ($builtContent['ok'] ?? false)) {
                            return $builtContent;
                        }

                        $contentPrepared = $builtContent['content'];
                        $contentPrepared['existing_model'] = $existingContent;

                        // Validate assignment IDs against current campaign & content
                        $rawAssigns = $contentRow['assignments'] ?? $contentRow['tag_assignments'] ?? [];
                        if (is_array($rawAssigns)) {
                            $seenAssignUuids = [];
                            foreach ($rawAssigns as $ai => $assignRow) {
                                if (! is_array($assignRow)) {
                                    continue;
                                }
                                $assignUuid = trim((string) ($assignRow['id'] ?? $assignRow['assignment_uuid'] ?? ''));
                                if ($assignUuid === '') {
                                    continue;
                                }
                                $ea = $existingAssignByUuid->get($assignUuid);
                                if (! $ea instanceof SaCampaignContentAssignment
                                    || ! $existingContent instanceof SaCampaignContent
                                    || (int) $ea->campaign_content_id !== (int) $existingContent->id) {
                                    return $this->fail(
                                        422,
                                        ['cross_campaign_assignment_reference'],
                                        ["contents.{$ci}.assignments.{$ai}.id" => ["Assignment '{$assignUuid}' does not belong to this Content/Campaign."]]
                                    );
                                }
                                if (isset($seenAssignUuids[$assignUuid])) {
                                    return $this->fail(
                                        422,
                                        ['duplicate_assignment_id'],
                                        ["contents.{$ci}.assignments.{$ai}.id" => ["Duplicate assignment '{$assignUuid}'."]]
                                    );
                                }
                                $seenAssignUuids[$assignUuid] = true;
                                $contentPrepared['assignments'][$ai]['existing_model'] = $ea;
                            }
                        }

                        if ($existingContent instanceof SaCampaignContent && isset($executedContentIds[(int) $existingContent->id])) {
                            $existingAssignsForContent = $existingAssignments
                                ->where('campaign_content_id', $existingContent->id)
                                ->values()
                                ->all();
                            $immutableErr = $this->validateImmutableExecutedContent(
                                $existingContent,
                                $existingAssignsForContent,
                                $contentPrepared,
                                $preparedTags,
                                $ci,
                            );
                            if ($immutableErr !== null) {
                                return $immutableErr;
                            }
                        }

                        $preparedContents[] = $contentPrepared;
                    }

                    if ($isPausedLocked) {
                        foreach (array_keys($executedContentIds) as $execCid) {
                            if (! isset($retainedContentIds[$execCid])) {
                                return $this->fail(
                                    422,
                                    ['executed_content_immutable'],
                                    ['contents' => ['Already executed or claimed Content items cannot be deleted while Paused.']]
                                );
                            }
                        }
                    }
                }

                // --- Reconciliation persistence (all validations passed) ---

                // 1. Delete removed Action Tags (and their assignments explicitly for SQLite/FK-agnostic safety)
                if (! $isPausedLocked) {
                    $removedTagIds = $existingTags
                        ->filter(fn (SaCampaignActionTag $pt): bool => ! isset($retainedTagIds[(int) $pt->id]))
                        ->pluck('id')
                        ->all();
                    if ($removedTagIds !== []) {
                        SaCampaignContentAssignment::query()->whereIn('campaign_action_tag_id', $removedTagIds)->delete();
                        SaCampaignActionTag::query()->whereIn('id', $removedTagIds)->delete();
                    }
                }

                // 2. Update retained Action Tags & create new Action Tags
                $tagModelsByIdx = [];
                if ($rawTags === null) {
                    foreach ($preparedTags as $idx => $tagData) {
                        $tagModelsByIdx[$idx] = $tagData['existing_model'];
                    }
                } else {
                    foreach ($preparedTags as $idx => $tagData) {
                        $existingPt = $tagData['existing_model'] ?? null;
                        if ($existingPt instanceof SaCampaignActionTag) {
                            $existingPt->shortcode_mapping = $tagData['shortcode_mapping'];
                            $existingPt->shortcode_mapping_version = $tagData['shortcode_mapping_version'];
                            if (! $isPausedLocked) {
                                $existingPt->sort_order = $idx;
                            }
                            if (Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasColumn('sa_campaign_action_tags', 'interval_minutes')) {
                                $existingPt->interval_minutes = (int) ($tagData['interval_minutes'] ?? ($existingPt->interval_minutes ?? 0));
                            }
                            $existingPt->save();
                            $tagModelsByIdx[$idx] = $existingPt;
                        } else {
                            $newTagAttrs = [
                                'plan_tag_uuid' => (string) Str::uuid(),
                                'campaign_id' => $locked->id,
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
                            ];
                            if (Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasColumn('sa_campaign_action_tags', 'interval_minutes')) {
                                $newTagAttrs['interval_minutes'] = (int) ($tagData['interval_minutes'] ?? 0);
                            }
                            $tagModelsByIdx[$idx] = SaCampaignActionTag::query()->create($newTagAttrs);
                        }
                    }
                }

                if ($rawContents !== null) {
                    // 3. Delete removed Contents first (prevents external_ref unique index collisions)
                    $removedContentIds = $existingContents
                        ->filter(fn (SaCampaignContent $c): bool => ! isset($retainedContentIds[(int) $c->id]) && ! isset($executedContentIds[(int) $c->id]))
                        ->pluck('id')
                        ->all();
                    if ($removedContentIds !== []) {
                        SaCampaignContentAssignment::query()->whereIn('campaign_content_id', $removedContentIds)->delete();
                        SaCampaignContent::query()->whereIn('id', $removedContentIds)->delete();
                    }

                    // Clear external_ref on mutable retained contents temporarily to prevent swap collisions
                    $mutableRetainedIds = array_values(array_filter(
                        array_keys($retainedContentIds),
                        static fn (int $cid): bool => ! isset($executedContentIds[$cid])
                    ));
                    if ($mutableRetainedIds !== []) {
                        SaCampaignContent::query()
                            ->whereIn('id', $mutableRetainedIds)
                            ->update(['external_ref' => null]);
                    }

                    // 4. Update retained mutable Contents / create new Contents, and reconcile their assignments
                    foreach ($preparedContents as $cRow) {
                        /** @var SaCampaignContent|null $content */
                        $content = $cRow['existing_model'] ?? null;
                        if ($content instanceof SaCampaignContent && isset($executedContentIds[(int) $content->id])) {
                            continue;
                        }
                        if ($content instanceof SaCampaignContent) {
                            $content->external_ref = $cRow['external_ref'];
                            $content->sort_order = $cRow['sort_order'];
                            if (SocialAutomationSchema::campaignShortcodeReady()) {
                                $content->source_format = $cRow['source_format'];
                                $content->raw_source = $cRow['raw_source'];
                            }
                            $content->save();
                        } else {
                            $contentAttrs = [
                                'content_uuid' => (string) Str::uuid(),
                                'campaign_id' => $locked->id,
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
                        }

                        $retainedAssignIds = [];
                        foreach ($cRow['assignments'] as $aRow) {
                            /** @var SaCampaignActionTag $planTag */
                            $planTag = $tagModelsByIdx[$aRow['tag_idx']];
                            /** @var SaCampaignContentAssignment|null $existingAssign */
                            $existingAssign = $aRow['existing_model']
                                ?? ($existingAssignByContentAndTag[(int) $content->id][(int) $planTag->id] ?? null);

                            if ($existingAssign instanceof SaCampaignContentAssignment) {
                                $existingAssign->campaign_action_tag_id = $planTag->id;
                                $existingAssign->package_id = $planTag->package_id;
                                $existingAssign->release_id = $planTag->release_id;
                                $existingAssign->script_id = $planTag->script_id;
                                $existingAssign->script_version = $planTag->script_version;
                                $existingAssign->source_format = $aRow['source_format'];
                                $existingAssign->shortcode_mapping = $aRow['shortcode_mapping'];
                                $existingAssign->mapping_version = $aRow['mapping_version'];
                                $existingAssign->input = $aRow['input'];
                                $existingAssign->save();
                                $retainedAssignIds[(int) $existingAssign->id] = true;
                            } else {
                                $createdAssign = SaCampaignContentAssignment::query()->create([
                                    'assignment_uuid' => (string) Str::uuid(),
                                    'campaign_id' => $locked->id,
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
                                $retainedAssignIds[(int) $createdAssign->id] = true;
                            }
                        }

                        $assignDeleteQuery = SaCampaignContentAssignment::query()
                            ->where('campaign_content_id', $content->id);
                        if ($retainedAssignIds !== []) {
                            $assignDeleteQuery->whereNotIn('id', array_keys($retainedAssignIds));
                        }
                        $assignDeleteQuery->delete();
                    }
                }

                $meta['v2_plan'] = true;
                $meta['tag_count'] = count($preparedTags);
                $meta['draft_revision'] = $currentRevision + 1;

                if (! $isPausedLocked) {
                    $campaignPlatform = count($platforms) === 1 ? (string) array_key_first($platforms) : 'multi';
                    $locked->name = $name;
                    $locked->platform = $campaignPlatform;
                    $locked->run_mode = $runMode->value;
                }
                $locked->execution_mode = $mode->value;
                $locked->resource_recheck_seconds = $recheckSeconds;
                $locked->meta = $meta;
                $locked->save();

                return ['ok' => true, 'http' => 200, 'data' => $this->detailDto($locked->fresh() ?? $locked)];
            });
        } catch (Throwable) {
            return $this->fail(500, ['update_failed'], ['campaign' => ['Failed to update Campaign V2 draft.']]);
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

        $campaignMeta = is_array($campaign->meta) ? $campaign->meta : [];
        $gates = is_array($campaignMeta['action_tag_gates'] ?? null) ? $campaignMeta['action_tag_gates'] : [];
        $lastSuccessByTagId = $this->lastSuccessByActionTag($campaign);
        $nextRunByTagId = [];

        $planTagUuidById = [];
        $actionTagsDto = [];
        foreach ($planTags as $pt) {
            $planTagUuidById[(int) $pt->id] = (string) $pt->plan_tag_uuid;
            $manifest = is_array($pt->manifest_snapshot) ? $pt->manifest_snapshot : [];
            $projectedVars = $manifest !== []
                ? $this->variableSchema->projectStaffList($manifest)
                : ['status' => 'empty', 'variables' => [], 'errors' => []];
            $gate = is_array($gates[(string) $pt->id] ?? null)
                ? $gates[(string) $pt->id]
                : ['blocked' => false];
            $intervalMinutes = max(0, (int) ($pt->interval_minutes ?? 0));
            $lastSuccessAt = $lastSuccessByTagId[(int) $pt->id] ?? null;
            $nextRunAt = $lastSuccessAt !== null
                ? $lastSuccessAt->copy()->addMinutes($intervalMinutes)
                : null;
            $nextRunByTagId[(int) $pt->id] = $nextRunAt;

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
                'variant_group_id' => SocialAutomationSchema::scriptVariantGroupsReady() ? $pt->variant_group_uuid : null,
                'pinned_variants' => SocialAutomationSchema::scriptVariantGroupsReady() ? $pt->pinned_variants : null,
                'variant_preferences' => SocialAutomationSchema::scriptVariantGroupsReady()
                    ? $this->variantPreferences->listPreferencesForActionTag(
                        (string) $campaign->installation_id,
                        (string) $pt->action_tag_key,
                        $pt->variant_group_uuid ? (string) $pt->variant_group_uuid : null,
                    )
                    : [],
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
                'interval_minutes' => $intervalMinutes,
                'last_success_at' => $lastSuccessAt?->toIso8601String(),
                'next_run_at' => $nextRunAt?->toIso8601String(),
                'gate' => $gate,
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
        $attemptsByContent = $this->contentHistory->attemptsByContentId($campaign, $contents);
        $articleTitles = [];
        if (SocialAutomationSchema::articleLibraryReady()) {
            $aIds = $contents->pluck('article_id')->filter()->unique()->map(static fn ($id): int => (int) $id)->values()->all();
            if ($aIds !== []) {
                $articleTitles = \Omnichannel\Addons\SocialAutomation\Models\SaArticle::query()
                    ->whereIn('id', $aIds)
                    ->pluck('title', 'id')
                    ->mapWithKeys(static fn ($t, $id): array => [(int) $id => (string) $t])
                    ->all();
            }
        }

        $seenQueueHead = [];
        $inFlightTags = [];
        foreach ($contents as $cRow) {
            if ((string) $cRow->status === CampaignContentStatus::Running->value) {
                $assigns = $assignmentsByContentId[(int) $cRow->id] ?? [];
                if (count($assigns) === 1 && isset($assigns[0]['plan_tag_id'])) {
                    $inFlightTags[$assigns[0]['plan_tag_id']] = true;
                }
            }
        }

        $cStatus = CampaignStatus::tryFrom((string) $campaign->status);
        $campaignAllowsRetry = $cStatus !== null && ! $cStatus->isTerminal() && $cStatus !== CampaignStatus::Draft;
        $activeJobContentIds = [];
        $activeCampaignJobs = SaJob::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', AccountLockPolicy::ACTIVE_STATUSES)
            ->get(['campaign_content_id', 'payload']);
        foreach ($activeCampaignJobs as $aj) {
            if ($aj->campaign_content_id !== null) {
                $activeJobContentIds[(int) $aj->campaign_content_id] = true;
            } elseif (is_array($aj->payload) && isset($aj->payload['campaign_action_tag_id'])) {
                $mjUuid = $planTagUuidById[(int) $aj->payload['campaign_action_tag_id']] ?? null;
                if ($mjUuid !== null) {
                    $inFlightTags[$mjUuid] = true;
                }
            }
        }

        $headContentIdByTagUuid = [];
        $fallbackBlockedTagUuids = [];
        foreach ($contents as $cRow) {
            $rowStatus = (string) $cRow->status;
            $assigns = $assignmentsByContentId[(int) $cRow->id] ?? [];
            if (count($assigns) !== 1 || ! isset($assigns[0]['plan_tag_id'])) {
                continue;
            }
            $ptUuid = (string) $assigns[0]['plan_tag_id'];
            if (in_array($rowStatus, [
                CampaignContentStatus::Pending->value,
                CampaignContentStatus::WaitingResources->value,
                CampaignContentStatus::FailedLocked->value,
                CampaignContentStatus::Unknown->value,
            ], true) && ! isset($headContentIdByTagUuid[$ptUuid])) {
                $headContentIdByTagUuid[$ptUuid] = (int) $cRow->id;
            }
            if (in_array($rowStatus, [
                CampaignContentStatus::FailedLocked->value,
                CampaignContentStatus::Unknown->value,
            ], true)) {
                $fallbackBlockedTagUuids[$ptUuid] = true;
            }
        }

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
        $now = now();
        foreach ($contents as $c) {
            $counts['total']++;
            $st = (string) $c->status;
            if (array_key_exists($st, $counts)) {
                $counts[$st]++;
            }
            $contentAssigns = $assignmentsByContentId[(int) $c->id] ?? [];
            $primaryPlanTagId = null;
            if (count($contentAssigns) === 1 && isset($contentAssigns[0]['plan_tag_id'])) {
                foreach ($planTagUuidById as $ptIntId => $ptUuid) {
                    if ($ptUuid === $contentAssigns[0]['plan_tag_id']) {
                        $primaryPlanTagId = (string) $ptIntId;
                        break;
                    }
                }
            }
            $contentGate = $primaryPlanTagId !== null && is_array($gates[$primaryPlanTagId] ?? null)
                ? $gates[$primaryPlanTagId]
                : null;
            $isBlockedByGate = $st === 'pending' && is_array($contentGate) && (($contentGate['blocked'] ?? false) === true);

            $queueState = $isBlockedByGate ? 'blocked_by_failure' : $st;
            $contentNextRunAt = null;
            if (! $isBlockedByGate && $st === 'pending' && $primaryPlanTagId !== null) {
                $ptUuid = $planTagUuidById[(int) $primaryPlanTagId] ?? null;
                $isHead = $ptUuid !== null && ! isset($inFlightTags[$ptUuid]) && ! isset($seenQueueHead[$ptUuid]);
                if ($ptUuid !== null) {
                    $seenQueueHead[$ptUuid] = true;
                }
                $tagNextRun = $nextRunByTagId[(int) $primaryPlanTagId] ?? null;
                if ($isHead && $tagNextRun instanceof \Carbon\CarbonInterface && $now->lt($tagNextRun)) {
                    $queueState = 'waiting_interval';
                    $contentNextRunAt = $tagNextRun->toIso8601String();
                }
            }

            $ptUuidForRow = $primaryPlanTagId !== null ? ($planTagUuidById[(int) $primaryPlanTagId] ?? null) : null;
            $hasActiveJobForRow = $c->current_job_id !== null || isset($activeJobContentIds[(int) $c->id]);
            $isGateBlockedForRetry = $isBlockedByGate
                || ($st === CampaignContentStatus::Pending->value
                    && $primaryPlanTagId !== null
                    && ! array_key_exists($primaryPlanTagId, $gates)
                    && $ptUuidForRow !== null
                    && isset($fallbackBlockedTagUuids[$ptUuidForRow]));

            $canRetry = false;
            if ($campaignAllowsRetry) {
                if ($st === CampaignContentStatus::FailedLocked->value || $st === CampaignContentStatus::Unknown->value) {
                    $canRetry = true;
                } elseif ($st === CampaignContentStatus::Pending->value
                    && ! $hasActiveJobForRow
                    && $isGateBlockedForRetry
                    && $ptUuidForRow !== null
                    && ! isset($inFlightTags[$ptUuidForRow])
                    && (($headContentIdByTagUuid[$ptUuidForRow] ?? null) === (int) $c->id)) {
                    $canRetry = true;
                }
            }

            $row = [
                'id' => (string) $c->content_uuid,
                'content_uuid' => (string) $c->content_uuid,
                'external_ref' => $c->external_ref,
                'status' => $st,
                'queue_state' => $queueState,
                'can_retry' => $canRetry,
                'blocked_by_gate' => $isBlockedByGate ? $contentGate : null,
                'next_run_at' => $contentNextRunAt,
                'sort_order' => (int) $c->sort_order,
                'assigned_account_id' => null,
                'last_error_code' => $c->last_error_code,
                'last_error_message' => $c->last_error_message,
                'input' => is_array($c->input) ? $c->input : [],
                'assignments' => $assignmentsByContentId[(int) $c->id] ?? [],
                'attempts' => $attemptsByContent[(int) $c->id] ?? [],
            ];
            if (SocialAutomationSchema::campaignShortcodeReady()) {
                $row['source_format'] = (string) ($c->source_format ?? 'shortcode');
                $row['raw_source'] = $c->raw_source;
                $row['mapping_version'] = $c->mapping_version !== null ? (int) $c->mapping_version : null;
            }
            if (SocialAutomationSchema::articleLibraryReady()) {
                $row['article_id'] = $c->article_uuid;
                $row['article_title'] = $c->snapshot_payload['title'] ?? ($articleTitles[(int) ($c->article_id ?? 0)] ?? null);
                $row['snapshot_at'] = $c->snapshot_at?->toIso8601String();
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

        $meta = is_array($campaign->meta) ? $campaign->meta : [];
        $dto['plan_version'] = (int) ($campaign->plan_version ?? 2);
        $dto['revision'] = (int) (($meta['draft_revision'] ?? null) ?: 1);
        $dto['updated_at'] = $campaign->updated_at?->toIso8601String();
        $dto['action_tags'] = $actionTagsDto;
        $dto['action_tag_gates'] = $gates;
        $dto['contents'] = $contentsDto;

        return $dto;
    }

    /**
     * Operational update of a single Action Tag's posting interval (Draft/Running/Paused/Completed).
     * Never dispatches jobs, unlocks gates, or mutates pinning/history.
     *
     * @return array{ok: bool, http?: int, data?: array<string, mixed>, errors?: list<string>}
     */
    public function updateActionTagInterval(
        string $campaignUuid,
        string $planTagUuid,
        int $intervalMinutes,
        string $installationId,
    ): array {
        if ($intervalMinutes < 0 || $intervalMinutes > self::MAX_INTERVAL_MINUTES) {
            return ['ok' => false, 'http' => 422, 'errors' => ['invalid_interval_minutes']];
        }

        if (! Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasColumn('sa_campaign_action_tags', 'interval_minutes')) {
            return ['ok' => false, 'http' => 503, 'errors' => ['interval_schema_unavailable']];
        }

        $conn = SocialAutomationServiceConfig::CONNECTION;

        return DB::connection($conn)->transaction(function () use ($campaignUuid, $planTagUuid, $intervalMinutes, $installationId): array {
            $campaign = SaCampaign::query()
                ->notDeleted()
                ->where('campaign_uuid', $campaignUuid)
                ->where('installation_id', $installationId)
                ->lockForUpdate()
                ->first();

            if (! $campaign instanceof SaCampaign) {
                return ['ok' => false, 'http' => 404, 'errors' => ['campaign_not_found']];
            }
            if ((int) ($campaign->plan_version ?? 1) < 2) {
                return ['ok' => false, 'http' => 422, 'errors' => ['v2_campaign_required']];
            }

            $status = CampaignStatus::tryFrom((string) $campaign->status);
            if (! in_array($status, [
                CampaignStatus::Draft,
                CampaignStatus::Running,
                CampaignStatus::Paused,
                CampaignStatus::Completed,
            ], true)) {
                return ['ok' => false, 'http' => 409, 'errors' => ['campaign_not_editable']];
            }

            $planTag = SaCampaignActionTag::query()
                ->where('campaign_id', $campaign->id)
                ->where('plan_tag_uuid', $planTagUuid)
                ->lockForUpdate()
                ->first();

            if (! $planTag instanceof SaCampaignActionTag) {
                return ['ok' => false, 'http' => 404, 'errors' => ['action_tag_not_found']];
            }

            $planTag->interval_minutes = $intervalMinutes;
            $planTag->save();
            $persistedInterval = max(0, (int) $planTag->interval_minutes);

            $lastSuccessByTag = $this->lastSuccessByActionTag($campaign);
            $lastSuccessAt = $lastSuccessByTag[(int) $planTag->id] ?? null;
            $nextRunAt = $lastSuccessAt !== null
                ? $lastSuccessAt->copy()->addMinutes($persistedInterval)
                : null;

            if ($status === CampaignStatus::Running && $nextRunAt !== null) {
                if ($campaign->next_recheck_at === null || $nextRunAt->lt($campaign->next_recheck_at)) {
                    $campaign->next_recheck_at = $nextRunAt->isPast() ? now() : $nextRunAt;
                    $campaign->save();
                }
            }

            return [
                'ok' => true,
                'data' => [
                    'ok' => true,
                    'campaign_id' => (string) $campaign->campaign_uuid,
                    'plan_tag_id' => (string) $planTag->plan_tag_uuid,
                    'interval_minutes' => $persistedInterval,
                    'last_success_at' => $lastSuccessAt?->toIso8601String(),
                    'next_run_at' => $nextRunAt?->toIso8601String(),
                ],
            ];
        });
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

        $parsedInterval = $this->parseIntervalMinutes($tagRow, $index, 0);
        if (! ($parsedInterval['ok'] ?? false)) {
            return $parsedInterval;
        }

        $cmsScope = is_array($res['cms_scope'] ?? null) ? $res['cms_scope'] : null;
        $cmsType = $cmsScope['cms_type'] ?? SaSite::normalizeCmsType($site->cms_type ?? null);

        $parsedInterval = $this->parseIntervalMinutes(
            $tagRow,
            $index,
            max(0, (int) ($existing->interval_minutes ?? 0)),
        );
        if (! ($parsedInterval['ok'] ?? false)) {
            return $parsedInterval;
        }

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
                'variant_group_id' => is_array($res['variant_group'] ?? null) ? ($res['variant_group']['group_id'] ?? null) : null,
                'variant_group_uuid' => is_array($res['variant_group'] ?? null) ? ($res['variant_group']['group_uuid'] ?? null) : null,
                'pinned_variants' => is_array($res['variant_group'] ?? null) ? ($res['variant_group']['variants'] ?? null) : null,
                'variables' => $variables,
                'shortcode_mapping' => $mappingDoc,
                'shortcode_mapping_version' => $mappingVersion,
                'interval_minutes' => $parsedInterval['value'],
            ],
        ];
    }

    /**
     * Validate an existing planned Tag during V2 Draft update without upgrading its pinned Release.
     *
     * @param  array<string, mixed>  $tagRow
     * @return array<string, mixed>
     */
    private function validateRetainedTagEntry(
        SaCampaignActionTag $existing,
        array $tagRow,
        int $index,
        CampaignRunMode $runMode,
    ): array {
        $inKey = strtolower(trim((string) ($tagRow['action_tag_key'] ?? $tagRow['tag_key'] ?? '')));
        if ($inKey !== '' && strcasecmp($inKey, (string) $existing->action_tag_key) !== 0) {
            return $this->fail(
                422,
                ['action_tag_mismatch'],
                ["action_tags.{$index}.action_tag_key" => ["action_tag_key '{$inKey}' does not match planned tag '{$existing->action_tag_key}'."]]
            );
        }

        if (isset($tagRow['action_tag_id']) && $tagRow['action_tag_id'] !== '' && (int) $tagRow['action_tag_id'] !== (int) $existing->action_tag_id) {
            return $this->fail(
                422,
                ['action_tag_mismatch'],
                ["action_tags.{$index}.action_tag_id" => ['action_tag_id does not match existing planned tag.']]
            );
        }

        if (array_key_exists('release_id', $tagRow) && $tagRow['release_id'] !== null && $tagRow['release_id'] !== '') {
            $expectedReleaseId = (int) $tagRow['release_id'];
            if ($expectedReleaseId !== (int) $existing->release_id) {
                return $this->fail(
                    409,
                    ['release_pin_mismatch'],
                    ["action_tags.{$index}.release_id" => ["Submitted release_id {$expectedReleaseId} does not match pinned release {$existing->release_id}."]]
                );
            }
        }

        if (array_key_exists('script_id', $tagRow) && trim((string) $tagRow['script_id']) !== '') {
            $expectedScriptId = trim((string) $tagRow['script_id']);
            if ($expectedScriptId !== (string) $existing->script_id) {
                return $this->fail(
                    409,
                    ['script_pin_mismatch'],
                    ["action_tags.{$index}.script_id" => ["Submitted script_id '{$expectedScriptId}' does not match pinned script '{$existing->script_id}'."]]
                );
            }
        }

        if (array_key_exists('script_version', $tagRow) && trim((string) $tagRow['script_version']) !== '') {
            $expectedVersion = trim((string) $tagRow['script_version']);
            if ($expectedVersion !== (string) $existing->script_version) {
                return $this->fail(
                    409,
                    ['script_version_mismatch'],
                    ["action_tags.{$index}.script_version" => ["Submitted script_version '{$expectedVersion}' does not match pinned version '{$existing->script_version}'."]]
                );
            }
        }

        if (array_key_exists('resolution_source', $tagRow) && trim((string) $tagRow['resolution_source']) !== '') {
            $expectedSource = trim((string) $tagRow['resolution_source']);
            if ($expectedSource !== (string) $existing->resolution_source) {
                return $this->fail(
                    409,
                    ['resolution_source_mismatch'],
                    ["action_tags.{$index}.resolution_source" => ["Submitted resolution_source '{$expectedSource}' does not match pinned source '{$existing->resolution_source}'."]]
                );
            }
        }

        $manifest = is_array($existing->manifest_snapshot) ? $existing->manifest_snapshot : null;
        if ($manifest === null) {
            $rel = SaPackageRelease::query()->find((int) $existing->release_id);
            $manifest = ($rel instanceof SaPackageRelease && is_array($rel->manifest)) ? $rel->manifest : [];
        }

        $schema = $this->variableSchema->fromManifest($manifest);
        if (! ($schema['ok'] ?? false)) {
            $errs = $schema['errors'] ?? ['script_variables_invalid'];

            return $this->fail(422, $errs, ["action_tags.{$index}" => ['Pinned script manifest has invalid variable schema.']]);
        }
        /** @var array<string, array<string, mixed>> $variables */
        $variables = $schema['variables'] ?? [];

        if ($runMode->requiresTestCompatibleScript()) {
            $testGate = $this->testScripts->assertCompatibleForTestMode($manifest);
            if (! ($testGate['ok'] ?? false)) {
                return $this->fail(
                    422,
                    $testGate['errors'] ?? ['script_not_test_compatible'],
                    ["action_tags.{$index}" => ['Pinned script is not compatible with test run_mode.']]
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
                    ["action_tags.{$index}.shortcode_mapping" => ['Invalid shortcode_mapping for pinned Script schema: '.implode(', ', $errs)]]
                );
            }
            $mappingDoc = $mapped['mapping'] ?? null;
            $mappingVersion = max(1, (int) $existing->shortcode_mapping_version);
        }

        $parsedInterval = $this->parseIntervalMinutes(
            $tagRow,
            $index,
            max(0, (int) ($existing->interval_minutes ?? 0)),
        );
        if (! ($parsedInterval['ok'] ?? false)) {
            return $parsedInterval;
        }

        return [
            'ok' => true,
            'tag' => [
                'existing_model' => $existing,
                'action_tag_id' => (int) $existing->action_tag_id,
                'action_tag_key' => (string) $existing->action_tag_key,
                'site_id' => $existing->site_id !== null ? (int) $existing->site_id : null,
                'site_key' => (string) $existing->site_key,
                'cms_type' => $existing->cms_type,
                'platform' => $existing->platform,
                'package_id' => (int) $existing->package_id,
                'script_id' => (string) $existing->script_id,
                'script_name' => $existing->script_name,
                'release_id' => (int) $existing->release_id,
                'script_version' => (string) $existing->script_version,
                'script_content_digest' => $existing->script_content_digest,
                'script_execution_digest' => $existing->script_execution_digest,
                'resolution_source' => (string) $existing->resolution_source,
                'source_action_tag_key' => $existing->source_action_tag_key,
                'cms_scope' => $existing->cms_scope,
                'manifest_snapshot' => $manifest,
                'variant_group_id' => $existing->variant_group_id ?? null,
                'variant_group_uuid' => $existing->variant_group_uuid ?? null,
                'pinned_variants' => $existing->pinned_variants ?? null,
                'variables' => $variables,
                'shortcode_mapping' => $mappingDoc,
                'shortcode_mapping_version' => $mappingVersion,
                'interval_minutes' => $parsedInterval['value'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $contentRow
     * @param  list<array<string, mixed>>  $preparedTags
     * @param  array<string, int>  $tagsByKey
     * @param  array<int, int>  $tagsById
     * @param  array<string, int>  $tagsByPlanUuid
     * @return array<string, mixed>
     */
    private function prepareContentWithAssignments(
        array $contentRow,
        int $ci,
        array $preparedTags,
        array $tagsByKey,
        array $tagsById,
        ?string $externalRef,
        array $tagsByPlanUuid = [],
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

            $tagIdx = $this->resolvePlannedTagIndex($assignRow, $tagsByKey, $tagsById, $tagsByPlanUuid);
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
     * @param  array<string, int>  $tagsByPlanUuid
     */
    private function resolvePlannedTagIndex(array $assignRow, array $tagsByKey, array $tagsById, array $tagsByPlanUuid = []): ?int
    {
        $planTagId = trim((string) ($assignRow['plan_tag_id'] ?? $assignRow['plan_tag_uuid'] ?? ''));
        if ($planTagId !== '' && isset($tagsByPlanUuid[$planTagId])) {
            return $tagsByPlanUuid[$planTagId];
        }
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
     * @param  array<string, mixed>  $tagRow
     * @return array<string, mixed>
     */
    private function parseIntervalMinutes(array $tagRow, int $index, int $default): array
    {
        if (! array_key_exists('interval_minutes', $tagRow) || $tagRow['interval_minutes'] === null || $tagRow['interval_minutes'] === '') {
            return ['ok' => true, 'value' => max(0, $default)];
        }

        $raw = $tagRow['interval_minutes'];
        if (is_bool($raw) || is_array($raw) || filter_var($raw, FILTER_VALIDATE_INT) === false) {
            return $this->fail(
                422,
                ['invalid_interval_minutes'],
                ["action_tags.{$index}.interval_minutes" => ['interval_minutes must be an integer between 0 and '.self::MAX_INTERVAL_MINUTES.'.']]
            );
        }

        $value = (int) $raw;
        if ($value < 0 || $value > self::MAX_INTERVAL_MINUTES) {
            return $this->fail(
                422,
                ['invalid_interval_minutes'],
                ["action_tags.{$index}.interval_minutes" => ['interval_minutes must be between 0 and '.self::MAX_INTERVAL_MINUTES.'.']]
            );
        }

        return ['ok' => true, 'value' => $value];
    }

    /**
     * Compute the latest terminal Success timestamp per Campaign Action Tag from durable SaJob records.
     *
     * @return array<int, \Carbon\CarbonInterface>
     */
    private function lastSuccessByActionTag(SaCampaign $campaign): array
    {
        if (! SocialAutomationSchema::jobsReady()) {
            return [];
        }

        $jobs = SaJob::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', 'completed')
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->get(['campaign_content_id', 'payload', 'finished_at']);

        if ($jobs->isEmpty()) {
            return [];
        }

        $contentIds = $jobs->pluck('campaign_content_id')->filter()->unique()->values()->all();
        $tagByContentId = $contentIds !== []
            ? SaCampaignContentAssignment::query()
                ->whereIn('campaign_content_id', $contentIds)
                ->pluck('campaign_action_tag_id', 'campaign_content_id')
                ->all()
            : [];

        $out = [];
        foreach ($jobs as $job) {
            $tagId = is_array($job->payload) ? (int) ($job->payload['campaign_action_tag_id'] ?? 0) : 0;
            if ($tagId <= 0 && $job->campaign_content_id !== null) {
                $tagId = (int) ($tagByContentId[(int) $job->campaign_content_id] ?? 0);
            }
            if ($tagId > 0 && ! isset($out[$tagId]) && $job->finished_at !== null) {
                $out[$tagId] = $job->finished_at;
            }
        }

        return $out;
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

    /**
     * Reject Step 1 structural changes on a retained Action Tag when Campaign is paused.
     *
     * @param  array<string, mixed>  $tagRow
     * @return array<string, mixed>|null
     */
    private function validatePausedTagStep1Immutability(SaCampaignActionTag $existing, array $tagRow, int $index): ?array
    {
        $stringFields = [
            'site_key' => $existing->site_key,
            'cms_type' => $existing->cms_type,
            'platform' => $existing->platform,
        ];
        foreach ($stringFields as $field => $persisted) {
            if (array_key_exists($field, $tagRow) && trim((string) $tagRow[$field]) !== '' && strcasecmp(trim((string) $tagRow[$field]), (string) $persisted) !== 0) {
                return $this->fail(
                    422,
                    ['step1_immutable_after_start'],
                    ["action_tags.{$index}.{$field}" => ["{$field} is immutable after Campaign start."]]
                );
            }
        }

        $intFields = [
            'site_id' => $existing->site_id,
            'package_id' => $existing->package_id,
        ];
        foreach ($intFields as $field => $persisted) {
            if (array_key_exists($field, $tagRow) && $tagRow[$field] !== null && $tagRow[$field] !== '' && (int) $tagRow[$field] !== (int) $persisted) {
                return $this->fail(
                    422,
                    ['step1_immutable_after_start'],
                    ["action_tags.{$index}.{$field}" => ["{$field} is immutable after Campaign start."]]
                );
            }
        }

        return null;
    }

    /**
     * @param  array<int, int>  $jobContentIds
     */
    private function isContentImmutableAfterExecution(SaCampaignContent $content, array $jobContentIds): bool
    {
        if (! in_array((string) $content->status, [
            CampaignContentStatus::Pending->value,
            CampaignContentStatus::WaitingResources->value,
        ], true)) {
            return true;
        }
        if ($content->current_job_id !== null || $content->assigned_account_id !== null) {
            return true;
        }

        return isset($jobContentIds[(int) $content->id]);
    }

    /**
     * Ensure an already-executed/claimed Content item and its assignments are not modified.
     *
     * @param  list<SaCampaignContentAssignment>  $existingAssigns
     * @param  array<string, mixed>  $contentPrepared
     * @param  list<array<string, mixed>>  $preparedTags
     * @return array<string, mixed>|null
     */
    private function validateImmutableExecutedContent(
        SaCampaignContent $existingContent,
        array $existingAssigns,
        array $contentPrepared,
        array $preparedTags,
        int $ci,
    ): ?array {
        if ((string) ($contentPrepared['external_ref'] ?? '') !== (string) ($existingContent->external_ref ?? '')) {
            return $this->fail(
                422,
                ['executed_content_immutable'],
                ["contents.{$ci}.external_ref" => ['Executed or claimed Content cannot be modified while Paused.']]
            );
        }
        if ((int) $contentPrepared['sort_order'] !== (int) $existingContent->sort_order) {
            return $this->fail(
                422,
                ['executed_content_immutable'],
                ["contents.{$ci}.sort_order" => ['Executed or claimed Content order cannot be modified while Paused.']]
            );
        }
        if (SocialAutomationSchema::campaignShortcodeReady()) {
            if ((string) ($contentPrepared['raw_source'] ?? '') !== (string) ($existingContent->raw_source ?? '')
                || (string) ($contentPrepared['source_format'] ?? '') !== (string) ($existingContent->source_format ?? '')) {
                return $this->fail(
                    422,
                    ['executed_content_immutable'],
                    ["contents.{$ci}.raw_source" => ['Executed or claimed Content source cannot be modified while Paused.']]
                );
            }
        }

        $submittedAssigns = $contentPrepared['assignments'] ?? [];
        if (count($submittedAssigns) !== count($existingAssigns)) {
            return $this->fail(
                422,
                ['executed_content_immutable'],
                ["contents.{$ci}.assignments" => ['Executed or claimed Content assignments cannot be modified while Paused.']]
            );
        }

        $existingByTagId = [];
        foreach ($existingAssigns as $ea) {
            $existingByTagId[(int) $ea->campaign_action_tag_id] = $ea;
        }
        foreach ($submittedAssigns as $ai => $aRow) {
            $pt = $preparedTags[$aRow['tag_idx']]['existing_model'] ?? null;
            $ptId = $pt instanceof SaCampaignActionTag ? (int) $pt->id : 0;
            $ea = $existingByTagId[$ptId] ?? null;
            if (! $ea instanceof SaCampaignContentAssignment) {
                return $this->fail(
                    422,
                    ['executed_content_immutable'],
                    ["contents.{$ci}.assignments.{$ai}" => ['Executed or claimed Content Action Tag assignment cannot be changed.']]
                );
            }
            $existingInput = is_array($ea->input) ? $ea->input : [];
            $submittedInput = is_array($aRow['input'] ?? null) ? $aRow['input'] : [];
            if ($existingInput != $submittedInput) {
                return $this->fail(
                    422,
                    ['executed_content_immutable'],
                    ["contents.{$ci}.assignments.{$ai}.input" => ['Executed or claimed Content input cannot be modified while Paused.']]
                );
            }
        }

        return null;
    }
}
