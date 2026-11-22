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
use Omnichannel\Addons\SocialAutomation\Enums\CampaignContentStatus;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignStatus;
use Omnichannel\Addons\SocialAutomation\Enums\SessionReadyStatus;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\StaffCampaignController;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTagScript;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContent;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaSessionAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Orchestration\CampaignOrchestrator;
use Omnichannel\Addons\SocialAutomation\Services\CampaignService;
use Omnichannel\Addons\SocialAutomation\Services\ScriptResolutionService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use PHPUnit\Framework\TestCase;

final class CampaignV2UnconditionalStartAndSchedulerTest extends TestCase
{
    private Capsule $capsule;

    private ?Container $previousApp = null;

    private string $root;

    private StaffCampaignController $controller;

    private CampaignService $campaigns;

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
        $app->instance(SocialAutomationAccess::class, new class extends SocialAutomationAccess {
            public function __construct() {}

            public function assertCanAccess(?User $user = null): void {}

            public function assertCanManageCampaigns(?User $user = null): void {}
        });
        $app->instance(SocialAutomationInstallationResolver::class, new class extends SocialAutomationInstallationResolver {
            public function __construct() {}

            public function resolveInstallationId(): string
            {
                return 'inst-v2-exec-test';
            }
        });
        $app->instance(ScriptResolutionService::class, new ScriptResolutionService);
        $app->instance(ResponseFactory::class, $this->makeResponseFactory());

        $this->createFullSchema();
        $this->runMigration($this->root.'/database/migrations/2026_11_02_150000_create_sa_campaign_tables.php');
        $this->runMigration($this->root.'/database/migrations/2026_11_20_100000_add_campaign_shortcode_v2_columns.php');
        $this->runMigration($this->root.'/database/migrations/2026_11_21_140000_create_sa_campaign_v2_tables.php');

        CampaignV2ExecutionPolicy::setTestOverride(true);

        $this->orchestrator = new CampaignOrchestrator;
        $this->campaigns = new CampaignService($this->orchestrator);
        $this->controller = new StaffCampaignController(
            $this->campaigns,
            $app->make(SocialAutomationAccess::class),
            $app->make(SocialAutomationInstallationResolver::class),
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

    public function test_feature_flag_is_default_off(): void
    {
        CampaignV2ExecutionPolicy::setTestOverride(null);
        self::assertFalse(CampaignV2ExecutionPolicy::isEnabled());
    }

    public function test_01_02_03_04_08_unconditional_start_and_single_scheduler_cycle_with_late_client(): void
    {
        $this->seedSiteFixtures();

        // 0 Clients Online, 0 Accounts, 0 Browsers
        self::assertSame(0, SaAgent::query()->count());
        self::assertSame(0, SaAccount::query()->count());

        $uuid = $this->createV2Draft('Unconditional Start Campaign', [
            ['external_ref' => 'art-site-a', 'raw_source' => '[t]Hello A[/t]', 'assignments' => [['action_tag_key' => 'site_a.post']]],
            ['external_ref' => 'art-site-b', 'raw_source' => '[t]Hello B[/t]', 'assignments' => [['action_tag_key' => 'site_b.post']]],
        ], 60);

        // 1. Start Campaign with 0 Clients Online -> Running
        $startResp = $this->controller->start($uuid);
        self::assertSame(200, $startResp->getStatusCode());
        $started = $startResp->getData(true)['data'];
        self::assertSame('running', $started['status']);
        self::assertNotNull($started['next_recheck_at']);

        // 2. No eligible resources -> 0 dispatch, Articles waiting
        self::assertSame(0, SaJob::query()->count());
        $contents = SaCampaignContent::query()->orderBy('id')->get();
        self::assertSame('waiting_resources', $contents[0]->status);
        self::assertSame('waiting_resources', $contents[1]->status);

        // 8. Remains Running across multiple cycles with 0 resources
        $cycle1 = $this->orchestrator->recheckDueCampaigns(now()->addMinutes(61));
        self::assertSame(1, $cycle1['checked']);
        self::assertSame(0, $cycle1['dispatched']);
        self::assertSame('running', SaCampaign::query()->where('campaign_uuid', $uuid)->value('status'));

        // 3. Client for Site A added later -> next cycle dispatches Site A Article automatically without manual restart!
        $agentA = $this->seedOnlineClientForSite('site_a', 'acc_site_a');

        $cycle2 = $this->orchestrator->recheckDueCampaigns(now()->addMinutes(122));
        self::assertSame(1, $cycle2['checked']);
        self::assertSame(1, $cycle2['dispatched']);
        self::assertSame(1, SaJob::query()->count());

        $contents = SaCampaignContent::query()->orderBy('id')->get();
        self::assertSame('running', $contents[0]->status); // Site A dispatched
        self::assertSame('waiting_resources', $contents[1]->status); // Site B still waiting!

        // 4. Client for Site B added offline -> Site B Article remains waiting
        $agentB = $this->seedOnlineClientForSite('site_b', 'acc_site_b', online: false);
        $cycle3 = $this->orchestrator->recheckDueCampaigns(now()->addMinutes(183));
        self::assertSame(0, $cycle3['dispatched']);
        self::assertSame('waiting_resources', SaCampaignContent::query()->find($contents[1]->id)->status);

        // Bring Site B online -> next cycle dispatches Site B Article
        $agentB->update(['last_heartbeat_at' => now()]);
        $cycle4 = $this->orchestrator->recheckDueCampaigns(now()->addMinutes(244));
        self::assertSame(1, $cycle4['dispatched']);
        self::assertSame(2, SaJob::query()->count());
    }

    public function test_05_06_site_a_client_never_receives_site_b_and_no_duplicate_jobs(): void
    {
        $this->seedSiteFixtures();
        $this->seedOnlineClientForSite('site_a', 'acc_site_a');

        $uuid = $this->createV2Draft('Site Isolation Campaign', [
            ['external_ref' => 'art-b-only', 'raw_source' => '[t]Only B[/t]', 'assignments' => [['action_tag_key' => 'site_b.post']]],
        ], 60);

        $this->controller->start($uuid);
        // Even across multiple cycles, Site A client never receives Site B article
        $this->orchestrator->recheckDueCampaigns(now()->addMinutes(5));
        self::assertSame(0, SaJob::query()->count());
        self::assertSame('waiting_resources', SaCampaignContent::query()->first()->status);

        // Now test no duplicate job for Site A article across repeated cycles
        $uuidA = $this->createV2Draft('No Dup Campaign', [
            ['external_ref' => 'art-a-only', 'raw_source' => '[t]Only A[/t]', 'assignments' => [['action_tag_key' => 'site_a.post']]],
        ], 60);
        $this->controller->start($uuidA);
        self::assertSame(1, SaJob::query()->count());

        $campA = SaCampaign::query()->where('campaign_uuid', $uuidA)->firstOrFail();
        $res = $this->orchestrator->dispatchCampaign($campA, now()->addMinutes(2));
        self::assertSame(0, $res['dispatched']);
        self::assertSame(1, SaJob::query()->count());
    }

    public function test_07_multi_assigned_articles_rejected_safely_on_start(): void
    {
        $this->seedSiteFixtures();

        $uuid = $this->createV2Draft('Multi-Assigned Reject', [
            [
                'external_ref' => 'multi-1',
                'raw_source' => '[t]Shared[/t]',
                'assignments' => [
                    ['action_tag_key' => 'site_a.post'],
                    ['action_tag_key' => 'site_b.post'],
                ],
            ],
        ], 60);

        $startResp = $this->controller->start($uuid);
        self::assertSame(422, $startResp->getStatusCode());
        self::assertSame(['v2_multi_tag_content_unsupported'], $startResp->getData(true)['errors']);
        self::assertSame('draft', SaCampaign::query()->where('campaign_uuid', $uuid)->value('status'));
        self::assertSame(0, SaJob::query()->count());
    }

    public function test_09_no_execution_when_paused_cancelled_expired_or_completed(): void
    {
        $this->seedSiteFixtures();
        $this->seedOnlineClientForSite('site_a', 'acc_site_a');

        foreach (['paused', 'cancelled', 'completed', 'expired'] as $terminalOrNonRunnable) {
            $uuid = $this->createV2Draft('State '.$terminalOrNonRunnable, [
                ['external_ref' => 'art-'.$terminalOrNonRunnable, 'raw_source' => '[t]X[/t]', 'assignments' => [['action_tag_key' => 'site_a.post']]],
            ], 60);

            $camp = SaCampaign::query()->where('campaign_uuid', $uuid)->firstOrFail();
            $camp->status = $terminalOrNonRunnable;
            $camp->next_recheck_at = now()->subMinute();
            $camp->save();

            $res = $this->orchestrator->dispatchCampaign($camp, now());
            self::assertSame(0, $res['dispatched']);
        }

        $recheck = $this->orchestrator->recheckDueCampaigns(now());
        self::assertSame(0, $recheck['checked']);
        self::assertSame(0, $recheck['dispatched']);
        self::assertSame(0, SaJob::query()->count());
    }

    public function test_10_claim_payload_preserves_v2_identity_and_unknown_result_blocks_repost(): void
    {
        $this->seedSiteFixtures();
        $agent = $this->seedOnlineClientForSite('site_a', 'acc_site_a');
        $session = SaBrowserSession::query()->where('agent_id', $agent->id)->firstOrFail();

        $uuid = $this->createV2Draft('Claim & Unknown Audit', [
            ['external_ref' => 'art-claim-1', 'raw_source' => '[t]Audit Title[/t]', 'assignments' => [['action_tag_key' => 'site_a.post']]],
        ], 60);

        $startResp = $this->controller->start($uuid);
        self::assertSame(200, $startResp->getStatusCode());

        // Client claims the reserved V2 job
        $claim = $this->orchestrator->claimNext(
            (string) $agent->agent_uuid,
            [(string) $session->session_uuid],
            null,
            'inst-v2-exec-test',
        );
        self::assertTrue($claim['ok']);
        $payload = $claim['data'];
        self::assertIsArray($payload);
        self::assertSame(2, $payload['plan_version']);
        self::assertSame('site_a', $payload['site_key']);
        self::assertSame('site_a.post', $payload['action_tag_key']);
        self::assertNotEmpty($payload['plan_tag_id']);
        self::assertSame('site_a.post', $payload['script_id']);
        self::assertSame('1.0.0', $payload['version']);
        self::assertIsInt($payload['release_id']);
        self::assertSame(['title' => 'Audit Title'], $payload['input']);
        self::assertNotEmpty($payload['lease_token']);

        // Client acknowledges (Claimed -> Running)
        $ack = $this->orchestrator->acknowledge(
            (string) $payload['job_id'],
            [
                'attempt_id' => $payload['attempt_id'],
                'lease_token' => $payload['lease_token'],
            ],
            null,
            'inst-v2-exec-test',
        );
        self::assertTrue($ack['ok']);

        // Client reports 'unknown' result -> Content & Assignment become unknown, never auto-reposted
        $res = $this->orchestrator->result(
            (string) $payload['job_id'],
            [
                'status' => 'unknown',
                'attempt_id' => $payload['attempt_id'],
                'lease_token' => $payload['lease_token'],
                'error_code' => 'network_timeout',
            ],
            null,
            'inst-v2-exec-test',
        );
        self::assertTrue($res['ok'], (string) json_encode($res));
        self::assertSame('unknown', SaCampaignContent::query()->first()->status);
        // Campaign stays Running (not Completed) because unknown is not terminal
        self::assertSame('running', SaCampaign::query()->where('campaign_uuid', $uuid)->value('status'));

        // Next scheduler cycle must NOT re-dispatch the unknown content
        $cycle = $this->orchestrator->recheckDueCampaigns(now()->addMinutes(5));
        self::assertSame(0, $cycle['dispatched']);
        self::assertSame(1, SaJob::query()->count());
    }

    /**
     * @param  list<array<string, mixed>>  $contents
     */
    private function createV2Draft(string $name, array $contents, int $recheckSeconds = 60): string
    {
        $resp = $this->controller->store(Request::create('/social-automation/api/campaigns', 'POST', [
            'plan_version' => 2,
            'name' => $name,
            'resource_recheck_seconds' => $recheckSeconds,
            'action_tags' => [
                ['action_tag_key' => 'site_a.post', 'shortcode_mapping' => ['entries' => [['shortcode' => 't', 'variable' => 'title']]]],
                ['action_tag_key' => 'site_b.post', 'shortcode_mapping' => ['entries' => [['shortcode' => 't', 'variable' => 'title']]]],
            ],
            'contents' => $contents,
        ]));
        self::assertSame(201, $resp->getStatusCode());

        return (string) $resp->getData(true)['data']['id'];
    }

    private function seedSiteFixtures(): void
    {
        foreach (['site_a', 'site_b'] as $siteKey) {
            $site = SaSite::query()->create(['key' => $siteKey, 'name' => strtoupper($siteKey), 'base_url' => "https://{$siteKey}.example.com"]);
            $path = SaPath::query()->create(['site_id' => $site->id, 'key' => 'post', 'name' => 'Post', 'url_pattern' => '/post']);
            $tag = SaActionTag::query()->create(['site_id' => $site->id, 'path_id' => $path->id, 'site_key' => $siteKey, 'path_key' => 'post', 'platform' => $siteKey, 'target' => 'post', 'action' => '', 'tag_key' => "{$siteKey}.post", 'is_enabled' => true]);
            $pkg = SaPackage::query()->create(['package_key' => "{$siteKey}.post", 'kind' => 'script', 'name' => "{$siteKey} Script", 'is_enabled' => true, 'sharing_mode' => 'site_specific']);
            $rel = SaPackageRelease::query()->create([
                'package_id' => $pkg->id,
                'version' => '1.0.0',
                'content_digest' => hash('sha256', $siteKey),
                'schema_version' => 1,
                'manifest' => ['variables' => [['key' => 'title', 'type' => 'string', 'required' => true]]],
                'status' => 'published',
                'published_at' => now(),
            ]);
            SaActionTagScript::query()->create(['action_tag_id' => $tag->id, 'action_tag_key' => $tag->tag_key, 'package_id' => $pkg->id, 'release_id' => $rel->id, 'script_id' => $pkg->package_key, 'script_version' => '1.0.0']);
        }
    }

    private function seedOnlineClientForSite(string $siteKey, string $accountName, bool $online = true): SaAgent
    {
        $agent = SaAgent::query()->create([
            'agent_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-v2-exec-test',
            'name' => 'Agent '.$siteKey,
            'availability' => AgentAvailability::Available->value,
            'active_jobs' => 0,
            'max_concurrency' => 2,
            'last_heartbeat_at' => $online ? now() : now()->subMinutes(30),
            'agent_kind' => 'runner',
        ]);

        $account = SaAccount::query()->create([
            'account_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-v2-exec-test',
            'platform' => $siteKey,
            'domain' => "{$siteKey}.example.com",
            'platform_account_id' => $accountName,
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
            $table->string('availability', 32)->default('online');
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
            $table->json('result')->nullable();
            $table->timestamp('occurred_at')->nullable();
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
