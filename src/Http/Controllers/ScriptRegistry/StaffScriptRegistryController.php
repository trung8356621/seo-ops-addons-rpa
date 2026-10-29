<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers\ScriptRegistry;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptRegistryUiService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;

/**
 * Staff Session read API for React Script Registry (GET only).
 * Reuses ScriptRegistryUiService — no second business catalog.
 */
final class StaffScriptRegistryController extends Controller
{
    public const MAX_LIST = 500;

    public const MAX_RELEASES = 25;

    public function __construct(
        private readonly ScriptRegistryUiService $ui,
        private readonly SocialAutomationAccess $access,
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
        $variables = is_array($release['variables'] ?? null) ? $release['variables'] : [];
        $steps = is_array($release['steps'] ?? null) ? $release['steps'] : [];

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
            'variable_count' => count($variables),
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
