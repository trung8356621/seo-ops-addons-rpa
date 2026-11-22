<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTagScript;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptVariantGroup;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptVariantGroupItem;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

final class ScriptVariantGroupService
{
    public function __construct(
        private readonly ?ScriptResolutionService $resolver = null,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        if (! SocialAutomationSchema::scriptVariantGroupsReady()) {
            return [];
        }

        $groups = SaScriptVariantGroup::query()
            ->with(['items.package', 'items.release'])
            ->orderByDesc('id')
            ->get();

        return $groups->map(fn (SaScriptVariantGroup $g): array => $this->groupDto($g))->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detail(string $groupUuid): ?array
    {
        if (! SocialAutomationSchema::scriptVariantGroupsReady()) {
            return null;
        }

        $group = SaScriptVariantGroup::query()
            ->with(['items.package', 'items.release'])
            ->where('group_uuid', $groupUuid)
            ->first();

        return $group instanceof SaScriptVariantGroup ? $this->groupDto($group) : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, http?: int, data?: array<string, mixed>, error?: string, errors?: list<string>, conflict?: array<string, mixed>}
     */
    public function create(array $input, ?int $userId): array
    {
        if (! SocialAutomationSchema::scriptVariantGroupsReady()) {
            return ['ok' => false, 'http' => 503, 'error' => 'schema_unavailable', 'errors' => ['schema_unavailable']];
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'http' => 422, 'error' => 'name_required', 'errors' => ['name_required']];
        }

        $scopeType = strtolower(trim((string) ($input['scope_type'] ?? SaScriptVariantGroup::SCOPE_CMS_GLOBAL)));
        if (! in_array($scopeType, [SaScriptVariantGroup::SCOPE_CMS_GLOBAL, SaScriptVariantGroup::SCOPE_SITE_ACTION], true)) {
            return ['ok' => false, 'http' => 422, 'error' => 'invalid_scope_type', 'errors' => ['invalid_scope_type']];
        }

        $globalScopeKey = null;
        $cmsType = null;
        $cmsTemplateId = null;
        $cmsPathKey = null;
        $actionTagKey = null;

        if ($scopeType === SaScriptVariantGroup::SCOPE_CMS_GLOBAL) {
            $cmsType = strtolower(trim((string) ($input['cms_type'] ?? '')));
            $cmsPathKey = strtolower(trim((string) ($input['cms_path_key'] ?? $input['path_key'] ?? '')));
            $globalScopeKey = strtolower(trim((string) ($input['global_scope_key'] ?? '')));
            $cmsTemplateId = isset($input['cms_template_id']) && $input['cms_template_id'] !== ''
                ? (int) $input['cms_template_id']
                : null;

            if ($globalScopeKey === '' && $cmsType !== '' && $cmsPathKey !== '') {
                $globalScopeKey = "{$cmsType}:{$cmsPathKey}";
            } elseif ($globalScopeKey !== '' && ($cmsType === '' || $cmsPathKey === '') && str_contains($globalScopeKey, ':')) {
                [$parsedCms, $parsedPath] = explode(':', $globalScopeKey, 2);
                $cmsType = $cmsType !== '' ? $cmsType : $parsedCms;
                $cmsPathKey = $cmsPathKey !== '' ? $cmsPathKey : $parsedPath;
            }

            if ($globalScopeKey === '') {
                return ['ok' => false, 'http' => 422, 'error' => 'global_scope_key_required', 'errors' => ['global_scope_key_required']];
            }
        } else {
            $actionTagKey = strtolower(trim((string) ($input['action_tag_key'] ?? '')));
            if ($actionTagKey === '') {
                return ['ok' => false, 'http' => 422, 'error' => 'action_tag_key_required', 'errors' => ['action_tag_key_required']];
            }
            $tag = SaActionTag::query()->where('tag_key', $actionTagKey)->first();
            if (! $tag instanceof SaActionTag) {
                return ['ok' => false, 'http' => 422, 'error' => 'action_tag_not_found', 'errors' => ['action_tag_not_found']];
            }
        }

        $rawVariants = is_array($input['variants'] ?? null) ? $input['variants'] : [];
        $conn = SocialAutomationServiceConfig::CONNECTION;

        try {
            return DB::connection($conn)->transaction(function () use (
            $name,
            $scopeType,
            $cmsType,
            $cmsTemplateId,
            $cmsPathKey,
            $globalScopeKey,
            $actionTagKey,
            $rawVariants,
            $userId
        ): array {
            if ($scopeType === SaScriptVariantGroup::SCOPE_CMS_GLOBAL && $globalScopeKey !== null) {
                $existingGroup = SaScriptVariantGroup::query()
                    ->where('global_scope_key', $globalScopeKey)
                    ->lockForUpdate()
                    ->first();
                if ($existingGroup instanceof SaScriptVariantGroup) {
                    return [
                        'ok' => false,
                        'http' => 409,
                        'error' => 'global_scope_conflict',
                        'errors' => ['global_scope_conflict'],
                        'conflict' => ['group_id' => $existingGroup->group_uuid, 'name' => $existingGroup->name],
                    ];
                }

                $submittedPkgKeys = [];
                foreach ($rawVariants as $rv) {
                    if (is_array($rv) && isset($rv['package_key'])) {
                        $submittedPkgKeys[] = trim((string) $rv['package_key']);
                    }
                }

                $conflictPkg = SaPackage::query()
                    ->where('kind', 'script')
                    ->where('sharing_mode', SaPackage::SHARING_CMS_GLOBAL)
                    ->where('global_scope_key', $globalScopeKey)
                    ->when($submittedPkgKeys !== [], fn ($q) => $q->whereNotIn('package_key', $submittedPkgKeys))
                    ->lockForUpdate()
                    ->first();
                if ($conflictPkg instanceof SaPackage) {
                    return [
                        'ok' => false,
                        'http' => 409,
                        'error' => 'global_scope_conflict',
                        'errors' => ['global_scope_conflict'],
                        'conflict' => [
                            'package_key' => (string) $conflictPkg->package_key,
                            'script_id' => (string) $conflictPkg->package_key,
                            'name' => (string) $conflictPkg->name,
                        ],
                    ];
                }
            }

            $group = SaScriptVariantGroup::query()->create([
                'group_uuid' => (string) Str::uuid(),
                'name' => substr($name, 0, 191),
                'scope_type' => $scopeType,
                'cms_type' => $cmsType ?: null,
                'cms_template_id' => $cmsTemplateId,
                'cms_path_key' => $cmsPathKey ?: null,
                'global_scope_key' => $globalScopeKey,
                'action_tag_key' => $actionTagKey,
                'is_enabled' => (bool) ($input['is_enabled'] ?? true),
                'created_by_user_id' => $userId,
            ]);

            foreach ($rawVariants as $idx => $variantRow) {
                if (! is_array($variantRow)) {
                    throw new \RuntimeException(json_encode(['ok' => false, 'http' => 422, 'error' => 'invalid_variant_row', 'errors' => ['invalid_variant_row']]) ?: 'invalid_variant_row');
                }
                if (! isset($variantRow['priority'])) {
                    $variantRow['priority'] = $idx + 1;
                }
                $added = $this->insertVariantIntoGroup($group, $variantRow);
                if (! ($added['ok'] ?? false)) {
                    throw new \RuntimeException(json_encode($added) ?: 'variant_add_failed');
                }
            }

            return [
                'ok' => true,
                'data' => $this->groupDto($group->fresh(['items.package', 'items.release']) ?? $group),
            ];
        });
        } catch (\RuntimeException $e) {
            $decoded = json_decode($e->getMessage(), true);
            if (is_array($decoded) && array_key_exists('ok', $decoded)) {
                return $decoded;
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, http?: int, data?: array<string, mixed>, error?: string, errors?: list<string>}
     */
    public function addVariant(string $groupUuid, array $input): array
    {
        if (! SocialAutomationSchema::scriptVariantGroupsReady()) {
            return ['ok' => false, 'http' => 503, 'error' => 'schema_unavailable', 'errors' => ['schema_unavailable']];
        }

        $conn = SocialAutomationServiceConfig::CONNECTION;

        return DB::connection($conn)->transaction(function () use ($groupUuid, $input): array {
            $group = SaScriptVariantGroup::query()
                ->where('group_uuid', $groupUuid)
                ->lockForUpdate()
                ->first();
            if (! $group instanceof SaScriptVariantGroup) {
                return ['ok' => false, 'http' => 404, 'error' => 'variant_group_not_found', 'errors' => ['variant_group_not_found']];
            }

            $res = $this->insertVariantIntoGroup($group, $input);
            if (! ($res['ok'] ?? false)) {
                return $res;
            }

            return [
                'ok' => true,
                'data' => $this->groupDto($group->fresh(['items.package', 'items.release']) ?? $group),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, http?: int, data?: array<string, mixed>, error?: string, errors?: list<string>}
     */
    public function updateVariant(string $groupUuid, string $variantUuid, array $input): array
    {
        if (! SocialAutomationSchema::scriptVariantGroupsReady()) {
            return ['ok' => false, 'http' => 503, 'error' => 'schema_unavailable', 'errors' => ['schema_unavailable']];
        }

        $conn = SocialAutomationServiceConfig::CONNECTION;

        return DB::connection($conn)->transaction(function () use ($groupUuid, $variantUuid, $input): array {
            $group = SaScriptVariantGroup::query()
                ->where('group_uuid', $groupUuid)
                ->lockForUpdate()
                ->first();
            if (! $group instanceof SaScriptVariantGroup) {
                return ['ok' => false, 'http' => 404, 'error' => 'variant_group_not_found', 'errors' => ['variant_group_not_found']];
            }

            $item = SaScriptVariantGroupItem::query()
                ->where('group_id', $group->id)
                ->where('variant_uuid', $variantUuid)
                ->first();
            if (! $item instanceof SaScriptVariantGroupItem) {
                return ['ok' => false, 'http' => 404, 'error' => 'variant_not_found', 'errors' => ['variant_not_found']];
            }

            if (array_key_exists('language_label', $input)) {
                $label = $this->normalizeLanguageLabel((string) $input['language_label']);
                if ($label === '') {
                    return ['ok' => false, 'http' => 422, 'error' => 'language_label_required', 'errors' => ['language_label_required']];
                }
                $dup = SaScriptVariantGroupItem::query()
                    ->where('group_id', $group->id)
                    ->where('language_label', $label)
                    ->where('id', '!=', $item->id)
                    ->exists();
                if ($dup) {
                    return ['ok' => false, 'http' => 422, 'error' => 'duplicate_language_label', 'errors' => ['duplicate_language_label']];
                }
                $item->language_label = $label;
            }

            if (array_key_exists('is_enabled', $input)) {
                $item->is_enabled = (bool) $input['is_enabled'];
            }

            $item->save();

            return [
                'ok' => true,
                'data' => $this->groupDto($group->fresh(['items.package', 'items.release']) ?? $group),
            ];
        });
    }

    /**
     * @return array{ok: bool, http?: int, data?: array<string, mixed>, error?: string, errors?: list<string>}
     */
    public function removeVariant(string $groupUuid, string $variantUuid): array
    {
        if (! SocialAutomationSchema::scriptVariantGroupsReady()) {
            return ['ok' => false, 'http' => 503, 'error' => 'schema_unavailable', 'errors' => ['schema_unavailable']];
        }

        $conn = SocialAutomationServiceConfig::CONNECTION;

        return DB::connection($conn)->transaction(function () use ($groupUuid, $variantUuid): array {
            $group = SaScriptVariantGroup::query()
                ->where('group_uuid', $groupUuid)
                ->lockForUpdate()
                ->first();
            if (! $group instanceof SaScriptVariantGroup) {
                return ['ok' => false, 'http' => 404, 'error' => 'variant_group_not_found', 'errors' => ['variant_group_not_found']];
            }

            $item = SaScriptVariantGroupItem::query()
                ->where('group_id', $group->id)
                ->where('variant_uuid', $variantUuid)
                ->first();
            if (! $item instanceof SaScriptVariantGroupItem) {
                return ['ok' => false, 'http' => 404, 'error' => 'variant_not_found', 'errors' => ['variant_not_found']];
            }

            $item->delete();

            $remaining = SaScriptVariantGroupItem::query()
                ->where('group_id', $group->id)
                ->orderBy('priority')
                ->orderBy('id')
                ->get();
            foreach ($remaining as $idx => $rem) {
                $rem->priority = $idx + 1;
                $rem->save();
            }

            return [
                'ok' => true,
                'data' => $this->groupDto($group->fresh(['items.package', 'items.release']) ?? $group),
            ];
        });
    }

    /**
     * @param  list<string>  $orderedVariantUuids
     * @return array{ok: bool, http?: int, data?: array<string, mixed>, error?: string, errors?: list<string>}
     */
    public function reorder(string $groupUuid, array $orderedVariantUuids): array
    {
        if (! SocialAutomationSchema::scriptVariantGroupsReady()) {
            return ['ok' => false, 'http' => 503, 'error' => 'schema_unavailable', 'errors' => ['schema_unavailable']];
        }

        $clean = array_values(array_unique(array_filter(array_map(
            static fn ($v): string => trim((string) $v),
            $orderedVariantUuids
        ), static fn (string $v): bool => $v !== '')));

        $conn = SocialAutomationServiceConfig::CONNECTION;

        return DB::connection($conn)->transaction(function () use ($groupUuid, $clean): array {
            $group = SaScriptVariantGroup::query()
                ->where('group_uuid', $groupUuid)
                ->lockForUpdate()
                ->first();
            if (! $group instanceof SaScriptVariantGroup) {
                return ['ok' => false, 'http' => 404, 'error' => 'variant_group_not_found', 'errors' => ['variant_group_not_found']];
            }

            $items = SaScriptVariantGroupItem::query()
                ->where('group_id', $group->id)
                ->get()
                ->keyBy('variant_uuid');

            if (count($clean) !== $items->count()) {
                return ['ok' => false, 'http' => 422, 'error' => 'invalid_variant_reorder', 'errors' => ['invalid_variant_reorder']];
            }

            foreach ($clean as $uuid) {
                if (! $items->has($uuid)) {
                    return ['ok' => false, 'http' => 422, 'error' => 'invalid_variant_reorder', 'errors' => ['invalid_variant_reorder']];
                }
            }

            foreach ($clean as $idx => $uuid) {
                /** @var SaScriptVariantGroupItem $item */
                $item = $items->get($uuid);
                $item->priority = $idx + 1;
                $item->save();
            }

            return [
                'ok' => true,
                'data' => $this->groupDto($group->fresh(['items.package', 'items.release']) ?? $group),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function groupDto(SaScriptVariantGroup $group): array
    {
        $items = $group->items->sortBy(fn (SaScriptVariantGroupItem $i): int => (int) $i->priority)->values();

        return [
            'id' => (string) $group->group_uuid,
            'name' => (string) $group->name,
            'scope_type' => (string) $group->scope_type,
            'cms_type' => $group->cms_type,
            'cms_template_id' => $group->cms_template_id !== null ? (int) $group->cms_template_id : null,
            'cms_path_key' => $group->cms_path_key,
            'global_scope_key' => $group->global_scope_key,
            'action_tag_key' => $group->action_tag_key,
            'is_enabled' => (bool) $group->is_enabled,
            'variants' => $items->map(function (SaScriptVariantGroupItem $item): array {
                $rel = $item->release;
                $pkg = $item->package;

                return [
                    'id' => (string) $item->variant_uuid,
                    'priority' => (int) $item->priority,
                    'language_label' => (string) $item->language_label,
                    'package_id' => (int) $item->package_id,
                    'package_key' => (string) $item->package_key,
                    'script_id' => (string) $item->package_key,
                    'script_name' => $pkg?->name,
                    'release_id' => (int) $item->release_id,
                    'script_version' => (string) $item->script_version,
                    'release_status' => $rel?->status,
                    'content_digest' => $item->content_digest,
                    'execution_digest' => $item->execution_digest,
                    'published_at' => $rel?->published_at?->toIso8601String(),
                    'is_enabled' => (bool) $item->is_enabled,
                ];
            })->all(),
            'created_at' => $group->created_at?->toIso8601String(),
            'updated_at' => $group->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, http?: int, error?: string, errors?: list<string>}
     */
    private function insertVariantIntoGroup(SaScriptVariantGroup $group, array $input): array
    {
        $label = $this->normalizeLanguageLabel((string) ($input['language_label'] ?? ''));
        if ($label === '') {
            return ['ok' => false, 'http' => 422, 'error' => 'language_label_required', 'errors' => ['language_label_required']];
        }

        $packageKey = trim((string) ($input['package_key'] ?? $input['script_id'] ?? ''));
        $packageId = isset($input['package_id']) && $input['package_id'] !== '' ? (int) $input['package_id'] : null;

        $package = null;
        if ($packageId !== null) {
            $package = SaPackage::query()->where('id', $packageId)->where('kind', 'script')->first();
        } elseif ($packageKey !== '') {
            $package = SaPackage::query()->where('package_key', $packageKey)->where('kind', 'script')->first();
        }
        if (! $package instanceof SaPackage) {
            return ['ok' => false, 'http' => 422, 'error' => 'package_not_found', 'errors' => ['package_not_found']];
        }

        $releaseId = isset($input['release_id']) && $input['release_id'] !== '' ? (int) $input['release_id'] : null;
        $wantedVersion = trim((string) ($input['script_version'] ?? $input['version'] ?? ''));
        $release = null;
        if ($releaseId !== null) {
            $release = SaPackageRelease::query()->where('id', $releaseId)->where('package_id', $package->id)->first();
        } elseif ($wantedVersion !== '') {
            $release = SaPackageRelease::query()->where('package_id', $package->id)->where('version', $wantedVersion)->first();
        } else {
            $release = SaPackageRelease::query()
                ->where('package_id', $package->id)
                ->where('status', 'published')
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->first();
        }

        if (! $release instanceof SaPackageRelease || (string) $release->status !== 'published') {
            return ['ok' => false, 'http' => 422, 'error' => 'published_release_required', 'errors' => ['published_release_required']];
        }

        // Validate scope/CMS/Action compatibility
        $resolver = $this->resolver ?? new ScriptResolutionService;
        if ((string) $group->scope_type === SaScriptVariantGroup::SCOPE_CMS_GLOBAL) {
            $derived = $resolver->deriveGlobalScope($package);
            if (! ($derived['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'http' => 422,
                    'error' => (string) ($derived['error'] ?? 'incompatible_variant_scope'),
                    'errors' => [(string) ($derived['error'] ?? 'incompatible_variant_scope')],
                ];
            }
            $scope = $derived['scope'];
            if (strtolower((string) $scope['scope_key']) !== strtolower((string) $group->global_scope_key)) {
                return ['ok' => false, 'http' => 422, 'error' => 'incompatible_variant_scope', 'errors' => ['incompatible_variant_scope']];
            }
            // Ensure package is marked cms_global for this owned scope
            if (! $package->isCmsGlobal() || $package->global_scope_key !== $group->global_scope_key) {
                $package->fill([
                    'sharing_mode' => SaPackage::SHARING_CMS_GLOBAL,
                    'global_cms_type' => $scope['cms_type'],
                    'global_cms_template_id' => $scope['cms_template_id'],
                    'global_cms_path_key' => $scope['path_key'],
                    'global_scope_key' => $scope['scope_key'],
                ])->save();
            }
        } else {
            $matchesAction = $this->packageMatchesActionTag($package, $release, (string) $group->action_tag_key);
            if (! $matchesAction) {
                return ['ok' => false, 'http' => 422, 'error' => 'incompatible_variant_action', 'errors' => ['incompatible_variant_action']];
            }
        }

        $dupLang = SaScriptVariantGroupItem::query()
            ->where('group_id', $group->id)
            ->where('language_label', $label)
            ->exists();
        if ($dupLang) {
            return ['ok' => false, 'http' => 422, 'error' => 'duplicate_language_label', 'errors' => ['duplicate_language_label']];
        }

        $dupPkg = SaScriptVariantGroupItem::query()
            ->where('group_id', $group->id)
            ->where('package_id', $package->id)
            ->exists();
        if ($dupPkg) {
            return ['ok' => false, 'http' => 422, 'error' => 'duplicate_variant_package', 'errors' => ['duplicate_variant_package']];
        }

        $maxPriority = (int) SaScriptVariantGroupItem::query()
            ->where('group_id', $group->id)
            ->max('priority');
        $priority = isset($input['priority']) && (int) $input['priority'] > 0
            ? (int) $input['priority']
            : ($maxPriority + 1);

        SaScriptVariantGroupItem::query()->create([
            'variant_uuid' => (string) Str::uuid(),
            'group_id' => $group->id,
            'package_id' => (int) $package->id,
            'package_key' => (string) $package->package_key,
            'release_id' => (int) $release->id,
            'script_version' => (string) $release->version,
            'content_digest' => $release->content_digest,
            'execution_digest' => $release->execution_digest,
            'language_label' => $label,
            'priority' => $priority,
            'is_enabled' => (bool) ($input['is_enabled'] ?? true),
        ]);

        return ['ok' => true];
    }

    private function packageMatchesActionTag(SaPackage $package, SaPackageRelease $release, string $actionTagKey): bool
    {
        if (strcasecmp((string) $package->package_key, $actionTagKey) === 0) {
            return true;
        }
        $manifest = is_array($release->manifest) ? $release->manifest : [];
        if (isset($manifest['action_tag_key']) && strcasecmp((string) $manifest['action_tag_key'], $actionTagKey) === 0) {
            return true;
        }

        return SaActionTagScript::query()
            ->where('package_id', $package->id)
            ->where('action_tag_key', $actionTagKey)
            ->exists();
    }

    private function normalizeLanguageLabel(string $label): string
    {
        return strtoupper(substr(trim($label), 0, 32));
    }
}
