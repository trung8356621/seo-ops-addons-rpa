<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Support;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptReleaseAsset;

/**
 * Shared fail-closed reader for script release ROI/assets.
 * Used by Auth Script and Action Script client download paths.
 * Never returns filesystem paths or credentials.
 */
final class ScriptReleaseAssetReader
{
    /**
     * @return array{
     *   ok: true,
     *   asset_path: string,
     *   sha256: string,
     *   mime: string,
     *   bytes: string
     * }|array{ok: false, errors: list<string>, http?: int}
     */
    public function loadVerified(
        int $releaseId,
        string $assetPath,
        string $expectedSha256,
        ?array $manifestAssets = null,
    ): array {
        $assetPath = trim($assetPath);
        $expectedSha256 = strtolower(trim($expectedSha256));

        if ($releaseId < 1 || $assetPath === '' || $expectedSha256 === '') {
            return ['ok' => false, 'errors' => ['asset_request_invalid'], 'http' => 422];
        }
        if (! preg_match('/^[a-f0-9]{64}$/', $expectedSha256)) {
            return ['ok' => false, 'errors' => ['asset_sha256_invalid'], 'http' => 422];
        }
        if (! $this->isSafeAssetPath($assetPath)) {
            return ['ok' => false, 'errors' => ['asset_not_found'], 'http' => 404];
        }

        $conn = SocialAutomationServiceConfig::CONNECTION;
        try {
            if (! Schema::connection($conn)->hasTable('sa_script_release_assets')) {
                return ['ok' => false, 'errors' => ['asset_schema_unavailable'], 'http' => 503];
            }
        } catch (\Throwable) {
            return ['ok' => false, 'errors' => ['asset_schema_unavailable'], 'http' => 503];
        }

        if (is_array($manifestAssets)) {
            $declared = false;
            foreach ($manifestAssets as $asset) {
                if (! is_array($asset)) {
                    continue;
                }
                $path = trim((string) ($asset['path'] ?? ''));
                $sha = strtolower(trim((string) ($asset['sha256'] ?? '')));
                if ($path === $assetPath && $sha === $expectedSha256) {
                    $declared = true;
                    break;
                }
            }
            if (! $declared) {
                return ['ok' => false, 'errors' => ['asset_not_found'], 'http' => 404];
            }
        }

        $row = SaScriptReleaseAsset::query()
            ->where('release_id', $releaseId)
            ->where('asset_path', $assetPath)
            ->first();
        if (! $row instanceof SaScriptReleaseAsset) {
            return ['ok' => false, 'errors' => ['asset_not_found'], 'http' => 404];
        }

        $storedSha = strtolower(trim((string) $row->checksum_sha256));
        if ($storedSha === '' || ! hash_equals($expectedSha256, $storedSha)) {
            return ['ok' => false, 'errors' => ['asset_sha256_mismatch'], 'http' => 404];
        }

        $storagePath = (string) $row->storage_path;
        if ($storagePath === '' || str_contains($storagePath, '..')) {
            return ['ok' => false, 'errors' => ['asset_not_found'], 'http' => 404];
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($storagePath)) {
            return ['ok' => false, 'errors' => ['asset_not_found'], 'http' => 404];
        }

        $bytes = $disk->get($storagePath);
        if (! is_string($bytes) || $bytes === '') {
            return ['ok' => false, 'errors' => ['asset_not_found'], 'http' => 404];
        }

        $actualSha = hash('sha256', $bytes);
        if (! hash_equals($expectedSha256, $actualSha)) {
            return ['ok' => false, 'errors' => ['asset_sha256_mismatch'], 'http' => 404];
        }

        $mime = trim((string) ($row->mime ?? ''));
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }

        return [
            'ok' => true,
            'asset_path' => $assetPath,
            'sha256' => $expectedSha256,
            'mime' => $mime,
            'bytes' => $bytes,
        ];
    }

    public function isSafeAssetPath(string $assetPath): bool
    {
        if ($assetPath === '' || str_contains($assetPath, "\0")) {
            return false;
        }
        if (str_contains($assetPath, '..') || str_contains($assetPath, '\\')) {
            return false;
        }
        if (str_starts_with($assetPath, '/') || str_starts_with($assetPath, '~')) {
            return false;
        }
        if (preg_match('/^[a-zA-Z]:/', $assetPath) === 1) {
            return false;
        }

        return (bool) preg_match('#^[A-Za-z0-9._/-]+$#', $assetPath);
    }
}
