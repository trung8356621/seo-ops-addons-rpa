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
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Staff + Client Auth Script pin / resolve / pull (CP8).
 * Pins live on sa_action_tags.auth_* — never sa_action_tag_scripts.
 */
final class AuthScriptService
{
    public function __construct(
        private readonly AuthScriptPinPolicy $pinPolicy = new AuthScriptPinPolicy,
        private readonly AccountTagEntitlementPolicy $entitlement = new AccountTagEntitlementPolicy,
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
        if (! $this->schemaReady()) {
            return ['match' => 'none', 'count' => 0];
        }

        $accountPlatform = strtolower(trim((string) $account->platform));
        if ($accountPlatform === '') {
            return ['match' => 'none', 'count' => 0];
        }

        $eligible = [];
        foreach ($this->candidateAuthTagsForSite($accountPlatform) as $tag) {
            $check = $this->assertAuthTagEligibleForAccount($account, $tag);
            if (! ($check['ok'] ?? false)) {
                continue;
            }

            $eligible[] = [
                'action_tag_id' => (int) $tag->id,
                'action_tag_key' => (string) $tag->tag_key,
            ];
        }

        $count = count($eligible);
        if ($count === 0) {
            return ['match' => 'none', 'count' => 0];
        }
        if ($count === 1) {
            return [
                'match' => 'unique',
                'count' => 1,
                'action_tag_id' => $eligible[0]['action_tag_id'],
                'action_tag_key' => $eligible[0]['action_tag_key'],
            ];
        }

        return [
            'match' => 'ambiguous',
            'count' => $count,
            'candidates' => $eligible,
        ];
    }

    /**
     * Resolve explicit Tag → pinned Auth release for an Account on the same Site.
     * Eligibility aligns with findEligibleAuthContextForAccount (Site + Auth Path + pin).
     * Does not require sa_account_action_tags for Authentication Scripts.
     *
     * @return array{ok: true, release: array<string, mixed>}|array{ok: false, errors: list<string>, http?: int}
     */
    public function resolveForClient(SaAccount $account, SaActionTag $tag, bool $includeManifest = false): array
    {
        if (! $this->schemaReady()) {
            return ['ok' => false, 'errors' => ['auth_schema_unavailable'], 'http' => 503];
        }

        $check = $this->assertAuthTagEligibleForAccount($account, $tag);
        if (! ($check['ok'] ?? false)) {
            return [
                'ok' => false,
                'errors' => $check['errors'] ?? ['auth_pin_invalid'],
                'http' => (int) ($check['http'] ?? 404),
            ];
        }

        $pinnedPackageId = (int) $tag->auth_script_id;
        $package = SaPackage::query()->find($pinnedPackageId);
        $release = SaPackageRelease::query()->find((int) $tag->auth_release_id);
        if (! $package instanceof SaPackage || ! $release instanceof SaPackageRelease) {
            return ['ok' => false, 'errors' => ['auth_pin_target_missing'], 'http' => 404];
        }

        return [
            'ok' => true,
            'release' => $this->clientReleasePayload($package, $release, $tag, $includeManifest),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function clientReleasePayload(
        SaPackage $package,
        SaPackageRelease $release,
        SaActionTag $tag,
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
            'action_tag_id' => (int) $tag->id,
            'action_tag_key' => (string) $tag->tag_key,
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
     * Candidate Auth-pinned tags for a Site. No account-grant filter.
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
            ->whereNotNull('auth_script_id')
            ->whereNotNull('auth_release_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * Shared Auth eligibility: Site match + Authentication Path + valid published pin.
     * Intentionally ignores sa_account_action_tags (Auth is Site-scoped).
     *
     * @return array{ok: true}|array{ok: false, errors: list<string>, http?: int}
     */
    private function assertAuthTagEligibleForAccount(SaAccount $account, SaActionTag $tag): array
    {
        $accountPlatform = strtolower(trim((string) $account->platform));
        if (! $this->platformsMatch($accountPlatform, $tag)) {
            return ['ok' => false, 'errors' => ['account_tag_platform_mismatch'], 'http' => 403];
        }

        if (! $this->tagPathIsAuthentication($tag)) {
            return ['ok' => false, 'errors' => ['auth_path_required'], 'http' => 404];
        }

        $pinnedPackageId = $tag->auth_script_id !== null ? (int) $tag->auth_script_id : null;
        $pinnedReleaseId = $tag->auth_release_id !== null ? (int) $tag->auth_release_id : null;
        if ($pinnedPackageId === null || $pinnedReleaseId === null) {
            return ['ok' => false, 'errors' => ['auth_pin_missing'], 'http' => 404];
        }

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
            ];
        }

        return ['ok' => true];
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
