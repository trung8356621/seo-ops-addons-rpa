<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Domain\AccountTagEntitlementPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\AuthScriptPinPolicy;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaAccountActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTagScript;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Support\ScriptReleaseAssetReader;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Staff + Client Auth Script pin / resolve / pull (CP8).
 * Pins live on sa_action_tags.auth_* — never sa_action_tag_scripts.
 * Supports CMS Global Auth Script fallback when Site-specific Auth pin is absent.
 */
final class AuthScriptService
{
    public function __construct(
        private readonly AuthScriptPinPolicy $pinPolicy = new AuthScriptPinPolicy,
        private readonly AccountTagEntitlementPolicy $entitlement = new AccountTagEntitlementPolicy,
        private readonly ScriptReleaseAssetReader $assets = new ScriptReleaseAssetReader,
        private readonly ?ScriptResolutionService $scriptResolution = null,
    ) {}

    public function schemaReady(): bool
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;
        try {
            return Schema::connection($conn)->hasTable('sa_action_tags')
                && Schema::connection($conn)->hasColumn('sa_action_tags', 'auth_script_id')
                && Schema::connection($conn)->hasColumn('sa_action_tags', 'auth_release_id')
                && Schema::connection($conn)->hasTable('sa_packages')
                && Schema::connection($conn)->hasColumn('sa_packages', 'is_authentication')
                && Schema::connection($conn)->hasTable('sa_account_action_tags');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAuthenticationCatalog(): array
    {
        if (! $this->schemaReady()) {
            return [];
        }

        $packageIds = $this->authenticationPackageIds();
        if ($packageIds === []) {
            return [];
        }

        return SaPackage::query()
            ->where('kind', 'script')
            ->whereIn('id', $packageIds)
            ->orderBy('package_key')
            ->get()
            ->map(function (SaPackage $package): array {
                $releases = SaPackageRelease::query()
                    ->where('package_id', $package->id)
                    ->where('status', 'published')
                    ->orderByDesc('published_at')
                    ->orderByDesc('id')
                    ->get()
                    ->map(static fn (SaPackageRelease $r): array => [
                        'id' => (int) $r->id,
                        'version' => (string) $r->version,
                        'content_digest' => (string) $r->content_digest,
                        'status' => (string) $r->status,
                        'published_at' => $r->published_at?->toIso8601String(),
                    ])
                    ->all();

                return [
                    'package_id' => (int) $package->id,
                    'package_key' => (string) $package->package_key,
                    'name' => (string) $package->name,
                    'description' => $package->description,
                    'is_enabled' => (bool) ($package->is_enabled ?? true),
                    'is_authentication' => true,
                    'releases' => $releases,
                ];
            })
            ->all();
    }

    /**
     * Package ids eligible for Auth Script pin: explicit flag OR linked to an Authentication Path.
     *
     * @return list<int>
     */
    public function authenticationPackageIds(): array
    {
        if (! $this->schemaReady()) {
            return [];
        }

        $ids = SaPackage::query()
            ->where('kind', 'script')
            ->where('is_authentication', true)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $conn = SocialAutomationServiceConfig::CONNECTION;
        try {
            if (! Schema::connection($conn)->hasColumn('sa_paths', 'is_authentication')
                || ! Schema::connection($conn)->hasTable('sa_action_tag_scripts')) {
                return array_values(array_unique($ids));
            }
        } catch (\Throwable) {
            return array_values(array_unique($ids));
        }

        $authPathIds = SaPath::query()
            ->where('is_authentication', true)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        if ($authPathIds === []) {
            return array_values(array_unique($ids));
        }

        $tagIds = SaActionTag::query()
            ->whereIn('path_id', $authPathIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        if ($tagIds === []) {
            return array_values(array_unique($ids));
        }

        $linked = SaActionTagScript::query()
            ->whereIn('action_tag_id', $tagIds)
            ->whereNotNull('package_id')
            ->pluck('package_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return array_values(array_unique([...$ids, ...$linked]));
    }

    /**
     * Persist package.is_authentication for scripts linked to the given path (denormalized cache).
     *
     * @return int Number of packages updated
     */
    public function syncPackageAuthFlagsForPath(SaPath $path): int
    {
        if (! $this->schemaReady()) {
            return 0;
        }

        $conn = SocialAutomationServiceConfig::CONNECTION;
        if (! Schema::connection($conn)->hasTable('sa_action_tag_scripts')) {
            return 0;
        }

        $tagIds = SaActionTag::query()
            ->where('path_id', $path->id)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        if ($tagIds === []) {
            return 0;
        }

        $packageIds = SaActionTagScript::query()
            ->whereIn('action_tag_id', $tagIds)
            ->whereNotNull('package_id')
            ->pluck('package_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        if ($packageIds === []) {
            return 0;
        }

        $wantAuth = (bool) ($path->is_authentication ?? false);
        $updated = 0;
        foreach (SaPackage::query()->whereIn('id', $packageIds)->where('kind', 'script')->get() as $package) {
            if ((bool) ($package->is_authentication ?? false) === $wantAuth) {
                continue;
            }
            $package->is_authentication = $wantAuth;
            $package->save();
            $updated++;
        }

        return $updated;
    }

    /**
     * @return array{ok: true, tag: SaActionTag}|array{ok: false, errors: list<string>}
     */
    public function setAuthPin(SaActionTag $tag, ?int $packageId, ?int $releaseId): array
    {
        if (! $this->schemaReady()) {
            return ['ok' => false, 'errors' => ['auth_schema_unavailable']];
        }

        if ($packageId === null && $releaseId === null) {
            $tag->auth_script_id = null;
            $tag->auth_release_id = null;
            $tag->save();

            return ['ok' => true, 'tag' => $tag->fresh() ?? $tag];
        }

        if ($packageId === null || $releaseId === null) {
            return ['ok' => false, 'errors' => ['auth_pin_incomplete']];
        }

        $package = SaPackage::query()->find($packageId);
        $release = SaPackageRelease::query()->find($releaseId);
        $check = $this->pinPolicy->assertPinTarget(
            $package instanceof SaPackage ? $this->packageRow($package) : null,
            $release instanceof SaPackageRelease ? $this->releaseRow($release) : null,
        );
        if (! ($check['ok'] ?? false)) {
            return ['ok' => false, 'errors' => $check['errors'] ?? ['auth_pin_invalid']];
        }

        $tag->auth_script_id = (int) $packageId;
        $tag->auth_release_id = (int) $releaseId;
        $tag->save();

        return ['ok' => true, 'tag' => $tag->fresh() ?? $tag];
    }

    /**
     * @return array{ok: true, binding: SaAccountActionTag}|array{ok: false, errors: list<string>}
     */
    public function grant(int $accountId, int $actionTagId, ?int $grantedByUserId = null): array
    {
        if (! $this->schemaReady()) {
            return ['ok' => false, 'errors' => ['auth_schema_unavailable']];
        }
        if (! SaAccount::query()->whereKey($accountId)->exists()) {
            return ['ok' => false, 'errors' => ['account_not_found']];
        }
        if (! SaActionTag::query()->whereKey($actionTagId)->exists()) {
            return ['ok' => false, 'errors' => ['action_tag_not_found']];
        }

        $binding = SaAccountActionTag::query()->firstOrCreate(
            ['account_id' => $accountId, 'action_tag_id' => $actionTagId],
            [
                'granted_by_user_id' => $grantedByUserId,
                'granted_at' => now(),
            ],
        );

        return ['ok' => true, 'binding' => $binding];
    }

    public function revoke(int $accountId, int $actionTagId): bool
    {
        if (! $this->schemaReady()) {
            return false;
        }

        return SaAccountActionTag::query()
            ->where('account_id', $accountId)
            ->where('action_tag_id', $actionTagId)
            ->delete() > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listGrantsForTag(int $actionTagId): array
    {
        if (! $this->schemaReady()) {
            return [];
        }

        return SaAccountActionTag::query()
            ->where('action_tag_id', $actionTagId)
            ->orderByDesc('id')
            ->get()
            ->map(static function (SaAccountActionTag $b): array {
                return [
                    'account_id' => (int) $b->account_id,
                    'action_tag_id' => (int) $b->action_tag_id,
                    'granted_by_user_id' => $b->granted_by_user_id !== null ? (int) $b->granted_by_user_id : null,
                    'granted_at' => $b->granted_at?->toIso8601String(),
                ];
            })
            ->all();
    }

    public function isEntitled(int $accountId, int $actionTagId): bool
    {
        if (! $this->schemaReady()) {
            return false;
        }

        $exists = SaAccountActionTag::query()
            ->where('account_id', $accountId)
            ->where('action_tag_id', $actionTagId)
            ->exists();

        return $this->entitlement->isAuthorized($exists);
    }

    /**
     * Discover Auth Action Tags eligible for an Account by Site (0 / 1 / N).
     * Site = Account.platform matched to Tag site_key/platform.
     * Authentication Script does NOT require sa_account_action_tags (that entitlement
     * remains for ordinary Action Scripts: Post/Comment/Collect).
     * Requires Authentication Path + valid published Auth pin.
     * Does not invent tags from account name or "{platform}.login".
     *
     * @return array{
     *   match: 'none'|'unique'|'ambiguous',
     *   count: int,
     *   action_tag_id?: int,
     *   action_tag_key?: string,
     *   candidates?: list<array{action_tag_id: int, action_tag_key: string}>
     * }
     */
    public function findEligibleAuthContextForAccount(SaAccount $account): array
    {
        $accountUuid = (string) ($account->account_uuid ?? '');
        if (! $this->schemaReady()) {
            return [
                'match' => 'none',
                'count' => 0,
                'source' => ScriptResolutionService::SOURCE_MISSING,
                'reason' => 'auth_schema_unavailable',
                'account_id' => $accountUuid,
                'site_id' => null,
                'site_key' => null,
                'auth_path' => null,
                'cms_scope' => null,
                'script' => null,
                'execution_context' => null,
            ];
        }

        $accountPlatform = strtolower(trim((string) $account->platform));
        if ($accountPlatform === '') {
            return [
                'match' => 'none',
                'count' => 0,
                'source' => ScriptResolutionService::SOURCE_MISSING,
                'reason' => 'account_platform_missing',
                'account_id' => $accountUuid,
                'site_id' => null,
                'site_key' => null,
                'auth_path' => null,
                'cms_scope' => null,
                'script' => null,
                'execution_context' => null,
            ];
        }

        $site = $this->resolveSiteForPlatform($accountPlatform);
        $eligible = [];
        $failureReasons = [];
        $firstAuthPath = null;

        foreach ($this->candidateAuthTagsForSite($accountPlatform) as $tag) {
            $resolved = $this->assertAuthTagEligibleForAccount($account, $tag);
            if (! ($resolved['ok'] ?? false)) {
                $err = (string) (($resolved['errors'][0] ?? null) ?: 'script_not_found');
                if ($err !== 'auth_path_required') {
                    $failureReasons[] = $err;
                    if ($firstAuthPath === null && ($resolved['path'] ?? null) instanceof SaPath) {
                        $firstAuthPath = $resolved['path'];
                    }
                }
                continue;
            }

            $eligible[] = $resolved;
        }

        $count = count($eligible);
        if ($count === 0) {
            $reason = $failureReasons[0] ?? 'no_auth_path_for_site';

            return [
                'match' => 'none',
                'count' => 0,
                'source' => ScriptResolutionService::SOURCE_MISSING,
                'reason' => $reason,
                'account_id' => $accountUuid,
                'site_id' => $site instanceof SaSite ? (int) $site->id : null,
                'site_key' => $site instanceof SaSite ? (string) $site->key : $accountPlatform,
                'auth_path' => $firstAuthPath instanceof SaPath ? $this->pathSummary($firstAuthPath) : null,
                'cms_scope' => null,
                'script' => null,
                'execution_context' => null,
            ];
        }

        if ($count === 1) {
            $item = $eligible[0];
            /** @var SaActionTag $tag */
            $tag = $item['tag'];
            /** @var SaPackage $package */
            $package = $item['package'];
            /** @var SaPackageRelease $release */
            $release = $item['release'];
            /** @var SaPath|null $path */
            $path = $item['path'] ?? null;
            /** @var SaSite|null $resolvedSite */
            $resolvedSite = $item['site'] ?? $site;

            return [
                'match' => 'unique',
                'count' => 1,
                'action_tag_id' => (int) $tag->id,
                'action_tag_key' => (string) $tag->tag_key,
                'source' => (string) $item['source'],
                'reason' => null,
                'account_id' => $accountUuid,
                'site_id' => $resolvedSite instanceof SaSite ? (int) $resolvedSite->id : null,
                'site_key' => $resolvedSite instanceof SaSite ? (string) $resolvedSite->key : $accountPlatform,
                'auth_path' => $path instanceof SaPath ? $this->pathSummary($path) : null,
                'cms_scope' => $item['cms_scope'] ?? null,
                'script' => [
                    'package_key' => (string) $package->package_key,
                    'package_id' => (int) $package->id,
                    'script_id' => (string) $package->package_key,
                    'release_id' => (int) $release->id,
                    'version' => (string) $release->version,
                    'content_digest' => $release->content_digest !== null ? (string) $release->content_digest : null,
                    'source_action_tag_key' => $item['source_action_tag_key'] ?? (string) $tag->tag_key,
                ],
                'execution_context' => $this->buildExecutionContext($resolvedSite, $path, $account),
            ];
        }

        return [
            'match' => 'ambiguous',
            'count' => $count,
            'source' => ScriptResolutionService::SOURCE_MISSING,
            'reason' => 'ambiguous_auth_tags',
            'account_id' => $accountUuid,
            'site_id' => $site instanceof SaSite ? (int) $site->id : null,
            'site_key' => $site instanceof SaSite ? (string) $site->key : $accountPlatform,
            'auth_path' => null,
            'cms_scope' => null,
            'script' => null,
            'execution_context' => null,
            'candidates' => array_map(static fn (array $item): array => [
                'action_tag_id' => (int) $item['tag']->id,
                'action_tag_key' => (string) $item['tag']->tag_key,
                'source' => (string) $item['source'],
            ], $eligible),
        ];
    }

    /**
     * Resolve explicit Tag → pinned or CMS Global Auth release for an Account on the same Site.
     * Eligibility aligns with findEligibleAuthContextForAccount (Site + Auth Path + Site-specific pin or CMS Global).
     * Does not require sa_account_action_tags for Authentication Scripts.
     *
     * @return array{ok: true, release: array<string, mixed>}|array{ok: false, errors: list<string>, http?: int}
     */
    public function resolveForClient(SaAccount $account, SaActionTag $tag, bool $includeManifest = false): array
    {
        if (! $this->schemaReady()) {
            return ['ok' => false, 'errors' => ['auth_schema_unavailable'], 'http' => 503];
        }

        $resolved = $this->assertAuthTagEligibleForAccount($account, $tag);
        if (! ($resolved['ok'] ?? false)) {
            return [
                'ok' => false,
                'errors' => $resolved['errors'] ?? ['auth_pin_invalid'],
                'http' => (int) ($resolved['http'] ?? 404),
            ];
        }

        /** @var SaPackage $package */
        $package = $resolved['package'];
        /** @var SaPackageRelease $release */
        $release = $resolved['release'];

        return [
            'ok' => true,
            'release' => $this->clientReleasePayload(
                $package,
                $release,
                $tag,
                $includeManifest,
                (string) $resolved['source'],
                $resolved['source_action_tag_key'] ?? (string) $tag->tag_key,
                $resolved['cms_scope'] ?? null,
                $resolved['site'] ?? null,
                $resolved['path'] ?? null,
            ),
        ];
    }

    /**
     * Download one ROI/asset binary from the Account's exact pinned published Auth release.
     * Same Site/Auth-Path/pin eligibility as context/resolve/pull (no sa_account_action_tags).
     * Fail-closed on path traversal, undeclared asset, missing bytes, or SHA-256 mismatch.
     * Never returns filesystem paths, secrets, or unrelated package assets.
     *
     * @return array{
     *   ok: true,
     *   asset_path: string,
     *   sha256: string,
     *   mime: string,
     *   bytes: string
     * }|array{ok: false, errors: list<string>, http?: int}
     */
    public function downloadAssetForClient(
        SaAccount $account,
        string $packageKey,
        string $version,
        int $releaseId,
        string $assetPath,
        string $expectedSha256,
    ): array {
        if (! $this->schemaReady()) {
            return ['ok' => false, 'errors' => ['auth_schema_unavailable'], 'http' => 503];
        }

        $packageKey = trim($packageKey);
        $version = trim($version);
        $assetPath = trim($assetPath);
        $expectedSha256 = strtolower(trim($expectedSha256));

        if ($packageKey === '' || $version === '' || $releaseId < 1 || $assetPath === '' || $expectedSha256 === '') {
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
                return ['ok' => false, 'errors' => ['auth_schema_unavailable'], 'http' => 503];
            }
        } catch (\Throwable) {
            return ['ok' => false, 'errors' => ['auth_schema_unavailable'], 'http' => 503];
        }

        $matched = $this->findEligiblePinnedAuthRelease($account, $packageKey, $version, $releaseId);
        if (! ($matched['ok'] ?? false)) {
            return [
                'ok' => false,
                'errors' => $matched['errors'] ?? ['auth_release_not_found'],
                'http' => (int) ($matched['http'] ?? 404),
            ];
        }

        /** @var SaPackageRelease $release */
        $release = $matched['release'];
        $manifest = is_array($release->manifest) ? $release->manifest : [];
        $manifestAssets = is_array($manifest['assets'] ?? null) ? $manifest['assets'] : [];

        // Shared storage/hash verification (also used by Action Script distribution).
        return $this->assets->loadVerified(
            (int) $release->id,
            $assetPath,
            $expectedSha256,
            $manifestAssets,
        );
    }

    /**
     * @return array{ok: true, tag: SaActionTag, package: SaPackage, release: SaPackageRelease}|array{ok: false, errors: list<string>, http?: int}
     */
    private function findEligiblePinnedAuthRelease(
        SaAccount $account,
        string $packageKey,
        string $version,
        int $releaseId,
    ): array {
        $accountPlatform = strtolower(trim((string) $account->platform));
        if ($accountPlatform === '') {
            return ['ok' => false, 'errors' => ['auth_release_not_found'], 'http' => 404];
        }

        foreach ($this->candidateAuthTagsForSite($accountPlatform) as $tag) {
            $resolved = $this->assertAuthTagEligibleForAccount($account, $tag);
            if (! ($resolved['ok'] ?? false)) {
                continue;
            }
            /** @var SaPackage $package */
            $package = $resolved['package'];
            /** @var SaPackageRelease $release */
            $release = $resolved['release'];

            if ((int) $release->id !== $releaseId) {
                continue;
            }
            if ((string) $package->package_key !== $packageKey) {
                continue;
            }
            if ((string) $release->version !== $version) {
                continue;
            }
            if ((int) $release->package_id !== (int) $package->id) {
                continue;
            }
            if ((string) $release->status !== 'published') {
                continue;
            }

            return [
                'ok' => true,
                'tag' => $tag,
                'package' => $package,
                'release' => $release,
            ];
        }

        return ['ok' => false, 'errors' => ['auth_release_not_found'], 'http' => 404];
    }

    private function isSafeAssetPath(string $assetPath): bool
    {
        return $this->assets->isSafeAssetPath($assetPath);
    }

    /**
     * @return array<string, mixed>
     */
    private function clientReleasePayload(
        SaPackage $package,
        SaPackageRelease $release,
        SaActionTag $tag,
        bool $includeManifest,
        string $source = ScriptResolutionService::SOURCE_SITE_SPECIFIC,
        ?string $sourceActionTagKey = null,
        ?array $cmsScope = null,
        ?SaSite $site = null,
        ?SaPath $path = null,
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
            'action_tag_id' => (int) $tag->id,
            'action_tag_key' => (string) $tag->tag_key,
            'source' => $source,
            'source_action_tag_key' => $sourceActionTagKey ?? (string) $tag->tag_key,
            'cms_scope' => $cmsScope,
            'target_site_key' => $site?->key ?? ($tag->site_key ?: $tag->platform),
            'target_path' => $path instanceof SaPath ? $this->pathSummary($path) : null,
            'package_key' => (string) $package->package_key,
            'package_id' => (int) $package->id,
            'kind' => (string) $package->kind,
            'is_authentication' => true,
            'version' => (string) $release->version,
            'release_id' => (int) $release->id,
            'content_digest' => (string) $release->content_digest,
            'schema_version' => (int) $release->schema_version,
            'status' => (string) $release->status,
            'runtime_compatibility' => $release->runtime_compatibility,
            'assets_complete' => $assetsComplete,
            'ready_to_execute' => $assetsComplete,
        ];

        if ($includeManifest) {
            $payload['manifest'] = $manifest;
        }

        return $payload;
    }

    private function platformsMatch(string $accountPlatform, SaActionTag $tag): bool
    {
        $tagSite = strtolower(trim((string) ($tag->site_key ?? $tag->platform ?? '')));

        return $accountPlatform !== '' && $tagSite !== '' && $tagSite === $accountPlatform;
    }

    /**
     * Candidate Action Tags for a Site (includes both pinned and unpinned tags so CMS Global Auth can resolve).
     *
     * @return \Illuminate\Support\Collection<int, SaActionTag>
     */
    private function candidateAuthTagsForSite(string $accountPlatform)
    {
        return SaActionTag::query()
            ->where(function ($query) use ($accountPlatform): void {
                $query->whereRaw('LOWER(site_key) = ?', [$accountPlatform])
                    ->orWhereRaw('LOWER(platform) = ?', [$accountPlatform]);
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * Authoritative Auth binding resolver for an Account + Action Tag:
     * 1. Site match + enabled Action Tag + Authentication Path
     * 2. Prefer explicit Site-specific Auth pin (auth_script_id / auth_release_id)
     * 3. Otherwise resolve CMS Global Authentication Script via ScriptResolutionService
     *
     * @return array{
     *   ok: true,
     *   source: string,
     *   tag: SaActionTag,
     *   package: SaPackage,
     *   release: SaPackageRelease,
     *   path: SaPath|null,
     *   site: SaSite|null,
     *   cms_scope: array<string, mixed>|null,
     *   source_action_tag_key: string
     * }|array{ok: false, errors: list<string>, http?: int, path?: SaPath|null}
     */
    private function assertAuthTagEligibleForAccount(SaAccount $account, SaActionTag $tag): array
    {
        $accountPlatform = strtolower(trim((string) $account->platform));
        if (! $this->platformsMatch($accountPlatform, $tag)) {
            return ['ok' => false, 'errors' => ['account_tag_platform_mismatch'], 'http' => 403];
        }

        if (! $tag->isEnabled()) {
            return ['ok' => false, 'errors' => ['action_tag_disabled'], 'http' => 404];
        }

        $path = $tag->path_id ? SaPath::query()->find((int) $tag->path_id) : null;
        if (! $this->tagPathIsAuthentication($tag)) {
            return ['ok' => false, 'errors' => ['auth_path_required'], 'http' => 404, 'path' => $path];
        }

        $site = $this->resolveSiteForTag($tag, $accountPlatform);

        // Priority 1: Explicit Site-specific Auth pin
        $pinnedPackageId = $tag->auth_script_id !== null ? (int) $tag->auth_script_id : null;
        $pinnedReleaseId = $tag->auth_release_id !== null ? (int) $tag->auth_release_id : null;
        if ($pinnedPackageId !== null && $pinnedReleaseId !== null) {
            $package = SaPackage::query()->find($pinnedPackageId);
            $release = SaPackageRelease::query()->find($pinnedReleaseId);
            $packageRow = $this->packageRowForAuthPin(
                $package instanceof SaPackage ? $package : null,
                $tag,
            );

            $check = $this->pinPolicy->assertPinnedAuthRelease(
                $packageRow,
                $release instanceof SaPackageRelease ? $this->releaseRow($release) : null,
                $pinnedPackageId,
                $pinnedReleaseId,
            );
            if (! ($check['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'errors' => $check['errors'] ?? ['auth_pin_invalid'],
                    'http' => 404,
                    'path' => $path,
                ];
            }

            /** @var SaPackage $package */
            /** @var SaPackageRelease $release */
            return [
                'ok' => true,
                'source' => ScriptResolutionService::SOURCE_SITE_SPECIFIC,
                'tag' => $tag,
                'package' => $package,
                'release' => $release,
                'path' => $path,
                'site' => $site,
                'cms_scope' => null,
                'source_action_tag_key' => (string) $tag->tag_key,
            ];
        }

        // Priority 2: CMS Global Authentication Script fallback
        $resolver = $this->scriptResolution ?? new ScriptResolutionService;
        $globalRes = $resolver->resolveForTag($tag);
        if (! ($globalRes['ok'] ?? false)
            || ($globalRes['source'] ?? '') !== ScriptResolutionService::SOURCE_CMS_GLOBAL
            || ! ($globalRes['package'] instanceof SaPackage)
            || ! ($globalRes['release'] instanceof SaPackageRelease)) {
            $reason = (string) ($globalRes['reason'] ?? 'auth_pin_missing');

            return [
                'ok' => false,
                'errors' => [$reason],
                'http' => 404,
                'path' => $path,
            ];
        }

        /** @var SaPackage $globalPackage */
        $globalPackage = $globalRes['package'];
        /** @var SaPackageRelease $globalRelease */
        $globalRelease = $globalRes['release'];

        // Verify via AuthScriptPinPolicy as well (ensures kind=script, enabled, published, auth-compatible)
        $globalPackageRow = $this->packageRow($globalPackage);
        $globalPackageRow['is_authentication'] = true; // verified by ScriptResolutionService::effectiveIsAuthentication
        $pinCheck = $this->pinPolicy->assertPinnedAuthRelease(
            $globalPackageRow,
            $this->releaseRow($globalRelease),
            (int) $globalPackage->id,
            (int) $globalRelease->id,
        );
        if (! ($pinCheck['ok'] ?? false)) {
            return [
                'ok' => false,
                'errors' => $pinCheck['errors'] ?? ['auth_pin_invalid'],
                'http' => 404,
                'path' => $path,
            ];
        }

        return [
            'ok' => true,
            'source' => ScriptResolutionService::SOURCE_CMS_GLOBAL,
            'tag' => $tag,
            'package' => $globalPackage,
            'release' => $globalRelease,
            'path' => $path,
            'site' => $site,
            'cms_scope' => $globalRes['cms_scope'] ?? null,
            'source_action_tag_key' => (string) ($globalRes['source_action_tag_key'] ?? $tag->tag_key),
        ];
    }

    private function resolveSiteForPlatform(string $platform): ?SaSite
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;
        if (! Schema::connection($conn)->hasTable('sa_sites')) {
            return null;
        }

        $site = SaSite::query()->where('key', strtolower(trim($platform)))->first();

        return $site instanceof SaSite ? $site : null;
    }

    /**
     * Read-only Auth resolution preview for Staff Action Registry UI (no Account required).
     *
     * @return array{auth_resolution_source: 'site_specific'|'cms_global'|'missing'|null, effective_auth_script: array<string, mixed>|null}
     */
    public function resolveAuthPreviewForTag(SaActionTag $tag): array
    {
        if (! $this->tagPathIsAuthentication($tag)) {
            return [
                'auth_resolution_source' => null,
                'effective_auth_script' => null,
            ];
        }

        if (! $this->schemaReady()) {
            return [
                'auth_resolution_source' => ScriptResolutionService::SOURCE_MISSING,
                'effective_auth_script' => null,
            ];
        }

        $siteKey = strtolower(trim((string) ($tag->site_key ?: $tag->platform)));
        $dummyAccount = new SaAccount;
        $dummyAccount->platform = $siteKey;

        $resolved = $this->assertAuthTagEligibleForAccount($dummyAccount, $tag);
        if (! ($resolved['ok'] ?? false)) {
            return [
                'auth_resolution_source' => ScriptResolutionService::SOURCE_MISSING,
                'effective_auth_script' => null,
            ];
        }

        /** @var SaPackage $package */
        $package = $resolved['package'];
        /** @var SaPackageRelease $release */
        $release = $resolved['release'];

        return [
            'auth_resolution_source' => (string) $resolved['source'],
            'effective_auth_script' => [
                'package_id' => (int) $package->id,
                'package_key' => (string) $package->package_key,
                'script_id' => (string) $package->package_key,
                'name' => (string) $package->name,
                'release_id' => (int) $release->id,
                'version' => (string) $release->version,
                'content_digest' => (string) $release->content_digest,
                'source_action_tag_key' => (string) ($resolved['source_action_tag_key'] ?? $tag->tag_key),
                'cms_scope' => $resolved['cms_scope'] ?? null,
            ],
        ];
    }

    private function resolveSiteForTag(SaActionTag $tag, string $fallbackPlatform): ?SaSite
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;
        if (! Schema::connection($conn)->hasTable('sa_sites')) {
            return null;
        }

        if ($tag->site_id) {
            $site = SaSite::query()->find((int) $tag->site_id);
            if ($site instanceof SaSite) {
                return $site;
            }
        }

        return $this->resolveSiteForPlatform((string) ($tag->site_key ?: $tag->platform ?: $fallbackPlatform));
    }

    /**
     * @return array{id: int, key: string, name: string, url_pattern: string, is_authentication: bool, cms_template_id: int|null}
     */
    private function pathSummary(SaPath $path): array
    {
        return [
            'id' => (int) $path->id,
            'key' => (string) $path->key,
            'name' => (string) $path->name,
            'url_pattern' => (string) $path->url_pattern,
            'is_authentication' => (bool) ($path->is_authentication ?? false),
            'cms_template_id' => $path->cms_template_id !== null ? (int) $path->cms_template_id : null,
        ];
    }

    /**
     * @return array{base_url: string|null, url_pattern: string|null, resolved_url: string|null, is_authentication: bool}|null
     */
    private function buildExecutionContext(?SaSite $site, ?SaPath $path, SaAccount $account): ?array
    {
        if (! $path instanceof SaPath) {
            return null;
        }

        $baseUrl = $site instanceof SaSite ? $site->canonicalBaseUrl() : null;
        if ($baseUrl === null && is_string($account->site_url) && trim($account->site_url) !== '') {
            $baseUrl = SaSite::normalizeBaseUrl($account->site_url);
        }
        $pattern = (string) $path->url_pattern;
        $resolvedUrl = $baseUrl !== null && $pattern !== ''
            ? rtrim($baseUrl, '/').'/'.ltrim($pattern, '/')
            : null;

        return [
            'base_url' => $baseUrl,
            'url_pattern' => $pattern !== '' ? $pattern : null,
            'resolved_url' => $resolvedUrl,
            'is_authentication' => (bool) ($path->is_authentication ?? false),
        ];
    }

    private function tagPathIsAuthentication(SaActionTag $tag): bool
    {
        if (! $tag->path_id) {
            return false;
        }

        $conn = SocialAutomationServiceConfig::CONNECTION;
        try {
            if (! Schema::connection($conn)->hasColumn('sa_paths', 'is_authentication')) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        $path = SaPath::query()->find((int) $tag->path_id);

        return $path instanceof SaPath && (bool) ($path->is_authentication ?? false);
    }

    /**
     * Build package row for Auth pin checks. Agreement: Path.is_authentication=true
     * counts as Auth-eligible even if denormalized package flag is still false.
     * Does not rewrite DB package flag.
     *
     * @return array{id: int, kind: string, is_authentication: bool, is_enabled: bool, package_key: string}|null
     */
    private function packageRowForAuthPin(?SaPackage $package, SaActionTag $tag): ?array
    {
        if (! $package instanceof SaPackage) {
            return null;
        }

        $row = $this->packageRow($package);
        if ($this->tagPathIsAuthentication($tag) && ! ($row['is_authentication'] ?? false)) {
            $row['is_authentication'] = true;
        }

        return $row;
    }

    /**
     * @return array{id: int, kind: string, is_authentication: bool, is_enabled: bool, package_key: string}
     */
    private function packageRow(SaPackage $package): array
    {
        $isAuth = (bool) ($package->is_authentication ?? false);
        if (! $isAuth) {
            $isAuth = in_array((int) $package->id, $this->authenticationPackageIds(), true);
        }

        return [
            'id' => (int) $package->id,
            'kind' => (string) $package->kind,
            'is_authentication' => $isAuth,
            'is_enabled' => (bool) ($package->is_enabled ?? true),
            'package_key' => (string) $package->package_key,
        ];
    }

    /**
     * @return array{id: int, package_id: int, version: string, status: string, content_digest: string}
     */
    private function releaseRow(SaPackageRelease $release): array
    {
        return [
            'id' => (int) $release->id,
            'package_id' => (int) $release->package_id,
            'version' => (string) $release->version,
            'status' => (string) $release->status,
            'content_digest' => (string) $release->content_digest,
        ];
    }
}
