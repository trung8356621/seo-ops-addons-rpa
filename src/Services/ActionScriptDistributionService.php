<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Omnichannel\Addons\SocialAutomation\Domain\AttemptFencePolicy;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaJobAttempt;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackageDigest;
use Omnichannel\Addons\SocialAutomation\Support\ScriptReleaseAssetReader;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;

/**
 * Client Action Script pull/asset for Campaign Jobs.
 * Distinct from Auth Script distribution — gated by Job attempt lease + exact Job pin.
 */
final class ActionScriptDistributionService
{
    public function __construct(
        private readonly ScriptReleaseAssetReader $assets = new ScriptReleaseAssetReader,
        private readonly AttemptFencePolicy $fence = new AttemptFencePolicy,
    ) {}

    /**
     * @return array{ok: true, release: array<string, mixed>}|array{ok: false, errors: list<string>, http?: int}
     */
    public function pullForJob(
        string $installationId,
        string $jobUuid,
        string $attemptUuid,
        string $leaseToken,
        string $accountUuid,
        string $packageKey,
        string $version,
        int $releaseId,
    ): array {
        $authz = $this->authorizeJobPin(
            $installationId,
            $jobUuid,
            $attemptUuid,
            $leaseToken,
            $accountUuid,
            $packageKey,
            $version,
            $releaseId,
        );
        if (! ($authz['ok'] ?? false)) {
            return [
                'ok' => false,
                'errors' => $authz['errors'] ?? ['action_script_forbidden'],
                'http' => (int) ($authz['http'] ?? 403),
            ];
        }

        /** @var SaPackage $package */
        $package = $authz['package'];
        /** @var SaPackageRelease $release */
        $release = $authz['release'];

        return [
            'ok' => true,
            'release' => $this->clientReleasePayload($package, $release, includeManifest: true),
        ];
    }

    /**
     * @return array{
     *   ok: true,
     *   asset_path: string,
     *   sha256: string,
     *   mime: string,
     *   bytes: string
     * }|array{ok: false, errors: list<string>, http?: int}
     */
    public function downloadAssetForJob(
        string $installationId,
        string $jobUuid,
        string $attemptUuid,
        string $leaseToken,
        string $accountUuid,
        string $packageKey,
        string $version,
        int $releaseId,
        string $assetPath,
        string $expectedSha256,
    ): array {
        $authz = $this->authorizeJobPin(
            $installationId,
            $jobUuid,
            $attemptUuid,
            $leaseToken,
            $accountUuid,
            $packageKey,
            $version,
            $releaseId,
        );
        if (! ($authz['ok'] ?? false)) {
            return [
                'ok' => false,
                'errors' => $authz['errors'] ?? ['action_script_forbidden'],
                'http' => (int) ($authz['http'] ?? 403),
            ];
        }

        /** @var SaPackageRelease $release */
        $release = $authz['release'];
        $manifest = is_array($release->manifest) ? $release->manifest : [];
        $manifestAssets = is_array($manifest['assets'] ?? null) ? $manifest['assets'] : [];

        return $this->assets->loadVerified(
            (int) $release->id,
            $assetPath,
            $expectedSha256,
            $manifestAssets,
        );
    }

    /**
     * Resolve exact published release for a Campaign Job pin (no latest fallback).
     *
     * @return array{
     *   package_key: string,
     *   package_id: int,
     *   release_id: int,
     *   version: string,
     *   content_digest: string|null,
     *   execution_digest: string|null
     * }|null
     */
    public function resolvePublishedPin(string $packageKey, string $version, ?string $contentDigest = null): ?array
    {
        $packageKey = trim($packageKey);
        $version = trim($version);
        if ($packageKey === '' || $version === '') {
            return null;
        }

        $package = SaPackage::query()->where('package_key', $packageKey)->first();
        if (! $package instanceof SaPackage) {
            return null;
        }

        $q = SaPackageRelease::query()
            ->where('package_id', $package->id)
            ->where('version', $version)
            ->where('status', 'published');
        if ($contentDigest !== null && trim($contentDigest) !== '') {
            $q->where('content_digest', trim($contentDigest));
        }
        $release = $q->orderByDesc('published_at')->orderByDesc('id')->first();
        if (! $release instanceof SaPackageRelease) {
            return null;
        }

        return [
            'package_key' => (string) $package->package_key,
            'package_id' => (int) $package->id,
            'release_id' => (int) $release->id,
            'version' => (string) $release->version,
            'content_digest' => $release->content_digest !== null ? (string) $release->content_digest : null,
            'execution_digest' => $release->execution_digest !== null ? (string) $release->execution_digest : null,
        ];
    }

    /**
     * @return array{
     *   ok: true,
     *   job: SaJob,
     *   attempt: SaJobAttempt,
     *   package: SaPackage,
     *   release: SaPackageRelease
     * }|array{ok: false, errors: list<string>, http?: int}
     */
    private function authorizeJobPin(
        string $installationId,
        string $jobUuid,
        string $attemptUuid,
        string $leaseToken,
        string $accountUuid,
        string $packageKey,
        string $version,
        int $releaseId,
    ): array {
        if (! SocialAutomationSchema::jobsReady()) {
            return ['ok' => false, 'errors' => ['schema_unavailable'], 'http' => 503];
        }

        $jobUuid = trim($jobUuid);
        $attemptUuid = trim($attemptUuid);
        $leaseToken = trim($leaseToken);
        $accountUuid = trim($accountUuid);
        $packageKey = trim($packageKey);
        $version = trim($version);

        if ($jobUuid === '' || $attemptUuid === '' || $leaseToken === '' || $accountUuid === ''
            || $packageKey === '' || $version === '' || $releaseId < 1) {
            return ['ok' => false, 'errors' => ['action_script_request_invalid'], 'http' => 422];
        }

        $job = SaJob::query()->where('job_uuid', $jobUuid)->first();
        if (! $job instanceof SaJob) {
            return ['ok' => false, 'errors' => ['job_not_found_or_forbidden'], 'http' => 404];
        }

        $payload = is_array($job->payload) ? $job->payload : [];
        $jobInstallation = trim((string) ($payload['installation_id'] ?? ''));
        if ($jobInstallation === '') {
            // Campaign jobs: resolve via account installation.
            $account = $job->account_id ? SaAccount::query()->find($job->account_id) : null;
            $jobInstallation = $account instanceof SaAccount ? (string) $account->installation_id : '';
        }
        if ($jobInstallation === '' || $jobInstallation !== $installationId) {
            return ['ok' => false, 'errors' => ['installation_forbidden'], 'http' => 403];
        }

        $fenceUuid = is_string($payload['_fence']['attempt_uuid'] ?? null)
            ? (string) $payload['_fence']['attempt_uuid']
            : null;
        if ($fenceUuid === null || $fenceUuid === '') {
            $latest = SaJobAttempt::query()->where('job_id', $job->id)->orderByDesc('attempt_no')->first();
            $fenceUuid = $latest instanceof SaJobAttempt ? (string) $latest->attempt_uuid : null;
        }
        $attemptCheck = $this->fence->validate($attemptUuid, $fenceUuid);
        if (! ($attemptCheck['ok'] ?? false)) {
            return ['ok' => false, 'errors' => ['stale_attempt'], 'http' => 409];
        }

        $activeToken = trim((string) ($job->lease_token ?? ''));
        if ($activeToken === '' || ! hash_equals($activeToken, $leaseToken)) {
            return ['ok' => false, 'errors' => ['stale_lease_token'], 'http' => 409];
        }

        $expiresAt = $job->lease_expires_at;
        if ($expiresAt !== null && $expiresAt->isPast()) {
            return ['ok' => false, 'errors' => ['lease_expired'], 'http' => 409];
        }

        $attempt = SaJobAttempt::query()
            ->where('job_id', $job->id)
            ->where('attempt_uuid', $attemptUuid)
            ->first();
        if (! $attempt instanceof SaJobAttempt) {
            return ['ok' => false, 'errors' => ['stale_attempt'], 'http' => 409];
        }

        $account = SaAccount::query()->where('account_uuid', $accountUuid)->first();
        if (! $account instanceof SaAccount) {
            return ['ok' => false, 'errors' => ['account_not_found'], 'http' => 404];
        }
        if ((string) $account->installation_id !== $installationId) {
            return ['ok' => false, 'errors' => ['installation_forbidden'], 'http' => 403];
        }
        if ((int) $job->account_id !== (int) $account->id) {
            return ['ok' => false, 'errors' => ['account_binding_mismatch'], 'http' => 403];
        }

        $pin = $this->jobScriptPin($job);
        if ($pin === null) {
            return ['ok' => false, 'errors' => ['job_script_pin_missing'], 'http' => 422];
        }
        if ((string) $pin['package_key'] !== $packageKey
            || (string) $pin['version'] !== $version
            || (int) $pin['release_id'] !== $releaseId) {
            return ['ok' => false, 'errors' => ['release_not_pinned_to_job'], 'http' => 403];
        }

        $package = SaPackage::query()->where('package_key', $packageKey)->first();
        $release = SaPackageRelease::query()->find($releaseId);
        if (! $package instanceof SaPackage || ! $release instanceof SaPackageRelease) {
            return ['ok' => false, 'errors' => ['release_not_found'], 'http' => 404];
        }
        if ((int) $release->package_id !== (int) $package->id) {
            return ['ok' => false, 'errors' => ['release_not_pinned_to_job'], 'http' => 403];
        }
        if ((string) $release->version !== $version) {
            return ['ok' => false, 'errors' => ['release_not_pinned_to_job'], 'http' => 403];
        }
        if ((string) $release->status !== 'published') {
            return ['ok' => false, 'errors' => ['release_unpublished'], 'http' => 403];
        }
        if ($pin['content_digest'] !== null && $release->content_digest !== null
            && ! PackageDigest::equals((string) $pin['content_digest'], (string) $release->content_digest)) {
            return ['ok' => false, 'errors' => ['content_digest_mismatch'], 'http' => 403];
        }

        return [
            'ok' => true,
            'job' => $job,
            'attempt' => $attempt,
            'package' => $package,
            'release' => $release,
        ];
    }

    /**
     * @return array{
     *   package_key: string,
     *   package_id: int|null,
     *   release_id: int,
     *   version: string,
     *   content_digest: string|null,
     *   execution_digest: string|null
     * }|null
     */
    public function jobScriptPin(SaJob $job): ?array
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $pin = is_array($payload['script_pin'] ?? null) ? $payload['script_pin'] : null;
        if (is_array($pin)) {
            $packageKey = trim((string) ($pin['package_key'] ?? ''));
            $version = trim((string) ($pin['version'] ?? ''));
            $releaseId = (int) ($pin['release_id'] ?? 0);
            if ($packageKey !== '' && $version !== '' && $releaseId > 0) {
                return [
                    'package_key' => $packageKey,
                    'package_id' => isset($pin['package_id']) ? (int) $pin['package_id'] : null,
                    'release_id' => $releaseId,
                    'version' => $version,
                    'content_digest' => isset($pin['content_digest']) && $pin['content_digest'] !== null
                        ? (string) $pin['content_digest'] : null,
                    'execution_digest' => isset($pin['execution_digest']) && $pin['execution_digest'] !== null
                        ? (string) $pin['execution_digest'] : null,
                ];
            }
        }

        // Fallback for jobs reserved before script_pin payload existed.
        $resolved = $this->resolvePublishedPin(
            (string) $job->package_key,
            (string) $job->package_version,
            $job->content_digest !== null ? (string) $job->content_digest : null,
        );

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    private function clientReleasePayload(
        SaPackage $package,
        SaPackageRelease $release,
        bool $includeManifest,
    ): array {
        $manifest = is_array($release->manifest) ? $release->manifest : [];
        $assets = is_array($manifest['assets'] ?? null) ? $manifest['assets'] : [];
        $assetsComplete = true;
        foreach ($assets as $asset) {
            if (! is_array($asset) || trim((string) ($asset['path'] ?? '')) === ''
                || ! preg_match('/^[a-f0-9]{64}$/', strtolower((string) ($asset['sha256'] ?? '')))) {
                $assetsComplete = false;
                break;
            }
        }

        $payload = [
            'package_key' => (string) $package->package_key,
            'package_id' => (int) $package->id,
            'kind' => (string) $package->kind,
            'is_authentication' => (bool) ($package->is_authentication ?? false),
            'version' => (string) $release->version,
            'release_id' => (int) $release->id,
            // content_digest = package content integrity; distinct from execution_digest.
            'content_digest' => (string) $release->content_digest,
            'execution_digest' => $release->execution_digest !== null ? (string) $release->execution_digest : null,
            'schema_version' => (int) $release->schema_version,
            'status' => (string) $release->status,
            'runtime_compatibility' => $release->runtime_compatibility,
            'assets_complete' => $assetsComplete,
            'ready_to_execute' => $assetsComplete && (string) $release->status === 'published',
            'assets' => array_values(array_filter(array_map(static function ($asset): ?array {
                if (! is_array($asset)) {
                    return null;
                }
                $path = trim((string) ($asset['path'] ?? ''));
                $sha = strtolower(trim((string) ($asset['sha256'] ?? '')));
                if ($path === '' || $sha === '') {
                    return null;
                }

                return [
                    'path' => $path,
                    'sha256' => $sha,
                    'kind' => isset($asset['kind']) ? (string) $asset['kind'] : null,
                ];
            }, $assets))),
        ];

        if ($includeManifest) {
            $payload['manifest'] = $manifest;
        }

        return $payload;
    }
}
