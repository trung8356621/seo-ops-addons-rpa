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
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ActionRegistry\ActionRegistryController;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Services\AuthScriptService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use PHPUnit\Framework\TestCase;

final class PathAuthenticationPersistenceTest extends TestCase
{
    private Capsule $capsule;

    private ?Container $previousApp = null;

    private SaSite $site;

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
            $table->boolean('is_authentication')->default(false);
            $table->timestamps();
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
            $table->timestamps();
        });
        $schema->create('sa_script_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_id', 64)->unique();
            $table->unsignedBigInteger('action_tag_id')->nullable();
            $table->string('action_tag_key', 128)->nullable();
            $table->string('status', 32)->default('pending');
            $table->timestamps();
        });

        $this->site = SaSite::query()->create([
            'key' => 'wordpress',
            'name' => 'WordPress',
            'base_url' => 'https://example.test',
            'base_url_mode' => 'fixed',
        ]);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Container::setInstance($this->previousApp);
        Facade::setFacadeApplication($this->previousApp);

        parent::tearDown();
    }

    public function test_migration_file_adds_boolean_default_false(): void
    {
        $path = dirname(__DIR__, 2).'/database/migrations/2026_11_19_100000_add_is_authentication_to_sa_paths.php';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString("hasColumn('sa_paths', 'is_authentication')", $src);
        self::assertStringContainsString("boolean('is_authentication')->default(false)", $src);
    }

    public function test_sa_path_model_casts_is_authentication(): void
    {
        $path = new SaPath(['is_authentication' => 1]);
        self::assertTrue($path->is_authentication);
        $path->is_authentication = 0;
        self::assertFalse($path->is_authentication);
    }

    public function test_false_to_true_and_true_to_false_persist_via_registry(): void
    {
        $controller = new ActionRegistryController();

        $create = $controller->storePath(Request::create('/paths', 'POST', [
            'site_id' => $this->site->id,
            'name' => 'Login',
            'key' => 'login',
            'url_pattern' => '/wp-login.php',
            'is_authentication' => false,
        ]));
        self::assertSame(200, $create->getStatusCode());
        $created = $create->getData(true);
        self::assertTrue($created['ok']);
        self::assertFalse($created['path']['is_authentication']);
        $pathId = (int) $created['path']['id'];

        $toTrue = $controller->updatePath(Request::create("/paths/{$pathId}", 'PUT', [
            'name' => 'Login',
            'url_pattern' => '/wp-login.php',
            'is_authentication' => true,
        ]), $pathId);
        self::assertSame(200, $toTrue->getStatusCode());
        $trueBody = $toTrue->getData(true);
        self::assertTrue($trueBody['ok']);
        self::assertTrue($trueBody['path']['is_authentication']);

        $registryTrue = $controller->data(Request::create('/data', 'GET'));
        $pathsTrue = $registryTrue->getData(true)['paths'];
        $rowTrue = $this->findPathRow($pathsTrue, $pathId);
        self::assertNotNull($rowTrue);
        self::assertArrayHasKey('is_authentication', $rowTrue);
        self::assertTrue($rowTrue['is_authentication']);
        self::assertTrue((bool) SaPath::query()->find($pathId)?->is_authentication);

        $toFalse = $controller->updatePath(Request::create("/paths/{$pathId}", 'PUT', [
            'name' => 'Login',
            'url_pattern' => '/wp-login.php',
            'is_authentication' => false,
        ]), $pathId);
        self::assertSame(200, $toFalse->getStatusCode());
        $falseBody = $toFalse->getData(true);
        self::assertTrue($falseBody['ok']);
        self::assertFalse($falseBody['path']['is_authentication']);

        $registryFalse = $controller->data(Request::create('/data', 'GET'));
        $pathsFalse = $registryFalse->getData(true)['paths'];
        $rowFalse = $this->findPathRow($pathsFalse, $pathId);
        self::assertNotNull($rowFalse);
        self::assertFalse($rowFalse['is_authentication']);
        self::assertFalse((bool) SaPath::query()->find($pathId)?->is_authentication);
    }

    /**
     * @param  list<array<string, mixed>>  $paths
     * @return array<string, mixed>|null
     */
    private function findPathRow(array $paths, int $pathId): ?array
    {
        foreach ($paths as $row) {
            if ((int) ($row['id'] ?? 0) === $pathId) {
                return $row;
            }
        }

        return null;
    }

    public function test_create_with_true_returns_boolean_in_response(): void
    {
        $controller = new ActionRegistryController();
        $res = $controller->storePath(Request::create('/paths', 'POST', [
            'site_id' => $this->site->id,
            'name' => 'Auth',
            'key' => 'auth',
            'url_pattern' => '/login',
            'is_authentication' => true,
        ]));
        $body = $res->getData(true);
        self::assertTrue($body['ok']);
        self::assertTrue($body['path']['is_authentication']);
        self::assertTrue((bool) SaPath::query()->find((int) $body['path']['id'])?->is_authentication);
    }
}
