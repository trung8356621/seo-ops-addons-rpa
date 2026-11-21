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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ActionRegistry\ActionRegistryController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ActionRegistry\CmsRegistryController;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTagScript;
use Omnichannel\Addons\SocialAutomation\Models\SaCmsPathTemplate;
use Omnichannel\Addons\SocialAutomation\Models\SaCmsType;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptRequest;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Services\AuthScriptService;
use Omnichannel\Addons\SocialAutomation\Services\CmsPathTemplateService;
use Omnichannel\Addons\SocialAutomation\Services\SiteDeletionService;
use Omnichannel\Addons\SocialAutomation\Support\PathPatternResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use PHPUnit\Framework\TestCase;

final class DynamicCmsRegistryAndSafeSiteDeleteTest extends TestCase
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

        $app = new Container();
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
                return 99;
            }
        });
        $app->instance(SocialAutomationAccess::class, new class extends SocialAutomationAccess {
            public function __construct()
            {
            }

            public function assertCanAccess(?User $user = null): void
            {
            }

            public function assertCanMutate(?User $user = null): void
            {
            }
        });
        $app->instance(AuthScriptService::class, new AuthScriptService());
        $app->instance(CmsPathTemplateService::class, new CmsPathTemplateService());
        $app->instance(SiteDeletionService::class, new SiteDeletionService());
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

            public function streamJson($data, $status = 200, $headers = [], $encodingOptions = 15)
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

        $this->createSchemaAndSeedWordPress();
    }

    protected function tearDown(): void
    {
        SaSite::flushEventListeners();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousApp);
        Container::setInstance($this->previousApp);
        parent::tearDown();
    }

    private function createSchemaAndSeedWordPress(): void
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
            $table->string('base_url', 255)->nullable();
            $table->string('base_url_mode', 16)->default('fixed');
            $table->string('cms_type', 32)->nullable();
            $table->string('test_base_url', 255)->nullable();
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
            $table->text('description')->nullable();
            $table->boolean('is_authentication')->default(false);
            $table->boolean('is_override')->default(false);
            $table->unsignedInteger('template_version')->nullable();
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
            $table->string('action', 64);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedBigInteger('auth_script_id')->nullable();
            $table->unsignedBigInteger('auth_release_id')->nullable();
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

        $schema->create('sa_account_action_tags', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('action_tag_id');
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

        $schema->create('sa_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('campaign_uuid', 64)->unique();
            $table->string('installation_id', 128);
            $table->string('name', 255);
            $table->string('platform', 64);
            $table->string('script_id', 128);
            $table->timestamps();
        });

        $schema->create('sa_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('job_uuid', 64)->unique();
            $table->string('package_key', 128);
            $table->timestamps();
        });

        $schema->create('sa_tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('task_uuid', 64)->unique();
            $table->string('package_key', 128);
            $table->timestamps();
        });

        $schema->create('sa_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('platform', 64);
            $table->string('name', 128);
            $table->timestamps();
        });

        $wp = SaCmsType::query()->create([
            'key' => 'wordpress',
            'name' => 'WordPress',
            'is_enabled' => true,
        ]);
        SaCmsPathTemplate::query()->create([
            'cms_type_id' => $wp->id,
            'cms_type_key' => 'wordpress',
            'key' => 'create_post',
            'name' => 'Create Post',
            'url_pattern' => '/wp-admin/post-new.php',
            'path_inputs' => PathPatternResolver::parseInputs('/wp-admin/post-new.php'),
            'is_authentication' => false,
            'version' => 1,
        ]);
        SaCmsPathTemplate::query()->create([
            'cms_type_id' => $wp->id,
            'cms_type_key' => 'wordpress',
            'key' => 'login',
            'name' => 'Login',
            'url_pattern' => '/wp-login.php',
            'path_inputs' => PathPatternResolver::parseInputs('/wp-login.php'),
            'is_authentication' => true,
            'version' => 1,
        ]);
    }

    public function test_dynamic_cms_type_creation_inheritance_and_safe_guards(): void
    {
        $cmsCtrl = new CmsRegistryController();
        $regCtrl = new ActionRegistryController();

        // Create a brand new CMS type ("ghost") via API without any PHP edits
        $createCms = $cmsCtrl->store(Request::create('/cms-types', 'POST', [
            'key' => 'ghost',
            'name' => 'Ghost CMS',
            'path_templates' => [
                ['key' => 'new_post', 'name' => 'New Post', 'url_pattern' => '/ghost/#/editor/post', 'is_authentication' => false],
                ['key' => 'login', 'name' => 'Ghost Signin', 'url_pattern' => '/ghost/#/signin', 'is_authentication' => true],
            ],
        ]));
        self::assertSame(200, $createCms->getStatusCode());
        $ghostBody = $createCms->getData(true);
        self::assertSame('ghost', $ghostBody['cms_type']['key']);
        self::assertCount(2, $ghostBody['cms_type']['path_templates']);

        // Create a Site referencing "ghost"
        $siteRes = $regCtrl->storeSite(Request::create('/sites', 'POST', [
            'name' => 'My Ghost Blog',
            'key' => 'myghost',
            'base_url' => 'https://ghost.example.test',
            'cms_type' => 'ghost',
        ]));
        self::assertSame(200, $siteRes->getStatusCode());
        $siteBody = $siteRes->getData(true);
        self::assertSame('ghost', $siteBody['site']['cms_type']);
        self::assertCount(2, $siteBody['paths']);
        self::assertCount(2, $siteBody['tags']);

        // Key change or deletion of "ghost" must be blocked while in use
        $renameBlocked = $cmsCtrl->update(Request::create('/cms-types/'.$ghostBody['cms_type']['id'], 'PUT', [
            'key' => 'ghost_v2',
        ]), (int) $ghostBody['cms_type']['id']);
        self::assertSame(422, $renameBlocked->getStatusCode());
        self::assertSame('cms_type_in_use', $renameBlocked->getData(true)['error']);

        $deleteBlocked = $cmsCtrl->destroy((int) $ghostBody['cms_type']['id']);
        self::assertSame(422, $deleteBlocked->getStatusCode());
        self::assertSame('cms_type_in_use', $deleteBlocked->getData(true)['error']);

        // Disabling CMS type prevents assigning it to new sites
        $disableRes = $cmsCtrl->patch(Request::create('/cms-types/'.$ghostBody['cms_type']['id'], 'PATCH', [
            'is_enabled' => false,
        ]), (int) $ghostBody['cms_type']['id']);
        self::assertSame(200, $disableRes->getStatusCode());
        self::assertFalse($disableRes->getData(true)['cms_type']['is_enabled']);

        $newSiteBlocked = $regCtrl->storeSite(Request::create('/sites', 'POST', [
            'name' => 'Another Ghost',
            'key' => 'ghost2',
            'base_url' => 'https://ghost2.example.test',
            'cms_type' => 'ghost',
        ]));
        self::assertSame(422, $newSiteBlocked->getStatusCode());
        self::assertSame('cms_type_disabled', $newSiteBlocked->getData(true)['error']);
    }

    public function test_template_version_update_propagates_to_inherited_paths_but_preserves_site_overrides(): void
    {
        $cmsCtrl = new CmsRegistryController();
        $regCtrl = new ActionRegistryController();

        $wpCms = SaCmsType::query()->where('key', 'wordpress')->firstOrFail();
        $createPostTpl = SaCmsPathTemplate::query()
            ->where('cms_type_id', $wpCms->id)
            ->where('key', 'create_post')
            ->firstOrFail();

        // Site A: keeps inherited create_post
        $siteARes = $regCtrl->storeSite(Request::create('/sites', 'POST', [
            'name' => 'WP Inherited',
            'key' => 'wpinherit',
            'base_url' => 'https://inherit.example.test',
            'cms_type' => 'wordpress',
        ]))->getData(true);

        // Site B: overrides create_post
        $siteBRes = $regCtrl->storeSite(Request::create('/sites', 'POST', [
            'name' => 'WP Override',
            'key' => 'wpoverride',
            'base_url' => 'https://override.example.test',
            'cms_type' => 'wordpress',
            'path_overrides' => [
                'create_post' => '/wp-admin/post-new.php?post_type=page',
            ],
        ]))->getData(true);

        // Update WordPress create_post template -> bumps version to 2
        $tplUpdate = $cmsCtrl->updateTemplate(
            Request::create('/cms-types/'.$wpCms->id.'/templates/'.$createPostTpl->id, 'PUT', [
                'url_pattern' => '/wp-admin/post-new.php?v=2',
            ]),
            (int) $wpCms->id,
            (int) $createPostTpl->id,
        );
        self::assertSame(200, $tplUpdate->getStatusCode());
        self::assertSame(2, $tplUpdate->getData(true)['template']['version']);

        // Re-sync Site A and Site B
        $syncA = $regCtrl->updateSite(Request::create('/sites/'.$siteARes['site']['id'], 'PUT', [
            'cms_type' => 'wordpress',
        ]), (int) $siteARes['site']['id'])->getData(true);
        $syncB = $regCtrl->updateSite(Request::create('/sites/'.$siteBRes['site']['id'], 'PUT', [
            'cms_type' => 'wordpress',
        ]), (int) $siteBRes['site']['id'])->getData(true);

        $pathA = collect($syncA['paths'])->firstWhere('key', 'create_post');
        $pathB = collect($syncB['paths'])->firstWhere('key', 'create_post');

        self::assertSame('/wp-admin/post-new.php?v=2', $pathA['url_pattern']);
        self::assertTrue($pathA['is_template_inherited']);
        self::assertFalse($pathA['is_override']);

        self::assertSame('/wp-admin/post-new.php?post_type=page', $pathB['url_pattern']);
        self::assertTrue($pathB['is_override']);
        self::assertFalse($pathB['is_template_inherited']);
    }

    public function test_safe_site_deletion_allows_generated_unreferenced_children_and_blocks_active_history(): void
    {
        $regCtrl = new ActionRegistryController();

        // 1. WordPress site with auto-generated paths, tags, and pending create requests -> SAFE TO DELETE!
        $wpRes = $regCtrl->storeSite(Request::create('/sites', 'POST', [
            'name' => 'Unused WP Site',
            'key' => 'unusedwp',
            'base_url' => 'https://unusedwp.example.test',
            'cms_type' => 'wordpress',
        ]))->getData(true);
        $unusedId = (int) $wpRes['site']['id'];

        self::assertSame(2, SaPath::query()->where('site_id', $unusedId)->count());
        self::assertSame(2, SaActionTag::query()->where('site_id', $unusedId)->count());
        self::assertSame(2, SaScriptRequest::query()->where('site_key', 'unusedwp')->count());

        $delRes = $regCtrl->destroySite($unusedId);
        self::assertSame(200, $delRes->getStatusCode());
        $delBody = $delRes->getData(true);
        self::assertTrue($delBody['ok']);
        self::assertSame(2, $delBody['deleted']['paths']);
        self::assertSame(2, $delBody['deleted']['tags']);
        self::assertSame(2, $delBody['deleted']['script_requests']);
        self::assertFalse(SaSite::query()->whereKey($unusedId)->exists());
        self::assertSame(0, SaPath::query()->where('site_id', $unusedId)->count());
        self::assertSame(0, SaActionTag::query()->where('site_id', $unusedId)->count());

        // 2. Site with script binding + job + campaign -> BLOCKED!
        $usedRes = $regCtrl->storeSite(Request::create('/sites', 'POST', [
            'name' => 'Used WP Site',
            'key' => 'usedwp',
            'base_url' => 'https://usedwp.example.test',
            'cms_type' => 'wordpress',
        ]))->getData(true);
        $usedId = (int) $usedRes['site']['id'];
        $postTag = SaActionTag::query()->where('tag_key', 'usedwp.create_post')->firstOrFail();

        SaActionTagScript::query()->create([
            'action_tag_id' => $postTag->id,
            'action_tag_key' => $postTag->tag_key,
            'script_id' => 'wp_post_script',
        ]);
        DB::connection(SocialAutomationServiceConfig::CONNECTION)->table('sa_jobs')->insert([
            'job_uuid' => 'job-uuid-1',
            'package_key' => 'usedwp.create_post',
        ]);
        DB::connection(SocialAutomationServiceConfig::CONNECTION)->table('sa_campaigns')->insert([
            'campaign_uuid' => 'camp-uuid-1',
            'installation_id' => 'inst-1',
            'name' => 'WP Campaign',
            'platform' => 'usedwp',
            'script_id' => 'usedwp.create_post',
        ]);

        $blockedRes = $regCtrl->destroySite($usedId);
        self::assertSame(422, $blockedRes->getStatusCode());
        $blockedBody = $blockedRes->getData(true);
        self::assertFalse($blockedBody['ok']);
        self::assertSame('site_has_dependencies', $blockedBody['error']);
        self::assertSame(1, $blockedBody['blockers']['scripts']);
        self::assertSame(1, $blockedBody['blockers']['jobs']);
        self::assertSame(1, $blockedBody['blockers']['campaigns']);
        self::assertTrue(SaSite::query()->whereKey($usedId)->exists());
        self::assertSame(2, SaActionTag::query()->where('site_id', $usedId)->count());
    }

    public function test_safe_site_deletion_rolls_back_completely_on_failure(): void
    {
        $regCtrl = new ActionRegistryController();

        $wpRes = $regCtrl->storeSite(Request::create('/sites', 'POST', [
            'name' => 'Rollback Site',
            'key' => 'rollbacksite',
            'base_url' => 'https://rollback.example.test',
            'cms_type' => 'wordpress',
        ]))->getData(true);
        $siteId = (int) $wpRes['site']['id'];

        SaSite::deleting(function (): void {
            throw new \RuntimeException('Simulated failure during site deletion');
        });

        try {
            $regCtrl->destroySite($siteId);
            self::fail('Expected RuntimeException to be thrown');
        } catch (\RuntimeException $e) {
            self::assertSame('Simulated failure during site deletion', $e->getMessage());
        }

        // Verify zero partial deletion occurred
        self::assertTrue(SaSite::query()->whereKey($siteId)->exists());
        self::assertSame(2, SaPath::query()->where('site_id', $siteId)->count());
        self::assertSame(2, SaActionTag::query()->where('site_id', $siteId)->count());
        self::assertSame(2, SaScriptRequest::query()->where('site_key', 'rollbacksite')->count());
    }
}
