<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

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
use Omnichannel\Addons\SocialAutomation\Http\Controllers\RpaClientApiController;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTagScript;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Models\SaCmsPathTemplate;
use Omnichannel\Addons\SocialAutomation\Models\SaCmsType;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackageDigest;
use Omnichannel\Addons\SocialAutomation\Services\AuthScriptService;
use Omnichannel\Addons\SocialAutomation\Services\CmsPathTemplateService;
use Omnichannel\Addons\SocialAutomation\Services\ScriptResolutionService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use PHPUnit\Framework\TestCase;

final class CmsGlobalAuthContextTest extends TestCase
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
        $app->instance(CmsPathTemplateService::class, new CmsPathTemplateService);
        $app->instance(ScriptResolutionService::class, new ScriptResolutionService);
        $app->instance(AuthScriptService::class, new AuthScriptService);
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

        $schema->create('sa_account_action_tags', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('action_tag_id');
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
            $table->timestamps();
        });

        $schema->create('sa_package_releases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('package_id');
            $table->string('version', 64);
            $table->string('content_digest', 64);
            $table->unsignedInteger('schema_version')->default(1);
            $table->json('runtime_compatibility')->nullable();
            $table->json('manifest');
            $table->string('status', 32)->default('published');
            $table->timestamp('published_at')->nullable();
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
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
        });

        $schema->create('sa_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('account_uuid')->unique();
            $table->string('installation_id', 128);
            $table->string('platform', 64);
            $table->string('username', 191)->nullable();
            $table->string('encrypted_password')->nullable();
            $table->string('site_url', 255)->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();
        });
    }

    /**
     * @return array{clientKey: SaClientApiKey, loginPkg: SaPackage, loginRel: SaPackageRelease}
     */
    private function seedWordPressAndGhost(): array
    {
        $wp = SaCmsType::query()->create(['key' => 'wordpress', 'name' => 'WordPress', 'is_enabled' => true]);
        SaCmsPathTemplate::query()->create([
            'cms_type_id' => $wp->id,
            'cms_type_key' => 'wordpress',
            'key' => 'create_post',
            'name' => 'Create Post',
            'url_pattern' => '/wp-admin/post-new.php',
            'is_authentication' => false,
            'version' => 1,
        ]);
        SaCmsPathTemplate::query()->create([
            'cms_type_id' => $wp->id,
            'cms_type_key' => 'wordpress',
            'key' => 'login',
            'name' => 'Login',
            'url_pattern' => '/wp-login.php',
            'is_authentication' => true,
            'version' => 1,
        ]);

        $ghost = SaCmsType::query()->create(['key' => 'ghost', 'name' => 'Ghost', 'is_enabled' => true]);
        SaCmsPathTemplate::query()->create([
            'cms_type_id' => $ghost->id,
            'cms_type_key' => 'ghost',
            'key' => 'login',
            'name' => 'Login',
            'url_pattern' => '/ghost/#/signin',
            'is_authentication' => true,
            'version' => 1,
        ]);

        $cmsService = app(CmsPathTemplateService::class);
        foreach ([
            ['key' => 'mayhopphat', 'name' => 'May Hop Phat', 'base_url' => 'https://mayhopphat.vn', 'cms_type' => 'wordpress'],
            ['key' => 'congtybalo', 'name' => 'Cong Ty Balo', 'base_url' => 'https://congtybalo.vn', 'cms_type' => 'wordpress'],
            ['key' => 'maytuicanvas', 'name' => 'May Tui Canvas', 'base_url' => 'https://maytuicanvas.vn', 'cms_type' => 'wordpress'],
            ['key' => 'ghostblog', 'name' => 'Ghost Blog', 'base_url' => 'https://ghost.example', 'cms_type' => 'ghost'],
        ] as $row) {
            $site = SaSite::query()->create($row);
            $cmsService->syncSiteCmsPathsAndTags($site);
        }

        // Legacy mayhopphat.login v1 (sa_packages.is_authentication=0, manifest omits is_authentication)
        $manifest = [
            'script_id' => 'mayhopphat.login',
            'action_tag_key' => 'mayhopphat.login',
            'steps' => [['action' => 'login']],
            'assets' => [],
        ];
        $loginPkg = SaPackage::query()->create([
            'package_key' => 'mayhopphat.login',
            'kind' => 'script',
            'name' => 'WP Login',
            'is_enabled' => true,
            'is_authentication' => false,
            'sharing_mode' => SaPackage::SHARING_SITE_SPECIFIC,
        ]);
        $loginRel = SaPackageRelease::query()->create([
            'package_id' => $loginPkg->id,
            'version' => '1',
            'content_digest' => PackageDigest::sha256OfCanonicalJson($manifest),
            'schema_version' => 1,
            'manifest' => $manifest,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $hopTag = SaActionTag::query()->where('tag_key', 'mayhopphat.login')->firstOrFail();
        SaActionTagScript::query()->create([
            'action_tag_id' => $hopTag->id,
            'action_tag_key' => $hopTag->tag_key,
            'package_id' => $loginPkg->id,
            'release_id' => $loginRel->id,
            'script_id' => $loginPkg->package_key,
            'script_version' => '1',
        ]);
        $hopTag->update([
            'auth_script_id' => $loginPkg->id,
            'auth_release_id' => $loginRel->id,
        ]);

        $clientKey = new SaClientApiKey;
        $clientKey->installation_id = 'inst-1';

        return compact('clientKey', 'loginPkg', 'loginRel');
    }

    private function makeClientRequest(SaClientApiKey $key, array $body): Request
    {
        $req = Request::create('/client/auth/context', 'POST', $body);
        $req->attributes->set('sa_client_api_key', $key);

        return $req;
    }

    public function test_global_auth_context_resolve_pull_overrides_and_security(): void
    {
        $seed = $this->seedWordPressAndGhost();
        $ctrl = app(RpaClientApiController::class);
        $resolver = app(ScriptResolutionService::class);

        $hopAcc = SaAccount::query()->create([
            'account_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-1',
            'platform' => 'mayhopphat',
            'username' => 'hop_user',
            'encrypted_password' => 'SECRET_SHOULD_NEVER_LEAK',
            'site_url' => 'https://mayhopphat.vn',
        ]);
        $baloAcc = SaAccount::query()->create([
            'account_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-1',
            'platform' => 'congtybalo',
            'username' => 'balo_user',
            'encrypted_password' => 'SECRET_SHOULD_NEVER_LEAK',
            'site_url' => 'https://congtybalo.vn',
        ]);
        $ghostAcc = SaAccount::query()->create([
            'account_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-1',
            'platform' => 'ghostblog',
            'username' => 'ghost_user',
        ]);

        // 1. Before Global is enabled: legacy auth pin works for mayhopphat, missing for congtybalo
        $hopBefore = $ctrl->authContext($this->makeClientRequest($seed['clientKey'], ['account_id' => $hopAcc->account_uuid]))->getData(true);
        self::assertTrue($hopBefore['ok']);
        self::assertSame('unique', $hopBefore['match']);
        self::assertSame('mayhopphat.login', $hopBefore['action_tag_key']);
        self::assertSame('site_specific', $hopBefore['source']);

        $baloMissing = $ctrl->authContext($this->makeClientRequest($seed['clientKey'], ['account_id' => $baloAcc->account_uuid]))->getData(true);
        self::assertTrue($baloMissing['ok']);
        self::assertSame('none', $baloMissing['match']);
        self::assertSame('missing', $baloMissing['source']);
        self::assertSame('script_not_found', $baloMissing['reason']);

        // 2. Enable CMS Global on mayhopphat.login -> congtybalo resolves via cms_global
        $setRes = $resolver->setGlobalSharing($seed['loginPkg'], SaPackage::SHARING_CMS_GLOBAL);
        self::assertTrue($setRes['ok']);

        $baloGlobal = $ctrl->authContext($this->makeClientRequest($seed['clientKey'], ['account_id' => $baloAcc->account_uuid]))->getData(true);
        self::assertTrue($baloGlobal['ok']);
        self::assertSame('unique', $baloGlobal['match']);
        self::assertSame('congtybalo.login', $baloGlobal['action_tag_key']);
        self::assertSame('cms_global', $baloGlobal['source']);
        self::assertSame('mayhopphat.login', $baloGlobal['script']['package_key']);
        self::assertSame('1', $baloGlobal['script']['version']);
        self::assertSame('wordpress:login', $baloGlobal['cms_scope']['scope_key']);
        self::assertSame('https://congtybalo.vn/wp-login.php', $baloGlobal['execution_context']['resolved_url']);

        // Client resolveAuthScript and pullAuthScript work for congtybalo.login without manual pin
        $pullResp = $ctrl->pullAuthScript($this->makeClientRequest($seed['clientKey'], [
            'account_id' => $baloAcc->account_uuid,
            'action_tag_key' => 'congtybalo.login',
        ]))->getData(true);
        self::assertTrue($pullResp['ok'], json_encode($pullResp));
        self::assertSame('cms_global', $pullResp['release']['source']);
        self::assertSame('mayhopphat.login', $pullResp['release']['package_key']);
        self::assertSame('1', $pullResp['release']['version']);
        self::assertIsArray($pullResp['release']['manifest']);

        // No credential exposure in any response
        $encoded = json_encode([$baloGlobal, $pullResp]);
        self::assertStringNotContainsString('SECRET_SHOULD_NEVER_LEAK', (string) $encoded);
        self::assertStringNotContainsString('encrypted_password', (string) $encoded);

        // 3. Different CMS Type (ghostblog) does NOT match wordpress:login
        $ghostCtx = $ctrl->authContext($this->makeClientRequest($seed['clientKey'], ['account_id' => $ghostAcc->account_uuid]))->getData(true);
        self::assertSame('none', $ghostCtx['match']);
        self::assertSame('missing', $ghostCtx['source']);

        // 4. Disabled Login Tag blocks Global fallback
        SaActionTag::query()->where('tag_key', 'congtybalo.login')->update(['is_enabled' => false]);
        $baloDisabled = $ctrl->authContext($this->makeClientRequest($seed['clientKey'], ['account_id' => $baloAcc->account_uuid]))->getData(true);
        self::assertSame('none', $baloDisabled['match']);
        self::assertSame('missing', $baloDisabled['source']);
        self::assertSame('action_tag_disabled', $baloDisabled['reason']);
        SaActionTag::query()->where('tag_key', 'congtybalo.login')->update(['is_enabled' => true]);

        // 5. Site-specific Auth override takes priority over CMS Global
        $overrideManifest = ['script_id' => 'congtybalo.login', 'action_tag_key' => 'congtybalo.login', 'steps' => []];
        $overridePkg = SaPackage::query()->create([
            'package_key' => 'congtybalo.login',
            'kind' => 'script',
            'name' => 'Balo Custom Login',
            'is_enabled' => true,
            'is_authentication' => true,
            'sharing_mode' => SaPackage::SHARING_SITE_SPECIFIC,
        ]);
        $overrideRel = SaPackageRelease::query()->create([
            'package_id' => $overridePkg->id,
            'version' => '2',
            'content_digest' => PackageDigest::sha256OfCanonicalJson($overrideManifest),
            'schema_version' => 1,
            'manifest' => $overrideManifest,
            'status' => 'published',
            'published_at' => now(),
        ]);
        SaActionTag::query()->where('tag_key', 'congtybalo.login')->update([
            'auth_script_id' => $overridePkg->id,
            'auth_release_id' => $overrideRel->id,
        ]);

        $baloOverride = $ctrl->authContext($this->makeClientRequest($seed['clientKey'], ['account_id' => $baloAcc->account_uuid]))->getData(true);
        self::assertSame('unique', $baloOverride['match']);
        self::assertSame('site_specific', $baloOverride['source']);
        self::assertSame('congtybalo.login', $baloOverride['script']['package_key']);
        self::assertSame('2', $baloOverride['script']['version']);

        // 6. No Account/Site identity & Missing Action Tag context
        $noAccResp = $ctrl->authContext($this->makeClientRequest($seed['clientKey'], []))->getStatusCode();
        self::assertSame(422, $noAccResp);

        $noPlatformAcc = SaAccount::query()->create([
            'account_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-1',
            'platform' => '',
        ]);
        $noPlatCtx = $ctrl->authContext($this->makeClientRequest($seed['clientKey'], ['account_id' => $noPlatformAcc->account_uuid]))->getData(true);
        self::assertSame('none', $noPlatCtx['match']);
        self::assertSame('account_platform_missing', $noPlatCtx['reason']);

        $noSiteAcc = SaAccount::query()->create([
            'account_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-1',
            'platform' => 'unknownsite',
        ]);
        $noTagCtx = $ctrl->authContext($this->makeClientRequest($seed['clientKey'], ['account_id' => $noSiteAcc->account_uuid]))->getData(true);
        self::assertSame('none', $noTagCtx['match']);
        self::assertSame('no_auth_path_for_site', $noTagCtx['reason']);
    }
}
