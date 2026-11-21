<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers\ActionRegistry;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Models\SaCmsPathTemplate;
use Omnichannel\Addons\SocialAutomation\Models\SaCmsType;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Support\PathPatternResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

final class CmsRegistryController
{
    public function index(): JsonResponse
    {
        app(SocialAutomationAccess::class)->assertCanAccess();

        if (! $this->schemaReady()) {
            return response()->json(['ok' => false, 'error' => 'schema_unavailable'], 503);
        }

        $cmsTypes = SaCmsType::query()
            ->with(['pathTemplates' => fn ($q) => $q->orderBy('id')])
            ->orderBy('name')
            ->get()
            ->map(fn (SaCmsType $c) => $this->serializeCmsType($c))
            ->all();

        return response()->json(['ok' => true, 'cms_types' => $cmsTypes]);
    }

    public function store(Request $request): JsonResponse
    {
        app(SocialAutomationAccess::class)->assertCanMutate();

        if (! $this->schemaReady()) {
            return response()->json(['ok' => false, 'error' => 'schema_unavailable'], 503);
        }

        $name = trim((string) $request->input('name', ''));
        $rawKey = (string) $request->input('key', '');
        $key = $rawKey !== ''
            ? SaCmsType::normalizeKey($rawKey)
            : SaCmsType::normalizeKey(str_replace(' ', '_', $name));

        if ($name === '' || $key === '') {
            return response()->json(['ok' => false, 'error' => 'name_and_key_required'], 422);
        }

        if (SaCmsType::query()->where('key', $key)->exists()) {
            return response()->json(['ok' => false, 'error' => 'cms_type_key_exists'], 422);
        }

        $isEnabled = $request->exists('is_enabled')
            ? filter_var($request->input('is_enabled'), FILTER_VALIDATE_BOOLEAN)
            : true;
        $description = trim((string) $request->input('description', ''));
        $templatesInput = $request->input('path_templates', []);
        if (! is_array($templatesInput)) {
            $templatesInput = [];
        }

        $conn = SocialAutomationServiceConfig::CONNECTION;
        $cms = DB::connection($conn)->transaction(function () use ($key, $name, $description, $isEnabled, $templatesInput): SaCmsType {
            $cms = SaCmsType::query()->create([
                'key' => $key,
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'is_enabled' => $isEnabled,
            ]);

            foreach ($templatesInput as $tpl) {
                if (! is_array($tpl)) {
                    continue;
                }
                $tplName = trim((string) ($tpl['name'] ?? ''));
                $tplKey = SaCmsType::normalizeKey((string) ($tpl['key'] ?? str_replace(' ', '_', $tplName)));
                $urlPattern = trim((string) ($tpl['url_pattern'] ?? ''));
                if ($tplName === '' || $tplKey === '' || $urlPattern === '') {
                    continue;
                }
                if (! str_starts_with($urlPattern, '/')) {
                    $urlPattern = '/'.$urlPattern;
                }

                SaCmsPathTemplate::query()->create([
                    'cms_type_id' => $cms->id,
                    'cms_type_key' => $cms->key,
                    'key' => $tplKey,
                    'name' => $tplName,
                    'url_pattern' => $urlPattern,
                    'path_inputs' => PathPatternResolver::parseInputs($urlPattern),
                    'is_authentication' => filter_var($tpl['is_authentication'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'description' => isset($tpl['description']) && trim((string) $tpl['description']) !== ''
                        ? trim((string) $tpl['description'])
                        : null,
                    'version' => 1,
                ]);
            }

            return $cms->fresh('pathTemplates') ?? $cms;
        });

        return response()->json(['ok' => true, 'cms_type' => $this->serializeCmsType($cms)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        app(SocialAutomationAccess::class)->assertCanMutate();

        if (! $this->schemaReady()) {
            return response()->json(['ok' => false, 'error' => 'schema_unavailable'], 503);
        }

        $cms = SaCmsType::query()->find($id);
        if (! $cms) {
            return response()->json(['ok' => false, 'error' => 'cms_type_not_found'], 404);
        }

        $newKey = $request->exists('key') ? SaCmsType::normalizeKey((string) $request->input('key')) : $cms->key;
        if ($newKey === '') {
            return response()->json(['ok' => false, 'error' => 'key_required'], 422);
        }

        if ($newKey !== $cms->key) {
            $inUse = SaSite::query()->where('cms_type', $cms->key)->exists();
            if ($inUse) {
                return response()->json(['ok' => false, 'error' => 'cms_type_in_use'], 422);
            }
            if (SaCmsType::query()->where('key', $newKey)->where('id', '!=', $cms->id)->exists()) {
                return response()->json(['ok' => false, 'error' => 'cms_type_key_exists'], 422);
            }
        }

        if ($request->exists('name')) {
            $name = trim((string) $request->input('name', ''));
            if ($name === '') {
                return response()->json(['ok' => false, 'error' => 'name_required'], 422);
            }
            $cms->name = $name;
        }
        if ($request->exists('description')) {
            $desc = trim((string) $request->input('description', ''));
            $cms->description = $desc !== '' ? $desc : null;
        }
        if ($request->exists('is_enabled')) {
            $cms->is_enabled = filter_var($request->input('is_enabled'), FILTER_VALIDATE_BOOLEAN);
        }

        $conn = SocialAutomationServiceConfig::CONNECTION;
        DB::connection($conn)->transaction(function () use ($cms, $newKey): void {
            $oldKey = $cms->key;
            $cms->key = $newKey;
            $cms->save();

            if ($newKey !== $oldKey) {
                SaCmsPathTemplate::query()
                    ->where('cms_type_id', $cms->id)
                    ->update(['cms_type_key' => $newKey]);
            }
        });

        return response()->json([
            'ok' => true,
            'cms_type' => $this->serializeCmsType($cms->fresh('pathTemplates') ?? $cms),
        ]);
    }

    public function patch(Request $request, int $id): JsonResponse
    {
        app(SocialAutomationAccess::class)->assertCanMutate();

        if (! $this->schemaReady()) {
            return response()->json(['ok' => false, 'error' => 'schema_unavailable'], 503);
        }

        $cms = SaCmsType::query()->find($id);
        if (! $cms) {
            return response()->json(['ok' => false, 'error' => 'cms_type_not_found'], 404);
        }

        $raw = $request->exists('is_enabled')
            ? $request->input('is_enabled')
            : ($request->exists('enabled') ? $request->input('enabled') : null);
        $enabled = $raw !== null
            ? filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        if ($enabled === null) {
            return response()->json(['ok' => false, 'error' => 'is_enabled_required'], 422);
        }

        $cms->is_enabled = $enabled;
        $cms->save();

        return response()->json([
            'ok' => true,
            'cms_type' => $this->serializeCmsType($cms->fresh('pathTemplates') ?? $cms),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        app(SocialAutomationAccess::class)->assertCanMutate();

        if (! $this->schemaReady()) {
            return response()->json(['ok' => false, 'error' => 'schema_unavailable'], 503);
        }

        $cms = SaCmsType::query()->find($id);
        if (! $cms) {
            return response()->json(['ok' => false, 'error' => 'cms_type_not_found'], 404);
        }

        $sitesCount = SaSite::query()->where('cms_type', $cms->key)->count();
        $tplIds = SaCmsPathTemplate::query()->where('cms_type_id', $cms->id)->pluck('id')->all();
        $pathsCount = ($tplIds !== [] && Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasColumn('sa_paths', 'cms_template_id'))
            ? SaPath::query()->whereIn('cms_template_id', $tplIds)->count()
            : 0;

        if ($sitesCount > 0 || $pathsCount > 0) {
            return response()->json([
                'ok' => false,
                'error' => 'cms_type_in_use',
                'dependencies' => ['sites' => $sitesCount, 'paths' => $pathsCount],
            ], 422);
        }

        $conn = SocialAutomationServiceConfig::CONNECTION;
        DB::connection($conn)->transaction(function () use ($cms): void {
            SaCmsPathTemplate::query()->where('cms_type_id', $cms->id)->delete();
            $cms->delete();
        });

        return response()->json(['ok' => true]);
    }

    public function storeTemplate(Request $request, int $id): JsonResponse
    {
        app(SocialAutomationAccess::class)->assertCanMutate();

        if (! $this->schemaReady()) {
            return response()->json(['ok' => false, 'error' => 'schema_unavailable'], 503);
        }

        $cms = SaCmsType::query()->find($id);
        if (! $cms) {
            return response()->json(['ok' => false, 'error' => 'cms_type_not_found'], 404);
        }

        $name = trim((string) $request->input('name', ''));
        $rawKey = (string) $request->input('key', '');
        $key = $rawKey !== ''
            ? SaCmsType::normalizeKey($rawKey)
            : SaCmsType::normalizeKey(str_replace(' ', '_', $name));
        $urlPattern = trim((string) $request->input('url_pattern', ''));

        if ($name === '' || $key === '' || $urlPattern === '') {
            return response()->json(['ok' => false, 'error' => 'name_key_and_url_pattern_required'], 422);
        }
        if (! str_starts_with($urlPattern, '/')) {
            $urlPattern = '/'.$urlPattern;
        }

        if (SaCmsPathTemplate::query()->where('cms_type_id', $cms->id)->where('key', $key)->exists()) {
            return response()->json(['ok' => false, 'error' => 'cms_template_key_exists'], 422);
        }

        $description = trim((string) $request->input('description', ''));
        $template = SaCmsPathTemplate::query()->create([
            'cms_type_id' => $cms->id,
            'cms_type_key' => $cms->key,
            'key' => $key,
            'name' => $name,
            'url_pattern' => $urlPattern,
            'path_inputs' => PathPatternResolver::parseInputs($urlPattern),
            'is_authentication' => filter_var($request->input('is_authentication', false), FILTER_VALIDATE_BOOLEAN),
            'description' => $description !== '' ? $description : null,
            'version' => 1,
        ]);

        return response()->json(['ok' => true, 'template' => $this->serializeTemplate($template)]);
    }

    public function updateTemplate(Request $request, int $id, int $templateId): JsonResponse
    {
        app(SocialAutomationAccess::class)->assertCanMutate();

        if (! $this->schemaReady()) {
            return response()->json(['ok' => false, 'error' => 'schema_unavailable'], 503);
        }

        $template = SaCmsPathTemplate::query()->where('cms_type_id', $id)->find($templateId);
        if (! $template) {
            return response()->json(['ok' => false, 'error' => 'cms_template_not_found'], 404);
        }

        $bumpVersion = false;
        if ($request->exists('name')) {
            $name = trim((string) $request->input('name', ''));
            if ($name === '') {
                return response()->json(['ok' => false, 'error' => 'name_required'], 422);
            }
            if ($name !== $template->name) {
                $template->name = $name;
                $bumpVersion = true;
            }
        }
        if ($request->exists('url_pattern')) {
            $urlPattern = trim((string) $request->input('url_pattern', ''));
            if ($urlPattern === '') {
                return response()->json(['ok' => false, 'error' => 'url_pattern_required'], 422);
            }
            if (! str_starts_with($urlPattern, '/')) {
                $urlPattern = '/'.$urlPattern;
            }
            if ($urlPattern !== $template->url_pattern) {
                $template->url_pattern = $urlPattern;
                $template->path_inputs = PathPatternResolver::parseInputs($urlPattern);
                $bumpVersion = true;
            }
        }
        if ($request->exists('is_authentication')) {
            $isAuth = filter_var($request->input('is_authentication'), FILTER_VALIDATE_BOOLEAN);
            if ($isAuth !== (bool) $template->is_authentication) {
                $template->is_authentication = $isAuth;
                $bumpVersion = true;
            }
        }
        if ($request->exists('description')) {
            $desc = trim((string) $request->input('description', ''));
            $newDesc = $desc !== '' ? $desc : null;
            if ($newDesc !== $template->description) {
                $template->description = $newDesc;
                $bumpVersion = true;
            }
        }

        if ($bumpVersion) {
            $template->version = (int) ($template->version ?? 1) + 1;
        }
        $template->save();

        return response()->json([
            'ok' => true,
            'template' => $this->serializeTemplate($template->fresh() ?? $template),
        ]);
    }

    public function destroyTemplate(int $id, int $templateId): JsonResponse
    {
        app(SocialAutomationAccess::class)->assertCanMutate();

        if (! $this->schemaReady()) {
            return response()->json(['ok' => false, 'error' => 'schema_unavailable'], 503);
        }

        $template = SaCmsPathTemplate::query()->where('cms_type_id', $id)->find($templateId);
        if (! $template) {
            return response()->json(['ok' => false, 'error' => 'cms_template_not_found'], 404);
        }

        $inUseCount = Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasColumn('sa_paths', 'cms_template_id')
            ? SaPath::query()->where('cms_template_id', $template->id)->count()
            : 0;

        if ($inUseCount > 0) {
            return response()->json([
                'ok' => false,
                'error' => 'cms_template_in_use',
                'dependencies' => ['paths' => $inUseCount],
            ], 422);
        }

        $template->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeCmsType(SaCmsType $cms): array
    {
        $templates = $cms->relationLoaded('pathTemplates')
            ? $cms->pathTemplates
            : $cms->pathTemplates()->orderBy('id')->get();

        $sitesCount = SaSite::query()->where('cms_type', $cms->key)->count();

        return [
            'id' => (int) $cms->id,
            'key' => $cms->key,
            'name' => $cms->name,
            'description' => $cms->description,
            'is_enabled' => (bool) $cms->is_enabled,
            'sites_count' => $sitesCount,
            'path_templates' => $templates->map(fn (SaCmsPathTemplate $t) => $this->serializeTemplate($t))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTemplate(SaCmsPathTemplate $t): array
    {
        return [
            'id' => (int) $t->id,
            'cms_type_id' => (int) $t->cms_type_id,
            'cms_type_key' => $t->cms_type_key,
            'key' => $t->key,
            'name' => $t->name,
            'url_pattern' => $t->url_pattern,
            'path_inputs' => is_array($t->path_inputs)
                ? $t->path_inputs
                : PathPatternResolver::parseInputs($t->url_pattern ?? ''),
            'is_authentication' => (bool) $t->is_authentication,
            'description' => $t->description,
            'version' => (int) ($t->version ?? 1),
        ];
    }

    private function schemaReady(): bool
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;

        try {
            return Schema::connection($conn)->hasTable('sa_cms_types')
                && Schema::connection($conn)->hasTable('sa_cms_path_templates');
        } catch (\Throwable) {
            return false;
        }
    }
}
