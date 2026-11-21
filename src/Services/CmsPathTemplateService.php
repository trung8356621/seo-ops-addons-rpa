<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaCmsPathTemplate;
use Omnichannel\Addons\SocialAutomation\Models\SaCmsType;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptRequest;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Support\PathPatternResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Shared CMS Path Templates & idempotent two-part Action Tag synchronization.
 */
final class CmsPathTemplateService
{
    /**
     * @return list<array{
     *   id: int|null,
     *   key: string,
     *   name: string,
     *   url_pattern: string,
     *   is_authentication: bool,
     *   description: string,
     *   version: int
     * }>
     */
    public function templatesForCms(?string $cmsType): array
    {
        $normalized = SaSite::normalizeCmsType($cmsType);
        if ($normalized === null) {
            return [];
        }

        if ($this->cmsRegistryTablesReady()) {
            $cms = SaCmsType::query()->where('key', $normalized)->first();
            if (! $cms instanceof SaCmsType || ! $cms->is_enabled) {
                return [];
            }

            return SaCmsPathTemplate::query()
                ->where('cms_type_id', $cms->id)
                ->orderBy('id')
                ->get()
                ->map(fn (SaCmsPathTemplate $tpl) => [
                    'id' => (int) $tpl->id,
                    'key' => $tpl->key,
                    'name' => $tpl->name,
                    'url_pattern' => $tpl->url_pattern,
                    'is_authentication' => (bool) $tpl->is_authentication,
                    'description' => (string) ($tpl->description ?? ''),
                    'version' => (int) ($tpl->version ?? 1),
                ])
                ->all();
        }

        if ($normalized === SaSite::CMS_WORDPRESS) {
            return [
                [
                    'id' => null,
                    'key' => 'create_post',
                    'name' => 'Create Post',
                    'url_pattern' => '/wp-admin/post-new.php',
                    'is_authentication' => false,
                    'description' => 'WordPress Create Post',
                    'version' => 1,
                ],
                [
                    'id' => null,
                    'key' => 'login',
                    'name' => 'Login',
                    'url_pattern' => '/wp-login.php',
                    'is_authentication' => true,
                    'description' => 'WordPress Login (Authentication)',
                    'version' => 1,
                ],
            ];
        }

        return [];
    }

    public function isCmsTypeEnabled(?string $cmsType): bool
    {
        $normalized = SaSite::normalizeCmsType($cmsType);
        if ($normalized === null) {
            return true;
        }

        if ($this->cmsRegistryTablesReady()) {
            $cms = SaCmsType::query()->where('key', $normalized)->first();

            return $cms instanceof SaCmsType && (bool) $cms->is_enabled;
        }

        return SaSite::isSupportedCmsType($normalized);
    }

    /**
     * Normalize optional per-Site path overrides from request payload.
     *
     * Accepts either:
     * - ['login' => '/custom-login.php']
     * - ['login' => ['url_pattern' => '/custom-login.php', 'name' => 'Custom Login']]
     * - [['key' => 'login', 'url_pattern' => '/custom-login.php']]
     *
     * @return array<string, array{url_pattern?: string, name?: string, is_authentication?: bool, description?: string}>
     */
    public function normalizePathOverrides(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $overrides = [];
        foreach ($raw as $k => $val) {
            $pathKey = is_string($k) ? strtolower(trim($k)) : '';
            if (is_array($val)) {
                if ($pathKey === '' && isset($val['key'])) {
                    $pathKey = strtolower(trim((string) $val['key']));
                }
                if ($pathKey === '') {
                    continue;
                }
                $entry = [];
                if (isset($val['url_pattern']) && trim((string) $val['url_pattern']) !== '') {
                    $p = trim((string) $val['url_pattern']);
                    $entry['url_pattern'] = str_starts_with($p, '/') ? $p : '/'.$p;
                }
                if (isset($val['name']) && trim((string) $val['name']) !== '') {
                    $entry['name'] = trim((string) $val['name']);
                }
                if (array_key_exists('is_authentication', $val)) {
                    $entry['is_authentication'] = filter_var($val['is_authentication'], FILTER_VALIDATE_BOOLEAN);
                }
                if (array_key_exists('description', $val)) {
                    $entry['description'] = trim((string) $val['description']);
                }
                if ($entry !== []) {
                    $overrides[$pathKey] = $entry;
                }
            } elseif (is_string($val) && $pathKey !== '' && trim($val) !== '') {
                $p = trim($val);
                $overrides[$pathKey] = [
                    'url_pattern' => str_starts_with($p, '/') ? $p : '/'.$p,
                ];
            }
        }

        return $overrides;
    }

    /**
     * Resolve shared CMS path templates onto a Site and ensure matching Action Tags idempotently.
     * Existing per-Site Path overrides and existing Action Tags (IDs, Keys, Scripts, Auth pins) are preserved.
     *
     * @param  array<string, array{url_pattern?: string, name?: string, is_authentication?: bool, description?: string}>  $pathOverrides
     * @return array{paths: list<SaPath>, tags: list<SaActionTag>}
     */
    public function syncSiteCmsPathsAndTags(SaSite $site, array $pathOverrides = [], ?int $userId = null): array
    {
        $templates = $this->templatesForCms($site->cms_type ?? null);
        $hasAuthCol = $this->pathAuthenticationColumnReady();
        $hasTplTracking = $this->pathTemplateTrackingReady();

        foreach ($templates as $tpl) {
            $key = $tpl['key'];
            $override = $pathOverrides[$key] ?? [];
            $existingPath = SaPath::query()
                ->where('site_id', $site->id)
                ->where('key', $key)
                ->first();

            if ($existingPath instanceof SaPath) {
                $changed = false;
                if ($override !== []) {
                    if (isset($override['url_pattern']) && $override['url_pattern'] !== $existingPath->url_pattern) {
                        $existingPath->url_pattern = $override['url_pattern'];
                        $existingPath->path_inputs = PathPatternResolver::parseInputs($override['url_pattern']);
                        $changed = true;
                    }
                    if (isset($override['name']) && $override['name'] !== $existingPath->name) {
                        $existingPath->name = $override['name'];
                        $changed = true;
                    }
                    if ($hasAuthCol && array_key_exists('is_authentication', $override)) {
                        $existingPath->is_authentication = (bool) $override['is_authentication'];
                        $changed = true;
                    }
                    if (array_key_exists('description', $override)) {
                        $existingPath->description = $override['description'] !== '' ? $override['description'] : null;
                        $changed = true;
                    }
                    if ($hasTplTracking && ! ($existingPath->is_override ?? false)) {
                        $existingPath->is_override = true;
                        $changed = true;
                    }
                } elseif (
                    $hasTplTracking
                    && $existingPath->cms_template_id !== null
                    && ! ($existingPath->is_override ?? false)
                    && (int) ($tpl['version'] ?? 1) > (int) ($existingPath->template_version ?? 1)
                ) {
                    // Version-safe template propagation for non-overridden inherited paths
                    $existingPath->url_pattern = $tpl['url_pattern'];
                    $existingPath->path_inputs = PathPatternResolver::parseInputs($tpl['url_pattern']);
                    $existingPath->name = $tpl['name'];
                    if ($hasAuthCol) {
                        $existingPath->is_authentication = (bool) $tpl['is_authentication'];
                    }
                    $existingPath->description = $tpl['description'] !== '' ? $tpl['description'] : null;
                    $existingPath->template_version = (int) ($tpl['version'] ?? 1);
                    $changed = true;
                }

                if ($changed) {
                    $existingPath->save();
                    if ($hasAuthCol) {
                        app(AuthScriptService::class)->syncPackageAuthFlagsForPath($existingPath);
                    }
                }
                continue;
            }

            $urlPattern = $override['url_pattern'] ?? $tpl['url_pattern'];
            $payload = [
                'site_id' => $site->id,
                'site_key' => $site->key,
                'key' => $key,
                'name' => $override['name'] ?? $tpl['name'],
                'url_pattern' => $urlPattern,
                'path_inputs' => PathPatternResolver::parseInputs($urlPattern),
                'description' => array_key_exists('description', $override)
                    ? ($override['description'] !== '' ? $override['description'] : null)
                    : ($tpl['description'] !== '' ? $tpl['description'] : null),
            ];
            if ($hasAuthCol) {
                $payload['is_authentication'] = $override['is_authentication'] ?? $tpl['is_authentication'];
            }
            if ($hasTplTracking) {
                $payload['cms_template_id'] = $tpl['id'];
                $payload['is_override'] = $override !== [];
                $payload['template_version'] = (int) ($tpl['version'] ?? 1);
            }

            $createdPath = SaPath::query()->create($payload);
            if ($hasAuthCol) {
                app(AuthScriptService::class)->syncPackageAuthFlagsForPath($createdPath);
            }
        }

        $sitePaths = SaPath::query()
            ->where('site_id', $site->id)
            ->orderBy('id')
            ->get()
            ->all();

        $ensuredTags = [];
        foreach ($sitePaths as $path) {
            $ensured = $this->ensureTagForPath($site, $path, $userId);
            if ($ensured['tag'] instanceof SaActionTag) {
                $ensuredTags[] = $ensured['tag'];
            }
        }

        return [
            'paths' => $sitePaths,
            'tags' => $ensuredTags,
        ];
    }

    /**
     * Ensure a two-part Action Tag ({site_key}.{path_key}) exists for the given Site + Path.
     * Idempotent: preserves existing Tag ID, Key, Script bindings, Auth pin, and enabled state.
     *
     * @return array{tag: ?SaActionTag, created: bool, request: ?SaScriptRequest}
     */
    public function ensureTagForPath(SaSite $site, SaPath $path, ?int $userId = null): array
    {
        if (! $this->actionTagsSchemaReady()) {
            return ['tag' => null, 'created' => false, 'request' => null];
        }

        $tagKey = SaActionTag::buildKey($site->key, $path->key);
        $existing = SaActionTag::query()->where('tag_key', $tagKey)->first();

        if ($existing instanceof SaActionTag) {
            $dirty = false;
            if (! $existing->site_id) {
                $existing->site_id = $site->id;
                $dirty = true;
            }
            if (empty($existing->site_key)) {
                $existing->site_key = $site->key;
                $dirty = true;
            }
            if (! $existing->path_id) {
                $existing->path_id = $path->id;
                $dirty = true;
            }
            if (empty($existing->path_key)) {
                $existing->path_key = $path->key;
                $dirty = true;
            }
            if ($dirty) {
                $existing->save();
            }

            return ['tag' => $existing, 'created' => false, 'request' => null];
        }

        $payload = [
            'tag_key' => $tagKey,
            'site_id' => $site->id,
            'site_key' => $site->key,
            'path_id' => $path->id,
            'path_key' => $path->key,
            'action_id' => null,
            'action_key' => null,
            'platform' => $site->key,
            'target' => $path->key,
            'action' => '',
            'name' => "{$site->name} {$path->name}",
            'description' => "Thao tác tại {$site->name} {$path->name}",
        ];
        if ($this->tagEnabledColumnReady()) {
            $payload['is_enabled'] = true;
        }

        $tag = SaActionTag::query()->create($payload);
        $requestRow = $this->ensureInitialScriptRequest($site, $path, $tag, $userId);

        return ['tag' => $tag, 'created' => true, 'request' => $requestRow];
    }

    private function ensureInitialScriptRequest(
        SaSite $site,
        SaPath $path,
        SaActionTag $tag,
        ?int $userId,
    ): ?SaScriptRequest {
        $conn = SocialAutomationServiceConfig::CONNECTION;
        if (! Schema::connection($conn)->hasTable('sa_script_requests')) {
            return null;
        }

        $existing = SaScriptRequest::query()
            ->where(function ($q) use ($tag): void {
                $q->where('action_tag_id', $tag->id)
                    ->orWhere('action_tag_key', $tag->tag_key);
            })
            ->first();

        if ($existing instanceof SaScriptRequest) {
            return $existing;
        }

        return SaScriptRequest::query()->create([
            'request_id' => 'req_'.bin2hex(random_bytes(6)),
            'action_tag_id' => $tag->id,
            'action_tag_key' => $tag->tag_key,
            'site_key' => $site->key,
            'site_base_url' => $site->canonicalBaseUrl(),
            'path_key' => $path->key,
            'url_pattern' => $path->url_pattern,
            'path_inputs' => $path->path_inputs,
            'action_key' => null,
            'request_type' => SaScriptRequest::TYPE_CREATE,
            'status' => SaScriptRequest::STATUS_PENDING,
            'created_by_user_id' => $userId,
        ]);
    }

    private function pathAuthenticationColumnReady(): bool
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;

        try {
            return Schema::connection($conn)->hasTable('sa_paths')
                && Schema::connection($conn)->hasColumn('sa_paths', 'is_authentication');
        } catch (\Throwable) {
            return false;
        }
    }

    private function actionTagsSchemaReady(): bool
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;

        try {
            return Schema::connection($conn)->hasTable('sa_action_tags')
                && Schema::connection($conn)->hasColumn('sa_action_tags', 'site_id')
                && Schema::connection($conn)->hasColumn('sa_action_tags', 'path_id');
        } catch (\Throwable) {
            return false;
        }
    }

    private function tagEnabledColumnReady(): bool
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;

        try {
            return Schema::connection($conn)->hasTable('sa_action_tags')
                && Schema::connection($conn)->hasColumn('sa_action_tags', 'is_enabled');
        } catch (\Throwable) {
            return false;
        }
    }

    private function cmsRegistryTablesReady(): bool
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;

        try {
            return Schema::connection($conn)->hasTable('sa_cms_types')
                && Schema::connection($conn)->hasTable('sa_cms_path_templates');
        } catch (\Throwable) {
            return false;
        }
    }

    private function pathTemplateTrackingReady(): bool
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;

        try {
            return Schema::connection($conn)->hasTable('sa_paths')
                && Schema::connection($conn)->hasColumn('sa_paths', 'cms_template_id')
                && Schema::connection($conn)->hasColumn('sa_paths', 'is_override')
                && Schema::connection($conn)->hasColumn('sa_paths', 'template_version');
        } catch (\Throwable) {
            return false;
        }
    }
}
