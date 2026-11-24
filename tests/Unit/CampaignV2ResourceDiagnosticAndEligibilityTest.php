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
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignActionTag;
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

final class CampaignV2ResourceDiagnosticAndEligibilityTest extends TestCase
{
    private Capsule $capsule;

    private ?Container $previousApp = null;

    private string $root;

    private StaffCampaignController $controller;

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

            public function assertCanManageCampaigns(?User $user = null): void {}
        };
        $inst = new class extends SocialAutomationInstallationResolver {
            public function __construct() {}

            public function resolveInstallationId(): string
            {
                return 'inst-diag';
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
        $this->runMigration($this->root.'/database/migrations/2026_11_23_040000_add_interval_minutes_to_sa_campaign_action_tags.php');

        CampaignV2ExecutionPolicy::setTestOverride(true);

        $this->orchestrator = new CampaignOrchestrator;
        $this->controller = new StaffCampaignController(
            new CampaignService($this->orchestrator),
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
        }
        parent::tearDown();
    }

    public function test_online_ready_matching_browser_dispatches_queue_head_and_no_duplicate_on_overlap(): void
    {
        $this->seedSite('maytuicanvas');
        $client = $this->seedOnlineClient('maytuicanvas', 'acc_mtc');

        $uuid = $this->createV2Draft('V2 Eligible', 'one_account_many_jobs', [
            ['site_key' => 'maytuicanvas', 'ref' => 'ref-1'],
            ['site_key' => 'maytuicanvas', 'ref' => 'ref-2'],
        ]);
        $this->controller->start($uuid);
        $camp = SaCampaign::query()->where('campaign_uuid', $uuid)->firstOrFail();

        $jobs = SaJob::query()->where('campaign_id', $camp->id)->get();
        self::assertCount(1, $jobs);
        self::assertSame('ref-1', SaCampaignContent::query()->find($jobs[0]->campaign_content_id)->external_ref);

        // Overlapping cron tick must not duplicate dispatch
        $tick = $this->orchestrator->recheckDueCampaigns(now()->addMinute());
        self::assertSame(0, $tick['dispatched']);
        self::assertCount(1, SaJob::query()->where('campaign_id', $camp->id)->get());
    }

    public function test_paused_campaign_does_not_dispatch(): void
    {
        $this->seedSite('maytuicanvas');
        $this->seedOnlineClient('maytuicanvas', 'acc_mtc');

        $uuid = $this->createV2Draft('V2 Paused', 'one_account_many_jobs', [
            ['site_key' => 'maytuicanvas', 'ref' => 'ref-p1'],
        ]);
        $camp = SaCampaign::query()->where('campaign_uuid', $uuid)->firstOrFail();
        $camp->update(['status' => CampaignStatus::Paused->value]);

        $res = $this->orchestrator->recheckDueCampaigns(now()->addMinute());
        self::assertSame(0, $res['dispatched']);
        self::assertCount(0, SaJob::query()->where('campaign_id', $camp->id)->get());
    }

    public function test_resource_diagnostics_distinguish_missing_offline_not_ready_dispatch_disabled_locked_installation_and_mode_consumed(): void
    {
        $this->seedSite('maytuicanvas');

        // 1. Missing browser / no sessions
        $uuidMissing = $this->createV2Draft('Diag Missing', 'one_account_many_jobs', [
            ['site_key' => 'maytuicanvas', 'ref' => 'm-1'],
        ]);
        $this->controller->start($uuidMissing);
        $cMissing = SaCampaign::query()->where('campaign_uuid', $uuidMissing)->firstOrFail();
        $itemMissing = SaCampaignContent::query()->where('campaign_id', $cMissing->id)->firstOrFail();
        self::assertSame(CampaignContentStatus::WaitingResources->value, $itemMissing->status);
        self::assertStringContainsString('No eligible online client/account/browser', (string) $itemMissing->last_error_message);

        // 2. Wrong installation (session exists on another installation)
        $otherClient = $this->seedOnlineClient('maytuicanvas', 'acc_other', 'inst-other');
        $this->orchestrator->recheckDueCampaigns(now()->addMinutes(65));
        $itemMissing->refresh();
        self::assertStringContainsString('installation mismatch', (string) $itemMissing->last_error_message);
        $otherClient['session']->delete();
        $otherClient['agent']->delete();
        $otherClient['account']->delete();

        // 3. Client online, session dispatch_disabled
        $client = $this->seedOnlineClient('maytuicanvas', 'acc_mtc');
        $client['session']->update(['dispatch_enabled' => false]);
        $this->orchestrator->recheckDueCampaigns(now()->addMinutes(130));
        $itemMissing->refresh();
        self::assertStringContainsString('not dispatch-enabled', (string) $itemMissing->last_error_message);

        // 4. Client online, browser not ready
        $client['session']->update(['dispatch_enabled' => true, 'ready_status' => SessionReadyStatus::NeedsAttention->value]);
        $this->orchestrator->recheckDueCampaigns(now()->addMinutes(195));
        $itemMissing->refresh();
        self::assertStringContainsString('No ready browser session', (string) $itemMissing->last_error_message);

        // 5. Client offline
        $client['session']->update(['ready_status' => SessionReadyStatus::Ready->value]);
        $client['agent']->update(['last_heartbeat_at' => now()->subMinutes(10)]);
        $this->orchestrator->recheckDueCampaigns(now()->addMinutes(260));
        $itemMissing->refresh();
        self::assertStringContainsString('No active client heartbeat', (string) $itemMissing->last_error_message);

        // 6. Account locked by active job/lease
        $client['agent']->update(['last_heartbeat_at' => now()]);
        SaJob::query()->create([
            'job_uuid' => (string) Str::uuid(),
            'agent_id' => $client['agent']->id,
            'browser_session_id' => $client['session']->id,
            'account_id' => $client['account']->id,
            'status' => 'unknown',
            'idempotency_key' => 'lock-job-1',
        ]);
        $this->orchestrator->recheckDueCampaigns(now()->addMinutes(325));
        $itemMissing->refresh();
        self::assertStringContainsString('Account lease/job is currently occupied', (string) $itemMissing->last_error_message);
    }

    public function test_one_account_one_job_consumed_account_reports_specific_diagnostic_and_resume_with_v2_works(): void
    {
        $this->seedSite('maytuicanvas');
        $client = $this->seedOnlineClient('maytuicanvas', 'acc_mtc');

        $uuid = $this->createV2Draft('Mode A Consumed', 'one_account_one_job', [
            ['site_key' => 'maytuicanvas', 'ref' => 'c-1'],
            ['site_key' => 'maytuicanvas', 'ref' => 'c-2'],
        ]);
        $this->controller->start($uuid);
        $camp = SaCampaign::query()->where('campaign_uuid', $uuid)->firstOrFail();

        $claim = $this->orchestrator->claimNext((string) $client['agent']->agent_uuid, [(string) $client['session']->session_uuid], null, 'inst-diag');
        self::assertTrue($claim['ok']);
        $p = $claim['data'];
        $this->orchestrator->acknowledge((string) $p['job_id'], ['attempt_id' => $p['attempt_id'], 'lease_token' => $p['lease_token']], null, 'inst-diag');
        $this->orchestrator->result((string) $p['job_id'], ['status' => 'succeeded', 'attempt_id' => $p['attempt_id'], 'lease_token' => $p['lease_token']], null, 'inst-diag');

        // Pause then Resume with V2 enabled
        $this->controller->pause($uuid);
        $this->controller->resume($uuid);

        $this->orchestrator->recheckDueCampaigns(now()->addMinutes(5));
        $second = SaCampaignContent::query()->where('campaign_id', $camp->id)->where('external_ref', 'c-2')->firstOrFail();
        self::assertSame(CampaignContentStatus::WaitingResources->value, $second->status);
        self::assertStringContainsString('execution_mode=one_account_one_job', (string) $second->last_error_message);
    }

    public function test_non_head_waiting_resources_normalizes_to_pending_and_independent_queues_and_intervals(): void
    {
        $this->seedSite('maytuicanvas');
        $this->seedSite('congtybalo');
        $clientA = $this->seedOnlineClient('maytuicanvas', 'acc_mtc');

        $uuid = $this->createV2Draft('Multi Queue', 'one_account_many_jobs', [
            ['site_key' => 'maytuicanvas', 'ref' => 'mtc-1'],
            ['site_key' => 'maytuicanvas', 'ref' => 'mtc-2'],
            ['site_key' => 'congtybalo', 'ref' => 'ctb-1'],
        ], ['maytuicanvas' => 60, 'congtybalo' => 0]);

        $camp = SaCampaign::query()->where('campaign_uuid', $uuid)->firstOrFail();
        // Simulate stale waiting_resources on non-head mtc-2
        SaCampaignContent::query()->where('campaign_id', $camp->id)->where('external_ref', 'mtc-2')->update([
            'status' => CampaignContentStatus::WaitingResources->value,
            'last_error_code' => 'waiting_resources',
        ]);

        $this->controller->start($uuid);
        // mtc-1 dispatched, mtc-2 normalized to pending, ctb-1 waiting_resources independently
        $mtc1 = SaCampaignContent::query()->where('campaign_id', $camp->id)->where('external_ref', 'mtc-1')->firstOrFail();
        $mtc2 = SaCampaignContent::query()->where('campaign_id', $camp->id)->where('external_ref', 'mtc-2')->firstOrFail();
        $ctb1 = SaCampaignContent::query()->where('campaign_id', $camp->id)->where('external_ref', 'ctb-1')->firstOrFail();

        self::assertSame(CampaignContentStatus::Running->value, $mtc1->status);
        self::assertSame(CampaignContentStatus::Pending->value, $mtc2->status);
        self::assertSame(CampaignContentStatus::WaitingResources->value, $ctb1->status);

        // Complete mtc-1; interval=60 blocks mtc-2 at +10m, allows at +65m
        $claim = $this->orchestrator->claimNext((string) $clientA['agent']->agent_uuid, [(string) $clientA['session']->session_uuid], null, 'inst-diag');
        $p = $claim['data'];
        $this->orchestrator->acknowledge((string) $p['job_id'], ['attempt_id' => $p['attempt_id'], 'lease_token' => $p['lease_token']], null, 'inst-diag');
        $this->orchestrator->result((string) $p['job_id'], ['status' => 'succeeded', 'attempt_id' => $p['attempt_id'], 'lease_token' => $p['lease_token']], null, 'inst-diag');

        $tooSoon = $this->orchestrator->recheckDueCampaigns(now()->addMinutes(10));
        self::assertSame(0, $tooSoon['dispatched']);

        $due = $this->orchestrator->recheckDueCampaigns(now()->addMinutes(65));
        self::assertSame(1, $due['dispatched']);
        $mtc2->refresh();
        self::assertSame(CampaignContentStatus::Running->value, $mtc2->status);
    }

    /**
     * @param  list<array{site_key: string, ref: string}>  $items
     * @param  array<string, int>  $intervals
     */
    private function createV2Draft(string $name, string $mode, array $items, array $intervals = []): string
    {
        $sitesSeen = [];
        $tags = [];
        $contents = [];
        foreach ($items as $i => $item) {
            $sk = $item['site_key'];
            if (! isset($sitesSeen[$sk])) {
                $sitesSeen[$sk] = true;
                $tags[] = [
                    'action_tag_key' => $sk.'.create_post',
                    'interval_minutes' => $intervals[$sk] ?? 0,
                ];
            }
            $contents[] = [
                'external_ref' => $item['ref'],
                'raw_source' => '[t]Title '.$item['ref'].'[/t]',
                'sort_order' => $i + 1,
                'assignments' => [[
                    'action_tag_key' => $sk.'.create_post',
                    'input' => ['title' => 'Title '.$item['ref']],
                ]],
            ];
        }

        $res = $this->controller->store(Request::create('/campaigns', 'POST', [
            'plan_version' => 2,
            'name' => $name,
            'run_mode' => 'live',
            'execution_mode' => $mode,
            'action_tags' => $tags,
            'contents' => $contents,
        ]));
        $payload = json_decode((string) $res->getContent(), true);
        self::assertSame(201, $res->getStatusCode(), json_encode($payload));

        return (string) ($payload['data']['id'] ?? $payload['data']['campaign_uuid'] ?? '');
    }

    private function seedSite(string $siteKey): void
    {
        $site = SaSite::query()->create(['key' => $siteKey, 'name' => ucfirst($siteKey), 'base_url' => 'https://'.$siteKey.'.com']);
        $path = SaPath::query()->create(['site_id' => $site->id, 'key' => 'create_post', 'name' => 'Create Post', 'url_pattern' => '/wp-admin/post-new.php']);
        $tag = SaActionTag::query()->create([
            'site_id' => $site->id,
            'path_id' => $path->id,
            'site_key' => $siteKey,
            'path_key' => 'create_post',
            'platform' => $siteKey,
            'target' => 'create_post',
            'action' => '',
            'tag_key' => $siteKey.'.create_post',
            'is_enabled' => true,
        ]);
        $pkg = SaPackage::query()->create([
            'package_key' => $siteKey.'.create_post',
            'kind' => 'script',
            'name' => 'WP Create Post',
            'is_enabled' => true,
            'sharing_mode' => 'site_specific',
        ]);
        $rel = SaPackageRelease::query()->create([
            'package_id' => $pkg->id,
            'version' => '1.0.0',
            'content_digest' => hash('sha256', $siteKey),
            'schema_version' => 1,
            'manifest' => ['variables' => [['key' => 'title', 'type' => 'string', 'required' => true]]],
            'status' => 'published',
            'published_at' => now(),
        ]);
        SaActionTagScript::query()->create([
            'action_tag_id' => $tag->id,
            'action_tag_key' => $tag->tag_key,
            'package_id' => $pkg->id,
            'release_id' => $rel->id,
            'script_id' => $pkg->package_key,
            'script_version' => '1.0.0',
        ]);
    }

    /**
     * @return array{agent: SaAgent, account: SaAccount, session: SaBrowserSession}
     */
    private function seedOnlineClient(string $siteKey, string $accName, string $installationId = 'inst-diag'): array
    {
        $agent = SaAgent::query()->create([
            'agent_uuid' => (string) Str::uuid(),
            'installation_id' => $installationId,
            'name' => 'Runner '.$accName,
            'availability' => AgentAvailability::Available->value,
            'active_jobs' => 0,
            'max_concurrency' => 2,
            'capabilities' => ['wordpress'],
            'runtime_version' => '2.0.0',
            'last_heartbeat_at' => now(),
            'agent_kind' => 'runner',
        ]);
        $account = SaAccount::query()->create([
            'account_uuid' => (string) Str::uuid(),
            'installation_id' => $installationId,
            'platform' => $siteKey,
            'domain' => $siteKey.'.com',
            'platform_account_id' => null,
            'verified' => false,
            'identity_source' => 'inventory',
        ]);
        $session = SaBrowserSession::query()->create([
            'session_uuid' => (string) Str::uuid(),
            'agent_id' => $agent->id,
            'account_id' => $account->id,
            'ready_status' => SessionReadyStatus::Ready->value,
            'dispatch_enabled' => true,
            'session_state' => 'candidate',
        ]);
        SaSessionAccount::query()->create([
            'browser_session_id' => $session->id,
            'account_id' => $account->id,
            'is_active' => true,
        ]);

        return ['agent' => $agent, 'account' => $account, 'session' => $session];
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
            $table->string('session_state', 32)->default('candidate');
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
