<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use App\Models\User;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Omnichannel\Addons\SocialAutomation\Auth\RpaAdminRoleGate;
use Omnichannel\Addons\SocialAutomation\Auth\RpaAdminSessionService;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\RpaAdmin\RpaAdminScriptSyncController;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTagScript;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptReleaseAsset;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptReleaseMedia;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptVariantGroup;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptVariantGroupItem;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackageDigest;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackagePublishValidator;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackageRegistryService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\RpaAdminBackupService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptCatalogService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptReleaseMediaService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptSyncPlanner;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptSyncService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptTombstoneService;
use Omnichannel\Addons\SocialAutomation\Services\ScriptResolutionService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use PHPUnit\Framework\TestCase;

final class ScriptTwoSyncModesTest extends TestCase
{
    private Capsule $capsule;

    private ?Container $previousApp = null;

    private ?Container $previousContainer = null;

    private string $tempStorageDir;

    private ScriptSyncService $syncService;

    private RpaAdminScriptSyncController $controller;

    private PackageRegistryService $registry;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite unavailable');
        }

        $this->previousApp = Facade::getFacadeApplication() instanceof Container
            ? Facade::getFacadeApplication()
            : null;
        $this->previousContainer = Container::getInstance();

        $this->tempStorageDir = sys_get_temp_dir().'/sa_sync_modes_'.bin2hex(random_bytes(6));
        @mkdir($this->tempStorageDir, 0777, true);

        $app = new Container;
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $app->instance('config', new class($this->tempStorageDir) implements \ArrayAccess
        {
            /** @var array<string, mixed> */
            private array $items;

            public function __construct(string $root)
            {
                $this->items = [
                    'app.timezone' => 'UTC',
                    'filesystems.default' => 'local',
                    'filesystems.disks.local' => [
                        'driver' => 'local',
                        'root' => $root,
                    ],
                ];
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->items[$key] ?? $default;
            }

            public function offsetExists(mixed $offset): bool
            {
                return array_key_exists((string) $offset, $this->items);
            }

            public function offsetGet(mixed $offset): mixed
            {
                return $this->items[(string) $offset] ?? null;
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
                $this->items[(string) $offset] = $value;
            }

            public function offsetUnset(mixed $offset): void
            {
                unset($this->items[(string) $offset]);
            }
        });

        $app->singleton('files', fn () => new \Illuminate\Filesystem\Filesystem);
        $app->singleton('filesystem', fn ($c) => new FilesystemManager($c));
        $app->instance(\Illuminate\Contracts\Debug\ExceptionHandler::class, new class implements \Illuminate\Contracts\Debug\ExceptionHandler
        {
            public function report(\Throwable $e): void {}

            public function shouldReport(\Throwable $e): bool
            {
                return true;
            }

            public function render($request, \Throwable $e)
            {
                return new \Illuminate\Http\Response($e->getMessage(), 500);
            }

            public function renderForConsole($output, \Throwable $e): void {}
        });
        $app->instance(ResponseFactory::class, new class implements ResponseFactory
        {
            public function make($content = '', $status = 200, array $headers = [])
            {
                return new \Illuminate\Http\Response($content, $status, $headers);
            }

            public function noContent($status = 204, array $headers = [])
            {
                return new \Illuminate\Http\Response('', $status, $headers);
            }

            public function view($view, $data = [], $status = 200, array $headers = [])
            {
                return new \Illuminate\Http\Response('', $status, $headers);
            }

            public function json($data = [], $status = 200, array $headers = [], $options = 0)
            {
                return new \Illuminate\Http\JsonResponse($data, $status, $headers, $options);
            }

            public function jsonp($callback, $data = [], $status = 200, array $headers = [], $options = 0)
            {
                return new \Illuminate\Http\JsonResponse($data, $status, $headers, $options);
            }

            public function stream($callback, $status = 200, array $headers = [])
            {
                return new \Symfony\Component\HttpFoundation\StreamedResponse($callback, $status, $headers);
            }

            public function streamJson($data, $status = 200, $headers = [], $encodingOptions = 15)
            {
                return new \Illuminate\Http\JsonResponse($data, $status, $headers);
            }

            public function streamDownload($callback, $name = null, array $headers = [], $disposition = 'attachment')
            {
                return new \Symfony\Component\HttpFoundation\StreamedResponse($callback, 200, $headers);
            }

            public function download($file, $name = null, array $headers = [], $disposition = 'attachment')
            {
                return new \Symfony\Component\HttpFoundation\BinaryFileResponse($file, 200, $headers);
            }

            public function file($file, array $headers = [])
            {
                return new \Symfony\Component\HttpFoundation\BinaryFileResponse($file, 200, $headers);
            }

            public function redirectTo($path, $status = 302, $headers = [], $secure = null)
            {
                return new \Illuminate\Http\RedirectResponse($path, $status, $headers);
            }

            public function redirectToRoute($route, $parameters = [], $status = 302, $headers = [])
            {
                return new \Illuminate\Http\RedirectResponse('/', $status, $headers);
            }

            public function redirectToAction($action, $parameters = [], $status = 302, $headers = [])
            {
                return new \Illuminate\Http\RedirectResponse('/', $status, $headers);
            }

            public function redirectGuest($path, $status = 302, $headers = [], $secure = null)
            {
                return new \Illuminate\Http\RedirectResponse($path, $status, $headers);
            }

            public function redirectToIntended($default = '/', $status = 302, $headers = [], $secure = null)
            {
                return new \Illuminate\Http\RedirectResponse($default, $status, $headers);
            }
        });

        $this->capsule = new Capsule($app);
        $this->capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ], SocialAutomationServiceConfig::CONNECTION);
        $this->capsule->setEventDispatcher(new Dispatcher($app));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $app->instance('db', $this->capsule->getDatabaseManager());
        $app->instance('db.schema', $this->capsule->schema(SocialAutomationServiceConfig::CONNECTION));

        $this->createSchema();

        $validator = new PackagePublishValidator;
        $this->registry = new PackageRegistryService($validator);
        $this->syncService = new ScriptSyncService($validator, $this->registry);
        $this->controller = new RpaAdminScriptSyncController(
            new ScriptCatalogService,
            new ScriptSyncPlanner,
            $this->registry,
            new ScriptTombstoneService,
            new ScriptReleaseMediaService,
            new RpaAdminBackupService,
            $this->syncService,
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tempStorageDir);
        Facade::clearResolvedInstances();
        Container::setInstance($this->previousContainer);
        if ($this->previousApp instanceof Container) {
            Facade::setFacadeApplication($this->previousApp);
        }
        parent::tearDown();
    }

    private function createSchema(): void
    {
        $schema = Schema::connection(SocialAutomationServiceConfig::CONNECTION);

        $schema->create('sa_packages', function (Blueprint $t): void {
            $t->id();
            $t->string('package_key', 128)->unique();
            $t->string('kind', 32);
            $t->string('name', 255);
            $t->string('language_label', 32)->nullable();
            $t->text('description')->nullable();
            $t->boolean('is_enabled')->default(true);
            $t->boolean('is_primary')->default(false);
            $t->boolean('is_authentication')->default(false);
            $t->string('sharing_mode', 32)->default('site_specific');
            $t->string('global_cms_type', 64)->nullable();
            $t->unsignedBigInteger('global_cms_template_id')->nullable();
            $t->string('global_cms_path_key', 128)->nullable();
            $t->string('global_scope_key', 191)->nullable();
            $t->string('disabled_reason', 64)->nullable();
            $t->json('disabled_meta')->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->unsignedBigInteger('updated_by_user_id')->nullable();
            $t->string('sync_source', 64)->nullable();
            $t->timestamps();
        });

        $schema->create('sa_package_releases', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('package_id');
            $t->string('version', 64);
            $t->string('content_digest', 64);
            $t->string('execution_digest', 64)->nullable();
            $t->unsignedInteger('schema_version')->default(1);
            $t->json('runtime_compatibility')->nullable();
            $t->json('manifest');
            $t->string('status', 32)->default('published');
            $t->timestamp('published_at')->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->string('sync_source', 64)->nullable();
            $t->timestamps();
            $t->unique(['package_id', 'version']);
        });

        $schema->create('sa_package_dependencies', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('release_id');
            $t->string('dependency_package_key', 128);
            $t->string('dependency_version', 64);
            $t->string('dependency_digest', 64);
            $t->timestamps();
        });

        $schema->create('sa_script_release_assets', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('release_id');
            $t->string('asset_path', 255);
            $t->string('checksum_sha256', 64);
            $t->string('mime', 128)->default('application/octet-stream');
            $t->string('storage_path', 512);
            $t->unsignedBigInteger('size_bytes')->default(0);
            $t->timestamps();
        });

        $schema->create('sa_script_release_media', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('release_id');
            $t->string('kind', 32);
            $t->string('storage_disk', 64)->default('local');
            $t->string('storage_path', 512);
            $t->string('mime', 128)->default('video/webm');
            $t->unsignedBigInteger('size_bytes')->default(0);
            $t->string('checksum_sha256', 64)->nullable();
            $t->string('status', 32)->default('ready');
            $t->json('metadata')->nullable();
            $t->timestamps();
        });

        $schema->create('sa_action_tags', function (Blueprint $t): void {
            $t->id();
            $t->string('tag_key', 128)->unique();
            $t->string('site_key', 64)->nullable();
            $t->string('path_key', 64)->nullable();
            $t->string('action_key', 64)->nullable();
            $t->string('platform', 64)->nullable();
            $t->string('target', 64)->nullable();
            $t->string('action', 64)->nullable();
            $t->string('name', 255)->nullable();
            $t->boolean('is_enabled')->default(true);
            $t->timestamps();
        });

        $schema->create('sa_action_tag_scripts', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('action_tag_id');
            $t->string('action_tag_key', 128);
            $t->string('script_id', 128);
            $t->string('script_version', 64);
            $t->unsignedBigInteger('package_id');
            $t->unsignedBigInteger('release_id');
            $t->timestamps();
        });

        $schema->create('sa_script_variant_groups', function (Blueprint $t): void {
            $t->id();
            $t->string('group_uuid', 64)->unique();
            $t->string('name', 255);
            $t->string('scope_type', 32);
            $t->string('global_scope_key', 191)->nullable();
            $t->string('action_tag_key', 128)->nullable();
            $t->boolean('is_enabled')->default(true);
            $t->timestamps();
        });

        $schema->create('sa_script_variant_group_items', function (Blueprint $t): void {
            $t->id();
            $t->string('variant_uuid', 64)->nullable();
            $t->unsignedBigInteger('group_id');
            $t->unsignedBigInteger('package_id');
            $t->string('package_key', 128)->nullable();
            $t->unsignedBigInteger('release_id')->nullable();
            $t->string('script_version', 64)->nullable();
            $t->string('language_label', 32)->nullable();
            $t->unsignedInteger('priority')->default(1);
            $t->boolean('is_enabled')->default(true);
            $t->timestamps();
        });

        $schema->create('sa_campaigns', function (Blueprint $t): void {
            $t->id();
            $t->string('campaign_uuid', 64)->unique();
            $t->string('name', 255);
            $t->string('status', 32)->default('active');
            $t->string('script_id', 128)->nullable();
            $t->string('script_version', 64)->nullable();
            $t->string('script_content_digest', 64)->nullable();
            $t->timestamps();
        });

        $schema->create('sa_campaign_action_tags', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('campaign_id');
            $t->unsignedBigInteger('action_tag_id');
            $t->unsignedBigInteger('package_id');
            $t->unsignedBigInteger('release_id');
            $t->string('script_id', 128);
            $t->string('script_version', 64);
            $t->boolean('is_enabled')->default(true);
            $t->timestamps();
        });

        $schema->create('sa_audit_logs', function (Blueprint $t): void {
            $t->id();
            $t->string('actor_type', 32);
            $t->string('actor_id', 64)->nullable();
            $t->string('action', 128);
            $t->string('subject_type', 64);
            $t->string('subject_id', 64);
            $t->json('context')->nullable();
            $t->timestamp('occurred_at')->nullable();
            $t->timestamps();
        });
    }

    /**
     * @return array{package: SaPackage, release: SaPackageRelease, asset_bytes: string, asset_sha: string}
     */
    private function seedPublishedScript(string $packageKey = 'fb.post', bool $isEnabled = true): array
    {
        SaActionTag::query()->firstOrCreate(
            ['tag_key' => 'facebook.post.create'],
            ['platform' => 'facebook', 'target' => 'post', 'action' => 'create', 'name' => 'FB Post', 'is_enabled' => true],
        );

        $assetBytes = 'fake-png-roi-image-bytes';
        $assetSha = hash('sha256', $assetBytes);
        $manifest = [
            'script_id' => $packageKey,
            'action_tag_key' => 'facebook.post.create',
            'variables' => [['id' => 'v1', 'key' => 'title', 'type' => 'text']],
            'outputs' => [['key' => 'post_url', 'type' => 'text']],
            'steps' => [
                [
                    'id' => 'step-1',
                    'action' => 'CLICK_TARGET',
                    'target' => ['matchTo' => 'middle', 'ocr' => ['confirmed' => true, 'text' => 'Publish']],
                ],
                [
                    'id' => 'step-2',
                    'action' => 'TYPE_TEXT',
                    'value' => '{{title}}',
                ],
            ],
            'assets' => [
                ['path' => 'roi/publish-btn.png', 'sha256' => $assetSha, 'kind' => 'roi'],
            ],
            'video_demo' => [
                'artifact_ref' => 'demos/fb-post/run-1/recording.webm',
                'sha256' => hash('sha256', 'demo-video-bytes'),
                'mime' => 'video/webm',
                'test_run_id' => 'run-1',
                'reviewed' => true,
                'audio' => false,
                'desktop_capture' => false,
            ],
            'default_pattern' => 'desktop_1280',
            'zoom' => 100,
        ];

        $res = $this->registry->publish([
            'package_key' => $packageKey,
            'kind' => 'script',
            'name' => 'Facebook Post',
            'description' => 'Initial script',
            'version' => '1',
            'schema_version' => 1,
            'manifest' => $manifest,
            'dependencies' => [],
        ], ['user_id' => 1, 'sync_source' => 'rpa_admin']);
        self::assertTrue((bool) ($res->toArray()['ok'] ?? false), json_encode($res->toArray()));

        $pkg = SaPackage::query()->where('package_key', $packageKey)->firstOrFail();
        $pkg->update([
            'is_enabled' => $isEnabled,
            'language_label' => 'EN',
        ]);

        $rel = SaPackageRelease::query()->where('package_id', $pkg->id)->where('version', '1')->firstOrFail();

        $assetPath = sprintf('social-automation/script-assets/%d/%s', $rel->id, $assetSha);
        Storage::disk('local')->put($assetPath, $assetBytes);
        SaScriptReleaseAsset::query()->create([
            'release_id' => $rel->id,
            'asset_path' => 'roi/publish-btn.png',
            'checksum_sha256' => $assetSha,
            'mime' => 'image/png',
            'storage_path' => $assetPath,
            'size_bytes' => strlen($assetBytes),
        ]);

        $videoPath = sprintf('social-automation/script-media/%d/1/video.webm', $rel->id);
        $thumbPath = sprintf('social-automation/script-media/%d/1/thumb.jpg', $rel->id);
        Storage::disk('local')->put($videoPath, 'demo-video-bytes');
        Storage::disk('local')->put($thumbPath, 'demo-thumb-bytes');

        SaScriptReleaseMedia::query()->create([
            'release_id' => $rel->id,
            'kind' => SaScriptReleaseMedia::KIND_VIDEO_DEMO,
            'storage_disk' => 'local',
            'storage_path' => $videoPath,
            'mime' => 'video/webm',
            'size_bytes' => 16,
            'checksum_sha256' => hash('sha256', 'demo-video-bytes'),
            'status' => SaScriptReleaseMedia::STATUS_READY,
        ]);
        SaScriptReleaseMedia::query()->create([
            'release_id' => $rel->id,
            'kind' => SaScriptReleaseMedia::KIND_THUMBNAIL,
            'storage_disk' => 'local',
            'storage_path' => $thumbPath,
            'mime' => 'image/jpeg',
            'size_bytes' => 16,
            'checksum_sha256' => hash('sha256', 'demo-thumb-bytes'),
            'status' => SaScriptReleaseMedia::STATUS_READY,
        ]);

        $rel->update(['status' => 'published', 'published_at' => now()]);

        return [
            'package' => $pkg->fresh(),
            'release' => $rel->fresh(),
            'asset_bytes' => $assetBytes,
            'asset_sha' => $assetSha,
        ];
    }

    public function test_1_normal_sync_without_changes_is_idempotent(): void
    {
        $seeded = $this->seedPublishedScript();

        $res = $this->syncService->sync([
            'mode' => 'normal',
            'package_key' => 'fb.post',
        ], ['user_id' => 1]);

        self::assertSame(200, $res['http']);
        self::assertTrue($res['body']['ok']);
        self::assertSame('normal', $res['body']['mode']);
        self::assertSame('no_changes', $res['body']['outcome']);
        self::assertFalse($res['body']['changed']);
        self::assertTrue($res['body']['idempotent']);
        self::assertSame((int) $seeded['release']->id, $res['body']['release_id']);
        self::assertSame('1', $res['body']['version']);
        self::assertSame(1, SaPackageRelease::query()->where('package_id', $seeded['package']->id)->count());
    }

    public function test_2_3_4_5_normal_sync_with_changed_steps_preserves_identity_demo_state_and_immutability(): void
    {
        $seeded = $this->seedPublishedScript('fb.post', false);
        $v1Release = $seeded['release'];
        $v1Digest = $v1Release->content_digest;
        $v1Steps = $v1Release->manifest['steps'];

        $updatedManifest = $v1Release->manifest;
        $updatedManifest['steps'][] = ['id' => 'step-3', 'action' => 'WAIT', 'ms' => 500];
        unset($updatedManifest['video_demo']); // Omit video_demo to verify preservation

        $res = $this->syncService->sync([
            'mode' => 'normal',
            'package_key' => 'fb.post',
            'manifest' => $updatedManifest,
        ], ['user_id' => 1]);

        self::assertSame(201, $res['http']);
        self::assertTrue($res['body']['ok']);
        self::assertSame('synced', $res['body']['outcome']);
        self::assertTrue($res['body']['changed']);
        self::assertSame('fb.post', $res['body']['package_key']);
        self::assertSame('fb.post', $res['body']['script_id']);
        self::assertSame('2', $res['body']['version']);
        self::assertTrue($res['body']['demo_preserved']);
        self::assertFalse($res['body']['demo_required']);
        self::assertFalse($res['body']['predecessor_disabled']);
        self::assertSame('published', $res['body']['status']);

        // 3. Same Script identity & preserved disabled state
        $pkg = SaPackage::query()->where('package_key', 'fb.post')->firstOrFail();
        self::assertSame((int) $seeded['package']->id, (int) $pkg->id);
        self::assertFalse((bool) $pkg->is_enabled);
        self::assertSame('EN', $pkg->language_label);

        // 4. Existing Demo preserved on new release
        $v2Release = SaPackageRelease::query()->findOrFail($res['body']['release_id']);
        self::assertNotNull($v2Release->manifest['video_demo'] ?? null);
        self::assertSame(2, SaScriptReleaseMedia::query()->where('release_id', $v2Release->id)->count());
        self::assertSame(1, SaScriptReleaseAsset::query()->where('release_id', $v2Release->id)->count());

        // 5. Existing published Release v1 remains completely immutable
        $v1Reloaded = SaPackageRelease::query()->findOrFail($v1Release->id);
        self::assertSame($v1Digest, $v1Reloaded->content_digest);
        self::assertSame($v1Steps, $v1Reloaded->manifest['steps']);
        self::assertSame('published', $v1Reloaded->status);
    }

    public function test_6_7_8_9_new_version_clones_reusable_assets_strips_demo_and_disables_predecessor(): void
    {
        $seeded = $this->seedPublishedScript('fb.post', true);

        $res = $this->syncService->sync([
            'mode' => 'new_version',
            'source_package_key' => 'fb.post',
            'name' => 'Facebook Post V2',
            'idempotency_key' => 'idem-nv-1',
        ], ['user_id' => 1]);

        self::assertSame(201, $res['http']);
        self::assertTrue($res['body']['ok']);
        self::assertSame('new_version', $res['body']['mode']);
        self::assertSame('new_version_created', $res['body']['outcome']);
        self::assertSame('fb.post-v2', $res['body']['package_key']);
        self::assertSame('fb.post-v2', $res['body']['script_id']);
        self::assertSame('1', $res['body']['version']);
        self::assertSame('pending_media', $res['body']['status']);
        self::assertTrue($res['body']['demo_required']);
        self::assertTrue($res['body']['predecessor_disabled']);
        self::assertTrue($res['body']['assets_complete']);

        // 6. Distinct Script identity
        $successor = SaPackage::query()->where('package_key', 'fb.post-v2')->firstOrFail();
        self::assertNotSame((int) $seeded['package']->id, (int) $successor->id);
        self::assertTrue((bool) $successor->is_enabled);

        // 7. Preserves reusable Steps, OCR config, variables, and Assets
        $succRelease = SaPackageRelease::query()->findOrFail($res['body']['release_id']);
        self::assertCount(2, $succRelease->manifest['steps']);
        self::assertSame('Publish', $succRelease->manifest['steps'][0]['target']['ocr']['text']);
        self::assertSame('desktop_1280', $succRelease->manifest['default_pattern']);
        $succAssets = SaScriptReleaseAsset::query()->where('release_id', $succRelease->id)->get();
        self::assertCount(1, $succAssets);
        self::assertSame($seeded['asset_sha'], $succAssets[0]->checksum_sha256);
        self::assertTrue(Storage::disk('local')->exists($succAssets[0]->storage_path));

        // 8. No Demo association on successor
        self::assertArrayNotHasKey('video_demo', $succRelease->manifest);
        self::assertSame(0, SaScriptReleaseMedia::query()->where('release_id', $succRelease->id)->count());

        // 9. Predecessor disabled after successor creation
        $pred = SaPackage::query()->findOrFail($seeded['package']->id);
        self::assertFalse((bool) $pred->is_enabled);
        self::assertSame(ScriptSyncService::SUPERSEDED_REASON, $pred->disabled_reason);
    }

    public function test_10_failure_does_not_disable_predecessor_or_leave_orphans(): void
    {
        $seeded = $this->seedPublishedScript('fb.post', true);

        $res = $this->syncService->sync([
            'mode' => 'new_version',
            'source_package_key' => 'fb.post',
            '_simulate_failure_before_disable' => true,
        ], ['user_id' => 1]);

        self::assertSame(500, $res['http']);
        self::assertFalse($res['body']['ok']);
        self::assertFalse($res['body']['predecessor_disabled']);

        // Predecessor remains enabled and untouched
        $pred = SaPackage::query()->findOrFail($seeded['package']->id);
        self::assertTrue((bool) $pred->is_enabled);
        self::assertNull($pred->disabled_reason);

        // No orphaned successor package or release
        self::assertSame(1, SaPackage::query()->count());
        self::assertSame(1, SaPackageRelease::query()->count());
        self::assertSame(1, SaScriptReleaseAsset::query()->count());
    }

    public function test_11_repeat_new_version_request_is_idempotent_without_duplicate_successors(): void
    {
        $this->seedPublishedScript('fb.post', true);

        $first = $this->syncService->sync([
            'mode' => 'new_version',
            'source_package_key' => 'fb.post',
            'idempotency_key' => 'retry-key-123',
        ], ['user_id' => 1]);
        self::assertSame(201, $first['http']);

        $repeat = $this->syncService->sync([
            'mode' => 'new_version',
            'source_package_key' => 'fb.post',
            'idempotency_key' => 'retry-key-123',
        ], ['user_id' => 1]);
        self::assertSame(200, $repeat['http']);
        self::assertTrue($repeat['body']['ok']);
        self::assertTrue($repeat['body']['idempotent']);
        self::assertFalse($repeat['body']['changed']);
        self::assertSame($first['body']['package_key'], $repeat['body']['package_key']);
        self::assertSame($first['body']['release_id'], $repeat['body']['release_id']);
        self::assertSame(2, SaPackage::query()->count());

        // Different idempotency key against already-superseded predecessor returns 409 conflict
        $conflict = $this->syncService->sync([
            'mode' => 'new_version',
            'source_package_key' => 'fb.post',
            'idempotency_key' => 'different-key-456',
        ], ['user_id' => 1]);
        self::assertSame(409, $conflict['http']);
        self::assertFalse($conflict['body']['ok']);
        self::assertContains('predecessor_already_superseded', $conflict['body']['errors']);
    }

    public function test_12_historical_campaign_pins_and_predecessor_releases_remain_valid(): void
    {
        $seeded = $this->seedPublishedScript('fb.post', true);
        $tag = SaActionTag::query()->where('tag_key', 'facebook.post.create')->firstOrFail();

        $campaign = SaCampaign::query()->create([
            'campaign_uuid' => 'camp-hist-1',
            'name' => 'Pinned Campaign',
            'status' => 'active',
            'script_id' => 'fb.post',
            'script_version' => '1',
            'script_content_digest' => $seeded['release']->content_digest,
        ]);
        SaCampaignActionTag::query()->create([
            'campaign_id' => $campaign->id,
            'action_tag_id' => $tag->id,
            'package_id' => $seeded['package']->id,
            'release_id' => $seeded['release']->id,
            'script_id' => 'fb.post',
            'script_version' => '1',
            'is_enabled' => true,
        ]);

        $res = $this->syncService->sync([
            'mode' => 'new_version',
            'source_package_key' => 'fb.post',
        ], ['user_id' => 1]);
        self::assertSame(201, $res['http']);

        // Campaign pin unchanged and predecessor release still published + pullable via registry
        $pin = SaCampaignActionTag::query()->where('campaign_id', $campaign->id)->firstOrFail();
        self::assertSame((int) $seeded['package']->id, (int) $pin->package_id);
        self::assertSame((int) $seeded['release']->id, (int) $pin->release_id);
        self::assertSame('1', $pin->script_version);

        $pulled = $this->registry->findRelease('fb.post', '1');
        self::assertNotNull($pulled);
        self::assertSame('published', $pulled['status']);
        self::assertSame($seeded['release']->content_digest, $pulled['content_digest']);
    }

    public function test_13_vi_en_variant_group_assignments_are_not_silently_corrupted(): void
    {
        $seeded = $this->seedPublishedScript('fb.post', true);

        $group = SaScriptVariantGroup::query()->create([
            'group_uuid' => 'vg-1',
            'name' => 'FB Post Group',
            'scope_type' => SaScriptVariantGroup::SCOPE_SITE_ACTION,
            'action_tag_key' => 'facebook.post.create',
            'is_enabled' => true,
        ]);
        SaScriptVariantGroupItem::query()->create([
            'variant_uuid' => 'vgi-1',
            'group_id' => $group->id,
            'package_id' => $seeded['package']->id,
            'package_key' => 'fb.post',
            'release_id' => $seeded['release']->id,
            'script_version' => '1',
            'language_label' => 'EN',
            'priority' => 1,
            'is_enabled' => true,
        ]);

        $res = $this->syncService->sync([
            'mode' => 'new_version',
            'source_package_key' => 'fb.post',
        ], ['user_id' => 1]);
        self::assertSame(201, $res['http']);

        // Variant group items untouched; successor not silently injected as Primary/Fallback
        $items = SaScriptVariantGroupItem::query()->where('group_id', $group->id)->get();
        self::assertCount(1, $items);
        self::assertSame((int) $seeded['package']->id, (int) $items[0]->package_id);
        self::assertSame(1, (int) $items[0]->priority);

        $successor = SaPackage::query()->where('package_key', 'fb.post-v2')->firstOrFail();
        self::assertFalse((bool) $successor->is_primary);
        self::assertSame(SaPackage::SHARING_SITE_SPECIFIC, $successor->sharing_mode);
    }

    public function test_14_authorization_and_invalid_input_handling(): void
    {
        $gate = new RpaAdminRoleGate;
        $staff = new User;
        $staff->role = User::ROLE_STAFF;
        self::assertFalse($gate->allows($staff));
        self::assertFalse($gate->allows(null));

        // Controller strips _simulate_failure_before_disable, user_id, id
        $this->seedPublishedScript('fb.post', true);

        $invalidModeReq = Request::create('/api/v1/social-automation/rpa-admin/scripts/sync', 'POST', [
            'mode' => 'invalid_mode',
            'package_key' => 'fb.post',
        ]);
        $invalidResp = $this->controller->sync($invalidModeReq);
        self::assertSame(422, $invalidResp->getStatusCode());
        $invalidData = $invalidResp->getData(true);
        self::assertFalse($invalidData['ok']);
        self::assertContains('sync_mode_invalid', $invalidData['errors']);

        $notFoundReq = Request::create('/api/v1/social-automation/rpa-admin/scripts/sync', 'POST', [
            'mode' => 'normal',
            'package_key' => 'does.not.exist',
        ]);
        $notFoundResp = $this->controller->sync($notFoundReq);
        self::assertSame(404, $notFoundResp->getStatusCode());

        // Forbidden manifest field rejected
        $forbiddenRes = $this->syncService->sync([
            'mode' => 'new_version',
            'source_package_key' => 'fb.post',
            'manifest' => ['code' => 'eval("bad")'],
        ], ['user_id' => 1]);
        self::assertSame(422, $forbiddenRes['http']);
        self::assertContains('manifest_forbidden_field:code', $forbiddenRes['body']['errors']);
    }

    private function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if (! is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->deleteDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
