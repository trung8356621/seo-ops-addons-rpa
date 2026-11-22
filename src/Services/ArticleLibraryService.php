<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignInputSanitizer;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignScriptVariableSchema;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodeMappingValidator;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodePipeline;
use Omnichannel\Addons\SocialAutomation\Enums\ArticleStatus;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaArticle;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContent;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Independent Article Library CRUD + validation against associated Action Tag / Script contract.
 * Completely decoupled from Campaign lifecycle and Campaign execution status.
 */
final class ArticleLibraryService
{
    public function __construct(
        private readonly ScriptResolutionService $scriptResolver = new ScriptResolutionService,
        private readonly CampaignScriptVariableSchema $variableSchema = new CampaignScriptVariableSchema,
        private readonly CampaignShortcodePipeline $shortcodePipeline = new CampaignShortcodePipeline,
        private readonly CampaignShortcodeMappingValidator $mappingValidator = new CampaignShortcodeMappingValidator,
        private readonly CampaignInputSanitizer $sanitizer = new CampaignInputSanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function list(string $installationId, array $filters = [], int $limit = 100): array
    {
        if (! SocialAutomationSchema::articleLibraryReady()) {
            return [];
        }

        $query = SaArticle::query()
            ->where('installation_id', $installationId)
            ->orderByDesc('id')
            ->limit(max(1, min(500, $limit)));

        $this->applyFilters($query, $installationId, $filters);

        return $query->get()->map(fn (SaArticle $a): array => $this->dto($a))->all();
    }

    /**
     * List assignable (non-archived) Articles compatible with a Campaign or Action Tag/Site filter.
     *
     * @param  array<string, mixed>  $filters
     * @return array{ok: bool, data?: list<array<string, mixed>>, errors?: list<string>, http?: int}
     */
    public function listCompatible(string $installationId, array $filters = [], int $limit = 100): array
    {
        if (! SocialAutomationSchema::articleLibraryReady()) {
            return ['ok' => true, 'data' => []];
        }

        $query = SaArticle::query()
            ->where('installation_id', $installationId)
            ->where('status', '!=', ArticleStatus::Archived->value)
            ->orderByDesc('id')
            ->limit(max(1, min(500, $limit)));

        $campaignUuid = trim((string) ($filters['campaign_id'] ?? ''));
        if ($campaignUuid !== '') {
            $campaign = SaCampaign::query()
                ->where('campaign_uuid', $campaignUuid)
                ->where('installation_id', $installationId)
                ->first();
            if (! $campaign instanceof SaCampaign) {
                return ['ok' => false, 'http' => 404, 'errors' => ['campaign_not_found']];
            }

            $planTags = SaCampaignActionTag::query()
                ->where('campaign_id', $campaign->id)
                ->where('is_enabled', true)
                ->get(['action_tag_id', 'site_id']);
            $tagIds = $planTags->pluck('action_tag_id')->map(static fn ($id): int => (int) $id)->values()->all();
            if ($tagIds === []) {
                return ['ok' => true, 'data' => []];
            }
            $query->whereIn('action_tag_id', $tagIds);

            $excludeAssigned = filter_var($filters['exclude_assigned'] ?? true, FILTER_VALIDATE_BOOLEAN);
            if ($excludeAssigned) {
                $assignedIds = SaCampaignContent::query()
                    ->where('campaign_id', $campaign->id)
                    ->whereNotNull('article_id')
                    ->pluck('article_id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();
                if ($assignedIds !== []) {
                    $query->whereNotIn('id', $assignedIds);
                }
            }
        }

        $this->applyFilters($query, $installationId, $filters);

        return [
            'ok' => true,
            'data' => $query->get()->map(fn (SaArticle $a): array => $this->dto($a))->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detail(string $articleUuid, string $installationId): ?array
    {
        if (! SocialAutomationSchema::articleLibraryReady()) {
            return null;
        }

        $article = $this->findOwned($articleUuid, $installationId);

        return $article instanceof SaArticle ? $this->dto($article) : null;
    }

    /**
     * Create an independent Article without campaign_id.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, http?: int, data?: array<string, mixed>, errors?: list<string>, field_errors?: array<string, list<string>>, message?: string}
     */
    public function create(array $input, string $installationId, ?int $userId): array
    {
        if (! SocialAutomationSchema::articleLibraryReady()) {
            return $this->fail(503, ['schema_unavailable'], ['schema' => ['Article Library schema is not migrated.']]);
        }

        if (array_key_exists('campaign_id', $input) && $input['campaign_id'] !== null && $input['campaign_id'] !== '') {
            return $this->fail(422, ['campaign_id_not_allowed'], ['campaign_id' => ['Articles are independent of Campaigns and must not include campaign_id.']]);
        }

        if (array_key_exists('action_tags', $input) && is_array($input['action_tags']) && count($input['action_tags']) > 1) {
            return $this->fail(422, ['single_action_tag_required'], ['action_tags' => ['An Article must be associated with exactly one Action Tag.']]);
        }

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            return $this->fail(422, ['title_required'], ['title' => ['Article title is required.']]);
        }

        $statusRaw = $input['status'] ?? ArticleStatus::Ready->value;
        $status = ArticleStatus::tryFromInput($statusRaw);
        if ($status === null) {
            return $this->fail(422, ['invalid_article_status'], ['status' => ['Status must be draft, ready, or archived.']]);
        }

        $tagResolution = $this->resolveActionTagAndScript($input);
        if (! ($tagResolution['ok'] ?? false)) {
            return $tagResolution;
        }

        /** @var SaActionTag $tag */
        $tag = $tagResolution['tag'];
        /** @var SaSite|null $site */
        $site = $tagResolution['site'];
        /** @var array<string, array<string, mixed>> $variables */
        $variables = $tagResolution['variables'];

        $validatedPayload = $this->validateArticlePayload($input, $variables, $status);
        if (! ($validatedPayload['ok'] ?? false)) {
            return $validatedPayload;
        }

        $extRef = isset($input['external_ref']) && trim((string) $input['external_ref']) !== ''
            ? substr(trim((string) $input['external_ref']), 0, 191)
            : null;
        $meta = is_array($input['meta'] ?? null) ? $input['meta'] : null;

        $article = SaArticle::query()->create([
            'article_uuid' => (string) Str::uuid(),
            'installation_id' => $installationId,
            'title' => substr($title, 0, 255),
            'external_ref' => $extRef,
            'action_tag_id' => (int) $tag->id,
            'action_tag_key' => (string) $tag->tag_key,
            'site_id' => $site instanceof SaSite ? (int) $site->id : ($tag->site_id ? (int) $tag->site_id : null),
            'site_key' => $site instanceof SaSite ? (string) $site->key : ($tag->site_key ?: null),
            'status' => $status->value,
            'source_format' => (string) $validatedPayload['source_format'],
            'raw_source' => $validatedPayload['raw_source'],
            'input' => $validatedPayload['input'],
            'shortcode_mapping' => $validatedPayload['shortcode_mapping'],
            'mapping_version' => (int) $validatedPayload['mapping_version'],
            'created_by_user_id' => $userId,
            'meta' => $meta,
        ]);

        return ['ok' => true, 'data' => $this->dto($article)];
    }

    /**
     * Edit an independent Article without modifying any Campaign.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, http?: int, data?: array<string, mixed>, errors?: list<string>, field_errors?: array<string, list<string>>, message?: string}
     */
    public function update(string $articleUuid, string $installationId, array $input): array
    {
        if (! SocialAutomationSchema::articleLibraryReady()) {
            return $this->fail(503, ['schema_unavailable'], ['schema' => ['Article Library schema is not migrated.']]);
        }

        $article = $this->findOwned($articleUuid, $installationId);
        if (! $article instanceof SaArticle) {
            return $this->fail(404, ['article_not_found'], ['article' => ['Article not found.']]);
        }

        if (array_key_exists('campaign_id', $input) && $input['campaign_id'] !== null && $input['campaign_id'] !== '') {
            return $this->fail(422, ['campaign_id_not_allowed'], ['campaign_id' => ['Articles are independent of Campaigns and cannot bind to campaign_id.']]);
        }

        if (array_key_exists('action_tags', $input) && is_array($input['action_tags']) && count($input['action_tags']) > 1) {
            return $this->fail(422, ['single_action_tag_required'], ['action_tags' => ['An Article must be associated with exactly one Action Tag.']]);
        }

        $title = array_key_exists('title', $input) ? trim((string) $input['title']) : (string) $article->title;
        if ($title === '') {
            return $this->fail(422, ['title_required'], ['title' => ['Article title is required.']]);
        }

        $status = array_key_exists('status', $input)
            ? ArticleStatus::tryFromInput($input['status'])
            : ArticleStatus::tryFrom((string) $article->status);
        if ($status === null) {
            return $this->fail(422, ['invalid_article_status'], ['status' => ['Status must be draft, ready, or archived.']]);
        }

        $tagInput = [
            'action_tag_id' => $input['action_tag_id'] ?? $article->action_tag_id,
            'action_tag_key' => $input['action_tag_key'] ?? $input['tag_key'] ?? (
                isset($input['action_tag_id']) ? null : $article->action_tag_key
            ),
        ];
        $tagResolution = $this->resolveActionTagAndScript($tagInput);
        if (! ($tagResolution['ok'] ?? false)) {
            return $tagResolution;
        }

        /** @var SaActionTag $tag */
        $tag = $tagResolution['tag'];
        /** @var SaSite|null $site */
        $site = $tagResolution['site'];
        /** @var array<string, array<string, mixed>> $variables */
        $variables = $tagResolution['variables'];

        $mergedPayloadInput = [
            'source_format' => $input['source_format'] ?? $article->source_format,
            'raw_source' => array_key_exists('raw_source', $input) ? $input['raw_source'] : $article->raw_source,
            'input' => array_key_exists('input', $input) ? $input['input'] : $article->input,
            'shortcode_mapping' => array_key_exists('shortcode_mapping', $input) ? $input['shortcode_mapping'] : $article->shortcode_mapping,
            'mapping_version' => $input['mapping_version'] ?? $article->mapping_version,
        ];

        $validatedPayload = $this->validateArticlePayload($mergedPayloadInput, $variables, $status);
        if (! ($validatedPayload['ok'] ?? false)) {
            return $validatedPayload;
        }

        $extRef = array_key_exists('external_ref', $input)
            ? (trim((string) $input['external_ref']) !== '' ? substr(trim((string) $input['external_ref']), 0, 191) : null)
            : $article->external_ref;

        $article->title = substr($title, 0, 255);
        $article->external_ref = $extRef;
        $article->action_tag_id = (int) $tag->id;
        $article->action_tag_key = (string) $tag->tag_key;
        $article->site_id = $site instanceof SaSite ? (int) $site->id : ($tag->site_id ? (int) $tag->site_id : null);
        $article->site_key = $site instanceof SaSite ? (string) $site->key : ($tag->site_key ?: null);
        $article->status = $status->value;
        $article->source_format = (string) $validatedPayload['source_format'];
        $article->raw_source = $validatedPayload['raw_source'];
        $article->input = $validatedPayload['input'];
        $article->shortcode_mapping = $validatedPayload['shortcode_mapping'];
        $article->mapping_version = (int) $validatedPayload['mapping_version'];
        if (array_key_exists('meta', $input)) {
            $article->meta = is_array($input['meta']) ? $input['meta'] : null;
        }
        $article->save();

        return ['ok' => true, 'data' => $this->dto($article)];
    }

    /**
     * @return array{ok: bool, http?: int, data?: array<string, mixed>, errors?: list<string>}
     */
    public function delete(string $articleUuid, string $installationId): array
    {
        if (! SocialAutomationSchema::articleLibraryReady()) {
            return ['ok' => false, 'http' => 503, 'errors' => ['schema_unavailable']];
        }

        $conn = SocialAutomationServiceConfig::CONNECTION;

        return DB::connection($conn)->transaction(function () use ($articleUuid, $installationId): array {
            $article = SaArticle::query()
                ->where('article_uuid', $articleUuid)
                ->where('installation_id', $installationId)
                ->lockForUpdate()
                ->first();

            if (! $article instanceof SaArticle) {
                return ['ok' => false, 'http' => 404, 'errors' => ['article_not_found']];
            }

            $inUse = SaCampaignContent::query()
                ->where('article_id', $article->id)
                ->exists();
            if ($inUse) {
                return ['ok' => false, 'http' => 409, 'errors' => ['article_in_use']];
            }

            $article->delete();

            return ['ok' => true, 'data' => ['id' => $articleUuid, 'deleted' => true]];
        });
    }

    public function findOwned(string $articleUuid, string $installationId): ?SaArticle
    {
        return SaArticle::query()
            ->where('article_uuid', $articleUuid)
            ->where('installation_id', $installationId)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function dto(SaArticle $article): array
    {
        return [
            'id' => (string) $article->article_uuid,
            'title' => (string) $article->title,
            'external_ref' => $article->external_ref,
            'action_tag_id' => (int) $article->action_tag_id,
            'action_tag_key' => (string) $article->action_tag_key,
            'site_id' => $article->site_id !== null ? (int) $article->site_id : null,
            'site_key' => $article->site_key,
            'status' => (string) $article->status,
            'source_format' => (string) $article->source_format,
            'raw_source' => $article->raw_source,
            'input' => is_array($article->input) ? $article->input : [],
            'shortcode_mapping' => is_array($article->shortcode_mapping) ? $article->shortcode_mapping : null,
            'mapping_version' => (int) $article->mapping_version,
            'meta' => is_array($article->meta) ? $article->meta : null,
            'created_at' => $article->created_at?->toIso8601String(),
            'updated_at' => $article->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<SaArticle>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters($query, string $installationId, array $filters): void
    {
        if (isset($filters['site_id']) && $filters['site_id'] !== '') {
            $query->where('site_id', (int) $filters['site_id']);
        }
        if (isset($filters['site_key']) && trim((string) $filters['site_key']) !== '') {
            $query->where('site_key', strtolower(trim((string) $filters['site_key'])));
        }
        if (isset($filters['action_tag_id']) && $filters['action_tag_id'] !== '') {
            $query->where('action_tag_id', (int) $filters['action_tag_id']);
        }
        if (isset($filters['action_tag_key']) && trim((string) $filters['action_tag_key']) !== '') {
            $query->where('action_tag_key', strtolower(trim((string) $filters['action_tag_key'])));
        }
        if (isset($filters['status']) && trim((string) $filters['status']) !== '') {
            $st = ArticleStatus::tryFromInput($filters['status']);
            if ($st !== null) {
                $query->where('status', $st->value);
            } else {
                $query->where('status', '__none__');
            }
        }
        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            $term = '%'.trim((string) $filters['search']).'%';
            $query->where(function ($sub) use ($term): void {
                $sub->where('title', 'like', $term)
                    ->orWhere('external_ref', 'like', $term);
            });
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function resolveActionTagAndScript(array $input): array
    {
        $tagId = isset($input['action_tag_id']) && $input['action_tag_id'] !== '' ? (int) $input['action_tag_id'] : null;
        $tagKey = strtolower(trim((string) ($input['action_tag_key'] ?? $input['tag_key'] ?? '')));

        if ($tagId === null && $tagKey === '') {
            return $this->fail(422, ['action_tag_required'], ['action_tag_id' => ['Exactly one Action Tag (action_tag_id or action_tag_key) is required.']]);
        }

        $tag = null;
        if ($tagId !== null) {
            $tag = SaActionTag::query()->find($tagId);
        } elseif ($tagKey !== '') {
            $tag = SaActionTag::query()->where('tag_key', $tagKey)->first();
        }

        if (! $tag instanceof SaActionTag) {
            return $this->fail(422, ['action_tag_not_found'], ['action_tag_id' => ['Action Tag was not found.']]);
        }

        if ($tagKey !== '' && strcasecmp($tagKey, (string) $tag->tag_key) !== 0) {
            return $this->fail(422, ['action_tag_mismatch'], ['action_tag_key' => ['action_tag_key does not match action_tag_id.']]);
        }

        if (! $tag->isEnabled()) {
            return $this->fail(422, ['action_tag_disabled'], ['action_tag_id' => ["Action Tag '{$tag->tag_key}' is disabled."]]);
        }

        $res = $this->scriptResolver->resolveForTag($tag);
        if (! ($res['ok'] ?? false)) {
            $code = (string) ($res['error'] ?? 'no_published_script_for_action_tag');

            return $this->fail(422, [$code], ['action_tag_id' => ["Action Tag '{$tag->tag_key}' has no compatible published Script ({$code})."]]);
        }

        $release = $res['release'] ?? null;
        if (! $release instanceof SaPackageRelease) {
            return $this->fail(422, ['script_release_not_found'], ['action_tag_id' => ['Resolved Script Release is missing.']]);
        }

        $manifest = is_array($release->manifest) ? $release->manifest : [];
        $schema = $this->variableSchema->fromManifest($manifest);
        if (! ($schema['ok'] ?? false)) {
            $errs = $schema['errors'] ?? ['script_variables_invalid'];

            return $this->fail(422, $errs, ['action_tag_id' => ['Associated Script manifest has invalid variable schema.']]);
        }

        $site = null;
        if ($tag->site_id) {
            $site = SaSite::query()->find((int) $tag->site_id);
        } elseif (! empty($tag->site_key)) {
            $site = SaSite::query()->where('key', $tag->site_key)->first();
        }

        return [
            'ok' => true,
            'tag' => $tag,
            'site' => $site,
            'variables' => $schema['variables'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, array<string, mixed>>  $variables
     * @return array<string, mixed>
     */
    private function validateArticlePayload(array $input, array $variables, ArticleStatus $status): array
    {
        $sourceFormat = trim((string) ($input['source_format'] ?? 'legacy_input'));
        if ($sourceFormat === '') {
            $sourceFormat = 'legacy_input';
        }
        if (! in_array($sourceFormat, ['legacy_input', 'shortcode', 'json'], true)) {
            return $this->fail(422, ['invalid_source_format'], ['source_format' => ['source_format must be legacy_input, shortcode, or json.']]);
        }

        $mappingDoc = null;
        $mappingVersion = (int) ($input['mapping_version'] ?? 0);
        if (array_key_exists('shortcode_mapping', $input) && $input['shortcode_mapping'] !== null) {
            if (! is_array($input['shortcode_mapping'])) {
                return $this->fail(422, ['shortcode_mapping_invalid'], ['shortcode_mapping' => ['shortcode_mapping must be an object.']]);
            }
            $mapped = $this->mappingValidator->validate($input['shortcode_mapping'], $variables);
            if (! ($mapped['ok'] ?? false)) {
                $errs = $mapped['errors'] ?? ['shortcode_mapping_invalid'];

                return $this->fail(422, $errs, ['shortcode_mapping' => ['Invalid shortcode_mapping for Action Tag Script: '.implode(', ', $errs)]]);
            }
            $mappingDoc = $mapped['mapping'] ?? null;
            $mappingVersion = max(1, $mappingVersion);
        }

        $rawSource = isset($input['raw_source']) && $input['raw_source'] !== null ? (string) $input['raw_source'] : null;

        if ($sourceFormat === 'shortcode') {
            if ($rawSource === null || trim($rawSource) === '') {
                return $this->fail(422, ['raw_source_required'], ['raw_source' => ['raw_source is required for shortcode format.']]);
            }
            if ($mappingDoc === null) {
                return $this->fail(422, ['shortcode_mapping_required'], ['shortcode_mapping' => ['shortcode_mapping is required for shortcode format.']]);
            }
            $processed = $this->shortcodePipeline->process($rawSource, $mappingDoc, $variables);
            if (! ($processed['ok'] ?? false)) {
                $errs = $processed['errors'] ?? ['content_input_invalid'];

                return $this->fail(422, $errs, ['raw_source' => ['Article shortcode content failed Script contract validation: '.implode(', ', $errs)]]);
            }

            return [
                'ok' => true,
                'source_format' => 'shortcode',
                'raw_source' => (string) ($processed['raw_source'] ?? $rawSource),
                'input' => $processed['input'] ?? [],
                'shortcode_mapping' => $mappingDoc,
                'mapping_version' => max(1, $mappingVersion),
            ];
        }

        $rawInput = $input['input'] ?? [];
        if (! is_array($rawInput)) {
            return $this->fail(422, ['content_input_invalid'], ['input' => ['Article input must be an object.']]);
        }

        $allowedKeys = array_keys($variables);
        $sanitized = $this->sanitizer->sanitize($rawInput, $allowedKeys);
        if (! ($sanitized['ok'] ?? false)) {
            $errs = $sanitized['errors'] ?? ['content_input_invalid'];

            return $this->fail(422, $errs, ['input' => ['Article input contains invalid values: '.implode(', ', $errs)]]);
        }
        $cleanInput = $sanitized['input'] ?? [];

        if ($variables !== [] && $status === ArticleStatus::Ready) {
            $validated = $this->shortcodePipeline->validateStoredInput($cleanInput, $variables);
            if (! ($validated['ok'] ?? false)) {
                $errs = $validated['errors'] ?? ['content_input_invalid'];

                return $this->fail(422, $errs, ['input' => ['Article input does not satisfy Action Tag Script contract: '.implode(', ', $errs)]]);
            }
            $cleanInput = $validated['input'] ?? $cleanInput;
        }

        return [
            'ok' => true,
            'source_format' => $sourceFormat,
            'raw_source' => $rawSource,
            'input' => $cleanInput,
            'shortcode_mapping' => $mappingDoc,
            'mapping_version' => $mappingVersion,
        ];
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
