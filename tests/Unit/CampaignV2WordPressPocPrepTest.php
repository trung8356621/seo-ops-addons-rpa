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
use Omnichannel\Addons\SocialAutomation\Domain\CampaignV2ExecutionPolicy;
use Omnichannel\Addons\SocialAutomation\Enums\AgentAvailability;
use Omnichannel\Addons\SocialAutomation\Enums\SessionReadyStatus;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\StaffCampaignController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\StaffSettingsController;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTagScript;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaArtifact;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContent;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContentAssignment;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaSessionAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Orchestration\CampaignOrchestrator;
use Omnichannel\Addons\SocialAutomation\Services\CampaignService;
use Omnichannel\Addons\SocialAutomation\Services\ScriptResolutionService;
use Omnichannel\Addons\SocialAutomation\Services\SocialAutomationSettingsService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use PHPUnit\Framework\TestCase;

final class CampaignV2WordPressPocPrepTest extends TestCase
{
    private Capsule $capsule;

    private ?Container $previousApp = null;

    private string $root;

    private StaffCampaignController $campaignsController;

    private StaffSettingsController $settingsController;

    private CampaignOrchestrator $orchestrator;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite unavailable');
        }

        $this->root = dirname(__DIR__, 2);
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
                return 42;
            }
        });
        $access = new class extends SocialAutomationAccess {
            public function __construct() {}

            public function assertCanAccess(?User $user = null): void {}

            public function assertCanAccessAdminPages(?User $user = null): void {}

            public function assertCanManageCampaigns(?User $user = null): void {}
        };
        $inst = new class extends SocialAutomationInstallationResolver {
            public function __construct() {}

            public function resolveInstallationId(): string
            {
                return 'inst-wp-poc-test';
            }
        };
        $app->instance(SocialAutomationAccess::class, $access);
        $app->instance(SocialAutomationInstallationResolver::class, $inst);
        $app->instance(ScriptResolutionService::class, new ScriptResolutionService);
        $app->instance(ResponseFactory::class, $this->makeResponseFactory());

        $this->createFullSchema();
        $this->runMigration($this->root.'/database/migrations/2026_11_02_150000_create_sa_campaign_tables.php');
        $this->runMigration($this->root.'/database/migrations/2026_11_20_100000_add_campaign_shortcode_v2_columns.php');
        $this->runMigration($this->root.'/database/migrations/2026_11_21_140000_create_sa_campaign_v2_tables.php');
        $this->runMigration($this->root.'/database/migrations/2026_11_22_100000_create_sa_settings_table.php');

        CampaignV2ExecutionPolicy::setTestOverride(true);

        $this->orchestrator = new CampaignOrchestrator;
        $this->campaignsController = new StaffCampaignController(
            new CampaignService($this->orchestrator),
            $access,
            $inst,
        );
        $this->settingsController = new StaffSettingsController(
            new SocialAutomationSettingsService,
            $access,
            $inst,
        );
    }

    protected function tearDown(): void
    {
        CampaignV2ExecutionPolicy::setTestOverride(null);
        Facade::clearResolvedInstances();
        if ($this->previousApp instanceof Container) {
            Container::setInstance($this->previousApp);
            Facade::setFacadeApplication($this->previousApp);
        } else {
            Container::setInstance(null);
            Facade::setFacadeApplication(null);
        }
        parent::tearDown();
    }

    public function test_01_02_03_12_global_settings_interval_and_shared_v2_scheduler_timer(): void
    {
        // 12. Feature flag default OFF
        CampaignV2ExecutionPolicy::setTestOverride(null);
        self::assertFalse(CampaignV2ExecutionPolicy::isEnabled());
        CampaignV2ExecutionPolicy::setTestOverride(true);

        // 1. Default is 3600s
        $getResp = $this->settingsController->show();
        self::assertSame(200, $getResp->getStatusCode());
        self::assertSame(3600, $getResp->getData(true)['data']['scheduler_interval_seconds']);

        // Validation rejects < 60 and > 86400
        $badLow = $this->settingsController->update(Request::create('/social-automation/api/settings', 'PUT', ['scheduler_interval_seconds' => 30]));
        self::assertSame(422, $badLow->getStatusCode());
        $badHigh = $this->settingsController->update(Request::create('/social-automation/api/settings', 'PUT', ['scheduler_interval_seconds' => 999999]));
        self::assertSame(422, $badHigh->getStatusCode());

        // 2. Set 120s for TEST
        $putResp = $this->settingsController->update(Request::create('/social-automation/api/settings', 'PUT', ['scheduler_interval_seconds' => 120]));
        self::assertSame(200, $putResp->getStatusCode());
        self::assertSame(120, $putResp->getData(true)['data']['scheduler_interval_seconds']);
        self::assertSame(120, $this->settingsController->show()->getData(true)['data']['scheduler_interval_seconds']);

        // 3. Multiple Running V2 Campaigns share the same global 120s interval
        $this->seedWordPressFixture();
        $uuid1 = $this->createWpDraft('WP Camp 1', 'art-1', 3600);
        $uuid2 = $this->createWpDraft('WP Camp 2', 'art-2', 900);

        $this->campaignsController->start($uuid1);
        $this->campaignsController->start($uuid2);

        $t0 = now();
        $this->orchestrator->recheckDueCampaigns($t0);

        $c1 = SaCampaign::query()->where('campaign_uuid', $uuid1)->firstOrFail();
        $c2 = SaCampaign::query()->where('campaign_uuid', $uuid2)->firstOrFail();
        self::assertSame($t0->copy()->addSeconds(120)->getTimestamp(), $c1->next_recheck_at?->getTimestamp());
        self::assertSame($t0->copy()->addSeconds(120)->getTimestamp(), $c2->next_recheck_at?->getTimestamp());
    }

    public function test_04_05_06_07_runtime_compatibility_matching_and_incompatible_rejection(): void
    {
        $this->seedWordPressFixture(runtimeCompat: [
            'runtime_min' => '1.5.0',
            'required_capabilities' => ['playwright'],
        ]);

        // 4. Zero-Client Start succeeds
        $uuid = $this->createWpDraft('WP Runtime Compat', 'wp-art-1');
        $start = $this->campaignsController->start($uuid);
        self::assertSame(200, $start->getStatusCode());
        self::assertSame('running', $start->getData(true)['data']['status']);
        self::assertSame(0, SaJob::query()->count());

        // 7a. Incompatible runtime version (1.2.0 < 1.5.0) -> rejected, remains waiting
        $oldAgent = $this->seedWordPressClient('1.2.0', ['playwright']);
        $this->orchestrator->recheckDueCampaigns(now()->addSeconds(60));
        self::assertSame(0, SaJob::query()->count());
        self::assertSame('waiting_resources', SaCampaignContent::query()->first()->status);

        // 7b. Missing runtime_version -> rejected safely
        $oldAgent->update(['runtime_version' => null]);
        $this->orchestrator->recheckDueCampaigns(now()->addMinutes(65));
        self::assertSame(0, SaJob::query()->count());

        // 7c. Missing required capability -> rejected safely
        $oldAgent->update(['runtime_version' => '1.6.0', 'capabilities' => ['fetch']]);
        $this->orchestrator->recheckDueCampaigns(now()->addMinutes(130));
        self::assertSame(0, SaJob::query()->count());

        // 5 & 6. Compatible client becomes available -> dispatches immediately
        $oldAgent->update(['runtime_version' => '1.6.0', 'capabilities' => ['playwright', 'screenshot']]);
        $cycle = $this->orchestrator->recheckDueCampaigns(now()->addMinutes(195));
        self::assertSame(1, $cycle['dispatched']);
        self::assertSame(1, SaJob::query()->count());
    }

    public function test_08_09_10_11_wordpress_payload_screenshot_artifact_and_v1_compat(): void
    {
        $this->seedWordPressFixture();
        $agent = $this->seedWordPressClient('1.5.0', ['playwright']);
        $session = SaBrowserSession::query()->where('agent_id', $agent->id)->firstOrFail();

        $uuid = $this->createWpDraft('WP POC E2E', 'wp-poc-001');
        $this->campaignsController->start($uuid);

        // 8. Claim & verify WordPress Job payload
        $claim = $this->orchestrator->claimNext((string) $agent->agent_uuid, [(string) $session->session_uuid], null, 'inst-wp-poc-test');
        self::assertTrue($claim['ok']);
        $payload = $claim['data'];
        self::assertSame(2, $payload['plan_version']);
        self::assertSame('wp_site_1', $payload['site_key']);
        self::assertSame('wp_site_1.create_post', $payload['action_tag_key']);
        self::assertSame('wp_site_1.create_post', $payload['script_id']);
        self::assertSame(['title' => 'WP Draft Title', 'body' => 'WP Draft Body'], $payload['input']);

        // Acknowledge
        $ack = $this->orchestrator->acknowledge((string) $payload['job_id'], ['attempt_id' => $payload['attempt_id'], 'lease_token' => $payload['lease_token']], null, 'inst-wp-poc-test');
        self::assertTrue($ack['ok']);

        // 9. Upload screenshot artifact & report succeeded result
        $job = SaJob::query()->where('job_uuid', $payload['job_id'])->firstOrFail();
        $artifact = SaArtifact::query()->create([
            'artifact_uuid' => (string) Str::uuid(),
            'job_id' => $job->id,
            'kind' => 'screenshot',
            'mime' => 'image/png',
            'size_bytes' => 1024,
            'checksum_sha256' => hash('sha256', 'fake-png'),
            'storage_path' => 'artifacts/wp-poc.png',
            'metadata' => ['phase' => 'final'],
        ]);

        $res = $this->orchestrator->result(
            (string) $payload['job_id'],
            [
                'status' => 'succeeded',
                'attempt_id' => $payload['attempt_id'],
                'lease_token' => $payload['lease_token'],
                'artifacts' => [
                    [
                        'artifact_uuid' => $artifact->artifact_uuid,
                        'kind' => 'screenshot',
                        'mime' => 'image/png',
                        'size_bytes' => 1024,
                        'checksum_sha256' => $artifact->checksum_sha256,
                        'metadata' => ['phase' => 'final'],
                    ],
                ],
            ],
            null,
            'inst-wp-poc-test',
        );
        self::assertTrue($res['ok'], (string) json_encode($res));

        // Verify artifact linked to attempt and campaign completed
        $artifact->refresh();
        self::assertNotNull($artifact->attempt_id);
        self::assertSame('success', SaCampaignContent::query()->first()->status);
        self::assertSame('success', SaCampaignContentAssignment::query()->first()->status);
        self::assertSame('completed', SaCampaign::query()->where('campaign_uuid', $uuid)->value('status'));

        // 10. Idempotent duplicate result returns ok + idempotent: true
        $dupRes = $this->orchestrator->result(
            (string) $payload['job_id'],
            ['status' => 'succeeded', 'attempt_id' => $payload['attempt_id'], 'lease_token' => $payload['lease_token']],
            null,
            'inst-wp-poc-test',
        );
        self::assertTrue($dupRes['ok']);
        self::assertTrue($dupRes['idempotent'] ?? false);

        // 11. V1 campaign create remains compatible
        $v1Resp = $this->campaignsController->store(Request::create('/social-automation/api/campaigns', 'POST', [
            'name' => 'Legacy V1 Check',
            'platform' => 'wp_site_1',
            'execution_mode' => 'one_account_one_job',
            'run_mode' => 'live',
            'script_id' => 'wp_site_1.create_post',
            'script_version' => '1.0.0',
            'contents' => [['external_ref' => 'v1-1', 'input' => ['title' => 'T', 'body' => 'B']]],
        ]));
        self::assertSame(201, $v1Resp->getStatusCode());
        self::assertSame(1, $v1Resp->getData(true)['data']['plan_version']);
    }

    private function createWpDraft(string $name, string $ref, int $recheckSeconds = 3600): string
    {
        $resp = $this->campaignsController->store(Request::create('/social-automation/api/campaigns', 'POST', [
            'plan_version' => 2,
            'name' => $name,
            'run_mode' => 'live',
            'resource_recheck_seconds' => $recheckSeconds,
            'action_tags' => [
                [
                    'action_tag_key' => 'wp_site_1.create_post',
                    'shortcode_mapping' => [
                        'entries' => [
                            ['shortcode' => 'title', 'variable' => 'title'],
                            ['shortcode' => 'body', 'variable' => 'body'],
                        ],
                    ],
                ],
            ],
            'contents' => [
                [
                    'external_ref' => $ref,
                    'raw_source' => '[title]WP Draft Title[/title][body]WP Draft Body[/body]',
                    'assignments' => [['action_tag_key' => 'wp_site_1.create_post']],
                ],
            ],
        ]));
        self::assertSame(201, $resp->getStatusCode());

        return (string) $resp->getData(true)['data']['id'];
    }

    /**
     * @param  array<string, mixed>  $runtimeCompat
     */
    private function seedWordPressFixture(array $runtimeCompat = []): void
    {
        $site = SaSite::query()->create(['key' => 'wp_site_1', 'name' => 'WP Site 1', 'base_url' => 'https://wp1.example.com', 'cms_type' => 'wordpress']);
        $path = SaPath::query()->create(['site_id' => $site->id, 'key' => 'create_post', 'name' => 'Create Post', 'url_pattern' => '/wp-admin/post-new.php']);
        $tag = SaActionTag::query()->create(['site_id' => $site->id, 'path_id' => $path->id, 'site_key' => 'wp_site_1', 'path_key' => 'create_post', 'platform' => 'wp_site_1', 'target' => 'create_post', 'action' => '', 'tag_key' => 'wp_site_1.create_post', 'is_enabled' => true]);
        $pkg = SaPackage::query()->create(['package_key' => 'wp_site_1.create_post', 'kind' => 'script', 'name' => 'WP Create Post', 'is_enabled' => true, 'sharing_mode' => 'site_specific']);
        $rel = SaPackageRelease::query()->create([
            'package_id' => $pkg->id,
            'version' => '1.0.0',
            'content_digest' => hash('sha256', 'wp1'),
            'schema_version' => 1,
            'runtime_compatibility' => $runtimeCompat,
            'manifest' => [
                'variables' => [
                    ['key' => 'title', 'type' => 'string', 'required' => true],
                    ['key' => 'body', 'type' => 'text', 'required' => true],
                ],
            ],
            'status' => 'published',
            'published_at' => now(),
        ]);
        SaActionTagScript::query()->create(['action_tag_id' => $tag->id, 'action_tag_key' => $tag->tag_key, 'package_id' => $pkg->id, 'release_id' => $rel->id, 'script_id' => $pkg->package_key, 'script_version' => '1.0.0']);
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function seedWordPressClient(string $runtimeVersion, array $capabilities): SaAgent
    {
        $agent = SaAgent::query()->create([
            'agent_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-wp-poc-test',
            'name' => 'WP Agent',
            'availability' => AgentAvailability::Available->value,
            'active_jobs' => 0,
            'max_concurrency' => 2,
            'runtime_version' => $runtimeVersion,
            'capabilities' => $capabilities,
            'last_heartbeat_at' => now(),
            'agent_kind' => 'runner',
        ]);

        $account = SaAccount::query()->create([
            'account_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-wp-poc-test',
            'platform' => 'wp_site_1',
            'domain' => 'wp1.example.com',
            'platform_account_id' => 'wp_admin_1',
            'verified' => true,
        ]);

        $session = SaBrowserSession::query()->create([
            'session_uuid' => (string) Str::uuid(),
            'agent_id' => $agent->id,
            'account_id' => $account->id,
            'ready_status' => SessionReadyStatus::Ready->value,
            'dispatch_enabled' => true,
            'session_state' => 'verified',
        ]);

        SaSessionAccount::query()->create([
            'browser_session_id' => $session->id,
            'account_id' => $account->id,
            'is_active' => true,
        ]);

        return $agent;
    }

    private function runMigration(string $path): void
    {
        $migration = require $path;
        $migration->up();
    }

    private function createFullSchema(): void
    {
        $schema = Schema::connection(SocialAutomationServiceConfig::CONNECTION);

        $schema->create('sa_sites', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 128)->unique();
            $table->string('name', 255);
            $table->string('base_url', 512)->nullable();
            $table->string('base_url_mode', 32)->default('fixed');
            $table->string('cms_type', 64)->nullable();
            $table->timestamps();
        });

        $schema->create('sa_paths', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('key', 128);
            $table->string('name', 255);
            $table->string('url_pattern', 512)->nullable();
            $table->json('path_inputs')->nullable();
            $table->boolean('is_authentication')->default(false);
            $table->timestamps();
        });

        $schema->create('sa_action_tags', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('path_id')->nullable();
            $table->unsignedBigInteger('action_id')->nullable();
            $table->string('site_key', 128)->nullable();
            $table->string('path_key', 128)->nullable();
            $table->string('action_key', 128)->nullable();
            $table->string('platform', 128);
            $table->string('target', 128);
            $table->string('action', 128)->default('');
            $table->string('tag_key', 191)->unique();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        $schema->create('sa_packages', function (Blueprint $table): void {
            $table->id();
            $table->string('package_key', 191)->unique();
            $table->string('kind', 32);
            $table->string('name', 255);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_authentication')->default(false);
            $table->string('sharing_mode', 32)->default('site_specific');
            $table->timestamps();
        });

        $schema->create('sa_package_releases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('package_id');
            $table->string('version', 64);
            $table->string('content_digest', 64)->nullable();
            $table->string('execution_digest', 64)->nullable();
            $table->unsignedInteger('schema_version')->default(1);
            $table->json('runtime_compatibility')->nullable();
            $table->json('manifest')->nullable();
            $table->string('status', 32)->default('published');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        $schema->create('sa_action_tag_scripts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('action_tag_id')->nullable();
            $table->string('action_tag_key', 191);
            $table->unsignedBigInteger('package_id')->nullable();
            $table->unsignedBigInteger('release_id')->nullable();
            $table->string('script_id', 191)->nullable();
            $table->string('script_version', 64)->nullable();
            $table->timestamps();
        });

        $schema->create('sa_agents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('agent_uuid')->unique();
            $table->string('installation_id', 128);
            $table->string('name', 255)->nullable();
            $table->string('availability', 32)->default('available');
            $table->unsignedInteger('active_jobs')->default(0);
            $table->unsignedInteger('max_concurrency')->default(1);
            $table->json('capabilities')->nullable();
            $table->string('runtime_version', 64)->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->string('agent_kind', 32)->default('runner');
            $table->timestamps();
        });

        $schema->create('sa_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('account_uuid')->unique();
            $table->string('installation_id', 128);
            $table->string('platform', 64);
            $table->string('domain', 255)->nullable();
            $table->string('platform_account_id', 255)->nullable();
            $table->boolean('verified')->default(false);
            $table->string('identity_source', 64)->nullable();
            $table->timestamps();
        });

        $schema->create('sa_browser_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('session_uuid')->unique();
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('ready_status', 32)->default('ready');
            $table->boolean('dispatch_enabled')->default(true);
            $table->string('session_state', 32)->default('verified');
            $table->json('capabilities')->nullable();
            $table->unsignedBigInteger('locked_job_id')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('lock_expires_at')->nullable();
            $table->timestamp('last_task_at')->nullable();
            $table->timestamps();
        });

        $schema->create('sa_session_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('browser_session_id');
            $table->unsignedBigInteger('account_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $schema->create('sa_account_device_affinities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id')->unique();
            $table->unsignedBigInteger('agent_id');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('affinity_until')->nullable();
            $table->timestamps();
        });

        $schema->create('sa_account_execution_leases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('browser_session_id');
            $table->unsignedBigInteger('job_id');
            $table->string('status', 32)->default('active');
            $table->timestamp('acquired_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });

        $schema->create('sa_tasks', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        $schema->create('sa_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('job_uuid')->unique();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('browser_session_id')->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('package_key', 191)->nullable();
            $table->string('package_version', 64)->nullable();
            $table->string('content_digest', 64)->nullable();
            $table->string('status', 32)->default('reserved');
            $table->string('idempotency_key', 191)->unique();
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->string('dispatch_source', 32)->nullable();
            $table->timestamps();
        });

        $schema->create('sa_job_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('job_id');
            $table->uuid('attempt_uuid')->unique();
            $table->unsignedInteger('attempt_no')->default(1);
            $table->string('status', 32)->default('queued');
            $table->string('error_code', 64)->nullable();
            $table->json('result')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        $schema->create('sa_job_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('job_id');
            $table->unsignedBigInteger('attempt_id')->nullable();
            $table->string('event_type', 64);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
        });

        $schema->create('sa_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('artifact_uuid')->unique();
            $table->unsignedBigInteger('job_id');
            $table->unsignedBigInteger('attempt_id')->nullable();
            $table->string('kind', 32);
            $table->string('mime', 128);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum_sha256', 64)->nullable();
            $table->string('storage_path', 512)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function makeResponseFactory(): ResponseFactory
    {
        return new class implements ResponseFactory {
            public function json($data = [], $status = 200, array $headers = [], $options = 0): JsonResponse
            {
                return new JsonResponse($data, $status, $headers, $options);
            }

            public function make($content = '', $status = 200, array $headers = []) { throw new \BadMethodCallException(__METHOD__); }
            public function noContent($status = 204, array $headers = []) { throw new \BadMethodCallException(__METHOD__); }
            public function view($view, $data = [], $status = 200, array $headers = []) { throw new \BadMethodCallException(__METHOD__); }
            public function jsonp($callback, $data = [], $status = 200, array $headers = [], $options = 0) { throw new \BadMethodCallException(__METHOD__); }
            public function stream($callback, $status = 200, array $headers = []) { throw new \BadMethodCallException(__METHOD__); }
            public function streamJson($data, $status = 200, $headers = [], $encodingOptions = 0) { throw new \BadMethodCallException(__METHOD__); }
            public function streamDownload($callback, $name = null, array $headers = [], $disposition = 'attachment') { throw new \BadMethodCallException(__METHOD__); }
            public function download($file, $name = null, array $headers = [], $disposition = 'attachment') { throw new \BadMethodCallException(__METHOD__); }
            public function file($file, array $headers = []) { throw new \BadMethodCallException(__METHOD__); }
            public function redirectTo($path, $status = 302, $headers = [], $secure = null) { throw new \BadMethodCallException(__METHOD__); }
            public function redirectToRoute($route, $parameters = [], $status = 302, $headers = []) { throw new \BadMethodCallException(__METHOD__); }
            public function redirectToAction($action, $parameters = [], $status = 302, $headers = []) { throw new \BadMethodCallException(__METHOD__); }
            public function redirectGuest($path, $status = 302, $headers = [], $secure = null) { throw new \BadMethodCallException(__METHOD__); }
            public function redirectToIntended($default = '/', $status = 302, $headers = [], $secure = null) { throw new \BadMethodCallException(__METHOD__); }
        };
    }
}
