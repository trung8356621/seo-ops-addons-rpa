<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\SocialAutomationPage as Access;
use Omnichannel\Addons\SocialAutomation\Models\SaAction;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTagScript;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptRequest;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Support\PathPatternResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

final class SocialAutomationActionTagsPage extends Page
{
    use Access;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $slug = 'action-tags';

    protected static ?int $navigationSort = 9;

    protected static string $view = 'social-automation::filament.pages.action-tags';

    public static function getNavigationLabel(): string
    {
        return __('social-automation::filament.action_tags.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('social-automation::filament.action_tags.nav');
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    /**
     * Build initial data for the React Action Registry app.
     *
     * @return array{
     *     sites: list<array<string, mixed>>,
     *     paths: list<array<string, mixed>>,
     *     actions: list<array<string, mixed>>,
     *     tags: list<array<string, mixed>>,
     *     requests: list<array<string, mixed>>
     * }
     */
    public function initialData(): array
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;
        if (! Schema::connection($conn)->hasTable('sa_sites')) {
            return [
                'sites' => [],
                'paths' => [],
                'actions' => [],
                'tags' => [],
                'requests' => [],
            ];
        }

        $sites = SaSite::query()->with('paths')->orderBy('name')->get()->map(function (SaSite $s) {
            return [
                'id' => (int) $s->id,
                'key' => $s->key,
                'name' => $s->name,
                'base_url' => $s->base_url,
                'description' => $s->description,
                'paths_count' => $s->paths->count(),
            ];
        })->all();

        $paths = SaPath::query()->orderBy('site_id')->orderBy('name')->get()->map(function (SaPath $p) {
            $inputs = is_array($p->path_inputs) ? $p->path_inputs : PathPatternResolver::parseInputs($p->url_pattern ?? '');
            return [
                'id' => (int) $p->id,
                'site_id' => (int) $p->site_id,
                'site_key' => $p->site_key,
                'key' => $p->key,
                'name' => $p->name,
                'url_pattern' => $p->url_pattern,
                'path_inputs' => $inputs,
                'description' => $p->description,
            ];
        })->all();

        $actions = SaAction::query()
            ->whereNotIn('key', ['like', 'share'])
            ->orderBy('name')
            ->get()
            ->map(fn (SaAction $a) => [
                'id' => (int) $a->id,
                'key' => $a->key,
                'name' => $a->name,
                'description' => $a->description,
            ])->all();

        $tagQuery = SaActionTag::query()->with(['site', 'path', 'actionDefinition'])->orderByDesc('id');
        $tags = $tagQuery->get()->map(function (SaActionTag $t) use ($conn) {
            $hasScriptLinks = Schema::connection($conn)->hasTable('sa_action_tag_scripts');
            $linksCount = $hasScriptLinks
                ? SaActionTagScript::query()->where('action_tag_id', $t->id)->count()
                : 0;

            $hasRequests = Schema::connection($conn)->hasTable('sa_script_requests');
            $latestRequest = $hasRequests
                ? SaScriptRequest::query()->where('action_tag_id', $t->id)->orderByDesc('id')->first()
                : null;

            $siteKey = $t->site_key ?: $t->platform;
            $pathKey = $t->path_key ?: $t->target;
            $actionKey = $t->action_key ?: $t->action;

            $siteName = $t->site?->name ?? ucfirst((string) $siteKey);
            $pathName = $t->path?->name ?? ucfirst((string) $pathKey);
            $actionName = $t->actionDefinition?->name ?? ucfirst((string) $actionKey);

            return [
                'id' => (int) $t->id,
                'tag_key' => $t->tag_key,
                'site_id' => $t->site_id,
                'site_key' => $siteKey,
                'site_name' => $siteName,
                'path_id' => $t->path_id,
                'path_key' => $pathKey,
                'path_name' => $pathName,
                'action_id' => $t->action_id,
                'action_key' => $actionKey,
                'action_name' => $actionName,
                'name' => $t->name,
                'description' => $t->description,
                'scripts_count' => $linksCount,
                'ready' => $linksCount > 0,
                'latest_request_status' => $latestRequest?->status,
                'latest_request_id' => $latestRequest?->request_id,
            ];
        })->all();

        $requests = Schema::connection($conn)->hasTable('sa_script_requests')
            ? SaScriptRequest::query()->orderByDesc('id')->limit(50)->get()->map(fn (SaScriptRequest $r) => [
                'id' => (int) $r->id,
                'request_id' => $r->request_id,
                'action_tag_key' => $r->action_tag_key,
                'request_type' => $r->request_type,
                'status' => $r->status,
                'script_id' => $r->script_id,
                'script_version' => $r->script_version,
                'error_reason' => $r->error_reason,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->all()
            : [];

        return [
            'sites' => $sites,
            'paths' => $paths,
            'actions' => $actions,
            'tags' => $tags,
            'requests' => $requests,
        ];
    }
}
