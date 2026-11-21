<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers\ScriptRegistry;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignScriptVariableSchema;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptRegistryUiService;
use Omnichannel\Addons\SocialAutomation\Services\ScriptResolutionService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;

/**
 * Staff Session API for React Script Registry.
 * Reuses ScriptRegistryUiService & ScriptResolutionService — no second business catalog.
 */
final class StaffScriptRegistryController extends Controller
{
    public const MAX_LIST = 500;

    public const MAX_RELEASES = 25;

    public function __construct(
        private readonly ScriptRegistryUiService $ui,
        private readonly SocialAutomationAccess $access,
        private readonly ?ScriptResolutionService $resolver = null,
    ) {}

    public function index(): JsonResponse
    {
        $this->access->assertCanAccess();

        $rows = $this->ui->listScriptsForUi();
        if (count($rows) > self::MAX_LIST) {
            $rows = array_slice($rows, 0, self::MAX_LIST);
        }

        $scripts = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $scripts[] = self::mapListItem($row);
        }

        return response()->json([
            'ok' => true,
            'scripts' => $scripts,
            'count' => count($scripts),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $this->access->assertCanAccess();

        $packageKey = trim($id);
        if ($packageKey === '' || strlen($packageKey) > 191) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $detail = $this->ui->scriptDetail($packageKey);
        if ($detail === null) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        return response()->json([
            'ok' => true,
            'script' => self::mapDetail($detail),
        ]);
    }

    public function updateSharing(Request $request, string $id): JsonResponse
    {
        $this->access->assertCanMutate();

        $packageKey = trim($id);
        if ($packageKey === '' || strlen($packageKey) > 191) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $package = SaPackage::query()
            ->where('package_key', $packageKey)
            ->where('kind', 'script')
            ->first();
        if (! $package instanceof SaPackage) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $mode = strtolower(trim((string) $request->input('sharing_mode', '')));
        $resolver = $this->resolver ?? app(ScriptResolutionService::class);
        $result = $resolver->setGlobalSharing($package, $mode);
        if (! ($result['ok'] ?? false)) {
            $payload = [
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'sharing_update_failed'),
            ];
            if (isset($result['conflict'])) {
                $payload['conflict'] = $result['conflict'];
            }

            return response()->json($payload, (int) ($result['http'] ?? 422));
        }

        $detail = $this->ui->scriptDetail($packageKey);

        return response()->json([
            'ok' => true,
            'sharing_mode' => $result['sharing_mode'],
            'global_scope' => $result['global_scope'],
            'script' => $detail !== null ? self::mapDetail($detail) : null,
        ]);
    }

    public function resolve(Request $request): JsonResponse
    {
        $this->access->assertCanAccess();

        $resolver = $this->resolver ?? app(ScriptResolutionService::class);
        $tagKey = trim((string) $request->query('tag_key', ''));
        $siteKey = strtolower(trim((string) $request->query('site_key', '')));
        $pathKey = strtolower(trim((string) $request->query('path_key', '')));
        $actionKey = trim((string) $request->query('action_key', ''));
        $version = trim((string) $request->query('version', ''));
        $wantedVersion = $version !== '' ? $version : null;

        if ($tagKey !== '') {
            $res = $resolver->resolveForTagKey($tagKey, $wantedVersion);
        } elseif ($siteKey !== '' && $pathKey !== '') {
            $site = SaSite::query()->where('key', $siteKey)->first();
            if (! $site instanceof SaSite) {
                return response()->json(['ok' => false, 'error' => 'site_not_found'], 404);
            }
            $path = SaPath::query()->where('site_id', $site->id)->where('key', $pathKey)->first();
            if (! $path instanceof SaPath) {
                return response()->json(['ok' => false, 'error' => 'path_not_found'], 404);
            }
            $res = $resolver->resolveForSiteAndPath(
                $site,
                $path,
                $actionKey !== '' ? $actionKey : null,
                $wantedVersion,
            );
        } else {
            return response()->json(['ok' => false, 'error' => 'tag_key_or_site_and_path_required'], 422);
        }

        return response()->json([
            'ok' => true,
            'resolution' => [
                'source' => (string) ($res['source'] ?? ScriptResolutionService::SOURCE_MISSING),
                'resolved' => (bool) ($res['resolved'] ?? false),
                'tag_enabled' => (bool) ($res['tag_enabled'] ?? true),
                'reason' => $res['reason'] ?? null,
                'target_tag_key' => $res['target_tag_key'] ?? null,
                'source_action_tag_key' => $res['source_action_tag_key'] ?? null,
                'cms_scope' => $res['cms_scope'] ?? null,
                'script' => $res['script'] ?? null,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function mapListItem(array $row): array
    {
        $videoMediaId = self::nullablePositiveInt($row['video_media_id'] ?? null);
        $thumbMediaId = self::nullablePositiveInt($row['thumbnail_media_id'] ?? null);

        return [
            'package_key' => (string) ($row['package_key'] ?? ''),
            'script_id' => (string) ($row['script_id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'description' => isset($row['description']) && $row['description'] !== null
                ? (string) $row['description']
                : null,
            'version' => (string) ($row['version'] ?? ''),
            'release_id' => (int) ($row['release_id'] ?? 0),
            'status' => (string) ($row['status'] ?? ''),
            'is_authentication' => (bool) ($row['is_authentication'] ?? false),
            'action_tag_key' => isset($row['action_tag_key']) && $row['action_tag_key'] !== null
                ? (string) $row['action_tag_key']
                : null,
            'sharing_mode' => (string) ($row['sharing_mode'] ?? SaPackage::SHARING_SITE_SPECIFIC),
            'global_scope' => is_array($row['global_scope'] ?? null) ? $row['global_scope'] : null,
            'global_eligible' => (bool) ($row['global_eligible'] ?? false),
            'global_ineligible_reason' => isset($row['global_ineligible_reason']) && $row['global_ineligible_reason'] !== null
                ? (string) $row['global_ineligible_reason']
                : null,
            'eligible_global_scope' => is_array($row['eligible_global_scope'] ?? null) ? $row['eligible_global_scope'] : null,
            'global_conflict' => is_array($row['global_conflict'] ?? null) ? $row['global_conflict'] : null,
            'assets_complete' => (bool) ($row['assets_complete'] ?? false),
            'video_demo_status' => (string) ($row['video_demo_status'] ?? 'missing'),
            'has_playable_video' => (bool) ($row['has_playable_video'] ?? false),
            'has_thumbnail' => (bool) ($row['has_thumbnail'] ?? false),
            'video_media_id' => $videoMediaId,
            'thumbnail_media_id' => $thumbMediaId,
            'video_media_url' => self::staffMediaUrl($videoMediaId),
            'thumbnail_media_url' => self::staffMediaUrl($thumbMediaId),
            'published_at' => isset($row['published_at']) && $row['published_at'] !== null
                ? (string) $row['published_at']
                : null,
            'updated_by_label' => (string) ($row['updated_by_label'] ?? '—'),
            'widget_dependency_count' => (int) ($row['widget_dependency_count'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    public static function mapDetail(array $detail): array
    {
        $releasesIn = is_array($detail['releases'] ?? null) ? $detail['releases'] : [];
        $releasesIn = array_slice($releasesIn, 0, self::MAX_RELEASES);
        $releases = [];
        foreach ($releasesIn as $release) {
            if (! is_array($release)) {
                continue;
            }
            $releases[] = self::mapRelease($release);
        }

        $latest = $releases[0] ?? null;

        return [
            'package_key' => (string) ($detail['package_key'] ?? ''),
            'script_id' => (string) ($detail['script_id'] ?? ''),
            'name' => (string) ($detail['name'] ?? ''),
            'description' => isset($detail['description']) && $detail['description'] !== null
                ? (string) $detail['description']
                : null,
            'is_authentication' => (bool) ($detail['is_authentication'] ?? false),
            'action_tag_key' => isset($detail['action_tag_key']) && $detail['action_tag_key'] !== null
                ? (string) $detail['action_tag_key']
                : null,
            'sharing_mode' => (string) ($detail['sharing_mode'] ?? SaPackage::SHARING_SITE_SPECIFIC),
            'global_scope' => is_array($detail['global_scope'] ?? null) ? $detail['global_scope'] : null,
            'global_eligible' => (bool) ($detail['global_eligible'] ?? false),
            'global_ineligible_reason' => isset($detail['global_ineligible_reason']) && $detail['global_ineligible_reason'] !== null
                ? (string) $detail['global_ineligible_reason']
                : null,
            'eligible_global_scope' => is_array($detail['eligible_global_scope'] ?? null) ? $detail['eligible_global_scope'] : null,
            'global_conflict' => is_array($detail['global_conflict'] ?? null) ? $detail['global_conflict'] : null,
            'sync_source' => isset($detail['sync_source']) && $detail['sync_source'] !== null
                ? (string) $detail['sync_source']
                : null,
            'updated_by_label' => (string) ($detail['updated_by_label'] ?? '—'),
            'widget_dependencies' => is_array($detail['widget_dependencies'] ?? null)
                ? $detail['widget_dependencies']
                : [],
            'version' => is_array($latest) ? (string) ($latest['version'] ?? '') : '',
            'status' => is_array($latest) ? (string) ($latest['status'] ?? '') : '',
            'release_id' => is_array($latest) ? (int) ($latest['id'] ?? 0) : 0,
            'assets_complete' => is_array($latest) ? (bool) ($latest['assets_complete'] ?? false) : false,
            'video_demo_status' => is_array($latest) ? (string) ($latest['video_demo_status'] ?? 'missing') : 'missing',
            'video_media_id' => is_array($latest) ? ($latest['video_media_id'] ?? null) : null,
            'thumbnail_media_id' => is_array($latest) ? ($latest['thumbnail_media_id'] ?? null) : null,
            'video_media_url' => is_array($latest) ? ($latest['video_media_url'] ?? null) : null,
            'thumbnail_media_url' => is_array($latest) ? ($latest['thumbnail_media_url'] ?? null) : null,
            'releases' => $releases,
        ];
    }

    /**
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>
     */
    public static function mapRelease(array $release): array
    {
        $videoMediaId = self::nullablePositiveInt($release['video_media_id'] ?? null);
        $thumbMediaId = self::nullablePositiveInt($release['thumbnail_media_id'] ?? null);
        $assets = is_array($release['assets'] ?? null) ? $release['assets'] : [];
        $steps = is_array($release['steps'] ?? null) ? $release['steps'] : [];

        // Variables originate only from this release's immutable manifest payload
        // (ScriptRegistryUiService copies manifest.variables onto the release DTO).
        $projection = self::projectReleaseVariables($release);

        return [
            'id' => (int) ($release['id'] ?? 0),
            'version' => (string) ($release['version'] ?? ''),
            'status' => (string) ($release['status'] ?? ''),
            'published_at' => isset($release['published_at']) && $release['published_at'] !== null
                ? (string) $release['published_at']
                : null,
            'content_digest' => (string) ($release['content_digest'] ?? ''),
            'execution_digest' => isset($release['execution_digest']) && $release['execution_digest'] !== null
                ? (string) $release['execution_digest']
                : null,
            'sync_source' => isset($release['sync_source']) && $release['sync_source'] !== null
                ? (string) $release['sync_source']
                : null,
            'assets_complete' => self::assetsCompleteSummary($assets),
            'asset_count' => count($assets),
            'variable_count' => count($projection['variables']),
            'variables' => $projection['variables'],
            'variables_status' => $projection['status'],
            'variables_errors' => $projection['errors'],
            'step_count' => count($steps),
            'video_demo_status' => (string) ($release['video_demo_status'] ?? 'missing'),
            'has_video_demo_meta' => is_array($release['video_demo'] ?? null),
            'video_media_id' => $videoMediaId,
            'thumbnail_media_id' => $thumbMediaId,
            'video_media_url' => self::staffMediaUrl($videoMediaId),
            'thumbnail_media_url' => self::staffMediaUrl($thumbMediaId),
            'runtime_requirements' => is_array($release['runtime_requirements'] ?? null)
                ? $release['runtime_requirements']
                : [],
        ];
    }

    /**
     * Sanitized variable projection shared with Campaign Shortcode V2 schema.
     *
     * @param  array<string, mixed>  $release
     * @return array{status: 'ok'|'empty'|'invalid', variables: list<array<string, mixed>>, errors: list<string>}
     */
    public static function projectReleaseVariables(array $release): array
    {
        $schema = new CampaignScriptVariableSchema;
        // Prefer explicit release.variables (copied from immutable published manifest).
        // Fall back to release.manifest when present. Never invent platform/tag defaults.
        if (array_key_exists('variables', $release)) {
            return $schema->projectStaffList(['variables' => $release['variables']]);
        }
        if (is_array($release['manifest'] ?? null)) {
            return $schema->projectStaffList($release['manifest']);
        }

        return [
            'status' => 'invalid',
            'variables' => [],
            'errors' => ['script_variables_missing'],
        ];
    }

    /**
     * @param  list<mixed>  $assets
     */
    public static function assetsCompleteSummary(array $assets): bool
    {
        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                return false;
            }
            if (trim((string) ($asset['path'] ?? '')) === ''
                || ! preg_match('/^[a-f0-9]{64}$/', strtolower((string) ($asset['sha256'] ?? '')))) {
                return false;
            }
        }

        return true;
    }

    public static function staffMediaUrl(?int $mediaId): ?string
    {
        if ($mediaId === null || $mediaId <= 0) {
            return null;
        }

        // Same-origin Staff Session path (ScriptReleaseMediaController). Relative avoids leaking APP_URL.
        return '/social-automation/script-media/'.$mediaId;
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $n = (int) $value;

        return $n > 0 ? $n : null;
    }
}
