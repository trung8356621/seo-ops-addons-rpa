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
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTagScript;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptRequest;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Services\AuthScriptService;
use Omnichannel\Addons\SocialAutomation\Services\CmsPathTemplateService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use PHPUnit\Framework\TestCase;

final class ActionRegistryCmsLifecycleTest extends TestCase
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

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousApp);
        Container::setInstance($this->previousApp);
        parent::tearDown();
    }

    private function createSchema(): void
    {
        $schema = Schema::connection(SocialAutomationServiceConfig::CONNECTION);

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
            $table->string('key', 64);
            $table->string('name', 128);
            $table->string('url_pattern', 255);
            $table->json('path_inputs')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_authentication')->default(false);
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
            $table->string('execution_mode', 32)->default('one_account_one_job');
            $table->string('run_mode', 16)->default('live');
            $table->string('status', 32)->default('draft');
            $table->string('script_id', 128);
            $table->string('script_version', 64)->default('1.0.0');
            $table->timestamps();
        });
    }

    public function test_wordpress_site_create_and_update_resolves_templates_and_idempotent_tags(): void
    {
        $controller = new ActionRegistryController();

        $invalid = $controller->storeSite(Request::create('/sites', 'POST', [
            'name' => 'Bad CMS',
            'key' => 'badcms',
            'base_url' => 'https://bad.example.com',
            'cms_type' => 'shopify',
        ]));
        self::assertSame(422, $invalid->getStatusCode());

        $createRes = $controller->storeSite(Request::create('/sites', 'POST', [
            'name' => 'WP Blog',
            'key' => 'wpblog',
            'base_url' => 'https://wpblog.example.com/',
            'cms_type' => 'wp',
        ]));
        self::assertSame(200, $createRes->getStatusCode());
        $created = $createRes->getData(true);
        self::assertTrue($created['ok']);
        self::assertSame('wordpress', $created['site']['cms_type']);
        self::assertSame(2, $created['site']['paths_count']);

        $pathsByKey = [];
        foreach ($created['paths'] as $p) {
            $pathsByKey[$p['key']] = $p;
        }
        self::assertSame('/wp-admin/post-new.php', $pathsByKey['create_post']['url_pattern']);
        self::assertFalse($pathsByKey['create_post']['is_authentication']);
        self::assertSame('/wp-login.php', $pathsByKey['login']['url_pattern']);
        self::assertTrue($pathsByKey['login']['is_authentication']);

        $tagsByKey = [];
        foreach ($created['tags'] as $t) {
            $tagsByKey[$t['tag_key']] = $t;
        }
        self::assertArrayHasKey('wpblog.create_post', $tagsByKey);
        self::assertArrayHasKey('wpblog.login', $tagsByKey);
        self::assertTrue($tagsByKey['wpblog.create_post']['is_enabled']);
        self::assertTrue($tagsByKey['wpblog.login']['is_enabled']);
        self::assertNull($tagsByKey['wpblog.create_post']['action_key']);

        $postTagId = $tagsByKey['wpblog.create_post']['id'];
        $loginTagId = $tagsByKey['wpblog.login']['id'];

        // Override login path on this site
        $overrideRes = $controller->updatePath(Request::create('/paths/'.$pathsByKey['login']['id'], 'PUT', [
            'url_pattern' => '/custom-wp-login.php',
        ]), (int) $pathsByKey['login']['id']);
        self::assertSame(200, $overrideRes->getStatusCode());

        // Update site again: must preserve per-site path override and existing tag IDs without duplicates
        $updateRes = $controller->updateSite(Request::create('/sites/'.$created['site']['id'], 'PUT', [
            'name' => 'WP Blog Updated',
            'base_url' => 'https://wpblog.example.com',
            'cms_type' => 'wordpress',
        ]), (int) $created['site']['id']);
        self::assertSame(200, $updateRes->getStatusCode());
        $updated = $updateRes->getData(true);
        self::assertTrue($updated['ok']);

        $dataRes = $controller->data(Request::create('/data', 'GET'));
        $data = $dataRes->getData(true);
        self::assertSame('wordpress', $data['sites'][0]['cms_type']);

        $effectivePaths = [];
        foreach ($data['paths'] as $p) {
            $effectivePaths[$p['key']] = $p;
        }
        self::assertSame('/custom-wp-login.php', $effectivePaths['login']['url_pattern']);
        self::assertSame('/wp-admin/post-new.php', $effectivePaths['create_post']['url_pattern']);

        self::assertSame(2, SaActionTag::query()->count());
        self::assertSame($postTagId, (int) SaActionTag::query()->where('tag_key', 'wpblog.create_post')->value('id'));
        self::assertSame($loginTagId, (int) SaActionTag::query()->where('tag_key', 'wpblog.login')->value('id'));
    }

    public function test_compatibility_with_existing_mayhopphat_tags_and_bindings(): void
    {
        $site = SaSite::query()->create([
            'key' => 'mayhopphat',
            'name' => 'May Hop Phat',
            'base_url' => 'https://mayhopphat.com',
            'base_url_mode' => SaSite::MODE_FIXED,
            'cms_type' => null,
        ]);

        $loginPath = SaPath::query()->create([
            'site_id' => $site->id,
            'site_key' => 'mayhopphat',
            'key' => 'login',
            'name' => 'Login',
            'url_pattern' => '/wp-login.php',
            'path_inputs' => [],
            'is_authentication' => true,
        ]);
        $postPath = SaPath::query()->create([
            'site_id' => $site->id,
            'site_key' => 'mayhopphat',
            'key' => 'create_post',
            'name' => 'Create Post',
            'url_pattern' => '/wp-admin/post-new.php',
            'path_inputs' => [],
            'is_authentication' => false,
        ]);

        $loginTag = SaActionTag::query()->create([
            'tag_key' => 'mayhopphat.login',
            'site_id' => $site->id,
            'site_key' => 'mayhopphat',
            'path_id' => $loginPath->id,
            'path_key' => 'login',
            'platform' => 'mayhopphat',
            'target' => 'login',
            'action' => '',
            'name' => 'May Hop Phat Login',
            'auth_script_id' => 77,
            'auth_release_id' => 88,
            'is_enabled' => true,
        ]);
        $postTag = SaActionTag::query()->create([
            'tag_key' => 'mayhopphat.create_post',
            'site_id' => $site->id,
            'site_key' => 'mayhopphat',
            'path_id' => $postPath->id,
            'path_key' => 'create_post',
            'platform' => 'mayhopphat',
            'target' => 'create_post',
            'action' => '',
            'name' => 'May Hop Phat Create Post',
            'is_enabled' => true,
        ]);

        $scriptLink = SaActionTagScript::query()->create([
            'action_tag_id' => $postTag->id,
            'action_tag_key' => 'mayhopphat.create_post',
            'package_id' => 10,
            'release_id' => 20,
            'script_id' => 'wp_create_post',
            'script_version' => '1.0.0',
        ]);

        $controller = new ActionRegistryController();
        $res = $controller->updateSite(Request::create('/sites/'.$site->id, 'PUT', [
            'cms_type' => 'wordpress',
        ]), (int) $site->id);
        self::assertSame(200, $res->getStatusCode());

        self::assertSame(2, SaActionTag::query()->count());
        $reloadedLogin = SaActionTag::query()->where('tag_key', 'mayhopphat.login')->firstOrFail();
        $reloadedPost = SaActionTag::query()->where('tag_key', 'mayhopphat.create_post')->firstOrFail();

        self::assertSame($loginTag->id, $reloadedLogin->id);
        self::assertSame(77, (int) $reloadedLogin->auth_script_id);
        self::assertSame(88, (int) $reloadedLogin->auth_release_id);

        self::assertSame($postTag->id, $reloadedPost->id);
        self::assertTrue(SaActionTagScript::query()->whereKey($scriptLink->id)->where('action_tag_id', $postTag->id)->exists());
    }

    public function test_store_path_auto_ensures_tag_without_duplicates(): void
    {
        $site = SaSite::query()->create([
            'key' => 'customsite',
            'name' => 'Custom Site',
            'base_url' => 'https://custom.example.com',
            'base_url_mode' => SaSite::MODE_FIXED,
        ]);

        $controller = new ActionRegistryController();
        $res = $controller->storePath(Request::create('/paths', 'POST', [
            'site_id' => $site->id,
            'name' => 'Submit Form',
            'key' => 'submit_form',
            'url_pattern' => '/forms/{form_id}',
            'is_authentication' => false,
        ]));

        self::assertSame(200, $res->getStatusCode());
        $body = $res->getData(true);
        self::assertTrue($body['ok']);
        self::assertSame('customsite.submit_form', $body['tag']['tag_key']);
        self::assertTrue($body['tag']['is_enabled']);
        self::assertSame(1, SaActionTag::query()->where('tag_key', 'customsite.submit_form')->count());
        self::assertSame(1, SaScriptRequest::query()->where('action_tag_key', 'customsite.submit_form')->count());

        // Calling storeTag for the same path must not create a duplicate
        $dup = $controller->storeTag(Request::create('/tags', 'POST', [
            'site_id' => $site->id,
            'path_id' => $body['path']['id'],
        ]));
        self::assertSame(422, $dup->getStatusCode());
        self::assertSame(1, SaActionTag::query()->where('tag_key', 'customsite.submit_form')->count());
    }

    public function test_patch_tag_toggles_enabled_without_deleting_tag_or_bindings(): void
    {
        $site = SaSite::query()->create([
            'key' => 'mayhopphat',
            'name' => 'May Hop Phat',
            'base_url' => 'https://mayhopphat.com',
            'cms_type' => 'wordpress',
        ]);
        $path = SaPath::query()->create([
            'site_id' => $site->id,
            'site_key' => 'mayhopphat',
            'key' => 'create_post',
            'name' => 'Create Post',
            'url_pattern' => '/wp-admin/post-new.php',
        ]);
        $tag = SaActionTag::query()->create([
            'tag_key' => 'mayhopphat.create_post',
            'site_id' => $site->id,
            'site_key' => 'mayhopphat',
            'path_id' => $path->id,
            'path_key' => 'create_post',
            'platform' => 'mayhopphat',
            'target' => 'create_post',
            'action' => '',
            'name' => 'May Hop Phat Create Post',
            'auth_script_id' => 55,
            'auth_release_id' => 66,
            'is_enabled' => true,
        ]);
        $link = SaActionTagScript::query()->create([
            'action_tag_id' => $tag->id,
            'action_tag_key' => $tag->tag_key,
            'package_id' => 11,
            'release_id' => 22,
            'script_id' => 'wp_post',
            'script_version' => '1.0.0',
        ]);

        $controller = new ActionRegistryController();

        $disable = $controller->patchTag(Request::create('/tags/'.$tag->id, 'PATCH', [
            'is_enabled' => false,
        ]), (int) $tag->id);
        self::assertSame(200, $disable->getStatusCode());
        $disabledBody = $disable->getData(true);
        self::assertTrue($disabledBody['ok']);
        self::assertFalse($disabledBody['tag']['is_enabled']);
        self::assertSame($tag->id, $disabledBody['tag']['id']);
        self::assertSame('mayhopphat.create_post', $disabledBody['tag']['tag_key']);
        self::assertTrue(SaActionTagScript::query()->whereKey($link->id)->exists());
        self::assertSame(55, (int) $tag->fresh()->auth_script_id);

        $enable = $controller->patchTag(Request::create('/tags/'.$tag->id, 'PATCH', [
            'enabled' => true,
        ]), (int) $tag->id);
        self::assertSame(200, $enable->getStatusCode());
        $enabledBody = $enable->getData(true);
        self::assertTrue($enabledBody['tag']['is_enabled']);
        self::assertSame($tag->id, $enabledBody['tag']['id']);
        self::assertSame('mayhopphat.create_post', $enabledBody['tag']['tag_key']);
    }

    public function test_safe_site_deletion_blocks_dependencies_and_deletes_when_clean(): void
    {
        $controller = new ActionRegistryController();

        $site = SaSite::query()->create([
            'key' => 'deletetest',
            'name' => 'Delete Test',
            'base_url' => 'https://deletetest.example.com',
        ]);
        $path = SaPath::query()->create([
            'site_id' => $site->id,
            'site_key' => 'deletetest',
            'key' => 'home',
            'name' => 'Home',
            'url_pattern' => '/',
        ]);
        $tag = SaActionTag::query()->create([
            'tag_key' => 'deletetest.home',
            'site_id' => $site->id,
            'site_key' => 'deletetest',
            'path_id' => $path->id,
            'path_key' => 'home',
            'platform' => 'deletetest',
            'target' => 'home',
            'action' => '',
            'name' => 'Delete Test Home',
        ]);
        SaActionTagScript::query()->create([
            'action_tag_id' => $tag->id,
            'action_tag_key' => $tag->tag_key,
            'script_id' => 'dt_home',
        ]);
        DB::connection(SocialAutomationServiceConfig::CONNECTION)->table('sa_campaigns')->insert([
            'campaign_uuid' => '11111111-1111-1111-1111-111111111111',
            'installation_id' => 'inst-1',
            'name' => 'Hist Campaign',
            'platform' => 'deletetest',
            'script_id' => 'deletetest.home',
        ]);

        $blocked = $controller->destroySite((int) $site->id);
        self::assertSame(422, $blocked->getStatusCode());
        $blockedBody = $blocked->getData(true);
        self::assertFalse($blockedBody['ok']);
        self::assertSame('site_has_dependencies', $blockedBody['error']);
        self::assertSame(1, $blockedBody['dependencies']['paths']);
        self::assertSame(1, $blockedBody['dependencies']['tags']);
        self::assertSame(1, $blockedBody['dependencies']['scripts']);
        self::assertSame(1, $blockedBody['dependencies']['campaigns']);
        self::assertTrue(SaSite::query()->whereKey($site->id)->exists());

        // Empty site with no dependencies can be deleted safely
        $cleanSite = SaSite::query()->create([
            'key' => 'cleansite',
            'name' => 'Clean Site',
            'base_url' => 'https://clean.example.com',
        ]);
        $deleted = $controller->destroySite((int) $cleanSite->id);
        self::assertSame(200, $deleted->getStatusCode());
        self::assertFalse(SaSite::query()->whereKey($cleanSite->id)->exists());
    }
}
