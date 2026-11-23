<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Enums\ArticleStatus;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignContentStatus;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignStatus;
use Omnichannel\Addons\SocialAutomation\Models\SaArticle;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContent;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContentAssignment;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptVariantGroup;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Assigns independent Article Library items to Campaign V2 Drafts,
 * enforces single-tag / site compatibility & uniqueness,
 * and freezes immutable Article + Script Release snapshots at Campaign Start.
 */
final class CampaignArticleAssignmentService
{
    /**
     * @return array{ok: bool, http?: int, data?: list<array<string, mixed>>, errors?: list<string>}
     */
    public function listAssignments(string $campaignUuid, string $installationId): array
    {
        if (! SocialAutomationSchema::articleLibraryReady()) {
            return ['ok' => false, 'http' => 503, 'errors' => ['schema_unavailable']];
        }

        $campaign = SaCampaign::query()
            ->notDeleted()
            ->where('campaign_uuid', $campaignUuid)
            ->where('installation_id', $installationId)
            ->first();
        if (! $campaign instanceof SaCampaign) {
            return ['ok' => false, 'http' => 404, 'errors' => ['campaign_not_found']];
        }

        $contents = SaCampaignContent::query()
            ->where('campaign_id', $campaign->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $assignmentsByContentId = SaCampaignContentAssignment::query()
            ->where('campaign_id', $campaign->id)
            ->get()
            ->keyBy('campaign_content_id');

        $planTagsById = SaCampaignActionTag::query()
            ->where('campaign_id', $campaign->id)
            ->get()
            ->keyBy('id');

        $articleIds = $contents->pluck('article_id')->filter()->unique()->values()->all();
        $articlesById = $articleIds !== []
            ? SaArticle::query()->whereIn('id', $articleIds)->get()->keyBy('id')
            : collect();

        $rows = [];
        foreach ($contents as $content) {
            /** @var SaCampaignContentAssignment|null $assign */
            $assign = $assignmentsByContentId->get($content->id);
            /** @var SaCampaignActionTag|null $planTag */
            $planTag = $assign ? $planTagsById->get($assign->campaign_action_tag_id) : null;
            /** @var SaArticle|null $article */
            $article = $content->article_id ? $articlesById->get($content->article_id) : null;

            $rows[] = [
                'content_id' => (string) $content->content_uuid,
                'assignment_id' => $assign ? (string) $assign->assignment_uuid : null,
                'article_id' => $content->article_uuid,
                'article_title' => $content->snapshot_payload['title'] ?? ($article?->title),
                'external_ref' => $content->external_ref,
                'sort_order' => (int) $content->sort_order,
                'status' => (string) $content->status,
                'is_legacy_inline' => $content->article_id === null,
                'action_tag_id' => $planTag ? (int) $planTag->action_tag_id : null,
                'action_tag_key' => $planTag ? (string) $planTag->action_tag_key : null,
                'site_id' => $planTag?->site_id !== null ? (int) $planTag->site_id : null,
                'site_key' => $planTag ? (string) $planTag->site_key : null,
                'release_id' => $assign ? (int) $assign->release_id : null,
                'script_id' => $assign ? (string) $assign->script_id : (string) $campaign->script_id,
                'script_version' => $assign ? (string) $assign->script_version : (string) $campaign->script_version,
                'input' => is_array($assign?->input) ? $assign->input : (is_array($content->input) ? $content->input : []),
                'snapshot_at' => $content->snapshot_at?->toIso8601String(),
            ];
        }

        return ['ok' => true, 'data' => $rows];
    }

    /**
     * Assign one or more Articles from the Article Library to a Campaign Draft.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, http?: int, data?: list<array<string, mixed>>, errors?: list<string>, field_errors?: array<string, list<string>>, message?: string}
     */
    public function assignArticles(string $campaignUuid, string $installationId, array $input): array
    {
        if (! SocialAutomationSchema::articleLibraryReady()) {
            return $this->fail(503, ['schema_unavailable'], ['schema' => ['Article Library schema is not migrated.']]);
        }

        $items = $this->normalizeArticleItems($input);
        if ($items === []) {
            return $this->fail(422, ['article_ids_required'], ['article_ids' => ['At least one article_id is required.']]);
        }

        $seenUuids = [];
        foreach ($items as $item) {
            $uuid = $item['article_id'];
            if (isset($seenUuids[$uuid])) {
                return $this->fail(422, ['duplicate_article_assignment'], ['article_ids' => ["Duplicate article_id '{$uuid}' in request."]]);
            }
            $seenUuids[$uuid] = true;
        }

        $conn = SocialAutomationServiceConfig::CONNECTION;

        return DB::connection($conn)->transaction(function () use ($campaignUuid, $installationId, $items): array {
            $campaign = SaCampaign::query()
                ->notDeleted()
                ->where('campaign_uuid', $campaignUuid)
                ->where('installation_id', $installationId)
                ->lockForUpdate()
                ->first();

            if (! $campaign instanceof SaCampaign) {
                return $this->fail(404, ['campaign_not_found'], ['campaign' => ['Campaign not found.']]);
            }

            if ((string) $campaign->status !== CampaignStatus::Draft->value) {
                return $this->fail(422, ['campaign_not_draft'], ['status' => ['Articles can only be assigned when Campaign is in draft status.']]);
            }

            if ((int) ($campaign->plan_version ?? 1) < 2) {
                return $this->fail(422, ['campaign_v2_required'], ['plan_version' => ['Article assignment requires a Campaign V2 draft.']]);
            }

            $planTags = SaCampaignActionTag::query()
                ->where('campaign_id', $campaign->id)
                ->where('is_enabled', true)
                ->get();
            if ($planTags->isEmpty()) {
                return $this->fail(422, ['action_tags_required'], ['action_tags' => ['Campaign Draft has no enabled Action Tags.']]);
            }
            $planTagsByActionTagId = $planTags->keyBy('action_tag_id');

            $existingArticleIds = SaCampaignContent::query()
                ->where('campaign_id', $campaign->id)
                ->whereNotNull('article_id')
                ->pluck('article_id')
                ->map(static fn ($id): int => (int) $id)
                ->flip()
                ->all();

            $existingExtRefs = SaCampaignContent::query()
                ->where('campaign_id', $campaign->id)
                ->whereNotNull('external_ref')
                ->pluck('external_ref')
                ->flip()
                ->all();

            $maxSort = (int) SaCampaignContent::query()
                ->where('campaign_id', $campaign->id)
                ->max('sort_order');

            $created = [];
            foreach ($items as $idx => $item) {
                $article = SaArticle::query()
                    ->where('article_uuid', $item['article_id'])
                    ->where('installation_id', $installationId)
                    ->first();

                if (! $article instanceof SaArticle) {
                    return $this->fail(422, ['article_not_found'], ["article_ids.{$idx}" => ["Article '{$item['article_id']}' was not found."]]);
                }

                if ((string) $article->status === ArticleStatus::Archived->value) {
                    return $this->fail(422, ['article_archived'], ["article_ids.{$idx}" => ["Article '{$article->article_uuid}' is archived and cannot be assigned."]]);
                }

                if (isset($existingArticleIds[(int) $article->id])) {
                    return $this->fail(422, ['duplicate_article_assignment'], ["article_ids.{$idx}" => ["Article '{$article->article_uuid}' is already assigned to this Campaign."]]);
                }

                /** @var SaCampaignActionTag|null $planTag */
                $planTag = $planTagsByActionTagId->get((int) $article->action_tag_id);
                if (! $planTag instanceof SaCampaignActionTag) {
                    return $this->fail(422, ['incompatible_article_action_tag'], ["article_ids.{$idx}" => ["Article Action Tag '{$article->action_tag_key}' is not configured on this Campaign."]]);
                }

                if ($planTag->site_id !== null && $article->site_id !== null && (int) $planTag->site_id !== (int) $article->site_id) {
                    return $this->fail(422, ['incompatible_article_site'], ["article_ids.{$idx}" => ["Article site_id {$article->site_id} does not match Campaign Action Tag site_id {$planTag->site_id}."]]);
                }

                $extRef = $article->external_ref;
                if ($extRef !== null && $extRef !== '' && isset($existingExtRefs[$extRef])) {
                    return $this->fail(422, ['duplicate_external_ref'], ["article_ids.{$idx}" => ["Duplicate external_ref '{$extRef}' in Campaign."]]);
                }
                if ($extRef !== null && $extRef !== '') {
                    $existingExtRefs[$extRef] = true;
                }

                $sortOrder = $item['sort_order'] ?? (++$maxSort);
                $maxSort = max($maxSort, $sortOrder);

                $content = SaCampaignContent::query()->create([
                    'content_uuid' => (string) Str::uuid(),
                    'campaign_id' => $campaign->id,
                    'article_id' => $article->id,
                    'article_uuid' => $article->article_uuid,
                    'external_ref' => $extRef,
                    'sort_order' => $sortOrder,
                    'status' => CampaignContentStatus::Pending->value,
                    'input' => is_array($article->input) ? $article->input : [],
                    'source_format' => (string) ($article->source_format ?: 'legacy_input'),
                    'raw_source' => $article->raw_source,
                    'mapping_version' => (int) $article->mapping_version,
                ]);

                $assignment = SaCampaignContentAssignment::query()->create([
                    'assignment_uuid' => (string) Str::uuid(),
                    'campaign_id' => $campaign->id,
                    'campaign_content_id' => $content->id,
                    'campaign_action_tag_id' => $planTag->id,
                    'package_id' => (int) $planTag->package_id,
                    'release_id' => (int) $planTag->release_id,
                    'script_id' => (string) $planTag->script_id,
                    'script_version' => (string) $planTag->script_version,
                    'source_format' => (string) ($article->source_format ?: 'legacy_input'),
                    'shortcode_mapping' => $article->shortcode_mapping ?? $planTag->shortcode_mapping,
                    'mapping_version' => (int) ($article->mapping_version ?: $planTag->shortcode_mapping_version),
                    'input' => is_array($article->input) ? $article->input : [],
                    'status' => CampaignContentStatus::Pending->value,
                ]);

                $existingArticleIds[(int) $article->id] = true;

                $created[] = [
                    'content_id' => (string) $content->content_uuid,
                    'assignment_id' => (string) $assignment->assignment_uuid,
                    'article_id' => (string) $article->article_uuid,
                    'article_title' => (string) $article->title,
                    'sort_order' => (int) $content->sort_order,
                    'action_tag_id' => (int) $planTag->action_tag_id,
                    'action_tag_key' => (string) $planTag->action_tag_key,
                    'site_id' => $planTag->site_id !== null ? (int) $planTag->site_id : null,
                    'site_key' => (string) $planTag->site_key,
                    'release_id' => (int) $planTag->release_id,
                    'script_id' => (string) $planTag->script_id,
                    'script_version' => (string) $planTag->script_version,
                    'status' => (string) $content->status,
                ];
            }

            return ['ok' => true, 'data' => $created];
        });
    }

    /**
     * Remove an Article assignment from a Campaign Draft (never allowed after Start).
     *
     * @return array{ok: bool, http?: int, data?: array<string, mixed>, errors?: list<string>}
     */
    public function removeAssignment(string $campaignUuid, string $installationId, string $targetUuid): array
    {
        if (! SocialAutomationSchema::articleLibraryReady()) {
            return ['ok' => false, 'http' => 503, 'errors' => ['schema_unavailable']];
        }

        $conn = SocialAutomationServiceConfig::CONNECTION;

        return DB::connection($conn)->transaction(function () use ($campaignUuid, $installationId, $targetUuid): array {
            $campaign = SaCampaign::query()
                ->notDeleted()
                ->where('campaign_uuid', $campaignUuid)
                ->where('installation_id', $installationId)
                ->lockForUpdate()
                ->first();

            if (! $campaign instanceof SaCampaign) {
                return ['ok' => false, 'http' => 404, 'errors' => ['campaign_not_found']];
            }

            if ((string) $campaign->status !== CampaignStatus::Draft->value) {
                return ['ok' => false, 'http' => 422, 'errors' => ['campaign_not_draft']];
            }

            $content = SaCampaignContent::query()
                ->where('campaign_id', $campaign->id)
                ->where(function ($q) use ($targetUuid): void {
                    $q->where('article_uuid', $targetUuid)
                        ->orWhere('content_uuid', $targetUuid);
                })
                ->first();

            if (! $content instanceof SaCampaignContent) {
                $assign = SaCampaignContentAssignment::query()
                    ->where('campaign_id', $campaign->id)
                    ->where('assignment_uuid', $targetUuid)
                    ->first();
                if ($assign instanceof SaCampaignContentAssignment) {
                    $content = SaCampaignContent::query()
                        ->where('campaign_id', $campaign->id)
                        ->where('id', $assign->campaign_content_id)
                        ->first();
                }
            }

            if (! $content instanceof SaCampaignContent) {
                return ['ok' => false, 'http' => 404, 'errors' => ['assignment_not_found']];
            }

            SaCampaignContentAssignment::query()
                ->where('campaign_content_id', $content->id)
                ->delete();
            $content->delete();

            return ['ok' => true, 'data' => ['removed' => true, 'id' => $targetUuid]];
        });
    }

    /**
     * Freeze Article snapshots & ensure Script Release pins are immutable on initial Campaign Start.
     * Must be called inside a DB transaction holding lockForUpdate() on SaCampaign.
     *
     * @return array{ok: bool, errors?: list<string>}
     */
    public function snapshotAndPinAtStart(SaCampaign $lockedCampaign): array
    {
        if (! SocialAutomationSchema::articleLibraryReady()) {
            return ['ok' => true];
        }

        // Only snapshot/pin on initial transition from Draft (never overwrite on Pause->Resume).
        if ($lockedCampaign->started_at !== null) {
            return ['ok' => true];
        }

        $planTags = SaCampaignActionTag::query()
            ->where('campaign_id', $lockedCampaign->id)
            ->get()
            ->keyBy('id');

        foreach ($planTags as $planTag) {
            if (! is_array($planTag->manifest_snapshot) || $planTag->manifest_snapshot === []) {
                $rel = SaPackageRelease::query()->find((int) $planTag->release_id);
                if (! $rel instanceof SaPackageRelease) {
                    return ['ok' => false, 'errors' => ['script_release_not_found']];
                }
                $planTag->manifest_snapshot = is_array($rel->manifest) ? $rel->manifest : [];
                $planTag->script_content_digest = $planTag->script_content_digest ?? $rel->content_digest;
                $planTag->script_execution_digest = $planTag->script_execution_digest ?? $rel->execution_digest;
                $planTag->save();
            }
            if (SocialAutomationSchema::scriptVariantGroupsReady()
                && $planTag->variant_group_id !== null
                && ! is_array($planTag->pinned_variants)) {
                $vg = SaScriptVariantGroup::query()->with(['items.package', 'items.release'])->find((int) $planTag->variant_group_id);
                if ($vg instanceof SaScriptVariantGroup && $vg->is_enabled) {
                    $pinned = [];
                    foreach ($vg->items->sortBy('priority') as $item) {
                        if (! $item->is_enabled || ! $item->package || ! $item->release) {
                            continue;
                        }
                        if ((string) $item->release->status !== 'published') {
                            continue;
                        }
                        $pinned[] = [
                            'variant_id' => (string) $item->variant_uuid,
                            'priority' => (int) $item->priority,
                            'language_label' => (string) $item->language_label,
                            'package_id' => (int) $item->package_id,
                            'package_key' => (string) $item->package_key,
                            'release_id' => (int) $item->release_id,
                            'script_version' => (string) $item->script_version,
                            'content_digest' => $item->content_digest,
                            'execution_digest' => $item->execution_digest,
                        ];
                    }
                    if ($pinned !== []) {
                        $planTag->variant_group_uuid = $planTag->variant_group_uuid ?? (string) $vg->group_uuid;
                        $planTag->pinned_variants = $pinned;
                        $planTag->save();
                    }
                }
            }
        }

        $contents = SaCampaignContent::query()
            ->where('campaign_id', $lockedCampaign->id)
            ->whereNotNull('article_id')
            ->whereNull('snapshot_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($contents->isEmpty()) {
            return ['ok' => true];
        }

        $now = now();
        foreach ($contents as $content) {
            $article = SaArticle::query()->find((int) $content->article_id);
            if (! $article instanceof SaArticle) {
                return ['ok' => false, 'errors' => ['article_not_found']];
            }
            if ((string) $article->status === ArticleStatus::Archived->value) {
                return ['ok' => false, 'errors' => ['article_archived']];
            }

            /** @var SaCampaignContentAssignment|null $assign */
            $assign = SaCampaignContentAssignment::query()
                ->where('campaign_content_id', $content->id)
                ->first();
            /** @var SaCampaignActionTag|null $planTag */
            $planTag = $assign ? $planTags->get((int) $assign->campaign_action_tag_id) : null;

            $content->article_uuid = $article->article_uuid;
            $content->input = is_array($article->input) ? $article->input : [];
            $content->source_format = (string) ($article->source_format ?: 'legacy_input');
            $content->raw_source = $article->raw_source;
            $content->mapping_version = (int) $article->mapping_version;
            $content->snapshot_at = $now;
            $content->snapshot_payload = [
                'article_id' => (int) $article->id,
                'article_uuid' => (string) $article->article_uuid,
                'title' => (string) $article->title,
                'external_ref' => $article->external_ref,
                'action_tag_id' => (int) $article->action_tag_id,
                'action_tag_key' => (string) $article->action_tag_key,
                'site_id' => $article->site_id !== null ? (int) $article->site_id : null,
                'site_key' => $article->site_key,
                'source_format' => $content->source_format,
                'raw_source' => $content->raw_source,
                'input' => $content->input,
                'shortcode_mapping' => $article->shortcode_mapping ?? $planTag?->shortcode_mapping,
                'mapping_version' => (int) $content->mapping_version,
                'release_id' => $planTag ? (int) $planTag->release_id : ($assign ? (int) $assign->release_id : null),
                'script_id' => $planTag ? (string) $planTag->script_id : ($assign ? (string) $assign->script_id : null),
                'script_version' => $planTag ? (string) $planTag->script_version : ($assign ? (string) $assign->script_version : null),
                'script_content_digest' => $planTag?->script_content_digest,
                'script_execution_digest' => $planTag?->script_execution_digest,
                'snapshotted_at' => $now->toIso8601String(),
            ];
            $content->save();

            if ($assign instanceof SaCampaignContentAssignment) {
                $assign->source_format = $content->source_format;
                $assign->shortcode_mapping = $article->shortcode_mapping ?? $planTag?->shortcode_mapping;
                $assign->mapping_version = (int) ($article->mapping_version ?: ($planTag?->shortcode_mapping_version ?? 0));
                $assign->input = $content->input;
                if ($planTag instanceof SaCampaignActionTag) {
                    $assign->package_id = (int) $planTag->package_id;
                    $assign->release_id = (int) $planTag->release_id;
                    $assign->script_id = (string) $planTag->script_id;
                    $assign->script_version = (string) $planTag->script_version;
                }
                $assign->save();
            }
        }

        return ['ok' => true];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array{article_id: string, sort_order: int|null}>
     */
    private function normalizeArticleItems(array $input): array
    {
        $items = [];
        if (isset($input['article_ids']) && is_array($input['article_ids'])) {
            foreach ($input['article_ids'] as $id) {
                $uuid = trim((string) $id);
                if ($uuid !== '') {
                    $items[] = ['article_id' => $uuid, 'sort_order' => null];
                }
            }
        } elseif (isset($input['articles']) && is_array($input['articles'])) {
            foreach ($input['articles'] as $row) {
                if (is_string($row)) {
                    $uuid = trim($row);
                    if ($uuid !== '') {
                        $items[] = ['article_id' => $uuid, 'sort_order' => null];
                    }
                } elseif (is_array($row)) {
                    $uuid = trim((string) ($row['article_id'] ?? $row['id'] ?? ''));
                    if ($uuid !== '') {
                        $items[] = [
                            'article_id' => $uuid,
                            'sort_order' => isset($row['sort_order']) ? (int) $row['sort_order'] : null,
                        ];
                    }
                }
            }
        } elseif (isset($input['article_id']) && is_string($input['article_id'])) {
            $uuid = trim($input['article_id']);
            if ($uuid !== '') {
                $items[] = [
                    'article_id' => $uuid,
                    'sort_order' => isset($input['sort_order']) ? (int) $input['sort_order'] : null,
                ];
            }
        }

        return $items;
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string, list<string>>  $fieldErrors
     * @return array{ok: false, http: int, errors: list<string>, field_errors: array<string, list<string>>, message: string}
     */
    private function fail(int $http, array $errors, array $fieldErrors): array
    {
        $first = 'Validation failed.';
        foreach ($fieldErrors as $messages) {
            if ($messages !== []) {
                $first = $messages[0];
                break;
            }
        }

        return [
            'ok' => false,
            'http' => $http,
            'errors' => $errors,
            'field_errors' => $fieldErrors,
            'message' => $first,
        ];
    }
}
