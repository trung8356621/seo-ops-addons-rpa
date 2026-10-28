<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Domain\AccountTagEntitlementPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\AuthScriptPinPolicy;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaAccountActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
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

        return SaPackage::query()
            ->where('kind', 'script')
            ->where('is_authentication', true)
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
     * Resolve explicit Tag → pinned Auth release for an entitled Account.
     *
     * @return array{ok: true, release: array<string, mixed>}|array{ok: false, errors: list<string>, http?: int}
     */
    public function resolveForClient(SaAccount $account, SaActionTag $tag, bool $includeManifest = false): array
    {
        if (! $this->schemaReady()) {
            return ['ok' => false, 'errors' => ['auth_schema_unavailable'], 'http' => 503];
        }

        if (! $this->isEntitled((int) $account->id, (int) $tag->id)) {
            return ['ok' => false, 'errors' => ['account_tag_unauthorized'], 'http' => 403];
        }

        $pinnedPackageId = $tag->auth_script_id !== null ? (int) $tag->auth_script_id : null;
        $pinnedReleaseId = $tag->auth_release_id !== null ? (int) $tag->auth_release_id : null;
        if ($pinnedPackageId === null || $pinnedReleaseId === null) {
            return ['ok' => false, 'errors' => ['auth_pin_missing'], 'http' => 404];
        }

        $package = SaPackage::query()->find($pinnedPackageId);
        $release = SaPackageRelease::query()->find($pinnedReleaseId);
        $check = $this->pinPolicy->assertPinnedAuthRelease(
            $package instanceof SaPackage ? $this->packageRow($package) : null,
            $release instanceof SaPackageRelease ? $this->releaseRow($release) : null,
            $pinnedPackageId,
            $pinnedReleaseId,
        );
        if (! ($check['ok'] ?? false)) {
            return ['ok' => false, 'errors' => $check['errors'] ?? ['auth_pin_invalid'], 'http' => 404];
        }

        /** @var SaPackage $package */
        /** @var SaPackageRelease $release */
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

    /**
     * @return array{id: int, kind: string, is_authentication: bool, is_enabled: bool, package_key: string}
     */
    private function packageRow(SaPackage $package): array
    {
        return [
            'id' => (int) $package->id,
            'kind' => (string) $package->kind,
            'is_authentication' => (bool) ($package->is_authentication ?? false),
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
