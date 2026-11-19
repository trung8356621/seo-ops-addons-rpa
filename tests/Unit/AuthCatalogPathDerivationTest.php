<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use App\Models\User;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTagScript;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Services\AuthScriptService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use PHPUnit\Framework\TestCase;

final class AuthCatalogPathDerivationTest extends TestCase
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

        $schema = Schema::connection(SocialAutomationServiceConfig::CONNECTION);
        $schema->create('sa_sites', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name', 128);
            $table->string('base_url', 255)->nullable();
            $table->string('base_url_mode', 16)->default('fixed');
            $table->string('test_base_url', 255)->nullable();
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
            $table->string('platform', 64)->nullable();
            $table->string('target', 64)->nullable();
            $table->string('action', 64)->nullable();
            $table->string('name', 255)->nullable();
            $table->text('description')->nullable();
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
        $schema->create('sa_packages', function (Blueprint $table): void {
            $table->id();
            $table->string('package_key', 128)->nullable();
            $table->string('kind', 32)->default('script');
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_authentication')->default(false);
            $table->timestamps();
        });
        $schema->create('sa_package_releases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('package_id');
            $table->string('version', 64);
            $table->string('status', 32)->default('published');
            $table->string('content_digest', 64)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        $schema->create('sa_account_action_tags', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('action_tag_id');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Container::setInstance($this->previousApp);
        Facade::setFacadeApplication($this->previousApp);
        parent::tearDown();
    }

    public function test_catalog_includes_package_linked_to_auth_path_even_if_flag_false(): void
    {
        $site = SaSite::query()->create([
            'key' => 'mayhopphat',
            'name' => 'May Hop Phat',
            'base_url' => 'https://example.test',
        ]);
        $path = SaPath::query()->create([
            'site_id' => $site->id,
            'site_key' => 'mayhopphat',
            'key' => 'login',
            'name' => 'Login',
            'url_pattern' => '/wp-login.php',
            'is_authentication' => true,
        ]);
        $tag = SaActionTag::query()->create([
            'tag_key' => 'mayhopphat.login',
            'site_id' => $site->id,
            'path_id' => $path->id,
            'path_key' => 'login',
        ]);
        $package = SaPackage::query()->create([
            'package_key' => '690493bd-a830-4648-83b3-86da1533c77a',
            'kind' => 'script',
            'name' => 'dd',
            'is_enabled' => true,
            'is_authentication' => false,
        ]);
        SaPackageRelease::query()->create([
            'package_id' => $package->id,
            'version' => '1',
            'status' => 'published',
            'content_digest' => str_repeat('a', 64),
            'published_at' => now(),
        ]);
        SaActionTagScript::query()->create([
            'action_tag_id' => $tag->id,
            'action_tag_key' => $tag->tag_key,
            'package_id' => $package->id,
            'release_id' => 1,
            'script_id' => (string) $package->package_key,
            'script_version' => '1',
        ]);

        $catalog = app(AuthScriptService::class)->listAuthenticationCatalog();
        self::assertCount(1, $catalog);
        self::assertSame('690493bd-a830-4648-83b3-86da1533c77a', $catalog[0]['package_key']);
        self::assertTrue($catalog[0]['is_authentication']);
        self::assertCount(1, $catalog[0]['releases']);
    }

    public function test_sync_package_auth_flags_for_path_updates_denormalized_flag(): void
    {
        $site = SaSite::query()->create([
            'key' => 'mayhopphat',
            'name' => 'May Hop Phat',
            'base_url' => 'https://example.test',
        ]);
        $path = SaPath::query()->create([
            'site_id' => $site->id,
            'site_key' => 'mayhopphat',
            'key' => 'login',
            'name' => 'Login',
            'url_pattern' => '/wp-login.php',
            'is_authentication' => true,
        ]);
        $tag = SaActionTag::query()->create([
            'tag_key' => 'mayhopphat.login',
            'site_id' => $site->id,
            'path_id' => $path->id,
            'path_key' => 'login',
        ]);
        $package = SaPackage::query()->create([
            'package_key' => 'auth-script-1',
            'kind' => 'script',
            'name' => 'Login Script',
            'is_enabled' => true,
            'is_authentication' => false,
        ]);
        SaActionTagScript::query()->create([
            'action_tag_id' => $tag->id,
            'action_tag_key' => $tag->tag_key,
            'package_id' => $package->id,
            'script_id' => 'auth-script-1',
            'script_version' => '1',
        ]);

        $updated = app(AuthScriptService::class)->syncPackageAuthFlagsForPath($path);
        self::assertSame(1, $updated);
        self::assertTrue((bool) SaPackage::query()->find($package->id)?->is_authentication);

        $path->is_authentication = false;
        $path->save();
        $updatedOff = app(AuthScriptService::class)->syncPackageAuthFlagsForPath($path);
        self::assertSame(1, $updatedOff);
        self::assertFalse((bool) SaPackage::query()->find($package->id)?->is_authentication);
    }

    public function test_publish_service_resolves_auth_from_path_source_contract(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/PackageRegistry/PackageRegistryService.php');
        self::assertStringContainsString('resolveIsAuthenticationFromActionTagKey', $src);
        self::assertStringContainsString('Authentication Path is source of truth', $src);
    }
}
