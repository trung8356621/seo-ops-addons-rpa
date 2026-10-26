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
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptRequest;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
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

        $sites = SaSite::query()->with('paths')->orderBy('name')->get()->map(function (SaSite $s) {
            return [
                'id' => (int) $s->id,
                'key' => $s->key,
                'name' => $s->name,
                'base_url' => $s->base_url ?? '',
                'base_url_mode' => $s->base_url_mode ?? SaSite::MODE_FIXED,
                'test_base_url' => $s->test_base_url,
                'description' => $s->description,
                'paths_count' => $s->paths->count(),
            ];
        })->all();

        $paths = SaPath::query()->orderBy('site_id')->orderBy('name')->get()->map(function (SaPath $p) {
            $inputs = is_array($p->path_inputs) ? $p->path_inputs : PathPatternResolver::parseInputs($p->url_pattern ?? '');
            return [
                'id' => (int) $p->id,
                'site_id' => (int) $p->site_id,
                'site_key' => $p->site_key,
                'key' => $p->key,
                'name' => $p->name,
                'url_pattern' => $p->url_pattern,
                'path_inputs' => $inputs,
                'description' => $p->description,
            ];
        })->all();

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
        $tags = $tagQuery->get()->map(function (SaActionTag $t) use ($conn) {
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
            $actionKey = $t->action_key ?: $t->action;

            $siteName = $t->site?->name ?? ucfirst((string) $siteKey);
            $pathName = $t->path?->name ?? ucfirst((string) $pathKey);
            $actionName = $t->actionDefinition?->name ?? ucfirst((string) $actionKey);

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
                'scripts_count' => $linksCount,
                'ready' => $linksCount > 0,
                'latest_request_status' => $latestRequest?->status,
                'latest_request_id' => $latestRequest?->request_id,
            ];
        })->all();

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

        return response()->json([
            'ok' => true,
            'sites' => $sites,
            'paths' => $paths,
            'actions' => $actions,
            'tags' => $tags,
            'requests' => $requests,
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
        $testBaseUrl = SaSite::normalizeBaseUrl((string) $request->input('test_base_url', ''));
        $description = trim((string) $request->input('description', ''));

        if ($name === '') {
            return response()->json(['ok' => false, 'error' => 'Tên Site không được để trống.'], 422);
        }

        if ($mode === SaSite::MODE_FIXED && $baseUrl === '') {
            return response()->json(['ok' => false, 'error' => 'Base URL là bắt buộc với chế độ Fixed.'], 422);
        }

        $key = $rawKey !== '' ? preg_replace('/[^a-z0-9_-]/', '', $rawKey) : preg_replace('/[^a-z0-9_-]/', '', strtolower(str_replace(' ', '_', $name)));
        if ($key === '') {
            $key = 'site_'.bin2hex(random_bytes(3));
        }

        if (SaSite::query()->where('key', $key)->exists()) {
            return response()->json(['ok' => false, 'error' => "Site key [{$key}] đã tồn tại."], 422);
        }

        $site = SaSite::query()->create([
            'key' => $key,
            'name' => $name,
            'base_url_mode' => $mode,
            'base_url' => $mode === SaSite::MODE_FIXED ? $baseUrl : ($baseUrl !== '' ? $baseUrl : null),
            'test_base_url' => $testBaseUrl !== '' ? $testBaseUrl : null,
            'description' => $description !== '' ? $description : null,
        ]);

        return response()->json(['ok' => true, 'site' => $site]);
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

        if ($request->has('base_url')) {
            $site->base_url = $rawBaseUrl !== '' ? SaSite::normalizeBaseUrl($rawBaseUrl) : null;
        }

        if ($request->has('test_base_url')) {
            $rawTest = (string) $request->input('test_base_url', '');
            $site->test_base_url = $rawTest !== '' ? SaSite::normalizeBaseUrl($rawTest) : null;
        }

        if ($site->isFixed() && empty($site->base_url)) {
            return response()->json(['ok' => false, 'error' => 'Base URL là bắt buộc với chế độ Fixed.'], 422);
        }

        if ($request->has('description')) {
            $site->description = $description !== '' ? $description : null;
        }

        $site->save();

        return response()->json(['ok' => true, 'site' => $site]);
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

        $path = SaPath::query()->create([
            'site_id' => $site->id,
            'site_key' => $site->key,
            'key' => $key,
            'name' => $name,
            'url_pattern' => $urlPattern,
            'path_inputs' => $detectedInputs,
            'description' => $description !== '' ? $description : null,
        ]);

        return response()->json(['ok' => true, 'path' => $path]);
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

        $path->save();

        return response()->json(['ok' => true, 'path' => $path]);
    }

    public function storeTag(Request $request): JsonResponse
    {
        app(SocialAutomationAccess::class)->assertCanMutate();

        $siteId = (int) $request->input('site_id');
        $pathId = (int) $request->input('path_id');
        $actionKey = strtolower(trim((string) $request->input('action_key', '')));

        // Security check: like/share not permitted in V1
        if (in_array($actionKey, ['like', 'share'], true)) {
            return response()->json(['ok' => false, 'error' => 'Hành động like/share chưa được hỗ trợ.'], 422);
        }

        $site = SaSite::query()->find($siteId);
        $path = SaPath::query()->find($pathId);
        $action = SaAction::query()->where('key', $actionKey)->first();

        if (! $site || ! $path || ! $action) {
            return response()->json(['ok' => false, 'error' => 'Site, Path hoặc Action không hợp lệ.'], 422);
        }

        if ((int) $path->site_id !== (int) $site->id) {
            return response()->json(['ok' => false, 'error' => 'Path không thuộc Site đã chọn.'], 422);
        }

        $tagKey = SaActionTag::buildKey($site->key, $path->key, $action->key);

        if (SaActionTag::query()->where('tag_key', $tagKey)->exists()) {
            return response()->json(['ok' => false, 'error' => "Action Tag [{$tagKey}] đã tồn tại."], 422);
        }

        $displayName = "{$site->name} {$path->name} {$action->name}";
        $conn = SocialAutomationServiceConfig::CONNECTION;

        try {
            $result = DB::connection($conn)->transaction(function () use (
                $tagKey,
                $site,
                $path,
                $action,
                $displayName
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
                    'action_id' => $action->id,
                    'action_key' => $action->key,
                    'platform' => $site->key,
                    'target' => $path->key,
                    'action' => $action->key,
                    'name' => $displayName,
                    'description' => "Thao tác {$action->name} tại {$site->name} {$path->name}",
                ]);

                // Atomically create Script Request: check if active/pending request exists for idempotency
                $existingRequest = SaScriptRequest::query()
                    ->where('action_tag_key', $tag->tag_key)
                    ->whereIn('status', [
                        SaScriptRequest::STATUS_PENDING,
                        SaScriptRequest::STATUS_IN_PROGRESS,
                        SaScriptRequest::STATUS_COMPLETED,
                    ])
                    ->first();

                $userId = Auth::id();
                $createdRequest = $existingRequest ?: SaScriptRequest::query()->create([
                    'request_id' => 'req_'.bin2hex(random_bytes(6)),
                    'action_tag_id' => $tag->id,
                    'action_tag_key' => $tag->tag_key,
                    'site_key' => $site->key,
                    'site_base_url' => $site->isDynamic() ? null : $site->base_url,
                    'path_key' => $path->key,
                    'url_pattern' => $path->url_pattern,
                    'path_inputs' => $path->path_inputs,
                    'action_key' => $action->key,
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
                    $actionKey = $tag->action_key ?: $tag->action;

                    SaScriptRequest::query()->create([
                        'request_id' => 'req_'.bin2hex(random_bytes(6)),
                        'action_tag_id' => $tag->id,
                        'action_tag_key' => $tag->tag_key,
                        'site_key' => $site?->key ?? $tag->platform,
                        'site_base_url' => $site?->isDynamic() ? null : ($site?->base_url ?? 'https://www.facebook.com'),
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
            'site_base_url' => $site?->isDynamic() ? null : $site?->base_url,
            'path_key' => $tag->path_key ?: $tag->target,
            'url_pattern' => $path?->url_pattern,
            'path_inputs' => $path?->path_inputs,
            'action_key' => $tag->action_key ?: $tag->action,
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
