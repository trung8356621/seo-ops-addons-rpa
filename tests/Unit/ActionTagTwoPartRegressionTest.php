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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ActionRegistry\ActionRegistryController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\RpaAdmin\RpaAdminScriptSyncController;
use Omnichannel\Addons\SocialAutomation\Models\SaAction;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptRequest;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Services\AuthScriptService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use PHPUnit\Framework\TestCase;

final class ActionTagTwoPartRegressionTest extends TestCase
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
        $app->instance('db.schema', $this->capsule->schema(SocialAutomationServiceConfig::CONNECTION));
        $app->instance('auth', new class {
            public function id(): ?int
            {
                return 42;
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
        $app->instance(ResponseFactory::class, new class implements ResponseFactory {
            public function json($data = [], $status = 200, array $headers = [], $options = 0): JsonResponse
            {
                return new JsonResponse($data, $status, $headers, $options);
            }

            public function make($content = '', $status = 200, array $headers = []) { throw new \BadMethodCallException(__METHOD__); }
            public function noContent($status = 204, array $headers = []) { throw new \BadMethodCallException(__METHOD__); }
            public function view($view, $data = [], $status = 200, array $headers = []) { throw new \BadMethodCallException(__METHOD__); }
            public function jsonp($callback, $data = [], $status = 200, array $headers = [], $options = 0) { throw new \BadMethodCallException(__METHOD__); }
            public function stream($callback, $status = 200, array $headers = []) { throw new \BadMethodCallException(__METHOD__); }
            public function streamJson($data, $status = 200, $headers = [], $encodingOptions = 15) { throw new \BadMethodCallException(__METHOD__); }
            public function streamDownload($callback, $name = null, array $headers = [], $disposition = 'attachment') { throw new \BadMethodCallException(__METHOD__); }
            public function download($file, $name = null, array $headers = [], $disposition = 'attachment') { throw new \BadMethodCallException(__METHOD__); }
            public function file($file, array $headers = []) { throw new \BadMethodCallException(__METHOD__); }
            public function redirectTo($path, $status = 302, $headers = [], $secure = null) { throw new \BadMethodCallException(__METHOD__); }
            public function redirectToRoute($route, $parameters = [], $status = 302, $headers = []) { throw new \BadMethodCallException(__METHOD__); }
            public function redirectToAction($action, $parameters = [], $status = 302, $headers = []) { throw new \BadMethodCallException(__METHOD__); }
            public function redirectGuest($path, $status = 302, $headers = [], $secure = null) { throw new \BadMethodCallException(__METHOD__); }
            public function redirectToIntended($default = '/', $status = 302, $headers = [], $secure = null) { throw new \BadMethodCallException(__METHOD__); }
        });

        $this->createSchema();
        $this->seedBaseData();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Container::setInstance($this->previousApp);
        Facade::setFacadeApplication($this->previousApp);

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
            $table->timestamps();
        });

        $schema->create('sa_actions', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name', 128);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Mirror production schema where legacy `action` column is NOT NULL.
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
            $table->unsignedBigInteger('auth_script_id')->nullable();
            $table->unsignedBigInteger('auth_release_id')->nullable();
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

        $schema->create('sa_packages', function (Blueprint $table): void {
            $table->id();
            $table->string('package_key', 128)->nullable();
            $table->string('kind', 32)->default('script');
            $table->string('name', 255);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_authentication')->default(false);
            $table->timestamps();
        });

        $schema->create('sa_package_releases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('package_id');
            $table->string('version', 64);
            $table->string('status', 32)->default('published');
            $table->string('content_digest', 128)->default('sha256:test');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        $schema->create('sa_account_action_tags', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('action_tag_id');
            $table->timestamp('granted_at')->nullable();
            $table->unsignedBigInteger('granted_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    private function seedBaseData(): void
    {
        $mhpSite = SaSite::query()->create([
            'key' => 'mayhopphat',
            'name' => 'May Hop Phat',
            'base_url' => 'https://mayhopphat.vn',
            'base_url_mode' => SaSite::MODE_FIXED,
        ]);

        SaPath::query()->create([
            'site_id' => $mhpSite->id,
            'site_key' => 'mayhopphat',
            'key' => 'login',
            'name' => 'Login',
            'url_pattern' => '/login',
            'path_inputs' => [],
        ]);

        $fbSite = SaSite::query()->create([
            'key' => 'facebook',
            'name' => 'Facebook',
            'base_url' => 'https://www.facebook.com',
            'base_url_mode' => SaSite::MODE_FIXED,
        ]);

        SaPath::query()->create([
            'site_id' => $fbSite->id,
            'site_key' => 'facebook',
            'key' => 'group',
            'name' => 'Group',
            'url_pattern' => '/groups/{group_id}',
            'path_inputs' => [['key' => 'group_id', 'type' => 'string', 'required' => true]],
        ]);

        SaAction::query()->create([
            'key' => 'post',
            'name' => 'Post',
            'description' => 'Publish posts',
        ]);
    }

    public function test_build_key_supports_two_part_and_three_part_keys(): void
    {
        self::assertSame('mayhopphat.login', SaActionTag::buildKey('MayHopPhat', 'Login'));
        self::assertSame('mayhopphat.login', SaActionTag::buildKey('  mayhopphat ', ' login ', null));
        self::assertSame('mayhopphat.login', SaActionTag::buildKey('mayhopphat', 'login', '   '));
        self::assertSame('facebook.group.post', SaActionTag::buildKey('Facebook', 'Group', 'Post'));
    }

    public function test_store_tag_creates_two_part_tag_and_script_request_without_action_key(): void
    {
        $site = SaSite::query()->where('key', 'mayhopphat')->firstOrFail();
        $path = SaPath::query()->where('site_id', $site->id)->where('key', 'login')->firstOrFail();

        $controller = new ActionRegistryController();
        $res = $controller->storeTag(Request::create('/tags', 'POST', [
            'site_id' => $site->id,
            'path_id' => $path->id,
        ]));

        self::assertSame(200, $res->getStatusCode());
        $payload = json_decode((string) $res->getContent(), true);
        self::assertTrue($payload['ok']);
        self::assertSame('mayhopphat.login', $payload['tag']['tag_key']);
        self::assertNull($payload['tag']['action_id']);
        self::assertNull($payload['tag']['action_key']);
        self::assertSame('May Hop Phat Login', $payload['tag']['name']);
        self::assertSame('Thao tác tại May Hop Phat Login', $payload['tag']['description']);

        self::assertSame('mayhopphat.login', $payload['request']['action_tag_key']);
        self::assertNull($payload['request']['action_key']);
        self::assertSame(SaScriptRequest::TYPE_CREATE, $payload['request']['request_type']);
        self::assertSame(SaScriptRequest::STATUS_PENDING, $payload['request']['status']);

        // Reload from DB and verify invariant: remains two-part with action_key = null
        $reloadedTag = SaActionTag::query()->findOrFail($payload['tag']['id']);
        self::assertSame('mayhopphat.login', $reloadedTag->tag_key);
        self::assertNull($reloadedTag->action_id);
        self::assertNull($reloadedTag->action_key);
        self::assertNull($reloadedTag->resolvedActionKey());

        $reloadedTag->name = 'May Hop Phat Login Updated';
        $reloadedTag->save();
        $reloadedAgain = $reloadedTag->fresh();
        self::assertNotNull($reloadedAgain);
        self::assertSame('mayhopphat.login', $reloadedAgain->tag_key);
        self::assertNull($reloadedAgain->action_id);
        self::assertNull($reloadedAgain->action_key);
        self::assertNull($reloadedAgain->resolvedActionKey());

        $serialized = json_decode(json_encode($reloadedAgain, JSON_THROW_ON_ERROR), true);
        self::assertNull($serialized['action_key']);
        self::assertNull($serialized['action_id']);
    }

    public function test_store_tag_rejects_duplicate_two_part_tag(): void
    {
        $site = SaSite::query()->where('key', 'mayhopphat')->firstOrFail();
        $path = SaPath::query()->where('site_id', $site->id)->where('key', 'login')->firstOrFail();

        $controller = new ActionRegistryController();
        $first = $controller->storeTag(Request::create('/tags', 'POST', [
            'site_id' => $site->id,
            'path_id' => $path->id,
        ]));
        self::assertSame(200, $first->getStatusCode());

        $duplicate = $controller->storeTag(Request::create('/tags', 'POST', [
            'site_id' => $site->id,
            'path_id' => $path->id,
        ]));
        self::assertSame(422, $duplicate->getStatusCode());
        $payload = json_decode((string) $duplicate->getContent(), true);
        self::assertFalse($payload['ok']);
        self::assertStringContainsString('mayhopphat.login', $payload['error']);
    }

    public function test_store_tag_rejects_path_not_belonging_to_site(): void
    {
        $mhpSite = SaSite::query()->where('key', 'mayhopphat')->firstOrFail();
        $fbSite = SaSite::query()->where('key', 'facebook')->firstOrFail();
        $fbPath = SaPath::query()->where('site_id', $fbSite->id)->where('key', 'group')->firstOrFail();

        $controller = new ActionRegistryController();
        $res = $controller->storeTag(Request::create('/tags', 'POST', [
            'site_id' => $mhpSite->id,
            'path_id' => $fbPath->id,
        ]));

        self::assertSame(422, $res->getStatusCode());
        $payload = json_decode((string) $res->getContent(), true);
        self::assertFalse($payload['ok']);
        self::assertSame('Path không thuộc Site đã chọn.', $payload['error']);
    }

    public function test_legacy_three_part_tag_still_works(): void
    {
        $fbSite = SaSite::query()->where('key', 'facebook')->firstOrFail();
        $fbPath = SaPath::query()->where('site_id', $fbSite->id)->where('key', 'group')->firstOrFail();

        $controller = new ActionRegistryController();
        $res = $controller->storeTag(Request::create('/tags', 'POST', [
            'site_id' => $fbSite->id,
            'path_id' => $fbPath->id,
            'action_key' => 'post',
        ]));

        self::assertSame(200, $res->getStatusCode());
        $payload = json_decode((string) $res->getContent(), true);
        self::assertTrue($payload['ok']);
        self::assertSame('facebook.group.post', $payload['tag']['tag_key']);
        self::assertSame('post', $payload['tag']['action_key']);
        self::assertNotNull($payload['tag']['action_id']);
        self::assertSame('post', $payload['request']['action_key']);
    }

    public function test_optional_auth_script_pin_works_with_two_part_tag(): void
    {
        $pkg = SaPackage::query()->create([
            'package_key' => 'auth-mhp',
            'kind' => 'script',
            'name' => 'Auth MHP',
            'is_enabled' => true,
            'is_authentication' => true,
        ]);
        $rel = SaPackageRelease::query()->create([
            'package_id' => $pkg->id,
            'version' => '1.0.0',
            'status' => 'published',
            'content_digest' => 'sha256:auth-mhp-100',
        ]);

        $site = SaSite::query()->where('key', 'mayhopphat')->firstOrFail();
        $path = SaPath::query()->where('site_id', $site->id)->where('key', 'login')->firstOrFail();

        $controller = new ActionRegistryController();
        $res = $controller->storeTag(Request::create('/tags', 'POST', [
            'site_id' => $site->id,
            'path_id' => $path->id,
            'auth_script_id' => $pkg->id,
            'auth_release_id' => $rel->id,
        ]));

        self::assertSame(200, $res->getStatusCode());
        $payload = json_decode((string) $res->getContent(), true);
        self::assertTrue($payload['ok']);
        self::assertSame('mayhopphat.login', $payload['tag']['tag_key']);
        self::assertNull($payload['tag']['action_key']);
        self::assertSame((int) $pkg->id, (int) $payload['tag']['auth_script_id']);
        self::assertSame((int) $rel->id, (int) $payload['tag']['auth_release_id']);
    }

    public function test_read_list_sync_and_recovery_endpoints_preserve_null_action_key(): void
    {
        $mhpSite = SaSite::query()->where('key', 'mayhopphat')->firstOrFail();
        $mhpPath = SaPath::query()->where('site_id', $mhpSite->id)->where('key', 'login')->firstOrFail();
        $fbSite = SaSite::query()->where('key', 'facebook')->firstOrFail();
        $fbPath = SaPath::query()->where('site_id', $fbSite->id)->where('key', 'group')->firstOrFail();

        $registry = new ActionRegistryController();
        $registry->storeTag(Request::create('/tags', 'POST', [
            'site_id' => $mhpSite->id,
            'path_id' => $mhpPath->id,
        ]));
        $registry->storeTag(Request::create('/tags', 'POST', [
            'site_id' => $fbSite->id,
            'path_id' => $fbPath->id,
            'action_key' => 'post',
        ]));

        // 1. ActionRegistryController::data()
        $dataRes = $registry->data(Request::create('/data', 'GET'));
        $data = json_decode((string) $dataRes->getContent(), true);
        self::assertTrue($data['ok']);
        $tagsByKey = [];
        foreach ($data['tags'] as $t) {
            $tagsByKey[$t['tag_key']] = $t;
        }
        self::assertArrayHasKey('mayhopphat.login', $tagsByKey);
        self::assertNull($tagsByKey['mayhopphat.login']['action_key']);
        self::assertNull($tagsByKey['mayhopphat.login']['action_id']);
        self::assertNull($tagsByKey['mayhopphat.login']['action_name']);
        self::assertSame('post', $tagsByKey['facebook.group.post']['action_key']);

        // 2. RpaAdminScriptSyncController::actionTags() & scriptRequests()
        /** @var RpaAdminScriptSyncController $sync */
        $sync = (new \ReflectionClass(RpaAdminScriptSyncController::class))->newInstanceWithoutConstructor();
        $syncTags = json_decode((string) $sync->actionTags()->getContent(), true);
        self::assertTrue($syncTags['ok']);
        $syncTagsByKey = [];
        foreach ($syncTags['action_tags'] as $t) {
            $syncTagsByKey[$t['tag_key']] = $t;
        }
        self::assertNull($syncTagsByKey['mayhopphat.login']['action_key']);
        self::assertNull($syncTagsByKey['mayhopphat.login']['action']);
        self::assertSame('post', $syncTagsByKey['facebook.group.post']['action_key']);

        $syncReqs = json_decode((string) $sync->scriptRequests(Request::create('/script-requests', 'GET'))->getContent(), true);
        self::assertTrue($syncReqs['ok']);
        $reqsByTag = [];
        foreach ($syncReqs['script_requests'] as $r) {
            $reqsByTag[$r['action_tag_key']] = $r;
        }
        self::assertNull($reqsByTag['mayhopphat.login']['action_key']);
        self::assertSame('post', $reqsByTag['facebook.group.post']['action_key']);

        // 3. createRequest() & backfillMissingRequests() keep action_key = null for two-part tag
        $mhpTag = SaActionTag::query()->where('tag_key', 'mayhopphat.login')->firstOrFail();
        $createReqRes = json_decode((string) $registry->createRequest(
            Request::create('/requests', 'POST', ['request_type' => SaScriptRequest::TYPE_FIX]),
            (int) $mhpTag->id
        )->getContent(), true);
        self::assertTrue($createReqRes['ok']);
        self::assertNull($createReqRes['request']['action_key']);

        SaScriptRequest::query()->where('action_tag_id', $mhpTag->id)->delete();
        $backfill = ActionRegistryController::backfillMissingRequests(false);
        self::assertSame(1, $backfill['created']);
        $backfilledReq = SaScriptRequest::query()->where('action_tag_id', $mhpTag->id)->firstOrFail();
        self::assertNull($backfilledReq->action_key);
    }
}
