<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers\ActionRegistry;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Models\SaAction;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTagScript;
use Omnichannel\Addons\SocialAutomation\Models\SaCmsType;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptRequest;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Services\AuthScriptService;
use Omnichannel\Addons\SocialAutomation\Services\CmsPathTemplateService;
use Omnichannel\Addons\SocialAutomation\Services\ScriptResolutionService;
use Omnichannel\Addons\SocialAutomation\Services\SiteDeletionService;
use Omnichannel\Addons\SocialAutomation\Support\PathPatternResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

final class ActionRegistryController
{
    public function data(Request $request): JsonResponse
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;
        if (! Schema::connection($conn)->hasTable('sa_sites')) {
            return response()->json(['ok' => false, 'error' => 'schema_unavailable'], 503);
        }

        $sites = SaSite::query()->with('paths')->orderBy('name')->get()->map(
            fn (SaSite $s) => $this->serializeSite($s)
        )->all();

        $paths = SaPath::query()->orderBy('site_id')->orderBy('name')->get()->map(
            fn (SaPath $p) => $this->serializePath($p)
        )->all();

        // V1 Actions: Post, Comment, Collect (explicitly filter out any unapproved like/share)
        $actions = SaAction::query()
            ->whereNotIn('key', ['like', 'share'])
            ->orderBy('name')
            ->get()
            ->map(fn (SaAction $a) => [
                'id' => (int) $a->id,
                'key' => $a->key,
                'name' => $a->name,
                'description' => $a->description,
            ])->all();

        $tagQuery = SaActionTag::query()->with(['site', 'path', 'actionDefinition'])->orderByDesc('id');
        $tags = $tagQuery->get()->map(
            fn (SaActionTag $t) => $this->serializeTag($t)
        )->all();

        $requests = Schema::connection($conn)->hasTable('sa_script_requests')
            ? SaScriptRequest::query()->orderByDesc('id')->limit(50)->get()->map(fn (SaScriptRequest $r) => [
                'id' => (int) $r->id,
                'request_id' => $r->request_id,
                'action_tag_key' => $r->action_tag_key,
                'request_type' => $r->request_type,
                'status' => $r->status,
                'script_id' => $r->script_id,
                'script_version' => $r->script_version,
                'error_reason' => $r->error_reason,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->all()
            : [];

        $cmsTypes = [];
        if (Schema::connection($conn)->hasTable('sa_cms_types')) {
            $cmsCtrl = new CmsRegistryController();
            $cmsTypes = SaCmsType::query()
                ->with(['pathTemplates' => fn ($q) => $q->orderBy('id')])
                ->orderBy('name')
                ->get()
                ->map(fn (SaCmsType $c) => $cmsCtrl->serializeCmsType($c))
                ->all();
        }

        return response()->json([
            'ok' => true,
            'sites' => $sites,
            'paths' => $paths,
            'actions' => $actions,
            'tags' => $tags,
            'requests' => $requests,
            'cms_types' => $cmsTypes,
        ]);
    }

    public function storeSite(Request $request): JsonResponse
    {
        app(SocialAutomationAccess::class)->assertCanMutate();

        $name = trim((string) $request->input('name', ''));
        $rawKey = strtolower(trim((string) $request->input('key', '')));
        $mode = strtolower(trim((string) $request->input('base_url_mode', SaSite::MODE_FIXED)));
        if ($mode !== SaSite::MODE_DYNAMIC) {
            $mode = SaSite::MODE_FIXED;
        }

        $rawBaseUrl = (string) $request->input('base_url', '');
        $baseUrl = SaSite::normalizeBaseUrl($rawBaseUrl);
        // Legacy column retained for compatibility; not required for unified Base URL contract.
        $testBaseUrl = SaSite::normalizeBaseUrl((string) $request->input('test_base_url', ''));
        $description = trim((string) $request->input('description', ''));
        $cmsType = SaSite::normalizeCmsType($request->input('cms_type'));
        $cmsService = app(CmsPathTemplateService::class);

        if (! SaSite::isSupportedCmsType($cmsType)) {
            return response()->json(['ok' => false, 'error' => 'invalid_cms_type'], 422);
        }
        if ($cmsType !== null && ! $cmsService->isCmsTypeEnabled($cmsType)) {
            return response()->json(['ok' => false, 'error' => 'cms_type_disabled'], 422);
        }

        if ($name === '') {
            return response()->json(['ok' => false, 'error' => 'Tên Site không được để trống.'], 422);
        }

        // Unified contract: both Fixed and Dynamic Sites configure the same base_url.
        if ($baseUrl === '') {
            return response()->json(['ok' => false, 'error' => 'Base URL là bắt buộc.'], 422);
        }

        $key = $rawKey !== '' ? preg_replace('/[^a-z0-9_-]/', '', $rawKey) : preg_replace('/[^a-z0-9_-]/', '', strtolower(str_replace(' ', '_', $name)));
        if ($key === '') {
            $key = 'site_'.bin2hex(random_bytes(3));
        }

        if (SaSite::query()->where('key', $key)->exists()) {
            return response()->json(['ok' => false, 'error' => "Site key [{$key}] đã tồn tại."], 422);
        }

        $conn = SocialAutomationServiceConfig::CONNECTION;
        $pathOverrides = $cmsService->normalizePathOverrides($request->input('path_overrides'));
        $userId = Auth::id();
        $sitePayload = [
            'key' => $key,
            'name' => $name,
            'base_url_mode' => $mode,
            'base_url' => $baseUrl,
            'test_base_url' => $testBaseUrl !== '' ? $testBaseUrl : null,
            'description' => $description !== '' ? $description : null,
        ];
        if ($this->siteCmsTypeColumnReady()) {
            $sitePayload['cms_type'] = $cmsType;
        }

        $result = DB::connection($conn)->transaction(function () use ($sitePayload, $cmsService, $pathOverrides, $userId): array {
            $site = SaSite::query()->create($sitePayload);
            $synced = $cmsService->syncSiteCmsPathsAndTags(
                $site,
                $pathOverrides,
                is_numeric($userId) ? (int) $userId : null,
            );

            return [
                'site' => $site->fresh('paths') ?? $site,
                'paths' => $synced['paths'],
                'tags' => $synced['tags'],
            ];
        });

        return response()->json([
            'ok' => true,
            'site' => $this->serializeSite($result['site']),
            'paths' => array_map(fn (SaPath $p) => $this->serializePath($p), $result['paths']),
            'tags' => array_map(fn (SaActionTag $t) => $this->serializeTag($t), $result['tags']),
        ]);
    }

    public function updateSite(Request $request, int $id): JsonResponse
    {
        app(SocialAutomationAccess::class)->assertCanMutate();

        $site = SaSite::query()->find($id);
        if (! $site) {
            return response()->json(['ok' => false, 'error' => 'site_not_found'], 404);
        }

        $name = trim((string) $request->input('name', ''));
        $rawBaseUrl = (string) $request->input('base_url', '');
        $description = trim((string) $request->input('description', ''));

        if ($name !== '') {
            $site->name = $name;
        }

        if ($request->has('base_url_mode')) {
            $mode = strtolower(trim((string) $request->input('base_url_mode', SaSite::MODE_FIXED)));
            $site->base_url_mode = $mode === SaSite::MODE_DYNAMIC ? SaSite::MODE_DYNAMIC : SaSite::MODE_FIXED;
        }

        $cmsService = app(CmsPathTemplateService::class);
        if ($request->exists('cms_type')) {
            $cmsType = SaSite::normalizeCmsType($request->input('cms_type'));
            if (! SaSite::isSupportedCmsType($cmsType)) {
                return response()->json(['ok' => false, 'error' => 'invalid_cms_type'], 422);
            }
            if ($cmsType !== null && $cmsType !== ($site->cms_type ?? null) && ! $cmsService->isCmsTypeEnabled($cmsType)) {
                return response()->json(['ok' => false, 'error' => 'cms_type_disabled'], 422);
            }
            if ($this->siteCmsTypeColumnReady()) {
                $site->cms_type = $cmsType;
            }
        }

        if ($request->has('base_url')) {
            $site->base_url = $rawBaseUrl !== '' ? SaSite::normalizeBaseUrl($rawBaseUrl) : null;
        }

        // Legacy write path retained; do not require dual entry of the same URL.
        if ($request->has('test_base_url')) {
            $rawTest = (string) $request->input('test_base_url', '');
            $site->test_base_url = $rawTest !== '' ? SaSite::normalizeBaseUrl($rawTest) : null;
        }

        if (empty($site->base_url)) {
            return response()->json(['ok' => false, 'error' => 'Base URL là bắt buộc.'], 422);
        }

        if ($request->has('description')) {
            $site->description = $description !== '' ? $description : null;
        }

        $conn = SocialAutomationServiceConfig::CONNECTION;
        $pathOverrides = $cmsService->normalizePathOverrides($request->input('path_overrides'));
        $userId = Auth::id();

        $result = DB::connection($conn)->transaction(function () use ($site, $cmsService, $pathOverrides, $userId): array {
            $site->save();
            $synced = $cmsService->syncSiteCmsPathsAndTags(
                $site,
                $pathOverrides,
                is_numeric($userId) ? (int) $userId : null,
            );

            return [
                'site' => $site->fresh('paths') ?? $site,
                'paths' => $synced['paths'],
                'tags' => $synced['tags'],
            ];
        });

        return response()->json([
            'ok' => true,
            'site' => $this->serializeSite($result['site']),
            'paths' => array_map(fn (SaPath $p) => $this->serializePath($p), $result['paths']),
            'tags' => array_map(fn (SaActionTag $t) => $this->serializeTag($t), $result['tags']),
        ]);
    }

    public function destroySite(int $id): JsonResponse
    {
        app(SocialAutomationAccess::class)->assertCanMutate();

        $site = SaSite::query()->find($id);
        if (! $site) {
            return response()->json(['ok' => false, 'error' => 'site_not_found'], 404);
        }

        $result = app(SiteDeletionService::class)->deleteSafely($site);
        $status = (int) ($result['status'] ?? ($result['ok'] ? 200 : 422));
        unset($result['status']);

        return response()->json($result, $status);
    }

    public function storePath(Request $request): JsonResponse
    {
        app(SocialAutomationAccess::class)->assertCanMutate();

        $siteId = (int) $request->input('site_id');
        $site = SaSite::query()->find($siteId);
        if (! $site) {
            return response()->json(['ok' => false, 'error' => 'site_not_found'], 422);
        }

        $name = trim((string) $request->input('name', ''));
        $rawKey = strtolower(trim((string) $request->input('key', '')));
        $urlPattern = trim((string) $request->input('url_pattern', ''));
        $description = trim((string) $request->input('description', ''));

        if ($name === '' || $urlPattern === '') {
            return response()->json(['ok' => false, 'error' => 'name_and_url_pattern_required'], 422);
        }

        if (! str_starts_with($urlPattern, '/')) {
            $urlPattern = '/'.$urlPattern;
        }

        $key = $rawKey !== '' ? preg_replace('/[^a-z0-9_-]/', '', $rawKey) : preg_replace('/[^a-z0-9_-]/', '', strtolower(str_replace(' ', '_', $name)));
        if ($key === '') {
            $key = 'path_'.bin2hex(random_bytes(3));
        }

        if (SaPath::query()->where('site_id', $site->id)->where('key', $key)->exists()) {
            return response()->json(['ok' => false, 'error' => "Path [{$key}] đã tồn tại trong site [{$site->name}]."], 422);
        }

        $detectedInputs = PathPatternResolver::parseInputs($urlPattern);
        $wantsAuth = filter_var($request->input('is_authentication', false), FILTER_VALIDATE_BOOLEAN);
        if ($wantsAuth && ! $this->pathAuthenticationColumnReady()) {
            return response()->json([
                'ok' => false,
                'error' => 'schema_unavailable',
                'message' => 'Migration/schema Authentication Path chưa sẵn sàng (sa_paths.is_authentication).',
            ], 503);
        }

        $conn = SocialAutomationServiceConfig::CONNECTION;
        $userId = Auth::id();
        $cmsService = app(CmsPathTemplateService::class);
        $hasAuthCol = $this->pathAuthenticationColumnReady();

        $payload = [
            'site_id' => $site->id,
            'site_key' => $site->key,
            'key' => $key,
            'name' => $name,
            'url_pattern' => $urlPattern,
            'path_inputs' => $detectedInputs,
            'description' => $description !== '' ? $description : null,
        ];
        if ($hasAuthCol) {
            $payload['is_authentication'] = $wantsAuth;
        }

        $result = DB::connection($conn)->transaction(function () use (
            $payload,
            $hasAuthCol,
            $site,
            $cmsService,
            $userId,
        ): array {
            $path = SaPath::query()->create($payload);
            if ($hasAuthCol) {
                app(AuthScriptService::class)->syncPackageAuthFlagsForPath($path);
            }
            $ensured = $cmsService->ensureTagForPath(
                $site,
                $path,
                is_numeric($userId) ? (int) $userId : null,
            );

            return [
                'path' => $path,
                'tag' => $ensured['tag'],
            ];
        });

        $response = [
            'ok' => true,
            'path' => $this->serializePath($result['path']),
        ];
        if ($result['tag'] instanceof SaActionTag) {
            $response['tag'] = $this->serializeTag($result['tag']);
        }

        return response()->json($response);
    }

    public function updatePath(Request $request, int $id): JsonResponse
    {
        app(SocialAutomationAccess::class)->assertCanMutate();

        $path = SaPath::query()->find($id);
        if (! $path) {
            return response()->json(['ok' => false, 'error' => 'path_not_found'], 404);
        }

        $name = trim((string) $request->input('name', ''));
        $urlPattern = trim((string) $request->input('url_pattern', ''));
        $description = trim((string) $request->input('description', ''));

        if ($name !== '') {
            $path->name = $name;
        }
        if ($urlPattern !== '') {
            if (! str_starts_with($urlPattern, '/')) {
                $urlPattern = '/'.$urlPattern;
            }
            $path->url_pattern = $urlPattern;
            $path->path_inputs = PathPatternResolver::parseInputs($urlPattern);
        }
        if ($request->has('description')) {
            $path->description = $description !== '' ? $description : null;
        }

        $authFlagTouched = false;
        if ($request->exists('is_authentication')) {
            if (! $this->pathAuthenticationColumnReady()) {
                return response()->json([
                    'ok' => false,
                    'error' => 'schema_unavailable',
                    'message' => 'Migration/schema Authentication Path chưa sẵn sàng (sa_paths.is_authentication).',
                ], 503);
            }
            $path->is_authentication = filter_var($request->input('is_authentication'), FILTER_VALIDATE_BOOLEAN);
            $authFlagTouched = true;
        }

        if ($this->pathTemplateTrackingReady() && $path->cms_template_id !== null && $path->isDirty(['name', 'url_pattern', 'is_authentication'])) {
            $path->is_override = true;
        }

        $path->save();

        if ($authFlagTouched) {
            app(AuthScriptService::class)->syncPackageAuthFlagsForPath($path);
        }

        return response()->json(['ok' => true, 'path' => $this->serializePath($path->fresh() ?? $path)]);
    }

    public function storeTag(Request $request): JsonResponse
    {
        app(SocialAutomationAccess::class)->assertCanMutate();

        $siteId = (int) $request->input('site_id');
        $pathId = (int) $request->input('path_id');
        $rawActionKey = $request->input('action_key');
        $actionKey = $rawActionKey !== null ? strtolower(trim((string) $rawActionKey)) : '';
        $hasActionKey = $actionKey !== '';

        // Security check: like/share not permitted in V1
        if ($hasActionKey && in_array($actionKey, ['like', 'share'], true)) {
            return response()->json(['ok' => false, 'error' => 'Hành động like/share chưa được hỗ trợ.'], 422);
        }

        $site = SaSite::query()->find($siteId);
        $path = SaPath::query()->find($pathId);
        $action = $hasActionKey ? SaAction::query()->where('key', $actionKey)->first() : null;

        if (! $site || ! $path || ($hasActionKey && ! $action)) {
            return response()->json([
                'ok' => false,
                'error' => $hasActionKey
                    ? 'Site, Path hoặc Action không hợp lệ.'
                    : 'Site hoặc Path không hợp lệ.',
            ], 422);
        }

        if ((int) $path->site_id !== (int) $site->id) {
            return response()->json(['ok' => false, 'error' => 'Path không thuộc Site đã chọn.'], 422);
        }

        $tagKey = $action
            ? SaActionTag::buildKey($site->key, $path->key, $action->key)
            : SaActionTag::buildKey($site->key, $path->key);

        if (SaActionTag::query()->where('tag_key', $tagKey)->exists()) {
            return response()->json(['ok' => false, 'error' => "Action Tag [{$tagKey}] đã tồn tại."], 422);
        }

        $displayName = $action
            ? "{$site->name} {$path->name} {$action->name}"
            : "{$site->name} {$path->name}";
        $description = $action
            ? "Thao tác {$action->name} tại {$site->name} {$path->name}"
            : "Thao tác tại {$site->name} {$path->name}";
        $conn = SocialAutomationServiceConfig::CONNECTION;
        $authScriptId = $request->has('auth_script_id') ? (int) $request->input('auth_script_id') : null;
        $authReleaseId = $request->has('auth_release_id') ? (int) $request->input('auth_release_id') : null;
        $wantsAuthPin = $request->filled('auth_script_id') || $request->filled('auth_release_id');

        try {
            $result = DB::connection($conn)->transaction(function () use (
                $tagKey,
                $site,
                $path,
                $action,
                $displayName,
                $description,
                $wantsAuthPin,
                $authScriptId,
                $authReleaseId,
            ) {
                // Double check uniqueness inside transaction to prevent race conditions
                if (SaActionTag::query()->where('tag_key', $tagKey)->exists()) {
                    throw new \RuntimeException("Action Tag [{$tagKey}] đã tồn tại.");
                }

                $tag = SaActionTag::query()->create([
                    'tag_key' => $tagKey,
                    'site_id' => $site->id,
                    'site_key' => $site->key,
                    'path_id' => $path->id,
                    'path_key' => $path->key,
                    'action_id' => $action?->id,
                    'action_key' => $action?->key,
                    'platform' => $site->key,
                    'target' => $path->key,
                    'action' => $action?->key ?? '',
                    'name' => $displayName,
                    'description' => $description,
                ]);

                if ($wantsAuthPin) {
                    $pin = app(AuthScriptService::class)->setAuthPin($tag, $authScriptId, $authReleaseId);
                    if (! ($pin['ok'] ?? false)) {
                        throw new \RuntimeException(implode(',', $pin['errors'] ?? ['auth_pin_invalid']));
                    }
                    $tag = $pin['tag'];
                }

                // Atomically create Script Request: check if open create request exists for idempotency
                $existingRequest = SaScriptRequest::query()
                    ->where('action_tag_key', $tag->tag_key)
                    ->whereIn('status', SaScriptRequest::openCreateRequestStatuses())
                    ->first();

                $userId = Auth::id();
                $createdRequest = $existingRequest ?: SaScriptRequest::query()->create([
                    'request_id' => 'req_'.bin2hex(random_bytes(6)),
                    'action_tag_id' => $tag->id,
                    'action_tag_key' => $tag->tag_key,
                    'site_key' => $site->key,
                    'site_base_url' => $site->canonicalBaseUrl(),
                    'path_key' => $path->key,
                    'url_pattern' => $path->url_pattern,
                    'path_inputs' => $path->path_inputs,
                    'action_key' => $action?->key,
                    'request_type' => SaScriptRequest::TYPE_CREATE,
                    'status' => SaScriptRequest::STATUS_PENDING,
                    'created_by_user_id' => is_numeric($userId) ? (int) $userId : null,
                ]);

                return ['tag' => $tag, 'request' => $createdRequest];
            });

            return response()->json([
                'ok' => true,
                'tag' => $result['tag'],
                'request' => $result['request'],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage() ?: 'Không thể tạo Action Tag và Script Request.',
            ], 422);
        }
    }

    public function updateAuthPin(Request $request, int $id): JsonResponse
    {
        app(SocialAutomationAccess::class)->assertCanMutate();

        $tag = SaActionTag::query()->find($id);
        if (! $tag) {
            return response()->json(['ok' => false, 'error' => 'tag_not_found'], 404);
        }

        $clear = filter_var($request->input('clear', false), FILTER_VALIDATE_BOOLEAN);
        $packageId = $clear ? null : ($request->has('auth_script_id') ? (int) $request->input('auth_script_id') : null);
        $releaseId = $clear ? null : ($request->has('auth_release_id') ? (int) $request->input('auth_release_id') : null);

        if (! $clear && ($packageId === null || $releaseId === null)) {
            return response()->json(['ok' => false, 'error' => 'auth_pin_incomplete', 'errors' => ['auth_pin_incomplete']], 422);
        }

        $result = app(AuthScriptService::class)->setAuthPin(
            $tag,
            $clear ? null : $packageId,
            $clear ? null : $releaseId,
        );
        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'error' => ($result['errors'][0] ?? 'auth_pin_invalid'),
                'errors' => $result['errors'] ?? ['auth_pin_invalid'],
            ], 422);
        }

        return response()->json(['ok' => true, 'tag' => $result['tag']]);
    }

    public function authenticationScriptsCatalog(): JsonResponse
    {
        app(SocialAutomationAccess::class)->assertCanAccess();

        return response()->json([
            'ok' => true,
            'packages' => app(AuthScriptService::class)->listAuthenticationCatalog(),
        ]);
    }

    public function listTagAccountGrants(int $id): JsonResponse
    {
        app(SocialAutomationAccess::class)->assertCanAccess();

        if (! SaActionTag::query()->whereKey($id)->exists()) {
            return response()->json(['ok' => false, 'error' => 'tag_not_found'], 404);
        }

        return response()->json([
            'ok' => true,
            'grants' => app(AuthScriptService::class)->listGrantsForTag($id),
        ]);
    }

    public function grantTagAccount(Request $request, int $id): JsonResponse
    {
        app(SocialAutomationAccess::class)->assertCanMutate();

        $accountId = (int) $request->input('account_id');
        if ($accountId < 1) {
            return response()->json(['ok' => false, 'error' => 'account_id_required', 'errors' => ['account_id_required']], 422);
        }

        $userId = Auth::id();
        $result = app(AuthScriptService::class)->grant(
            $accountId,
            $id,
            is_numeric($userId) ? (int) $userId : null,
        );
        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'error' => ($result['errors'][0] ?? 'grant_failed'),
                'errors' => $result['errors'] ?? ['grant_failed'],
            ], 422);
        }

        return response()->json(['ok' => true, 'grant' => [
            'account_id' => (int) $result['binding']->account_id,
            'action_tag_id' => (int) $result['binding']->action_tag_id,
            'granted_at' => $result['binding']->granted_at?->toIso8601String(),
        ]]);
    }

    public function revokeTagAccount(int $id, int $accountId): JsonResponse
    {
        app(SocialAutomationAccess::class)->assertCanMutate();

        $revoked = app(AuthScriptService::class)->revoke($accountId, $id);

        return response()->json(['ok' => true, 'revoked' => $revoked]);
    }

    public function patchTag(Request $request, int $id): JsonResponse
    {
        app(SocialAutomationAccess::class)->assertCanMutate();

        $tag = SaActionTag::query()->with(['site', 'path', 'actionDefinition'])->find($id);
        if (! $tag) {
            return response()->json(['ok' => false, 'error' => 'tag_not_found'], 404);
        }

        if (! $this->tagEnabledColumnReady()) {
            return response()->json([
                'ok' => false,
                'error' => 'schema_unavailable',
                'message' => 'Migration/schema is_enabled chưa sẵn sàng (sa_action_tags.is_enabled).',
            ], 503);
        }

        $raw = $request->exists('is_enabled')
            ? $request->input('is_enabled')
            : ($request->exists('enabled') ? $request->input('enabled') : null);

        $enabled = $raw !== null
            ? filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        if ($enabled === null) {
            return response()->json(['ok' => false, 'error' => 'is_enabled_required'], 422);
        }

        $tag->is_enabled = $enabled;
        $tag->save();

        return response()->json([
            'ok' => true,
            'tag' => $this->serializeTag($tag->fresh(['site', 'path', 'actionDefinition']) ?? $tag),
        ]);
    }

    public function destroyTag(int $id): JsonResponse
    {
        app(SocialAutomationAccess::class)->assertCanMutate();

        $tag = SaActionTag::query()->find($id);
        if (! $tag) {
            return response()->json(['ok' => false, 'error' => 'tag_not_found'], 404);
        }

        $conn = SocialAutomationServiceConfig::CONNECTION;
        if (Schema::connection($conn)->hasTable('sa_action_tag_scripts')) {
            $linkedCount = SaActionTagScript::query()->where('action_tag_id', $tag->id)->count();
            if ($linkedCount > 0) {
                return response()->json(['ok' => false, 'error' => 'Không thể xóa Action Tag đã có Script liên kết.'], 422);
            }
        }

        DB::connection($conn)->transaction(function () use ($tag) {
            SaScriptRequest::query()->where('action_tag_id', $tag->id)->delete();
            $tag->delete();
        });

        return response()->json(['ok' => true]);
    }

    /**
     * Proposes/executes an idempotent backfill for existing Action Tags that lack any Script Request.
     * Safe for inspection; never executed automatically on production.
     *
     * @return array{scanned: int, eligible: int, created: int, dry_run: bool, tags: list<string>}
     */
    public static function backfillMissingRequests(bool $dryRun = true): array
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;
        if (! Schema::connection($conn)->hasTable('sa_action_tags') || ! Schema::connection($conn)->hasTable('sa_script_requests')) {
            return ['scanned' => 0, 'eligible' => 0, 'created' => 0, 'dry_run' => $dryRun, 'tags' => []];
        }

        $allTags = SaActionTag::query()->with(['site', 'path'])->get();
        $scanned = 0;
        $eligible = 0;
        $created = 0;
        $tagKeys = [];

        foreach ($allTags as $tag) {
            $scanned++;
            $hasRequest = SaScriptRequest::query()
                ->where('action_tag_id', $tag->id)
                ->orWhere('action_tag_key', $tag->tag_key)
                ->exists();

            if ($hasRequest) {
                continue;
            }

            $eligible++;
            $tagKeys[] = $tag->tag_key;

            if (! $dryRun) {
                DB::connection($conn)->transaction(function () use ($tag, &$created) {
                    $site = $tag->site;
                    $path = $tag->path;
                    $actionKey = $tag->resolvedActionKey();

                    SaScriptRequest::query()->create([
                        'request_id' => 'req_'.bin2hex(random_bytes(6)),
                        'action_tag_id' => $tag->id,
                        'action_tag_key' => $tag->tag_key,
                        'site_key' => $site?->key ?? $tag->platform,
                        'site_base_url' => $site?->canonicalBaseUrl(),
                        'path_key' => $path?->key ?? $tag->target,
                        'url_pattern' => $path?->url_pattern ?? '/',
                        'path_inputs' => $path?->path_inputs ?? [],
                        'action_key' => $actionKey,
                        'request_type' => SaScriptRequest::TYPE_CREATE,
                        'status' => SaScriptRequest::STATUS_PENDING,
                    ]);
                    $created++;
                });
            }
        }

        return [
            'scanned' => $scanned,
            'eligible' => $eligible,
            'created' => $created,
            'dry_run' => $dryRun,
            'tags' => $tagKeys,
        ];
    }

    /**
     * @return array{
     *   id: int,
     *   site_id: int,
     *   site_key: string|null,
     *   key: string,
     *   name: string,
     *   url_pattern: string|null,
     *   path_inputs: list<array{key: string, type: string, required: bool}>,
     *   description: string|null,
     *   is_authentication?: bool
     * }
     */
    private function serializePath(SaPath $p): array
    {
        $inputs = is_array($p->path_inputs)
            ? $p->path_inputs
            : PathPatternResolver::parseInputs($p->url_pattern ?? '');

        $row = [
            'id' => (int) $p->id,
            'site_id' => (int) $p->site_id,
            'site_key' => $p->site_key,
            'key' => $p->key,
            'name' => $p->name,
            'url_pattern' => $p->url_pattern,
            'path_inputs' => $inputs,
            'description' => $p->description,
        ];

        // Omit key when schema is missing so clients can detect unreadiness.
        if ($this->pathAuthenticationColumnReady()) {
            $row['is_authentication'] = (bool) ($p->is_authentication ?? false);
        }

        if ($this->pathTemplateTrackingReady()) {
            $tplId = $p->cms_template_id !== null ? (int) $p->cms_template_id : null;
            $isOverride = (bool) ($p->is_override ?? false);
            $row['cms_template_id'] = $tplId;
            $row['is_override'] = $isOverride;
            $row['is_template_inherited'] = $tplId !== null && ! $isOverride;
            $row['template_version'] = $p->template_version !== null ? (int) $p->template_version : null;
        }

        return $row;
    }

    private function pathTemplateTrackingReady(): bool
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;

        try {
            return Schema::connection($conn)->hasTable('sa_paths')
                && Schema::connection($conn)->hasColumn('sa_paths', 'cms_template_id')
                && Schema::connection($conn)->hasColumn('sa_paths', 'is_override')
                && Schema::connection($conn)->hasColumn('sa_paths', 'template_version');
        } catch (\Throwable) {
            return false;
        }
    }

    private function pathAuthenticationColumnReady(): bool
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;

        try {
            return Schema::connection($conn)->hasTable('sa_paths')
                && Schema::connection($conn)->hasColumn('sa_paths', 'is_authentication');
        } catch (\Throwable) {
            return false;
        }
    }

    private function siteCmsTypeColumnReady(): bool
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;

        try {
            return Schema::connection($conn)->hasTable('sa_sites')
                && Schema::connection($conn)->hasColumn('sa_sites', 'cms_type');
        } catch (\Throwable) {
            return false;
        }
    }

    private function tagEnabledColumnReady(): bool
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;

        try {
            return Schema::connection($conn)->hasTable('sa_action_tags')
                && Schema::connection($conn)->hasColumn('sa_action_tags', 'is_enabled');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{
     *   id: int,
     *   key: string,
     *   name: string,
     *   base_url: string,
     *   base_url_mode: string,
     *   cms_type: string|null,
     *   test_base_url: string|null,
     *   description: string|null,
     *   paths_count: int
     * }
     */
    private function serializeSite(SaSite $s): array
    {
        $pathsCount = $s->relationLoaded('paths')
            ? $s->paths->count()
            : $s->paths()->count();

        return [
            'id' => (int) $s->id,
            'key' => $s->key,
            'name' => $s->name,
            'base_url' => $s->base_url ?? '',
            'base_url_mode' => $s->base_url_mode ?? SaSite::MODE_FIXED,
            'cms_type' => $s->cms_type ?? null,
            'test_base_url' => $s->test_base_url,
            'description' => $s->description,
            'paths_count' => $pathsCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTag(SaActionTag $t): array
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;
        $hasScriptLinks = Schema::connection($conn)->hasTable('sa_action_tag_scripts');
        $linksCount = $hasScriptLinks
            ? SaActionTagScript::query()->where('action_tag_id', $t->id)->count()
            : 0;

        $hasRequests = Schema::connection($conn)->hasTable('sa_script_requests');
        $latestRequest = $hasRequests
            ? SaScriptRequest::query()->where('action_tag_id', $t->id)->orderByDesc('id')->first()
            : null;

        $siteKey = $t->site_key ?: $t->platform;
        $pathKey = $t->path_key ?: $t->target;
        $actionKey = $t->resolvedActionKey();

        $siteName = $t->site?->name ?? ucfirst((string) $siteKey);
        $pathName = $t->path?->name ?? ucfirst((string) $pathKey);
        $actionName = $t->actionDefinition?->name ?? ($actionKey !== null ? ucfirst($actionKey) : null);

        $hasAuthCols = Schema::connection($conn)->hasColumn('sa_action_tags', 'auth_script_id');
        $resolution = app(ScriptResolutionService::class)->resolveForTag($t);

        return [
            'id' => (int) $t->id,
            'tag_key' => $t->tag_key,
            'site_id' => $t->site_id,
            'site_key' => $siteKey,
            'site_name' => $siteName,
            'path_id' => $t->path_id,
            'path_key' => $pathKey,
            'path_name' => $pathName,
            'action_id' => $t->action_id,
            'action_key' => $actionKey,
            'action_name' => $actionName,
            'name' => $t->name,
            'description' => $t->description,
            'is_enabled' => $this->tagEnabledColumnReady() ? (bool) ($t->is_enabled ?? true) : true,
            'auth_script_id' => $hasAuthCols && $t->auth_script_id !== null ? (int) $t->auth_script_id : null,
            'auth_release_id' => $hasAuthCols && $t->auth_release_id !== null ? (int) $t->auth_release_id : null,
            'scripts_count' => $linksCount,
            'ready' => $linksCount > 0,
            'effective_ready' => (bool) ($resolution['resolved'] ?? false),
            'resolution_source' => (string) ($resolution['source'] ?? ScriptResolutionService::SOURCE_MISSING),
            'resolution_reason' => $resolution['reason'] ?? null,
            'cms_scope' => $resolution['cms_scope'] ?? null,
            'effective_script' => $resolution['script'] ?? null,
            'latest_request_status' => $latestRequest?->status,
            'latest_request_id' => $latestRequest?->request_id,
        ];
    }

    /**
     * @return array{paths: int, tags: int, scripts: int, script_requests: int, campaigns: int}
     */
    private function collectSiteDependencies(SaSite $site): array
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;
        $siteKeyLower = strtolower((string) $site->key);

        $pathsCount = 0;
        if (Schema::connection($conn)->hasTable('sa_paths')) {
            $pathsCount = SaPath::query()
                ->where(function ($q) use ($site): void {
                    $q->where('site_id', $site->id)
                        ->orWhere('site_key', $site->key);
                })
                ->count();
        }

        $tagIds = [];
        $tagKeys = [];
        $tagsCount = 0;
        if (Schema::connection($conn)->hasTable('sa_action_tags')) {
            $tags = SaActionTag::query()
                ->where(function ($q) use ($site): void {
                    if (Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasColumn('sa_action_tags', 'site_id')) {
                        $q->where('site_id', $site->id)
                            ->orWhere('site_key', $site->key)
                            ->orWhere('platform', $site->key);
                    } else {
                        $q->where('platform', $site->key);
                    }
                })
                ->get(['id', 'tag_key']);
            $tagsCount = $tags->count();
            $tagIds = $tags->pluck('id')->map(fn ($v) => (int) $v)->all();
            $tagKeys = $tags->pluck('tag_key')->map(fn ($v) => (string) $v)->all();
        }

        $scriptsCount = 0;
        if (Schema::connection($conn)->hasTable('sa_action_tag_scripts')) {
            $scriptsCount = SaActionTagScript::query()
                ->where(function ($q) use ($tagIds, $tagKeys, $site): void {
                    $q->where('action_tag_key', 'like', $site->key.'.%');
                    if ($tagIds !== []) {
                        $q->orWhereIn('action_tag_id', $tagIds);
                    }
                    if ($tagKeys !== []) {
                        $q->orWhereIn('action_tag_key', $tagKeys);
                    }
                })
                ->count();
        }

        $requestsCount = 0;
        if (Schema::connection($conn)->hasTable('sa_script_requests')) {
            $requestsCount = SaScriptRequest::query()
                ->where(function ($q) use ($tagIds, $tagKeys, $site): void {
                    $q->where('action_tag_key', 'like', $site->key.'.%');
                    if (Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasColumn('sa_script_requests', 'site_key')) {
                        $q->orWhere('site_key', $site->key);
                    }
                    if ($tagIds !== []) {
                        $q->orWhereIn('action_tag_id', $tagIds);
                    }
                    if ($tagKeys !== []) {
                        $q->orWhereIn('action_tag_key', $tagKeys);
                    }
                })
                ->count();
        }

        $campaignsCount = 0;
        if (Schema::connection($conn)->hasTable('sa_campaigns')) {
            $campaignsCount = DB::connection($conn)
                ->table('sa_campaigns')
                ->where(function ($q) use ($siteKeyLower, $tagKeys, $site): void {
                    $q->whereRaw('LOWER(platform) = ?', [$siteKeyLower])
                        ->orWhere('script_id', 'like', $site->key.'.%');
                    if ($tagKeys !== []) {
                        $q->orWhereIn('script_id', $tagKeys);
                    }
                })
                ->count();
        }

        return [
            'paths' => $pathsCount,
            'tags' => $tagsCount,
            'scripts' => $scriptsCount,
            'script_requests' => $requestsCount,
            'campaigns' => $campaignsCount,
        ];
    }

    public function createRequest(Request $request, int $tagId): JsonResponse
    {
        app(SocialAutomationAccess::class)->assertCanMutate();

        $tag = SaActionTag::query()->with(['site', 'path'])->find($tagId);
        if (! $tag) {
            return response()->json(['ok' => false, 'error' => 'tag_not_found'], 404);
        }

        $type = trim((string) $request->input('request_type', 'create'));
        if (! in_array($type, [SaScriptRequest::TYPE_CREATE, SaScriptRequest::TYPE_FIX], true)) {
            $type = SaScriptRequest::TYPE_CREATE;
        }

        $scriptId = trim((string) $request->input('script_id', ''));
        $scriptVersion = trim((string) $request->input('script_version', ''));
        $errorReason = trim((string) $request->input('error_reason', ''));

        $userId = Auth::id();
        $site = $tag->site;
        $path = $tag->path;

        $req = SaScriptRequest::query()->create([
            'request_id' => 'req_'.bin2hex(random_bytes(6)),
            'action_tag_id' => $tag->id,
            'action_tag_key' => $tag->tag_key,
            'site_key' => $tag->site_key ?: $tag->platform,
            'site_base_url' => $site?->canonicalBaseUrl(),
            'path_key' => $tag->path_key ?: $tag->target,
            'url_pattern' => $path?->url_pattern,
            'path_inputs' => $path?->path_inputs,
            'action_key' => $tag->resolvedActionKey(),
            'request_type' => $type,
            'status' => SaScriptRequest::STATUS_PENDING,
            'script_id' => $scriptId !== '' ? $scriptId : null,
            'script_version' => $scriptVersion !== '' ? $scriptVersion : null,
            'error_reason' => $errorReason !== '' ? $errorReason : null,
            'created_by_user_id' => is_numeric($userId) ? (int) $userId : null,
        ]);

        return response()->json(['ok' => true, 'request' => $req]);
    }
}
