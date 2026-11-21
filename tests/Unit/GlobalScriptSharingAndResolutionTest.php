<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use App\Models\User;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ActionRegistry\ActionRegistryController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\RpaAdmin\RpaAdminScriptSyncController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ScriptRegistry\StaffScriptRegistryController;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTagScript;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCmsPathTemplate;
use Omnichannel\Addons\SocialAutomation\Models\SaCmsType;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaJobAttempt;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackageDigest;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackageRegistryService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptRegistryUiService;
use Omnichannel\Addons\SocialAutomation\Services\ActionScriptDistributionService;
use Omnichannel\Addons\SocialAutomation\Services\AuthScriptService;
use Omnichannel\Addons\SocialAutomation\Services\CampaignService;
use Omnichannel\Addons\SocialAutomation\Services\CmsPathTemplateService;
use Omnichannel\Addons\SocialAutomation\Services\ScriptResolutionService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use PHPUnit\Framework\TestCase;

final class GlobalScriptSharingAndResolutionTest extends TestCase
{
    private Capsule $capsule;

    private ?Container $previousApp = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite unavailable');
        }

        $this->previousApp = Facade::getFacadeApplication() instanceof Container
            ? Facade::getFacadeApplication()
            : null;

        $app = new Container;
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $this->capsule = new Capsule($app);
        $this->capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ], SocialAutomationServiceConfig::CONNECTION);
        $this->capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ], 'default');
        $this->capsule->setEventDispatcher(new Dispatcher($app));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $app->instance('db', $this->capsule->getDatabaseManager());
        $app->instance('auth', new class {
            public function id(): int
            {
                return 7;
            }
        });
        $app->instance(SocialAutomationAccess::class, new class extends SocialAutomationAccess {
            public function __construct() {}

            public function assertCanAccess(?User $user = null): void {}

            public function assertCanMutate(?User $user = null): void {}
        });
        $app->instance(AuthScriptService::class, new AuthScriptService);
        $app->instance(CmsPathTemplateService::class, new CmsPathTemplateService);
        $app->instance(ScriptResolutionService::class, new ScriptResolutionService);
        $app->instance(ActionScriptDistributionService::class, new ActionScriptDistributionService);
        $app->instance(ScriptRegistryUiService::class, new ScriptRegistryUiService);
        $app->instance(ResponseFactory::class, new class implements ResponseFactory {
            public function json($data = [], $status = 200, array $headers = [], $options = 0): JsonResponse
            {
                return new JsonResponse($data, $status, $headers, $options);
            }

            public function make($content = '', $status = 200, array $headers = [])
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function noContent($status = 204, array $headers = [])
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function view($view, $data = [], $status = 200, array $headers = [])
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function jsonp($callback, $data = [], $status = 200, array $headers = [], $options = 0)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function stream($callback, $status = 200, array $headers = [])
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function streamJson($data, $status = 200, $headers = [], $encodingOptions = 0)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function streamDownload($callback, $name = null, array $headers = [], $disposition = 'attachment')
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function download($file, $name = null, array $headers = [], $disposition = 'attachment')
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function file($file, array $headers = [])
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function redirectTo($path, $status = 302, $headers = [], $secure = null)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function redirectToRoute($route, $parameters = [], $status = 302, $headers = [])
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function redirectToAction($action, $parameters = [], $status = 302, $headers = [])
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function redirectGuest($path, $status = 302, $headers = [], $secure = null)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function redirectToIntended($default = '/', $status = 302, $headers = [], $secure = null)
            {
                throw new \BadMethodCallException(__METHOD__);
            }
        });

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        if ($this->previousApp instanceof Container) {
            Container::setInstance($this->previousApp);
            Facade::setFacadeApplication($this->previousApp);
        }
        parent::tearDown();
    }

    private function createSchema(): void
    {
        $schema = Schema::connection(SocialAutomationServiceConfig::CONNECTION);

        $schema->create('sa_cms_types', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name', 128);
            $table->text('description')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        $schema->create('sa_cms_path_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cms_type_id');
            $table->string('cms_type_key', 64);
            $table->string('key', 64);
            $table->string('name', 128);
            $table->string('url_pattern', 255);
            $table->json('path_inputs')->nullable();
            $table->boolean('is_authentication')->default(false);
            $table->text('description')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['cms_type_id', 'key']);
        });

        $schema->create('sa_sites', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name', 128);
            $table->string('base_url', 255);
            $table->string('base_url_mode', 32)->default('fixed');
            $table->string('cms_type', 32)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        $schema->create('sa_paths', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('site_key', 64);
            $table->unsignedBigInteger('cms_template_id')->nullable();
            $table->string('key', 64);
            $table->string('name', 128);
            $table->string('url_pattern', 255);
            $table->json('path_inputs')->nullable();
            $table->boolean('is_authentication')->default(false);
            $table->boolean('is_override')->default(false);
            $table->unsignedInteger('template_version')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'key']);
        });

        $schema->create('sa_actions', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name', 128);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        $schema->create('sa_action_tags', function (Blueprint $table): void {
            $table->id();
            $table->string('tag_key', 128)->unique();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('site_key', 64)->nullable();
            $table->unsignedBigInteger('path_id')->nullable();
            $table->string('path_key', 64)->nullable();
            $table->unsignedBigInteger('action_id')->nullable();
            $table->string('action_key', 64)->nullable();
            $table->string('platform', 64);
            $table->string('target', 64);
            $table->string('action', 64)->default('');
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedBigInteger('auth_script_id')->nullable();
            $table->unsignedBigInteger('auth_release_id')->nullable();
            $table->timestamps();
        });

        $schema->create('sa_packages', function (Blueprint $table): void {
            $table->id();
            $table->string('package_key', 128)->unique();
            $table->string('kind', 32);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_authentication')->default(false);
            $table->string('sharing_mode', 32)->default('site_specific');
            $table->string('global_cms_type', 64)->nullable();
            $table->unsignedBigInteger('global_cms_template_id')->nullable();
            $table->string('global_cms_path_key', 64)->nullable();
            $table->string('global_scope_key', 191)->nullable()->unique('sa_packages_global_scope_unique');
            $table->string('disabled_reason', 64)->nullable();
            $table->json('disabled_meta')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->string('sync_source', 64)->nullable();
            $table->timestamps();
        });

        $schema->create('sa_package_releases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('package_id');
            $table->string('version', 64);
            $table->string('content_digest', 64);
            $table->string('execution_digest', 64)->nullable();
            $table->unsignedInteger('schema_version')->default(1);
            $table->json('runtime_compatibility')->nullable();
            $table->json('manifest');
            $table->string('status', 32)->default('published');
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('sync_source', 64)->nullable();
            $table->timestamps();
            $table->unique(['package_id', 'version']);
        });

        $schema->create('sa_package_dependencies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('release_id');
            $table->string('dependency_package_key', 128);
            $table->string('dependency_version', 64);
            $table->string('dependency_digest', 64);
            $table->timestamps();
        });

        $schema->create('sa_action_tag_scripts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('action_tag_id');
            $table->string('action_tag_key', 128);
            $table->unsignedBigInteger('package_id')->nullable();
            $table->unsignedBigInteger('release_id')->nullable();
            $table->string('script_id', 128);
            $table->string('script_version', 64)->nullable();
            $table->timestamps();
        });

        $schema->create('sa_script_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_id', 64)->unique();
            $table->unsignedBigInteger('action_tag_id');
            $table->string('action_tag_key', 128);
            $table->string('site_key', 64)->nullable();
            $table->string('site_base_url', 255)->nullable();
            $table->string('path_key', 64)->nullable();
            $table->string('url_pattern', 255)->nullable();
            $table->json('path_inputs')->nullable();
            $table->string('action_key', 64)->nullable();
            $table->string('request_type', 32);
            $table->string('status', 32)->default('pending');
            $table->string('script_id', 128)->nullable();
            $table->string('script_version', 64)->nullable();
            $table->text('error_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
        });

        $schema->create('sa_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('account_uuid')->unique();
            $table->string('installation_id', 128);
            $table->string('platform', 64);
            $table->string('username', 191)->nullable();
            $table->string('site_url', 255)->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();
        });

        $schema->create('sa_agents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('agent_uuid')->unique();
            $table->string('installation_id', 128);
            $table->string('availability', 32)->default('online');
            $table->unsignedInteger('active_jobs')->default(0);
            $table->unsignedInteger('max_concurrency')->default(2);
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamps();
        });

        $schema->create('sa_browser_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('agent_id');
            $table->string('profile_key', 128)->nullable();
            $table->string('ready_status', 32)->default('ready');
            $table->timestamps();
        });

        $schema->create('sa_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->uuid('campaign_uuid')->unique();
            $table->string('installation_id', 128);
            $table->string('name', 255);
            $table->string('platform', 64);
            $table->string('execution_mode', 32);
            $table->string('run_mode', 16)->default('live');
            $table->string('status', 32)->default('draft');
            $table->string('script_id', 128);
            $table->string('script_version', 64);
            $table->string('script_content_digest', 64)->nullable();
            $table->unsignedInteger('resource_recheck_seconds')->default(3600);
            $table->timestamp('next_recheck_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->json('shortcode_mapping')->nullable();
            $table->unsignedInteger('shortcode_mapping_version')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        $schema->create('sa_campaign_contents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('content_uuid')->unique();
            $table->unsignedBigInteger('campaign_id');
            $table->string('external_ref', 191)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 32)->default('pending');
            $table->json('input')->nullable();
            $table->string('source_format', 32)->default('legacy_input');
            $table->text('raw_source')->nullable();
            $table->unsignedInteger('mapping_version')->nullable();
            $table->unsignedBigInteger('assigned_account_id')->nullable();
            $table->unsignedBigInteger('current_job_id')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->text('last_error_message')->nullable();
            $table->json('last_error_meta')->nullable();
            $table->string('admin_action_idempotency', 64)->nullable();
            $table->timestamp('admin_action_at')->nullable();
            $table->timestamps();
        });

        $schema->create('sa_campaign_account_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('content_id');
            $table->string('reason', 64)->default('dispatch');
            $table->timestamps();
        });

        $schema->create('sa_tasks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('task_uuid')->unique();
            $table->timestamps();
        });

        $schema->create('sa_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('job_uuid')->unique();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->unsignedBigInteger('campaign_content_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('browser_session_id')->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('package_key', 128);
            $table->string('package_version', 64);
            $table->string('content_digest', 64)->nullable();
            $table->string('status', 32)->default('reserved');
            $table->string('idempotency_key', 128)->unique();
            $table->string('lease_token', 64)->nullable();
            $table->unsignedInteger('fencing_generation')->default(1);
            $table->json('payload')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->string('dispatch_source', 32)->nullable();
            $table->timestamps();
        });

        $schema->create('sa_job_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('attempt_uuid')->unique();
            $table->string('lease_token', 64)->nullable();
            $table->unsignedBigInteger('job_id');
            $table->unsignedInteger('attempt_no')->default(1);
            $table->unsignedInteger('fencing_generation')->default(1);
            $table->string('status', 32)->default('queued');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('browser_session_id')->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Seed WordPress + Ghost CMS types, 3 WordPress sites (mayhopphat, maytuicanvas, congtybalo),
     * 1 Ghost site, and published script mayhopphat.create_post v1.
     *
     * @return array{wpCreateTpl: SaCmsPathTemplate, wpLoginTpl: SaCmsPathTemplate, ghostCreateTpl: SaCmsPathTemplate, mayhopphatPkg: SaPackage, mayhopphatRel: SaPackageRelease}
     */
    private function seedScenario(): array
    {
        $wp = SaCmsType::query()->create(['key' => 'wordpress', 'name' => 'WordPress', 'is_enabled' => true]);
        $wpCreateTpl = SaCmsPathTemplate::query()->create([
            'cms_type_id' => $wp->id,
            'cms_type_key' => 'wordpress',
            'key' => 'create_post',
            'name' => 'Create Post',
            'url_pattern' => '/wp-admin/post-new.php',
            'is_authentication' => false,
            'version' => 1,
        ]);
        $wpLoginTpl = SaCmsPathTemplate::query()->create([
            'cms_type_id' => $wp->id,
            'cms_type_key' => 'wordpress',
            'key' => 'login',
            'name' => 'Login',
            'url_pattern' => '/wp-login.php',
            'is_authentication' => true,
            'version' => 1,
        ]);

        $ghost = SaCmsType::query()->create(['key' => 'ghost', 'name' => 'Ghost', 'is_enabled' => true]);
        $ghostCreateTpl = SaCmsPathTemplate::query()->create([
            'cms_type_id' => $ghost->id,
            'cms_type_key' => 'ghost',
            'key' => 'create_post',
            'name' => 'Create Post',
            'url_pattern' => '/ghost/#/editor/post',
            'is_authentication' => false,
            'version' => 1,
        ]);

        $cmsService = app(CmsPathTemplateService::class);

        $mayhopphat = SaSite::query()->create([
            'key' => 'mayhopphat',
            'name' => 'May Hop Phat',
            'base_url' => 'https://mayhopphat.vn',
            'cms_type' => 'wordpress',
        ]);
        $cmsService->syncSiteCmsPathsAndTags($mayhopphat);

        $maytuicanvas = SaSite::query()->create([
            'key' => 'maytuicanvas',
            'name' => 'May Tui Canvas',
            'base_url' => 'https://maytuicanvas.vn',
            'cms_type' => 'wordpress',
        ]);
        // Site-specific path override for create_post (still linked to wpCreateTpl)
        $cmsService->syncSiteCmsPathsAndTags($maytuicanvas, [
            'create_post' => ['url_pattern' => '/custom-admin/post-new.php'],
        ]);

        $congtybalo = SaSite::query()->create([
            'key' => 'congtybalo',
            'name' => 'Cong Ty Balo',
            'base_url' => 'https://congtybalo.vn',
            'cms_type' => 'wordpress',
        ]);
        $cmsService->syncSiteCmsPathsAndTags($congtybalo);

        $ghostSite = SaSite::query()->create([
            'key' => 'ghostblog',
            'name' => 'Ghost Blog',
            'base_url' => 'https://ghostblog.example',
            'cms_type' => 'ghost',
        ]);
        $cmsService->syncSiteCmsPathsAndTags($ghostSite);

        // Publish mayhopphat.create_post v1
        [$mayhopphatPkg, $mayhopphatRel] = $this->publishScript('mayhopphat.create_post', 'v1', 'mayhopphat.create_post');

        return compact('wpCreateTpl', 'wpLoginTpl', 'ghostCreateTpl', 'mayhopphatPkg', 'mayhopphatRel');
    }

    /**
     * @return array{0: SaPackage, 1: SaPackageRelease}
     */
    private function publishScript(string $packageKey, string $version, string $tagKey, bool $isAuth = false): array
    {
        $manifest = [
            'script_id' => $packageKey,
            'action_tag_key' => $tagKey,
            'variables' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'string', 'required' => true],
            ],
            'steps' => [
                ['action' => 'navigate', 'url' => '{{base_url}}/wp-admin/post-new.php'],
            ],
        ];
        $digest = PackageDigest::sha256OfCanonicalJson($manifest);

        $pkg = SaPackage::query()->create([
            'package_key' => $packageKey,
            'kind' => 'script',
            'name' => $packageKey,
            'is_enabled' => true,
            'is_authentication' => $isAuth,
            'sharing_mode' => SaPackage::SHARING_SITE_SPECIFIC,
        ]);

        $rel = SaPackageRelease::query()->create([
            'package_id' => $pkg->id,
            'version' => $version,
            'content_digest' => $digest,
            'schema_version' => 1,
            'manifest' => $manifest,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $tag = SaActionTag::query()->where('tag_key', $tagKey)->firstOrFail();
        SaActionTagScript::query()->create([
            'action_tag_id' => $tag->id,
            'action_tag_key' => $tag->tag_key,
            'package_id' => $pkg->id,
            'release_id' => $rel->id,
            'script_id' => $packageKey,
            'script_version' => $version,
        ]);

        return [$pkg, $rel];
    }

    public function test_1_to_7_resolution_priority_scope_isolation_and_overrides(): void
    {
        $seed = $this->seedScenario();
        $resolver = app(ScriptResolutionService::class);
        $ctrl = new StaffScriptRegistryController(app(ScriptRegistryUiService::class), app(SocialAutomationAccess::class), $resolver);

        // 7. Global disabled (default site_specific) returns Missing Script for other WP sites
        $beforeGlobal = $resolver->resolveForTagKey('maytuicanvas.create_post');
        self::assertFalse($beforeGlobal['resolved']);
        self::assertSame(ScriptResolutionService::SOURCE_MISSING, $beforeGlobal['source']);

        // 1. Existing Site-specific Script resolves normally
        $local = $resolver->resolveForTagKey('mayhopphat.create_post');
        self::assertTrue($local['resolved']);
        self::assertSame(ScriptResolutionService::SOURCE_SITE_SPECIFIC, $local['source']);
        self::assertSame('mayhopphat.create_post', $local['script']['package_key']);
        self::assertSame('v1', $local['script']['version']);

        // Enable CMS Global via StaffScriptRegistryController
        $req = Request::create('/social-automation/api/script-registry/mayhopphat.create_post/sharing', 'PATCH', [
            'sharing_mode' => 'cms_global',
        ]);
        $resp = $ctrl->updateSharing($req, 'mayhopphat.create_post');
        self::assertSame(200, $resp->getStatusCode());
        $data = $resp->getData(true);
        self::assertTrue($data['ok']);
        self::assertSame('cms_global', $data['sharing_mode']);
        self::assertSame('wordpress:create_post', $data['global_scope']['scope_key']);

        // 2. Global WordPress create_post resolves for maytuicanvas (even with path URL override!) and congtybalo
        $resTui = $resolver->resolveForTagKey('maytuicanvas.create_post');
        self::assertTrue($resTui['resolved']);
        self::assertSame(ScriptResolutionService::SOURCE_CMS_GLOBAL, $resTui['source']);
        self::assertSame('mayhopphat.create_post', $resTui['script']['package_key']);
        self::assertSame('v1', $resTui['script']['version']);
        self::assertSame('mayhopphat.create_post', $resTui['source_action_tag_key']);
        self::assertSame('wordpress:create_post', $resTui['cms_scope']['scope_key']);

        $resBalo = $resolver->resolveForTagKey('congtybalo.create_post');
        self::assertTrue($resBalo['resolved']);
        self::assertSame(ScriptResolutionService::SOURCE_CMS_GLOBAL, $resBalo['source']);
        self::assertSame('mayhopphat.create_post', $resBalo['script']['package_key']);

        // 3. Does NOT resolve across different CMS Types (ghostblog.create_post)
        $resGhost = $resolver->resolveForTagKey('ghostblog.create_post');
        self::assertFalse($resGhost['resolved']);
        self::assertSame(ScriptResolutionService::SOURCE_MISSING, $resGhost['source']);

        // 4. Does NOT resolve across different Path Templates (maytuicanvas.login or custom arbitrary path)
        $resLogin = $resolver->resolveForTagKey('maytuicanvas.login');
        self::assertFalse($resLogin['resolved']);
        self::assertSame(ScriptResolutionService::SOURCE_MISSING, $resLogin['source']);

        $wpSite = SaSite::query()->where('key', 'maytuicanvas')->firstOrFail();
        $customPath = SaPath::query()->create([
            'site_id' => $wpSite->id,
            'site_key' => 'maytuicanvas',
            'cms_template_id' => null,
            'key' => 'custom_post',
            'name' => 'Custom Post',
            'url_pattern' => '/wp-admin/post-new.php',
        ]);
        $resCustom = $resolver->resolveForSiteAndPath($wpSite, $customPath);
        self::assertFalse($resCustom['resolved']);
        self::assertSame(ScriptResolutionService::SOURCE_MISSING, $resCustom['source']);

        // 5. Disabled target Tag blocks Global fallback
        SaActionTag::query()->where('tag_key', 'congtybalo.create_post')->update(['is_enabled' => false]);
        $resDisabled = $resolver->resolveForTagKey('congtybalo.create_post');
        self::assertFalse($resDisabled['resolved']);
        self::assertFalse($resDisabled['tag_enabled']);
        self::assertSame('action_tag_disabled', $resDisabled['reason']);
        self::assertSame(ScriptResolutionService::SOURCE_MISSING, $resDisabled['source']);

        // Re-enable congtybalo.create_post and publish a Site-specific script for congtybalo
        SaActionTag::query()->where('tag_key', 'congtybalo.create_post')->update(['is_enabled' => true]);
        $this->publishScript('congtybalo.create_post', 'v2', 'congtybalo.create_post');

        // 6. Site-specific Script overrides Global ONLY for its own Site
        $resBaloOverride = $resolver->resolveForTagKey('congtybalo.create_post');
        self::assertTrue($resBaloOverride['resolved']);
        self::assertSame(ScriptResolutionService::SOURCE_SITE_SPECIFIC, $resBaloOverride['source']);
        self::assertSame('congtybalo.create_post', $resBaloOverride['script']['package_key']);
        self::assertSame('v2', $resBaloOverride['script']['version']);

        // maytuicanvas still uses Global!
        $resTuiAfter = $resolver->resolveForTagKey('maytuicanvas.create_post');
        self::assertTrue($resTuiAfter['resolved']);
        self::assertSame(ScriptResolutionService::SOURCE_CMS_GLOBAL, $resTuiAfter['source']);
        self::assertSame('mayhopphat.create_post', $resTuiAfter['script']['package_key']);

        // Disable Global -> maytuicanvas returns Missing Script again
        $respOff = $ctrl->updateSharing(
            Request::create('/social-automation/api/script-registry/mayhopphat.create_post/sharing', 'PATCH', ['sharing_mode' => 'site_specific']),
            'mayhopphat.create_post',
        );
        self::assertSame(200, $respOff->getStatusCode());
        $resTuiOff = $resolver->resolveForTagKey('maytuicanvas.create_post');
        self::assertFalse($resTuiOff['resolved']);
        self::assertSame(ScriptResolutionService::SOURCE_MISSING, $resTuiOff['source']);
    }

    public function test_8_multiple_global_candidates_cannot_silently_conflict(): void
    {
        $this->seedScenario();
        $this->publishScript('congtybalo.create_post', 'v1', 'congtybalo.create_post');

        $resolver = app(ScriptResolutionService::class);
        $ctrl = new StaffScriptRegistryController(app(ScriptRegistryUiService::class), app(SocialAutomationAccess::class), $resolver);

        $r1 = $ctrl->updateSharing(
            Request::create('/social-automation/api/script-registry/mayhopphat.create_post/sharing', 'PATCH', ['sharing_mode' => 'cms_global']),
            'mayhopphat.create_post',
        );
        self::assertSame(200, $r1->getStatusCode());

        $r2 = $ctrl->updateSharing(
            Request::create('/social-automation/api/script-registry/congtybalo.create_post/sharing', 'PATCH', ['sharing_mode' => 'cms_global']),
            'congtybalo.create_post',
        );
        self::assertSame(409, $r2->getStatusCode());
        $data = $r2->getData(true);
        self::assertFalse($data['ok']);
        self::assertSame('global_scope_conflict', $data['error']);
        self::assertSame('mayhopphat.create_post', $data['conflict']['package_key']);
    }

    public function test_9_10_11_campaign_pinning_client_pull_and_rpa_admin_unchanged(): void
    {
        $seed = $this->seedScenario();
        $resolver = app(ScriptResolutionService::class);
        $ctrl = new StaffScriptRegistryController(app(ScriptRegistryUiService::class), app(SocialAutomationAccess::class), $resolver);

        // Mark mayhopphat.create_post as Global
        $ctrl->updateSharing(
            Request::create('/social-automation/api/script-registry/mayhopphat.create_post/sharing', 'PATCH', ['sharing_mode' => 'cms_global']),
            'mayhopphat.create_post',
        );

        // 11. RPA Admin still sees maytuicanvas.create_post and congtybalo.create_post WITHOUT their own Published Scripts
        $rpaSync = app(RpaAdminScriptSyncController::class);
        $tagsResp = $rpaSync->actionTags()->getData(true);
        $tagsByKey = [];
        foreach ($tagsResp['action_tags'] as $t) {
            $tagsByKey[$t['tag_key']] = $t;
        }
        self::assertSame(1, $tagsByKey['mayhopphat.create_post']['linked_scripts_count']);
        self::assertSame(0, $tagsByKey['maytuicanvas.create_post']['linked_scripts_count']);
        self::assertSame(0, $tagsByKey['congtybalo.create_post']['linked_scripts_count']);

        // Staff ActionRegistryController reports effective_ready + resolution_source
        $arResp = (new ActionRegistryController)->data(Request::create('/social-automation/api/action-registry/data', 'GET'))->getData(true);
        $arTags = [];
        foreach ($arResp['tags'] as $t) {
            $arTags[$t['tag_key']] = $t;
        }
        self::assertSame('site_specific', $arTags['mayhopphat.create_post']['resolution_source']);
        self::assertSame('cms_global', $arTags['maytuicanvas.create_post']['resolution_source']);
        self::assertTrue($arTags['maytuicanvas.create_post']['effective_ready']);
        self::assertFalse($arTags['maytuicanvas.create_post']['ready']); // own script count remains 0

        // 9. Campaign created for maytuicanvas.create_post pins actual mayhopphat.create_post v1 + resolved context
        $campaigns = app(CampaignService::class);
        $created = $campaigns->create([
            'name' => 'Canvas WP Post',
            'platform' => 'maytuicanvas',
            'execution_mode' => 'one_account_one_job',
            'run_mode' => 'live',
            'script_id' => 'maytuicanvas.create_post',
            'contents' => [
                ['input' => ['title' => 'Hello', 'body' => 'World']],
            ],
        ], 'inst-1', 7);

        self::assertTrue($created['ok'] ?? false, json_encode($created));
        $campaign = SaCampaign::query()->where('campaign_uuid', $created['data']['id'])->firstOrFail();
        self::assertSame('mayhopphat.create_post', $campaign->script_id);
        self::assertSame('v1', $campaign->script_version);
        self::assertSame('cms_global', $campaign->meta['resolved_script_context']['resolution_source']);
        self::assertSame('maytuicanvas.create_post', $campaign->meta['resolved_script_context']['target_action_tag_key']);
        self::assertSame('mayhopphat.create_post', $campaign->meta['resolved_script_context']['source_action_tag_key']);

        // 10. Client Script Pull retrieves actual Published Release (mayhopphat.create_post v1) for the pinned Job
        $account = SaAccount::query()->create([
            'account_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-1',
            'platform' => 'maytuicanvas',
            'username' => 'canvas_user',
        ]);
        $agent = SaAgent::query()->create([
            'agent_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-1',
            'availability' => 'online',
            'last_heartbeat_at' => now(),
        ]);
        $session = SaBrowserSession::query()->create([
            'agent_id' => $agent->id,
            'profile_key' => 'prof-1',
            'ready_status' => 'ready',
        ]);

        $dist = app(ActionScriptDistributionService::class);
        $pin = $dist->resolvePublishedPin((string) $campaign->script_id, (string) $campaign->script_version, (string) $campaign->script_content_digest);
        self::assertNotNull($pin);

        $attemptUuid = (string) Str::uuid();
        $leaseToken = 'lease-tok-123';
        $job = SaJob::query()->create([
            'job_uuid' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'agent_id' => $agent->id,
            'browser_session_id' => $session->id,
            'account_id' => $account->id,
            'package_key' => $pin['package_key'],
            'package_version' => $pin['version'],
            'content_digest' => $pin['content_digest'],
            'status' => 'reserved',
            'idempotency_key' => 'idem-1',
            'lease_token' => $leaseToken,
            'fencing_generation' => 1,
            'lease_expires_at' => now()->addMinutes(5),
            'payload' => [
                'installation_id' => 'inst-1',
                'script_pin' => $pin,
                '_fence' => ['attempt_uuid' => $attemptUuid],
            ],
        ]);
        SaJobAttempt::query()->create([
            'attempt_uuid' => $attemptUuid,
            'lease_token' => $leaseToken,
            'job_id' => $job->id,
            'attempt_no' => 1,
            'fencing_generation' => 1,
            'status' => 'queued',
            'agent_id' => $agent->id,
            'browser_session_id' => $session->id,
            'account_id' => $account->id,
            'lease_expires_at' => now()->addMinutes(5),
        ]);

        // Even if operator disables Global NOW, historical pinned Campaign & Job still pull v1 cleanly!
        $ctrl->updateSharing(
            Request::create('/social-automation/api/script-registry/mayhopphat.create_post/sharing', 'PATCH', ['sharing_mode' => 'site_specific']),
            'mayhopphat.create_post',
        );

        $pulled = $dist->pullForJob(
            'inst-1',
            $job->job_uuid,
            $attemptUuid,
            $leaseToken,
            $account->account_uuid,
            'mayhopphat.create_post',
            'v1',
            (int) $seed['mayhopphatRel']->id,
        );
        self::assertTrue($pulled['ok'], json_encode($pulled));
        self::assertSame('mayhopphat.create_post', $pulled['release']['package_key']);
        self::assertSame('v1', $pulled['release']['version']);
        self::assertSame((int) $seed['mayhopphatRel']->id, $pulled['release']['release_id']);

        // Also verify StaffScriptRegistryController::resolve endpoint
        $resolveResp = $ctrl->resolve(Request::create('/social-automation/api/script-registry/resolve', 'GET', [
            'site_key' => 'maytuicanvas',
            'path_key' => 'create_post',
        ]));
        self::assertSame(200, $resolveResp->getStatusCode());
        $resJson = $resolveResp->getData(true);
        self::assertSame('missing', $resJson['resolution']['source']);
    }
}
