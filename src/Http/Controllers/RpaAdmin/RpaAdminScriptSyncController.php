<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers\RpaAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Omnichannel\Addons\SocialAutomation\Auth\RpaAdminSessionService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackageRegistryService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptCatalogService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptReleaseMediaService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptSyncPlanner;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptTombstoneService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\RpaAdminBackupService;
use Omnichannel\Addons\SocialAutomation\Models\SaRpaExecutionReport;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptReleaseAsset;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptReleaseMedia;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTagScript;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptRequest;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationApiStatus;

/**
 * Bidirectional Script sync for RPA Admin (user session auth).
 */
final class RpaAdminScriptSyncController extends Controller
{
    public function __construct(
        private readonly ScriptCatalogService $catalog,
        private readonly ScriptSyncPlanner $planner,
        private readonly PackageRegistryService $registry,
        private readonly ScriptTombstoneService $tombstones,
        private readonly ScriptReleaseMediaService $media,
        private readonly RpaAdminBackupService $backups,
    ) {}

    public function backup(Request $request): JsonResponse
    {
        if (!$this->backups->available()) return response()->json(['ok' => false, 'errors' => ['backup_schema_unavailable']], 503);
        $user = $request->attributes->get(RpaAdminSessionService::REQUEST_USER_KEY);
        $binary = base64_decode((string)$request->input('archive_base64', ''), true);
        if (!$user instanceof User || $binary === false) return response()->json(['ok' => false, 'errors' => ['backup_invalid']], 422);
        $result = $this->backups->put((int)$user->id, trim((string)$request->input('installation_id', '')),
            (int)$request->input('draft_revision', 0), $binary, strtolower((string)$request->input('sha256', '')),
            is_array($request->input('summary')) ? $request->input('summary') : []);
        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function backupInfo(Request $request): JsonResponse
    {
        if (!$this->backups->available()) return response()->json(['ok' => false, 'errors' => ['backup_schema_unavailable']], 503);
        $user = $request->attributes->get(RpaAdminSessionService::REQUEST_USER_KEY);
        $info = $user instanceof User ? $this->backups->info((int)$user->id, trim((string)$request->query('installation_id', ''))) : null;
        return response()->json(['ok' => true, 'backup' => $info]);
    }

    public function backupDownload(Request $request): JsonResponse
    {
        if (!$this->backups->available()) return response()->json(['ok' => false, 'errors' => ['backup_schema_unavailable']], 503);
        $user = $request->attributes->get(RpaAdminSessionService::REQUEST_USER_KEY);
        $found = $user instanceof User ? $this->backups->download((int)$user->id, trim((string)$request->input('installation_id', ''))) : null;
        if ($found === null) return response()->json(['ok' => false, 'errors' => ['backup_unavailable_or_corrupt']], 404);
        return response()->json(['ok' => true, 'archive_base64' => base64_encode($found['bytes']), 'sha256' => $found['checksum_sha256'], 'backup' => $found['info']]);
    }

    public function reports(Request $request): JsonResponse
    {
        if (!Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_rpa_execution_reports'))
            return response()->json(['ok' => false, 'errors' => ['execution_report_schema_unavailable']], 503);
        $after = max(0, (int)$request->query('after_id', 0));
        $rows = SaRpaExecutionReport::query()->where('id', '>', $after)->orderBy('id')->limit(500)->get()->map(fn ($r) => [
            'id' => (int)$r->id, 'installation_id' => $r->installation_id, 'script_id' => $r->script_id,
            'script_version' => $r->script_version, 'step_id' => $r->step_id, 'step_version' => $r->step_version,
            'run_id' => $r->run_id, 'timestamp' => $r->occurred_at?->toIso8601String(), 'status' => $r->status,
            'error_category' => $r->error_category,
        ])->all();
        return response()->json(['ok' => true, 'reports' => $rows, 'next_after_id' => $rows === [] ? $after : $rows[array_key_last($rows)]['id']]);
    }

    public function storeReport(Request $request): JsonResponse
    {
        if (!Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_rpa_execution_reports'))
            return response()->json(['ok' => false, 'errors' => ['execution_report_schema_unavailable']], 503);
        foreach (['installation_id', 'script_id', 'script_version', 'run_id', 'timestamp', 'status'] as $field) {
            if (trim((string)$request->input($field, '')) === '') return response()->json(['ok' => false, 'errors' => ['report_'.$field.'_required']], 422);
        }
        $row = SaRpaExecutionReport::query()->firstOrCreate([
            'installation_id' => (string)$request->input('installation_id'), 'run_id' => (string)$request->input('run_id'),
            'step_id' => $request->input('step_id'), 'step_version' => $request->input('step_version'),
        ], [
            'script_id' => (string)$request->input('script_id'), 'script_version' => (string)$request->input('script_version'),
            'occurred_at' => (string)$request->input('timestamp'), 'status' => (string)$request->input('status'),
            'error_category' => $request->input('error_category'), 'details' => is_array($request->input('details')) ? $request->input('details') : null,
        ]);
        return response()->json(['ok' => true, 'id' => (int)$row->id], $row->wasRecentlyCreated ? 201 : 200);
    }

    public function attachAsset(Request $request): JsonResponse
    {
        $package = SaPackage::query()->where('package_key', (string)$request->input('package_key'))->where('kind', 'script')->first();
        $release = $package instanceof SaPackage ? SaPackageRelease::query()->where('package_id', $package->id)->where('version', (string)$request->input('version'))->first() : null;
        $assetPath = trim((string)$request->input('asset_path', '')); $sha = strtolower((string)$request->input('sha256', ''));
        $bytes = base64_decode((string)$request->input('asset_base64', ''), true);
        if (!$release instanceof SaPackageRelease || $assetPath === '' || str_contains($assetPath, '..') || $bytes === false || !hash_equals($sha, hash('sha256', $bytes)))
            return response()->json(['ok' => false, 'errors' => ['script_asset_invalid']], 422);
        $expected = collect(is_array($release->manifest['assets'] ?? null) ? $release->manifest['assets'] : [])->first(fn ($a) => is_array($a) && ($a['path'] ?? null) === $assetPath && ($a['sha256'] ?? null) === $sha);
        if (!$expected) return response()->json(['ok' => false, 'errors' => ['script_asset_not_declared']], 422);
        $storagePath = sprintf('social-automation/script-assets/%d/%s', $release->id, $sha); Storage::disk('local')->put($storagePath, $bytes);
        SaScriptReleaseAsset::query()->updateOrCreate(['release_id' => $release->id, 'asset_path' => $assetPath], [
            'checksum_sha256' => $sha, 'mime' => (string)$request->input('mime', 'application/octet-stream'), 'storage_path' => $storagePath, 'size_bytes' => strlen($bytes)]);
        return response()->json(['ok' => true, 'sha256' => $sha]);
    }

    public function catalog(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'scripts' => $this->catalog->listScripts(),
        ]);
    }

    public function plan(Request $request): JsonResponse
    {
        $local = $request->input('local_scripts', []);
        if (! is_array($local)) {
            return response()->json(['ok' => false, 'errors' => ['local_scripts_required']], 422);
        }

        /** @var list<array<string, mixed>> $normalized */
        $normalized = [];
        foreach ($local as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized[] = [
                'script_id' => (string) ($row['script_id'] ?? ''),
                'package_key' => (string) ($row['package_key'] ?? ''),
                'version' => (string) ($row['version'] ?? ''),
                'content_digest' => (string) ($row['content_digest'] ?? ''),
                'execution_digest' => isset($row['execution_digest']) ? (string) $row['execution_digest'] : null,
                'lineage' => is_array($row['lineage'] ?? null) ? $row['lineage'] : [],
                'assets_complete' => (bool) ($row['assets_complete'] ?? true),
            ];
        }

        $suppressed = $this->tombstones->suppressedScriptIds(ScriptTombstoneService::SOURCE_RPA_ADMIN);
        $plan = $this->planner->plan($normalized, $this->catalog->listScripts(), $suppressed);

        return response()->json(['ok' => true, 'plan' => $plan]);
    }

    public function publish(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get(RpaAdminSessionService::REQUEST_USER_KEY);
        $payload = $request->all();
        if (! is_array($payload)) {
            $payload = [];
        }
        unset($payload['user_id'], $payload['id']);

        $result = $this->registry->publish($payload, [
            'user_id' => $user instanceof User ? (int) $user->id : null,
            'sync_source' => 'rpa_admin',
        ]);

        return response()->json($result->toArray(), $result->httpStatus());
    }

    public function pull(Request $request): JsonResponse
    {
        $packageKey = trim((string) $request->input('package_key', ''));
        $version = trim((string) $request->input('version', ''));
        if ($packageKey === '' || $version === '') {
            return response()->json(['ok' => false, 'errors' => ['package_key_version_required']], 422);
        }

        $release = $this->registry->findRelease($packageKey, $version);
        if ($release === null || strtolower((string) ($release['kind'] ?? '')) !== 'script') {
            return response()->json(['ok' => false, 'errors' => ['script_not_found']], 404);
        }

        $manifest = is_array($release['manifest'] ?? null) ? $release['manifest'] : [];
        $assets = is_array($manifest['assets'] ?? null) ? $manifest['assets'] : [];
        $assetsComplete = true;
        foreach ($assets as $asset) {
            if (! is_array($asset) || trim((string) ($asset['path'] ?? '')) === ''
                || ! preg_match('/^[a-f0-9]{64}$/', strtolower((string) ($asset['sha256'] ?? '')))) {
                $assetsComplete = false;
                break;
            }
        }

        return response()->json([
            'ok' => true,
            'release' => $release,
            'assets_complete' => $assetsComplete,
            'ready_to_execute' => $assetsComplete,
        ], SocialAutomationApiStatus::fromResult(true));
    }

    public function attachVideoDemo(Request $request): JsonResponse
    {
        $packageKey = trim((string) $request->input('package_key', ''));
        $version = trim((string) $request->input('version', ''));
        $mime = strtolower(trim((string) $request->input('mime', 'video/webm')));
        $checksum = trim((string) $request->input('sha256', ''));

        $file = $request->file('video');
        $binary = null;
        if ($file !== null && $file->isValid()) {
            $binary = (string) file_get_contents($file->getRealPath());
            if ($mime === 'video/webm' || $mime === 'video/mp4') {
                // keep request mime
            } elseif (is_string($file->getMimeType()) && $file->getMimeType() !== '') {
                $mime = strtolower($file->getMimeType());
            }
        } else {
            $raw = $request->input('video_base64');
            if (is_string($raw) && $raw !== '') {
                $decoded = base64_decode($raw, true);
                $binary = $decoded === false ? null : $decoded;
            }
        }

        if ($packageKey === '' || $version === '' || ! is_string($binary) || $binary === '') {
            return response()->json(['ok' => false, 'errors' => ['video_demo_upload_required']], 422);
        }

        $thumbFile = $request->file('thumbnail');
        $thumbBinary = null;
        $thumbSha = trim((string) $request->input('thumbnail_sha256', ''));
        if ($thumbFile !== null && $thumbFile->isValid()) {
            $thumbBinary = (string) file_get_contents($thumbFile->getRealPath());
        } else {
            $thumbRaw = $request->input('thumbnail_base64');
            if (is_string($thumbRaw) && $thumbRaw !== '') {
                $decoded = base64_decode($thumbRaw, true);
                $thumbBinary = $decoded === false ? null : $decoded;
            }
        }

        $result = $this->media->attachVideoDemo(
            $packageKey,
            $version,
            $binary,
            $mime !== '' ? $mime : 'video/webm',
            $checksum !== '' ? $checksum : null,
            $thumbBinary,
            $thumbSha !== '' ? $thumbSha : null,
        );

        return response()->json($result, ($result['ok'] ?? false) ? 201 : 422);
    }

    public function actionTags(): JsonResponse
    {
        if (! Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_action_tags')) {
            return response()->json(['ok' => false, 'errors' => ['action_tags_schema_unavailable']], 503);
        }

        $tags = SaActionTag::query()->with(['site', 'path'])->orderBy('platform')->orderBy('target')->orderBy('action')->get()->map(function (SaActionTag $t) {
            $linkedCount = Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_action_tag_scripts')
                ? SaActionTagScript::query()->where('action_tag_id', $t->id)->count()
                : 0;

            $siteKey = $t->site_key ?: $t->platform;
            $pathKey = $t->path_key ?: $t->target;
            $actionKey = $t->action_key ?: $t->action;

            return [
                'id' => (int) $t->id,
                'tag_key' => $t->tag_key,
                'site_key' => $siteKey,
                'path_key' => $pathKey,
                'action_key' => $actionKey,
                'platform' => $t->platform ?: $siteKey,
                'target' => $t->target ?: $pathKey,
                'action' => $t->action ?: $actionKey,
                'name' => $t->name,
                'description' => $t->description,
                'linked_scripts_count' => $linkedCount,
                'base_url' => $t->site?->base_url,
                'url_pattern' => $t->path?->url_pattern,
                'path_inputs' => $t->path?->path_inputs ?? [],
            ];
        })->all();

        return response()->json(['ok' => true, 'action_tags' => $tags]);
    }

    public function activeScripts(Request $request): JsonResponse
    {
        if (! Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_action_tags')) {
            return response()->json(['ok' => false, 'errors' => ['action_tags_schema_unavailable']], 503);
        }

        $tagKeyFilter = trim((string) $request->query('tag_key', ''));
        $tagIdFilter = $request->query('action_tag_id');

        $query = SaActionTag::query()->with(['site', 'path']);
        if ($tagKeyFilter !== '') {
            $query->where('tag_key', $tagKeyFilter);
        }
        if ($tagIdFilter !== null && $tagIdFilter !== '') {
            $query->where('id', (int) $tagIdFilter);
        }

        $tags = $query->orderBy('platform')->orderBy('target')->orderBy('action')->get();
        $hasTagScripts = Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_action_tag_scripts');
        $hasPackages = Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_packages');

        $activeScripts = [];

        if ($hasPackages) {
            foreach ($tags as $tag) {
                $release = null;
                $package = null;

                // Priority 1: sa_action_tag_scripts linkage (latest release)
                if ($hasTagScripts) {
                    $links = SaActionTagScript::query()
                        ->where('action_tag_id', $tag->id)
                        ->orWhere('action_tag_key', $tag->tag_key)
                        ->orderByDesc('release_id')
                        ->orderByDesc('id')
                        ->get();

                    foreach ($links as $link) {
                        if ($link->release_id) {
                            $rel = SaPackageRelease::query()
                                ->where('id', $link->release_id)
                                ->where('status', 'published')
                                ->first();
                            if ($rel instanceof SaPackageRelease) {
                                $pkg = SaPackage::query()->where('id', $rel->package_id)->where('kind', 'script')->first();
                                if ($pkg instanceof SaPackage) {
                                    $release = $rel;
                                    $package = $pkg;
                                    break;
                                }
                            }
                        }
                        if ($link->package_id) {
                            $pkg = SaPackage::query()->where('id', $link->package_id)->where('kind', 'script')->first();
                            if ($pkg instanceof SaPackage) {
                                $rel = SaPackageRelease::query()
                                    ->where('package_id', $pkg->id)
                                    ->where('status', 'published')
                                    ->orderByDesc('published_at')
                                    ->orderByDesc('id')
                                    ->first();
                                if ($rel instanceof SaPackageRelease) {
                                    $release = $rel;
                                    $package = $pkg;
                                    break;
                                }
                            }
                        }
                    }
                }

                // Priority 2: Look up by action_tag_key matching package_key
                if (! $release) {
                    $pkg = SaPackage::query()->where('kind', 'script')->where('package_key', $tag->tag_key)->first();
                    if ($pkg instanceof SaPackage) {
                        $rel = SaPackageRelease::query()
                            ->where('package_id', $pkg->id)
                            ->where('status', 'published')
                            ->orderByDesc('published_at')
                            ->orderByDesc('id')
                            ->first();
                        if ($rel instanceof SaPackageRelease) {
                            $release = $rel;
                            $package = $pkg;
                        }
                    }
                }

                // Priority 3: Check all published script releases whose manifest has action_tag_key
                if (! $release) {
                    $candidates = SaPackageRelease::query()
                        ->where('status', 'published')
                        ->whereHas('package', fn ($q) => $q->where('kind', 'script'))
                        ->orderByDesc('published_at')
                        ->orderByDesc('id')
                        ->get();

                    foreach ($candidates as $cand) {
                        $man = is_array($cand->manifest) ? $cand->manifest : [];
                        if (($man['action_tag_key'] ?? '') === $tag->tag_key) {
                            $release = $cand;
                            $package = $cand->package;
                            break;
                        }
                    }
                }

                if ($release instanceof SaPackageRelease && $package instanceof SaPackage) {
                    $releaseData = $this->registry->findRelease($package->package_key, (string) $release->version);
                    if ($releaseData !== null) {
                        if (! isset($releaseData['manifest']) || ! is_array($releaseData['manifest'])) {
                            $releaseData['manifest'] = [];
                        }
                        $releaseData['manifest']['action_tag_key'] = $tag->tag_key;
                        $releaseData['manifest']['action_tag_id'] = (int) $tag->id;

                        if (Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_script_release_media')) {
                            $videoMedia = SaScriptReleaseMedia::query()
                                ->where('release_id', $release->id)
                                ->where('kind', SaScriptReleaseMedia::KIND_VIDEO_DEMO)
                                ->first();
                            $thumbMedia = SaScriptReleaseMedia::query()
                                ->where('release_id', $release->id)
                                ->where('kind', SaScriptReleaseMedia::KIND_THUMBNAIL)
                                ->first();

                            if ($videoMedia instanceof SaScriptReleaseMedia) {
                                $existingVd = is_array($releaseData['manifest']['video_demo'] ?? null)
                                    ? $releaseData['manifest']['video_demo']
                                    : [];
                                $mediaStatus = $this->media->statusForRelease((int) $release->id);
                                $scriptIdKey = (string) ($releaseData['manifest']['script_id'] ?? $package->package_key);
                                $testRunId = (string) ($existingVd['test_run_id'] ?? 'run-1');
                                $videoExt = $videoMedia->mime === 'video/mp4' ? 'mp4' : 'webm';
                                $releaseData['manifest']['video_demo'] = array_merge($existingVd, [
                                    'artifact_ref' => $existingVd['artifact_ref'] ?? ("demos/{$scriptIdKey}/{$testRunId}/recording.{$videoExt}"),
                                    'sha256' => $videoMedia->checksum_sha256 ?: ($existingVd['sha256'] ?? null),
                                    'mime' => $videoMedia->mime ?: ($existingVd['mime'] ?? 'video/webm'),
                                    'test_run_id' => $testRunId,
                                    'reviewed' => true,
                                    'thumbnail_ref' => $existingVd['thumbnail_ref'] ?? ("demos/{$scriptIdKey}/{$testRunId}/recording-thumb.jpg"),
                                    'thumbnail_sha256' => $thumbMedia?->checksum_sha256 ?: ($existingVd['thumbnail_sha256'] ?? null),
                                    'status' => $mediaStatus['video_demo_status'] ?? 'ready',
                                ]);
                            }
                        }

                        $activeScripts[] = [
                            'action_tag_id' => (int) $tag->id,
                            'action_tag_key' => $tag->tag_key,
                            'script_id' => (string) ($releaseData['manifest']['script_id'] ?? $package->package_key),
                            'package_key' => (string) $package->package_key,
                            'version' => (string) $release->version,
                            'content_digest' => (string) $release->content_digest,
                            'execution_digest' => (string) ($release->execution_digest ?? ''),
                            'name' => (string) $package->name,
                            'release' => $releaseData,
                        ];
                    }
                }
            }
        }

        return response()->json([
            'ok' => true,
            'active_scripts' => $activeScripts,
        ]);
    }

    public function getAsset(Request $request): JsonResponse
    {
        $packageKey = trim((string) $request->query('package_key', ''));
        $version = trim((string) $request->query('version', ''));
        $assetPath = trim((string) $request->query('asset_path', ''));

        if ($packageKey === '' || $version === '' || $assetPath === '') {
            return response()->json(['ok' => false, 'errors' => ['package_key_version_asset_path_required']], 422);
        }

        $package = SaPackage::query()->where('package_key', $packageKey)->where('kind', 'script')->first();
        if (! $package instanceof SaPackage) {
            return response()->json(['ok' => false, 'errors' => ['package_not_found']], 404);
        }

        $release = SaPackageRelease::query()->where('package_id', $package->id)->where('version', $version)->first();
        if (! $release instanceof SaPackageRelease) {
            return response()->json(['ok' => false, 'errors' => ['release_not_found']], 404);
        }

        $asset = SaScriptReleaseAsset::query()
            ->where('release_id', $release->id)
            ->where('asset_path', $assetPath)
            ->first();

        if (! $asset instanceof SaScriptReleaseAsset) {
            return response()->json(['ok' => false, 'errors' => ['asset_not_found']], 404);
        }

        if (! Storage::disk('local')->exists($asset->storage_path)) {
            return response()->json(['ok' => false, 'errors' => ['asset_file_missing']], 404);
        }

        $content = Storage::disk('local')->get($asset->storage_path);

        return response()->json([
            'ok' => true,
            'asset_path' => $assetPath,
            'sha256' => $asset->checksum_sha256,
            'mime' => $asset->mime,
            'asset_base64' => base64_encode($content),
        ]);
    }

    public function getVideoDemo(Request $request): mixed
    {
        $packageKey = trim((string) $request->query('package_key', ''));
        $version = trim((string) $request->query('version', ''));
        $kind = trim((string) $request->query('kind', 'video_demo'));
        if ($kind !== 'thumbnail') {
            $kind = 'video_demo';
        }

        if ($packageKey === '' || $version === '') {
            return response()->json(['ok' => false, 'errors' => ['package_key_and_version_required']], 422);
        }

        $package = SaPackage::query()->where('package_key', $packageKey)->where('kind', 'script')->first();
        if (! $package instanceof SaPackage) {
            return response()->json(['ok' => false, 'errors' => ['package_not_found']], 404);
        }

        $release = SaPackageRelease::query()
            ->where('package_id', $package->id)
            ->where('version', $version)
            ->first();
        if (! $release instanceof SaPackageRelease) {
            return response()->json(['ok' => false, 'errors' => ['release_not_found']], 404);
        }

        $mediaKind = $kind === 'thumbnail' ? SaScriptReleaseMedia::KIND_THUMBNAIL : SaScriptReleaseMedia::KIND_VIDEO_DEMO;
        $row = SaScriptReleaseMedia::query()
            ->where('release_id', $release->id)
            ->where('kind', $mediaKind)
            ->first();

        if (! $row instanceof SaScriptReleaseMedia || $row->storage_path === '') {
            return response()->json(['ok' => false, 'errors' => ['media_not_found']], 404);
        }

        $disk = Storage::disk($row->storage_disk ?: 'local');
        if (! $disk->exists($row->storage_path)) {
            return response()->json(['ok' => false, 'errors' => ['media_file_missing']], 404);
        }

        $format = strtolower(trim((string) $request->query('format', '')));
        if ($format === 'binary') {
            return $disk->response(
                $row->storage_path,
                basename($row->storage_path),
                [
                    'Content-Type' => (string) ($row->mime ?: ($kind === 'thumbnail' ? 'image/jpeg' : 'video/webm')),
                    'Cache-Control' => 'private, no-cache',
                    'X-Checksum-Sha256' => (string) ($row->checksum_sha256 ?? ''),
                ]
            );
        }

        $content = $disk->get($row->storage_path);

        return response()->json([
            'ok' => true,
            'kind' => $mediaKind,
            'mime' => $row->mime ?: ($kind === 'thumbnail' ? 'image/jpeg' : 'video/webm'),
            'sha256' => $row->checksum_sha256,
            'size_bytes' => $row->size_bytes ?: strlen($content),
            'content_base64' => base64_encode($content),
        ]);
    }

    public function scriptRequests(Request $request): JsonResponse
    {
        if (! Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_script_requests')) {
            return response()->json(['ok' => false, 'errors' => ['script_requests_schema_unavailable']], 503);
        }

        $query = SaScriptRequest::query()->with(['actionTag.site', 'actionTag.path'])->orderBy('id', 'desc');
        $status = trim((string) $request->query('status', ''));
        if ($status !== '') {
            $query->where('status', $status);
        }

        $rows = $query->limit(200)->get()->map(function (SaScriptRequest $r) {
            $tag = $r->actionTag;
            $siteKey = $r->site_key ?: ($tag?->site_key ?: $tag?->platform);
            $siteBaseUrl = $r->site_base_url ?: $tag?->site?->base_url;
            $pathKey = $r->path_key ?: ($tag?->path_key ?: $tag?->target);
            $urlPattern = $r->url_pattern ?: $tag?->path?->url_pattern;
            $pathInputs = $r->path_inputs ?: ($tag?->path?->path_inputs ?? []);
            $actionKey = $r->action_key ?: ($tag?->action_key ?: $tag?->action);

            return [
                'id' => (int) $r->id,
                'request_id' => $r->request_id,
                'action_tag_id' => (int) $r->action_tag_id,
                'action_tag_key' => $r->action_tag_key,
                'site_key' => $siteKey,
                'site_base_url' => $siteBaseUrl,
                'path_key' => $pathKey,
                'url_pattern' => $urlPattern,
                'path_inputs' => $pathInputs,
                'action_key' => $actionKey,
                'request_type' => $r->request_type,
                'status' => $r->status,
                'script_id' => $r->script_id,
                'script_version' => $r->script_version,
                'error_reason' => $r->error_reason,
                'metadata' => $r->metadata,
                'created_at' => $r->created_at?->toIso8601String(),
            ];
        })->all();

        return response()->json(['ok' => true, 'script_requests' => $rows]);
    }

    public function claimScriptRequest(Request $request, string $requestId): JsonResponse
    {
        if (! Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_script_requests')) {
            return response()->json(['ok' => false, 'errors' => ['script_requests_schema_unavailable']], 503);
        }

        $row = SaScriptRequest::query()->where('request_id', $requestId)->first();
        if (! $row instanceof SaScriptRequest) {
            return response()->json(['ok' => false, 'errors' => ['script_request_not_found']], 404);
        }

        if ($row->status === SaScriptRequest::STATUS_PENDING || $row->status === SaScriptRequest::STATUS_NEEDS_FIX) {
            $row->update(['status' => SaScriptRequest::STATUS_DEVELOPING]);
        }

        return response()->json(['ok' => true, 'request' => [
            'id' => (int) $row->id,
            'request_id' => $row->request_id,
            'action_tag_key' => $row->action_tag_key,
            'status' => $row->status,
        ]]);
    }
}
