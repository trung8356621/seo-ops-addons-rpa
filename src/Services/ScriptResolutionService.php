<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTagScript;
use Omnichannel\Addons\SocialAutomation\Models\SaCmsPathTemplate;
use Omnichannel\Addons\SocialAutomation\Models\SaCmsType;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptVariantGroup;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptVariantGroupItem;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Authoritative resolver for Site-specific vs CMS Global Published Scripts.
 *
 * Resolution Priority for a target Site + Path:
 * 1. Reject if target Action Tag is disabled.
 * 2. Prefer compatible Site-specific Published Script.
 * 3. Otherwise select compatible CMS Global Published Script (matching CMS Type + Path Template).
 * 4. Otherwise report Missing Script.
 */
final class ScriptResolutionService
{
    public const SOURCE_SITE_SPECIFIC = 'site_specific';

    public const SOURCE_CMS_GLOBAL = 'cms_global';

    public const SOURCE_MISSING = 'missing';

    public function sharingSchemaReady(): bool
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;

        return Schema::connection($conn)->hasTable('sa_packages')
            && Schema::connection($conn)->hasColumn('sa_packages', 'sharing_mode')
            && Schema::connection($conn)->hasColumn('sa_packages', 'global_scope_key');
    }

    public static function buildScopeKey(string $cmsType, string $pathKey): string
    {
        return strtolower(trim($cmsType)).':'.strtolower(trim($pathKey));
    }

    /**
     * Derive the CMS Global scope for a Published Script package.
     *
     * @return array{
     *   ok: true,
     *   scope: array{cms_type: string, cms_template_id: int, path_key: string, scope_key: string},
     *   source_tag_key: string
     * }|array{ok: false, error: string}
     */
    public function deriveGlobalScope(SaPackage $package): array
    {
        if ((string) $package->kind !== 'script') {
            return ['ok' => false, 'error' => 'not_a_script_package'];
        }
        if (isset($package->is_enabled) && ! (bool) $package->is_enabled) {
            return ['ok' => false, 'error' => 'script_package_disabled'];
        }

        $latestPublished = $this->findPublishedRelease($package, null);
        if (! $latestPublished instanceof SaPackageRelease) {
            return ['ok' => false, 'error' => 'no_published_release'];
        }

        $tag = $this->resolveSourceTagForPackage($package, $latestPublished);
        if (! $tag instanceof SaActionTag) {
            return ['ok' => false, 'error' => 'source_action_tag_not_found'];
        }

        $site = $this->resolveSiteForTag($tag);
        $path = $this->resolvePathForTag($tag, $site);
        if (! $site instanceof SaSite || ! $path instanceof SaPath) {
            return ['ok' => false, 'error' => 'source_site_or_path_not_found'];
        }

        $scope = $this->resolveCmsTemplateScope($site, $path);
        if (! ($scope['ok'] ?? false)) {
            return ['ok' => false, 'error' => (string) ($scope['error'] ?? 'no_cms_template_scope')];
        }

        /** @var array{cms_type: string, cms_template_id: int, path_key: string, scope_key: string, is_authentication: bool} $resolved */
        $resolved = $scope['scope'];

        if ($this->effectiveIsAuthentication($package, $latestPublished, $tag, $path) !== $resolved['is_authentication']) {
            return ['ok' => false, 'error' => 'authentication_flag_mismatch'];
        }

        return [
            'ok' => true,
            'scope' => [
                'cms_type' => $resolved['cms_type'],
                'cms_template_id' => $resolved['cms_template_id'],
                'path_key' => $resolved['path_key'],
                'scope_key' => $resolved['scope_key'],
            ],
            'source_tag_key' => (string) $tag->tag_key,
        ];
    }

    /**
     * Enable or disable CMS Global sharing on an existing Published Script.
     *
     * @return array{
     *   ok: true,
     *   package: SaPackage,
     *   sharing_mode: string,
     *   global_scope: array{cms_type: string, cms_template_id: int, path_key: string, scope_key: string}|null
     * }|array{
     *   ok: false,
     *   http: int,
     *   error: string,
     *   conflict?: array{package_key: string, script_id: string, name: string}
     * }
     */
    public function setGlobalSharing(SaPackage $package, string $sharingMode): array
    {
        if (! $this->sharingSchemaReady()) {
            return ['ok' => false, 'http' => 503, 'error' => 'schema_unavailable'];
        }

        $mode = strtolower(trim($sharingMode));
        if (! in_array($mode, SaPackage::SHARING_MODES, true)) {
            return ['ok' => false, 'http' => 422, 'error' => 'invalid_sharing_mode'];
        }

        if ($mode === SaPackage::SHARING_SITE_SPECIFIC) {
            $package->fill([
                'sharing_mode' => SaPackage::SHARING_SITE_SPECIFIC,
                'global_cms_type' => null,
                'global_cms_template_id' => null,
                'global_cms_path_key' => null,
                'global_scope_key' => null,
            ])->save();

            return [
                'ok' => true,
                'package' => $package->fresh() ?? $package,
                'sharing_mode' => SaPackage::SHARING_SITE_SPECIFIC,
                'global_scope' => null,
            ];
        }

        $derived = $this->deriveGlobalScope($package);
        if (! ($derived['ok'] ?? false)) {
            return [
                'ok' => false,
                'http' => 422,
                'error' => (string) ($derived['error'] ?? 'ineligible_for_global'),
            ];
        }

        $scope = $derived['scope'];
        $conn = SocialAutomationServiceConfig::CONNECTION;

        return DB::connection($conn)->transaction(function () use ($package, $scope): array {
            $locked = SaPackage::query()->where('id', $package->id)->lockForUpdate()->first();
            if (! $locked instanceof SaPackage) {
                return ['ok' => false, 'http' => 404, 'error' => 'not_found'];
            }

            $owningGroup = SocialAutomationSchema::scriptVariantGroupsReady()
                ? SaScriptVariantGroup::query()
                    ->where('scope_type', SaScriptVariantGroup::SCOPE_CMS_GLOBAL)
                    ->where('global_scope_key', $scope['scope_key'])
                    ->where('is_enabled', true)
                    ->first()
                : null;

            $allowedGroupPackageIds = [];
            if ($owningGroup instanceof SaScriptVariantGroup) {
                $allowedGroupPackageIds = SaScriptVariantGroupItem::query()
                    ->where('group_id', $owningGroup->id)
                    ->pluck('package_id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();

                if (! in_array((int) $locked->id, $allowedGroupPackageIds, true)) {
                    $conflictPkg = SaPackage::query()
                        ->whereIn('id', $allowedGroupPackageIds)
                        ->first();

                    return [
                        'ok' => false,
                        'http' => 409,
                        'error' => 'global_scope_conflict',
                        'conflict' => [
                            'package_key' => (string) ($conflictPkg?->package_key ?? $owningGroup->group_uuid),
                            'script_id' => (string) ($conflictPkg?->package_key ?? $owningGroup->group_uuid),
                            'name' => (string) ($conflictPkg?->name ?? $owningGroup->name),
                        ],
                    ];
                }
            }

            $conflictQuery = SaPackage::query()
                ->where('kind', 'script')
                ->where('sharing_mode', SaPackage::SHARING_CMS_GLOBAL)
                ->where('global_scope_key', $scope['scope_key'])
                ->where('id', '!=', $locked->id);
            if ($allowedGroupPackageIds !== []) {
                $conflictQuery->whereNotIn('id', $allowedGroupPackageIds);
            }
            $conflict = $conflictQuery->lockForUpdate()->first();

            if ($conflict instanceof SaPackage) {
                return [
                    'ok' => false,
                    'http' => 409,
                    'error' => 'global_scope_conflict',
                    'conflict' => [
                        'package_key' => (string) $conflict->package_key,
                        'script_id' => (string) $conflict->package_key,
                        'name' => (string) $conflict->name,
                    ],
                ];
            }

            $existingGroupHolder = ($owningGroup instanceof SaScriptVariantGroup && $allowedGroupPackageIds !== [])
                ? SaPackage::query()
                    ->where('global_scope_key', $scope['scope_key'])
                    ->where('id', '!=', $locked->id)
                    ->whereIn('id', $allowedGroupPackageIds)
                    ->exists()
                : false;

            if (! $existingGroupHolder) {
                $locked->fill([
                    'sharing_mode' => SaPackage::SHARING_CMS_GLOBAL,
                    'global_cms_type' => $scope['cms_type'],
                    'global_cms_template_id' => $scope['cms_template_id'],
                    'global_cms_path_key' => $scope['path_key'],
                    'global_scope_key' => $scope['scope_key'],
                ])->save();
            }

            return [
                'ok' => true,
                'package' => $locked->fresh() ?? $locked,
                'sharing_mode' => SaPackage::SHARING_CMS_GLOBAL,
                'global_scope' => $scope,
            ];
        });
    }

    /**
     * Build sharing metadata for Script Registry UI/API.
     *
     * @return array{
     *   sharing_mode: string,
     *   global_scope: array{cms_type: string, cms_template_id: int|null, path_key: string, scope_key: string}|null,
     *   global_eligible: bool,
     *   global_ineligible_reason: string|null,
     *   eligible_global_scope: array{cms_type: string, cms_template_id: int, path_key: string, scope_key: string}|null,
     *   global_conflict: array{package_key: string, script_id: string, name: string}|null
     * }
     */
    public function sharingMetadataForPackage(SaPackage $package): array
    {
        $sharingMode = $this->sharingSchemaReady()
            ? $package->sharingMode()
            : SaPackage::SHARING_SITE_SPECIFIC;

        $globalScope = null;
        if ($sharingMode === SaPackage::SHARING_CMS_GLOBAL && $package->isCmsGlobal()) {
            $globalScope = [
                'cms_type' => (string) $package->global_cms_type,
                'cms_template_id' => $package->global_cms_template_id !== null ? (int) $package->global_cms_template_id : null,
                'path_key' => (string) $package->global_cms_path_key,
                'scope_key' => (string) $package->global_scope_key,
            ];
        }

        $derived = $this->deriveGlobalScope($package);
        $eligibleScope = ($derived['ok'] ?? false) ? $derived['scope'] : null;
        $ineligibleReason = ($derived['ok'] ?? false) ? null : (string) ($derived['error'] ?? 'ineligible');

        $conflict = null;
        if ($this->sharingSchemaReady() && $eligibleScope !== null) {
            $owningGroup = SocialAutomationSchema::scriptVariantGroupsReady()
                ? SaScriptVariantGroup::query()
                    ->where('scope_type', SaScriptVariantGroup::SCOPE_CMS_GLOBAL)
                    ->where('global_scope_key', $eligibleScope['scope_key'])
                    ->where('is_enabled', true)
                    ->first()
                : null;
            $groupPkgIds = $owningGroup instanceof SaScriptVariantGroup
                ? SaScriptVariantGroupItem::query()->where('group_id', $owningGroup->id)->pluck('package_id')->map(static fn ($id): int => (int) $id)->all()
                : [];
            $isInOwningGroup = $owningGroup instanceof SaScriptVariantGroup && in_array((int) $package->id, $groupPkgIds, true);

            $otherQuery = SaPackage::query()
                ->where('kind', 'script')
                ->where('sharing_mode', SaPackage::SHARING_CMS_GLOBAL)
                ->where('global_scope_key', $eligibleScope['scope_key'])
                ->where('id', '!=', $package->id);
            if ($isInOwningGroup) {
                $otherQuery->whereNotIn('id', $groupPkgIds);
            }
            $other = $otherQuery->first();
            if ($other instanceof SaPackage) {
                $conflict = [
                    'package_key' => (string) $other->package_key,
                    'script_id' => (string) $other->package_key,
                    'name' => (string) $other->name,
                ];
            } elseif ($owningGroup instanceof SaScriptVariantGroup && ! $isInOwningGroup) {
                $conflict = [
                    'package_key' => (string) $owningGroup->group_uuid,
                    'script_id' => (string) $owningGroup->group_uuid,
                    'name' => (string) $owningGroup->name,
                ];
            }
        }

        return [
            'sharing_mode' => $sharingMode,
            'global_scope' => $globalScope,
            'global_eligible' => $eligibleScope !== null,
            'global_ineligible_reason' => $ineligibleReason,
            'eligible_global_scope' => $eligibleScope,
            'global_conflict' => $conflict,
        ];
    }

    /**
     * Resolve effective Published Script by Action Tag key.
     *
     * @return array<string, mixed>
     */
    public function resolveForTagKey(string $tagKey, ?string $wantedVersion = null): array
    {
        $normalizedKey = strtolower(trim($tagKey));
        if ($normalizedKey === '') {
            return $this->missingResult(null, true, 'tag_key_required');
        }

        $tag = SaActionTag::query()
            ->with(['site', 'path'])
            ->where('tag_key', $normalizedKey)
            ->first();

        if (! $tag instanceof SaActionTag) {
            return $this->missingResult($normalizedKey, true, 'action_tag_not_found');
        }

        return $this->resolveForTag($tag, $wantedVersion);
    }

    /**
     * Resolve effective Published Script for a target Site + Path (+ optional Action).
     *
     * @return array<string, mixed>
     */
    public function resolveForSiteAndPath(
        SaSite $site,
        SaPath $path,
        ?string $actionKey = null,
        ?string $wantedVersion = null
    ): array {
        $tagKey = SaActionTag::buildKey((string) $site->key, (string) $path->key, $actionKey);
        $tag = SaActionTag::query()
            ->with(['site', 'path'])
            ->where('tag_key', $tagKey)
            ->first();

        if ($tag instanceof SaActionTag) {
            return $this->resolveForTag($tag, $wantedVersion, $site, $path);
        }

        return $this->resolveWithoutTagRecord($site, $path, $tagKey, $wantedVersion);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveForTag(
        SaActionTag $tag,
        ?string $wantedVersion = null,
        ?SaSite $siteOverride = null,
        ?SaPath $pathOverride = null
    ): array {
        $tagKey = (string) $tag->tag_key;

        // Priority 1: Reject if target Action Tag is disabled.
        if (! $tag->isEnabled()) {
            return $this->missingResult($tagKey, false, 'action_tag_disabled');
        }

        // Priority 2: Prefer compatible Site-specific Published Script.
        $siteSpecific = $this->resolveSiteSpecificForTag($tag, $wantedVersion);
        if ($siteSpecific !== null) {
            $site = $siteOverride ?? $this->resolveSiteForTag($tag);
            $path = $pathOverride ?? $this->resolvePathForTag($tag, $site);
            $scope = ($site instanceof SaSite && $path instanceof SaPath)
                ? $this->resolveCmsTemplateScope($site, $path)
                : ['ok' => false];

            $cmsScope = ($scope['ok'] ?? false) ? [
                'cms_type' => $scope['scope']['cms_type'],
                'cms_template_id' => $scope['scope']['cms_template_id'],
                'path_key' => $scope['scope']['path_key'],
                'scope_key' => $scope['scope']['scope_key'],
            ] : null;

            $res = $this->foundResult(
                self::SOURCE_SITE_SPECIFIC,
                $tagKey,
                $tagKey,
                $siteSpecific['package'],
                $siteSpecific['release'],
                $cmsScope,
                $site,
                $path,
            );
            if (SocialAutomationSchema::scriptVariantGroupsReady()) {
                $siteGroup = SaScriptVariantGroup::query()
                    ->with(['items.package', 'items.release'])
                    ->where('scope_type', SaScriptVariantGroup::SCOPE_SITE_ACTION)
                    ->where('action_tag_key', strtolower($tagKey))
                    ->where('is_enabled', true)
                    ->first();
                if ($siteGroup instanceof SaScriptVariantGroup) {
                    $enabledVariants = [];
                    $primaryPkg = null;
                    $primaryRel = null;
                    foreach ($siteGroup->items->sortBy('priority') as $item) {
                        if (! $item->is_enabled || ! $item->package instanceof SaPackage || ! ($item->package->is_enabled ?? true)) {
                            continue;
                        }
                        if (! $item->release instanceof SaPackageRelease || (string) $item->release->status !== 'published') {
                            continue;
                        }
                        if ($primaryPkg === null) {
                            $primaryPkg = $item->package;
                            $primaryRel = $item->release;
                        }
                        $enabledVariants[] = [
                            'variant_id' => (string) $item->variant_uuid,
                            'priority' => (int) $item->priority,
                            'language_label' => (string) $item->language_label,
                            'package_id' => (int) $item->package->id,
                            'package_key' => (string) $item->package->package_key,
                            'release_id' => (int) $item->release->id,
                            'script_version' => (string) $item->release->version,
                            'content_digest' => $item->release->content_digest,
                            'execution_digest' => $item->release->execution_digest,
                        ];
                    }
                    if ($enabledVariants !== [] && $primaryPkg instanceof SaPackage && $primaryRel instanceof SaPackageRelease) {
                        $res = $this->foundResult(
                            self::SOURCE_SITE_SPECIFIC,
                            $tagKey,
                            $tagKey,
                            $primaryPkg,
                            $primaryRel,
                            $cmsScope,
                            $site,
                            $path,
                        );
                        $res['variant_group'] = [
                            'group_id' => (int) $siteGroup->id,
                            'group_uuid' => (string) $siteGroup->group_uuid,
                            'name' => (string) $siteGroup->name,
                            'variants' => $enabledVariants,
                        ];
                    }
                }
            }

            return $res;
        }

        // Priority 3: Otherwise select compatible CMS Global Published Script.
        $site = $siteOverride ?? $this->resolveSiteForTag($tag);
        $path = $pathOverride ?? $this->resolvePathForTag($tag, $site);
        if (! $site instanceof SaSite || ! $path instanceof SaPath) {
            return $this->missingResult($tagKey, true, 'script_not_found');
        }

        return $this->resolveCmsGlobalFallback($site, $path, $tagKey, $wantedVersion);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveWithoutTagRecord(
        SaSite $site,
        SaPath $path,
        string $tagKey,
        ?string $wantedVersion
    ): array {
        return $this->resolveCmsGlobalFallback($site, $path, $tagKey, $wantedVersion);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveCmsGlobalFallback(
        SaSite $site,
        SaPath $path,
        string $targetTagKey,
        ?string $wantedVersion
    ): array {
        if (! $this->sharingSchemaReady()) {
            return $this->missingResult($targetTagKey, true, 'script_not_found');
        }

        $scopeResult = $this->resolveCmsTemplateScope($site, $path);
        if (! ($scopeResult['ok'] ?? false)) {
            return $this->missingResult($targetTagKey, true, 'script_not_found');
        }

        /** @var array{cms_type: string, cms_template_id: int, path_key: string, scope_key: string, is_authentication: bool} $scope */
        $scope = $scopeResult['scope'];
        $cmsScope = [
            'cms_type' => $scope['cms_type'],
            'cms_template_id' => $scope['cms_template_id'],
            'path_key' => $scope['path_key'],
            'scope_key' => $scope['scope_key'],
        ];

        $candidates = SaPackage::query()
            ->where('kind', 'script')
            ->where('sharing_mode', SaPackage::SHARING_CMS_GLOBAL)
            ->where('global_scope_key', $scope['scope_key'])
            ->orderBy('id')
            ->get();

        if (SocialAutomationSchema::scriptVariantGroupsReady()) {
            $group = SaScriptVariantGroup::query()
                ->with(['items.package', 'items.release'])
                ->where('scope_type', SaScriptVariantGroup::SCOPE_CMS_GLOBAL)
                ->where('global_scope_key', $scope['scope_key'])
                ->where('is_enabled', true)
                ->first();

            if ($group instanceof SaScriptVariantGroup) {
                $groupPkgIds = $group->items->pluck('package_id')->map(static fn ($id): int => (int) $id)->all();
                foreach ($candidates as $cand) {
                    if (! in_array((int) $cand->id, $groupPkgIds, true)) {
                        return $this->missingResult($targetTagKey, true, 'ambiguous_global_script', $cmsScope);
                    }
                }

                $enabledVariants = [];
                $primaryPackage = null;
                $primaryRelease = null;
                foreach ($group->items->sortBy('priority') as $item) {
                    if (! $item->is_enabled) {
                        continue;
                    }
                    $vPkg = $item->package;
                    $vRel = $item->release;
                    if (! $vPkg instanceof SaPackage || ! ($vPkg->is_enabled ?? true)) {
                        continue;
                    }
                    if (! $vRel instanceof SaPackageRelease || (string) $vRel->status !== 'published') {
                        continue;
                    }
                    if ($primaryPackage === null) {
                        $primaryPackage = $vPkg;
                        $primaryRelease = $vRel;
                    }
                    $enabledVariants[] = [
                        'variant_id' => (string) $item->variant_uuid,
                        'priority' => (int) $item->priority,
                        'language_label' => (string) $item->language_label,
                        'package_id' => (int) $vPkg->id,
                        'package_key' => (string) $vPkg->package_key,
                        'release_id' => (int) $vRel->id,
                        'script_version' => (string) $vRel->version,
                        'content_digest' => $vRel->content_digest,
                        'execution_digest' => $vRel->execution_digest,
                    ];
                }

                if ($primaryPackage instanceof SaPackage && $primaryRelease instanceof SaPackageRelease) {
                    $result = $this->foundResult(
                        self::SOURCE_CMS_GLOBAL,
                        $targetTagKey,
                        null,
                        $primaryPackage,
                        $primaryRelease,
                        $cmsScope,
                        $site,
                        $path,
                    );
                    $result['variant_group'] = [
                        'group_id' => (int) $group->id,
                        'group_uuid' => (string) $group->group_uuid,
                        'name' => (string) $group->name,
                        'variants' => $enabledVariants,
                    ];

                    return $result;
                }
            }
        }

        if ($candidates->count() > 1) {
            return $this->missingResult($targetTagKey, true, 'ambiguous_global_script', $cmsScope);
        }

        $package = $candidates->first();
        if (! $package instanceof SaPackage) {
            return $this->missingResult($targetTagKey, true, 'script_not_found', $cmsScope);
        }

        if (isset($package->is_enabled) && ! (bool) $package->is_enabled) {
            return $this->missingResult($targetTagKey, true, 'global_script_disabled', $cmsScope);
        }

        $release = $this->findPublishedRelease($package, $wantedVersion);
        if (! $release instanceof SaPackageRelease) {
            return $this->missingResult($targetTagKey, true, 'published_release_not_found', $cmsScope);
        }

        $sourceTag = $this->resolveSourceTagForPackage($package, $release);
        $sourceSite = $sourceTag instanceof SaActionTag ? $this->resolveSiteForTag($sourceTag) : null;
        $sourcePath = $sourceTag instanceof SaActionTag ? $this->resolvePathForTag($sourceTag, $sourceSite) : null;

        if ($this->effectiveIsAuthentication($package, $release, $sourceTag, $sourcePath) !== $scope['is_authentication']) {
            return $this->missingResult($targetTagKey, true, 'script_compatibility_mismatch', $cmsScope);
        }

        $manifest = is_array($release->manifest) ? $release->manifest : [];
        $sourceTagKey = $sourceTag instanceof SaActionTag
            ? (string) $sourceTag->tag_key
            : (trim((string) ($manifest['action_tag_key'] ?? '')) ?: null);

        return $this->foundResult(
            self::SOURCE_CMS_GLOBAL,
            $targetTagKey,
            $sourceTagKey,
            $package,
            $release,
            $cmsScope,
            $site,
            $path,
        );
    }

    /**
     * @return array{package: SaPackage, release: SaPackageRelease}|null
     */
    private function resolveSiteSpecificForTag(SaActionTag $tag, ?string $wantedVersion): ?array
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;
        if (! Schema::connection($conn)->hasTable('sa_action_tag_scripts')
            || ! Schema::connection($conn)->hasTable('sa_packages')
            || ! Schema::connection($conn)->hasTable('sa_package_releases')) {
            return null;
        }

        $links = SaActionTagScript::query()
            ->where(function ($q) use ($tag): void {
                $q->where('action_tag_id', $tag->id)
                    ->orWhere('action_tag_key', $tag->tag_key);
            })
            ->orderByDesc('id')
            ->get();

        if ($links->isEmpty()) {
            return null;
        }

        $best = null;
        foreach ($links as $link) {
            $package = $this->resolvePackageForLink($link);
            if (! $package instanceof SaPackage) {
                continue;
            }
            if ((string) $package->kind !== 'script') {
                continue;
            }
            if (isset($package->is_enabled) && ! (bool) $package->is_enabled) {
                continue;
            }

            $release = null;
            if ($wantedVersion === null && $link->release_id) {
                $candidateRelease = SaPackageRelease::query()
                    ->where('id', (int) $link->release_id)
                    ->where('package_id', $package->id)
                    ->where('status', 'published')
                    ->first();
                if ($candidateRelease instanceof SaPackageRelease) {
                    $release = $candidateRelease;
                }
            }

            if (! $release instanceof SaPackageRelease) {
                $targetVersion = $wantedVersion ?? (is_string($link->script_version) && $link->script_version !== '' ? (string) $link->script_version : null);
                $release = $this->findPublishedRelease($package, $targetVersion);
            }

            if (! $release instanceof SaPackageRelease) {
                continue;
            }

            if ($best === null) {
                $best = ['package' => $package, 'release' => $release];
                continue;
            }

            // Deterministic ordering: latest published_at, then highest release id.
            $bestTs = $best['release']->published_at?->getTimestamp() ?? 0;
            $curTs = $release->published_at?->getTimestamp() ?? 0;
            if ($curTs > $bestTs || ($curTs === $bestTs && (int) $release->id > (int) $best['release']->id)) {
                $best = ['package' => $package, 'release' => $release];
            }
        }

        return $best;
    }

    private function resolvePackageForLink(SaActionTagScript $link): ?SaPackage
    {
        if ($link->package_id) {
            $pkg = SaPackage::query()->find((int) $link->package_id);
            if ($pkg instanceof SaPackage) {
                return $pkg;
            }
        }

        $sid = trim((string) $link->script_id);
        if ($sid !== '') {
            return SaPackage::query()->where('package_key', $sid)->first();
        }

        return null;
    }

    private function findPublishedRelease(SaPackage $package, ?string $wantedVersion): ?SaPackageRelease
    {
        $q = SaPackageRelease::query()
            ->where('package_id', $package->id)
            ->where('status', 'published');

        if ($wantedVersion !== null && trim($wantedVersion) !== '') {
            $q->where('version', trim($wantedVersion));
        } else {
            $q->orderByDesc('published_at')->orderByDesc('id');
        }

        $release = $q->first();

        return $release instanceof SaPackageRelease ? $release : null;
    }

    private function resolveSourceTagForPackage(SaPackage $package, ?SaPackageRelease $release = null): ?SaActionTag
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;
        if (Schema::connection($conn)->hasTable('sa_action_tag_scripts')) {
            $link = SaActionTagScript::query()
                ->where('package_id', $package->id)
                ->orWhere('script_id', $package->package_key)
                ->orderByDesc('id')
                ->first();
            if ($link instanceof SaActionTagScript) {
                $tag = $link->action_tag_id
                    ? SaActionTag::query()->find((int) $link->action_tag_id)
                    : null;
                if (! $tag instanceof SaActionTag && $link->action_tag_key !== '') {
                    $tag = SaActionTag::query()->where('tag_key', $link->action_tag_key)->first();
                }
                if ($tag instanceof SaActionTag) {
                    return $tag;
                }
            }
        }

        if ($release instanceof SaPackageRelease && is_array($release->manifest)) {
            $tagKey = trim((string) ($release->manifest['action_tag_key'] ?? ''));
            if ($tagKey !== '') {
                $tag = SaActionTag::query()->where('tag_key', $tagKey)->first();
                if ($tag instanceof SaActionTag) {
                    return $tag;
                }
            }
        }

        return null;
    }

    private function resolveSiteForTag(SaActionTag $tag): ?SaSite
    {
        if ($tag->relationLoaded('site') && $tag->site instanceof SaSite) {
            return $tag->site;
        }
        if ($tag->site_id) {
            $site = SaSite::query()->find((int) $tag->site_id);
            if ($site instanceof SaSite) {
                return $site;
            }
        }
        $siteKey = trim((string) ($tag->site_key ?: $tag->platform));

        return $siteKey !== '' ? SaSite::query()->where('key', $siteKey)->first() : null;
    }

    /**
     * Resolve effective authentication flag for a Script package.
     * Supports legacy auth scripts where sa_packages.is_authentication=0 and manifest omits is_authentication,
     * when the source Path is an authentication path or the Action Tag pins auth_script_id to this package.
     */
    private function effectiveIsAuthentication(
        SaPackage $package,
        ?SaPackageRelease $release,
        ?SaActionTag $sourceTag,
        ?SaPath $sourcePath,
    ): bool {
        if ((bool) ($package->is_authentication ?? false)) {
            return true;
        }

        $manifest = ($release instanceof SaPackageRelease && is_array($release->manifest)) ? $release->manifest : [];
        if (array_key_exists('is_authentication', $manifest) && $manifest['is_authentication'] !== null) {
            return (bool) $manifest['is_authentication'];
        }

        if ($sourcePath instanceof SaPath && (bool) ($sourcePath->is_authentication ?? false)) {
            return true;
        }

        if ($sourceTag instanceof SaActionTag && (int) ($sourceTag->auth_script_id ?? 0) === (int) $package->id) {
            return true;
        }

        return false;
    }

    private function resolvePathForTag(SaActionTag $tag, ?SaSite $site): ?SaPath
    {
        if ($tag->relationLoaded('path') && $tag->path instanceof SaPath) {
            return $tag->path;
        }
        if ($tag->path_id) {
            $path = SaPath::query()->find((int) $tag->path_id);
            if ($path instanceof SaPath) {
                return $path;
            }
        }
        $pathKey = trim((string) ($tag->path_key ?: $tag->target));
        if ($site instanceof SaSite && $pathKey !== '') {
            return SaPath::query()
                ->where('site_id', $site->id)
                ->where('key', $pathKey)
                ->first();
        }

        return null;
    }

    /**
     * Validate and resolve CMS Type + CMS Path Template identity for a Site + Path.
     *
     * @return array{
     *   ok: true,
     *   scope: array{cms_type: string, cms_template_id: int, path_key: string, scope_key: string, is_authentication: bool}
     * }|array{ok: false, error: string}
     */
    private function resolveCmsTemplateScope(SaSite $site, SaPath $path): array
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;
        if (! Schema::connection($conn)->hasTable('sa_cms_types')
            || ! Schema::connection($conn)->hasTable('sa_cms_path_templates')) {
            return ['ok' => false, 'error' => 'cms_registry_unavailable'];
        }

        $cmsType = SaSite::normalizeCmsType($site->cms_type ?? null);
        if ($cmsType === null) {
            return ['ok' => false, 'error' => 'site_has_no_cms_type'];
        }

        $cms = SaCmsType::query()->where('key', $cmsType)->first();
        if (! $cms instanceof SaCmsType || ! $cms->is_enabled) {
            return ['ok' => false, 'error' => 'cms_type_disabled_or_missing'];
        }

        $template = null;
        if (isset($path->cms_template_id) && $path->cms_template_id !== null) {
            $candidate = SaCmsPathTemplate::query()->find((int) $path->cms_template_id);
            if (! $candidate instanceof SaCmsPathTemplate || (int) $candidate->cms_type_id !== (int) $cms->id) {
                return ['ok' => false, 'error' => 'cms_template_mismatch'];
            }
            $template = $candidate;
        } else {
            $pathKey = strtolower(trim((string) $path->key));
            if ($pathKey !== '') {
                $template = SaCmsPathTemplate::query()
                    ->where('cms_type_id', $cms->id)
                    ->where('key', $pathKey)
                    ->first();
            }
        }

        if (! $template instanceof SaCmsPathTemplate) {
            return ['ok' => false, 'error' => 'no_cms_template_scope'];
        }

        $pathIsAuth = (bool) ($path->is_authentication ?? false);
        $tplIsAuth = (bool) ($template->is_authentication ?? false);
        if ($pathIsAuth !== $tplIsAuth) {
            return ['ok' => false, 'error' => 'path_authentication_mismatch'];
        }

        $scopeKey = self::buildScopeKey($cms->key, $template->key);

        return [
            'ok' => true,
            'scope' => [
                'cms_type' => (string) $cms->key,
                'cms_template_id' => (int) $template->id,
                'path_key' => (string) $template->key,
                'scope_key' => $scopeKey,
                'is_authentication' => $tplIsAuth,
            ],
        ];
    }

    /**
     * @param  array{cms_type: string, cms_template_id: int, path_key: string, scope_key: string}|null  $cmsScope
     * @return array<string, mixed>
     */
    private function foundResult(
        string $source,
        string $targetTagKey,
        ?string $sourceTagKey,
        SaPackage $package,
        SaPackageRelease $release,
        ?array $cmsScope,
        ?SaSite $site,
        ?SaPath $path,
    ): array {
        return [
            'ok' => true,
            'resolved' => true,
            'source' => $source,
            'reason' => null,
            'tag_enabled' => true,
            'target_tag_key' => $targetTagKey,
            'source_action_tag_key' => $sourceTagKey,
            'target_site_key' => $site?->key,
            'target_path_key' => $path?->key,
            'cms_scope' => $cmsScope,
            'package' => $package,
            'release' => $release,
            'script' => [
                'package_key' => (string) $package->package_key,
                'package_id' => (int) $package->id,
                'script_id' => (string) $package->package_key,
                'name' => (string) $package->name,
                'version' => (string) $release->version,
                'release_id' => (int) $release->id,
                'content_digest' => $release->content_digest !== null ? (string) $release->content_digest : null,
                'execution_digest' => $release->execution_digest !== null ? (string) $release->execution_digest : null,
                'sharing_mode' => $this->sharingSchemaReady() ? $package->sharingMode() : SaPackage::SHARING_SITE_SPECIFIC,
                'source_action_tag_key' => $sourceTagKey,
            ],
        ];
    }

    /**
     * @param  array{cms_type: string, cms_template_id: int, path_key: string, scope_key: string}|null  $cmsScope
     * @return array<string, mixed>
     */
    private function missingResult(
        ?string $targetTagKey,
        bool $tagEnabled,
        string $reason,
        ?array $cmsScope = null,
    ): array {
        return [
            'ok' => false,
            'resolved' => false,
            'source' => self::SOURCE_MISSING,
            'reason' => $reason,
            'tag_enabled' => $tagEnabled,
            'target_tag_key' => $targetTagKey,
            'source_action_tag_key' => null,
            'target_site_key' => null,
            'target_path_key' => null,
            'cms_scope' => $cmsScope,
            'package' => null,
            'release' => null,
            'script' => null,
        ];
    }
}
