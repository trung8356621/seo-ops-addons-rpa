<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Illuminate\Support\Facades\Storage;
use Omnichannel\Addons\SocialAutomation\Models\SaArtifact;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaRecoveryErrorTag;
use Omnichannel\Addons\SocialAutomation\Models\SaRecoveryVisualTemplate;
use Omnichannel\Addons\SocialAutomation\Support\ScriptReleaseAssetReader;

final class RecoveryRuntimeAssetService
{
    public function __construct(private readonly ScriptReleaseAssetReader $assets = new ScriptReleaseAssetReader) {}

    /** @return array<string, mixed> */
    public function templateImage(int $ownerUserId, string $installationId, string $templateUuid): array
    {
        $template = SaRecoveryVisualTemplate::query()
            ->with('errorTag')
            ->where('template_uuid', trim($templateUuid))
            ->where('is_enabled', true)
            ->first();
        $tag = $template?->errorTag;
        if (! $template || ! $tag || ! $tag->is_enabled
            || (int) $tag->owner_user_id !== $ownerUserId
            || (string) $tag->installation_id !== $installationId) {
            return ['ok' => false, 'errors' => ['visual_template_not_found'], 'http' => 404];
        }

        $artifact = SaArtifact::query()
            ->whereKey((int) $template->source_artifact_id)
            ->where('attempt_id', (int) $template->source_attempt_id)
            ->where('kind', 'screenshot')
            ->first();
        if (! $artifact || ! str_starts_with(strtolower((string) $artifact->mime), 'image/')) {
            return ['ok' => false, 'errors' => ['template_artifact_invalid'], 'http' => 404];
        }

        $sha = strtolower(trim((string) $artifact->checksum_sha256));
        $path = (string) $artifact->storage_path;
        if (! preg_match('/^[a-f0-9]{64}$/', $sha) || $path === '' || str_contains($path, '..')) {
            return ['ok' => false, 'errors' => ['template_artifact_invalid'], 'http' => 404];
        }
        $disk = Storage::disk($artifact->storage_disk ?: 'local');
        if (! $disk->exists($path)) {
            return ['ok' => false, 'errors' => ['template_artifact_not_found'], 'http' => 404];
        }
        $bytes = $disk->get($path);
        if (! is_string($bytes) || $bytes === '' || ! hash_equals($sha, hash('sha256', $bytes))) {
            return ['ok' => false, 'errors' => ['template_artifact_digest_mismatch'], 'http' => 409];
        }

        return ['ok' => true, 'bytes' => $bytes, 'mime' => (string) $artifact->mime, 'sha256' => $sha,
            'visual_template_id' => (string) $template->template_uuid];
    }

    /** @return array<string, mixed> */
    public function pullHandler(int $ownerUserId, string $installationId, string $tagUuid, int $releaseId): array
    {
        $authorized = $this->authorizeHandler($ownerUserId, $installationId, $tagUuid, $releaseId);
        if (! ($authorized['ok'] ?? false)) return $authorized;
        /** @var SaPackage $package */ $package = $authorized['package'];
        /** @var SaPackageRelease $release */ $release = $authorized['release'];
        $manifest = is_array($release->manifest) ? $release->manifest : [];

        return ['ok' => true, 'release' => [
            'package_key' => (string) $package->package_key, 'package_id' => (int) $package->id,
            'version' => (string) $release->version, 'release_id' => (int) $release->id,
            'content_digest' => (string) $release->content_digest,
            'execution_digest' => $release->execution_digest !== null ? (string) $release->execution_digest : null,
            'status' => 'published', 'runtime_compatibility' => $release->runtime_compatibility,
            'manifest' => $manifest,
        ]];
    }

    /** @return array<string, mixed> */
    public function handlerAsset(int $ownerUserId, string $installationId, string $tagUuid, int $releaseId, string $assetPath, string $expectedSha256): array
    {
        $authorized = $this->authorizeHandler($ownerUserId, $installationId, $tagUuid, $releaseId);
        if (! ($authorized['ok'] ?? false)) return $authorized;
        /** @var SaPackageRelease $release */ $release = $authorized['release'];
        $manifest = is_array($release->manifest) ? $release->manifest : [];
        return $this->assets->loadVerified((int) $release->id, $assetPath, $expectedSha256,
            is_array($manifest['assets'] ?? null) ? $manifest['assets'] : []);
    }

    /** @return array<string, mixed> */
    private function authorizeHandler(int $ownerUserId, string $installationId, string $tagUuid, int $releaseId): array
    {
        if ($releaseId < 1 || trim($tagUuid) === '') return ['ok' => false, 'errors' => ['handler_request_invalid'], 'http' => 422];
        $tag = SaRecoveryErrorTag::query()->where('error_tag_uuid', trim($tagUuid))->where('owner_user_id', $ownerUserId)
            ->where('installation_id', $installationId)->where('is_enabled', true)->first();
        if (! $tag) return ['ok' => false, 'errors' => ['recovery_handler_not_found'], 'http' => 404];
        if ((int) $tag->handler_release_id !== $releaseId) return ['ok' => false, 'errors' => ['handler_release_not_bound'], 'http' => 403];
        $release = SaPackageRelease::query()->with('package')->whereKey($releaseId)->where('status', 'published')->first();
        $package = $release?->package;
        if (! $release || ! $package || (int) $tag->handler_package_id !== (int) $package->id
            || $package->kind !== 'script' || ! ($package->is_enabled ?? true)
            || (int) ($package->created_by_user_id ?? 0) !== $ownerUserId || ! $this->compatible($release)) {
            return ['ok' => false, 'errors' => ['handler_release_unavailable'], 'http' => 403];
        }
        return ['ok' => true, 'package' => $package, 'release' => $release];
    }

    private function compatible(SaPackageRelease $release): bool
    {
        $compat = is_array($release->runtime_compatibility) ? $release->runtime_compatibility : [];
        $manifest = is_array($release->manifest) ? $release->manifest : [];
        return ($compat['recovery_handler_v1'] ?? false) === true || ($manifest['runtime_contract'] ?? null) === 'recovery_handler_v1';
    }
}
