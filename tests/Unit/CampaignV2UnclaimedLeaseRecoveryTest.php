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
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaAccountDeviceAffinity;
use Omnichannel\Addons\SocialAutomation\Models\SaAccountExecutionLease;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTagScript;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignAccountAssignment;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContent;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaJobAttempt;
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

final class CampaignV2UnclaimedLeaseRecoveryTest extends TestCase
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
                return 'inst-lease-recovery';
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
            Facade::setFacadeApplication(null);
        }
        parent::tearDown();
    }

    public function test_01_02_03_04_07_10_unclaimed_reservation_expiry_releases_assignment_and_allows_redispach(): void
    {
        $this->seedSite();
        $client = $this->seedOnlineClient('site_a', 'acc_a_1');

        $uuid1 = $this->createV2Draft('Primary Camp', 'one_account_one_job', ['art-1']);
        $uuid2 = $this->createV2Draft('Unrelated Camp', 'one_account_one_job', ['art-other']);

        // Unrelated campaign assignment that must remain untouched
        $camp2 = SaCampaign::query()->where('campaign_uuid', $uuid2)->firstOrFail();
        $content2 = SaCampaignContent::query()->where('campaign_id', $camp2->id)->firstOrFail();
        $otherAccount = SaAccount::query()->create([
            'account_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-lease-recovery',
            'platform' => 'site_a',
            'platform_account_id' => 'acc_other',
            'verified' => true,
        ]);
        SaCampaignAccountAssignment::query()->create([
            'campaign_id' => $camp2->id,
            'account_id' => $otherAccount->id,
            'content_id' => $content2->id,
            'reason' => 'dispatch',
        ]);

        // 1. Start Camp 1 -> reserves Job 1
        $this->controller->start($uuid1);
        $camp1 = SaCampaign::query()->where('campaign_uuid', $uuid1)->firstOrFail();
        $job1 = SaJob::query()->where('campaign_id', $camp1->id)->firstOrFail();
        self::assertSame('reserved', $job1->status);
        self::assertSame(1, SaCampaignAccountAssignment::query()->where('campaign_id', $camp1->id)->count());

        // Expire the unclaimed reservation lease
        $job1->update(['lease_expires_at' => now()->subMinute()]);
        $reconciled = $this->orchestrator->reconcileExpiredCampaignLeases();
        self::assertSame(1, $reconciled['reconciled']);

        // 2 & 3. Reservation released safely, Content waiting_resources, Attempt cancelled
        $job1->refresh();
        self::assertSame('cancelled', $job1->status);
        self::assertSame('lease_expired_unclaimed', $job1->error_code);
        self::assertSame('cancelled', SaJobAttempt::query()->where('job_id', $job1->id)->firstOrFail()->status);
        self::assertSame('released', SaAccountExecutionLease::query()->where('job_id', $job1->id)->firstOrFail()->status);
        self::assertSame(0, SaCampaignAccountAssignment::query()->where('campaign_id', $camp1->id)->count());

        $content1 = SaCampaignContent::query()->where('campaign_id', $camp1->id)->firstOrFail();
        self::assertSame('waiting_resources', $content1->status);
        self::assertNull($content1->current_job_id);
        self::assertNull($content1->assigned_account_id);

        // 7. Other campaign's assignment untouched
        self::assertSame(1, SaCampaignAccountAssignment::query()->where('campaign_id', $camp2->id)->count());

        // 4 & 10. Next scheduler tick dispatches a new Job; second tick does NOT double-dispatch
        $cycle1 = $this->orchestrator->recheckDueCampaigns(now()->addMinutes(65));
        self::assertSame(1, $cycle1['dispatched']);
        $cycle2 = $this->orchestrator->recheckDueCampaigns(now()->addMinutes(130));
        self::assertSame(0, $cycle2['dispatched']);

        self::assertSame(2, SaJob::query()->where('campaign_id', $camp1->id)->count());
        self::assertSame(1, SaJob::query()->where('campaign_id', $camp1->id)->where('status', 'reserved')->count());
        self::assertSame(1, SaCampaignAccountAssignment::query()->where('campaign_id', $camp1->id)->count());
    }

    public function test_05_06_08_claimed_unknown_and_completed_assignments_remain_protected_in_mode_a(): void
    {
        $this->seedSite();
        $client = $this->seedOnlineClient('site_a', 'acc_a_1');

        // Case A: Claimed job expires -> unknown, assignment & account lock preserved
        $uuidUnknown = $this->createV2Draft('Claimed Unknown Camp', 'one_account_one_job', ['u-1']);
        $this->controller->start($uuidUnknown);
        $campU = SaCampaign::query()->where('campaign_uuid', $uuidUnknown)->firstOrFail();

        $claim = $this->orchestrator->claimNext((string) $client['agent']->agent_uuid, [(string) $client['session']->session_uuid], null, 'inst-lease-recovery');
        self::assertTrue($claim['ok']);
        $jobU = SaJob::query()->where('campaign_id', $campU->id)->firstOrFail();
        $jobU->update(['lease_expires_at' => now()->subMinute()]);

        $this->orchestrator->reconcileExpiredCampaignLeases();
        $jobU->refresh();
        self::assertSame('unknown', $jobU->status);
        self::assertSame(1, SaCampaignAccountAssignment::query()->where('campaign_id', $campU->id)->count());
        self::assertSame('active', SaAccountExecutionLease::query()->where('job_id', $jobU->id)->firstOrFail()->status);

        // Clean up unknown lock so Case B can use a fresh account
        $client2 = $this->seedOnlineClient('site_a', 'acc_a_2');

        // Case B: Completed execution in OneAccountOneJob protects account from running 2nd content
        $uuidModeA = $this->createV2Draft('Mode A Completed', 'one_account_one_job', ['m-1', 'm-2']);
        $this->controller->start($uuidModeA);
        $campA = SaCampaign::query()->where('campaign_uuid', $uuidModeA)->firstOrFail();

        $claimA = $this->orchestrator->claimNext((string) $client2['agent']->agent_uuid, [(string) $client2['session']->session_uuid], null, 'inst-lease-recovery');
        self::assertTrue($claimA['ok']);
        $pA = $claimA['data'];
        $this->orchestrator->acknowledge((string) $pA['job_id'], ['attempt_id' => $pA['attempt_id'], 'lease_token' => $pA['lease_token']], null, 'inst-lease-recovery');
        $this->orchestrator->result((string) $pA['job_id'], ['status' => 'succeeded', 'attempt_id' => $pA['attempt_id'], 'lease_token' => $pA['lease_token']], null, 'inst-lease-recovery');

        // 2nd content must stay waiting_resources because acc_a_2 is consumed in OneAccountOneJob
        $recheck = $this->orchestrator->recheckDueCampaigns(now()->addMinutes(65));
        self::assertSame(0, $recheck['dispatched']);
        self::assertSame(1, SaJob::query()->where('campaign_id', $campA->id)->count());
    }

    public function test_09_one_account_many_jobs_runs_sequentially_after_completion(): void
    {
        $this->seedSite();
        $client = $this->seedOnlineClient('site_a', 'acc_seq');

        $uuid = $this->createV2Draft('Mode B Sequential', 'one_account_many_jobs', ['seq-1', 'seq-2']);
        $this->controller->start($uuid);
        $camp = SaCampaign::query()->where('campaign_uuid', $uuid)->firstOrFail();

        self::assertSame(1, SaJob::query()->where('campaign_id', $camp->id)->count());

        $claim1 = $this->orchestrator->claimNext((string) $client['agent']->agent_uuid, [(string) $client['session']->session_uuid], null, 'inst-lease-recovery');
        $p1 = $claim1['data'];
        $this->orchestrator->acknowledge((string) $p1['job_id'], ['attempt_id' => $p1['attempt_id'], 'lease_token' => $p1['lease_token']], null, 'inst-lease-recovery');
        $this->orchestrator->result((string) $p1['job_id'], ['status' => 'succeeded', 'attempt_id' => $p1['attempt_id'], 'lease_token' => $p1['lease_token']], null, 'inst-lease-recovery');

        // interval_minutes=0 wakes immediate redispach (not schedule-tick gated)
        self::assertSame(2, SaJob::query()->where('campaign_id', $camp->id)->count());
        self::assertSame(1, SaJob::query()->where('campaign_id', $camp->id)->where('status', 'reserved')->count());
        $cycle = $this->orchestrator->recheckDueCampaigns(now()->addMinutes(65));
        self::assertSame(0, $cycle['dispatched']);
    }

    public function test_unclaimed_after_prior_completed_clears_pin_affinity_and_allows_other_client(): void
    {
        $this->seedSite();
        $c1 = $this->seedOnlineClient('site_a', 'acc_c1', ownerUserId: 9);
        $c2 = $this->seedOnlineClient('site_a', 'acc_c2', ownerUserId: 6);

        $uuid = $this->createV2Draft('Mode B Failover', 'one_account_many_jobs', ['f-1', 'f-2']);
        $this->controller->start($uuid);
        $camp = SaCampaign::query()->where('campaign_uuid', $uuid)->firstOrFail();

        $claim1 = $this->orchestrator->claimNext(
            (string) $c1['agent']->agent_uuid,
            [(string) $c1['session']->session_uuid],
            (int) $c1['key']->id,
            'inst-lease-recovery',
        );
        self::assertTrue($claim1['ok'] && is_array($claim1['data']));
        $p1 = $claim1['data'];
        $this->orchestrator->acknowledge((string) $p1['job_id'], ['attempt_id' => $p1['attempt_id'], 'lease_token' => $p1['lease_token']], (int) $c1['key']->id, 'inst-lease-recovery');
        $this->orchestrator->result((string) $p1['job_id'], ['status' => 'succeeded', 'attempt_id' => $p1['attempt_id'], 'lease_token' => $p1['lease_token']], (int) $c1['key']->id, 'inst-lease-recovery');

        // interval=0 wake reserves job 2 onto some eligible client (fair allocation)
        $job2 = SaJob::query()->where('campaign_id', $camp->id)->where('status', 'reserved')->firstOrFail();
        $owner = (int) $job2->agent_id === (int) $c1['agent']->id ? $c1 : $c2;
        $other = $owner === $c1 ? $c2 : $c1;

        // Other client has no reserved job → data null
        $nullClaim = $this->orchestrator->claimNext(
            (string) $other['agent']->agent_uuid,
            [(string) $other['session']->session_uuid],
            (int) $other['key']->id,
            'inst-lease-recovery',
        );
        self::assertTrue($nullClaim['ok']);
        self::assertNull($nullClaim['data']);

        // Wrong API key cannot claim owner's reserved job
        $forbidden = $this->orchestrator->claimNext(
            (string) $owner['agent']->agent_uuid,
            [(string) $owner['session']->session_uuid],
            (int) $other['key']->id,
            'inst-lease-recovery',
        );
        self::assertFalse($forbidden['ok']);
        self::assertContains('agent_not_found', $forbidden['errors'] ?? []);

        // Unclaimed expiry clears soft pin + affinity even with prior Completed history
        $job2->update(['lease_expires_at' => now()->subMinute()]);
        SaAccountDeviceAffinity::query()->updateOrCreate(
            ['account_id' => $job2->account_id],
            ['agent_id' => $owner['agent']->id, 'last_used_at' => now(), 'affinity_until' => now()->addHour()],
        );
        $this->orchestrator->reconcileExpiredCampaignLeases();

        $content2 = SaCampaignContent::query()->where('campaign_id', $camp->id)->orderBy('id')->skip(1)->firstOrFail();
        self::assertNull($content2->fresh()->assigned_account_id);
        self::assertSame(0, SaAccountDeviceAffinity::query()->where('account_id', $job2->account_id)->count());

        // Owner offline → other client with distinct account claims after redispach
        $owner['agent']->update(['last_heartbeat_at' => now()->subHour()]);
        $this->orchestrator->recheckDueCampaigns(now());
        $claim2 = $this->orchestrator->claimNext(
            (string) $other['agent']->agent_uuid,
            [(string) $other['session']->session_uuid],
            (int) $other['key']->id,
            'inst-lease-recovery',
        );
        self::assertTrue($claim2['ok'] && is_array($claim2['data']), json_encode($claim2));
        self::assertSame((int) $other['account']->id, (int) SaJob::query()->where('job_uuid', $claim2['data']['job_id'])->value('account_id'));
    }

    public function test_same_account_stays_locked_across_clients(): void
    {
        $this->seedSite();
        $shared = SaAccount::query()->create([
            'account_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-lease-recovery',
            'platform' => 'site_a',
            'platform_account_id' => 'shared_acc',
            'verified' => true,
        ]);
        $c1 = $this->seedOnlineClient('site_a', 'shared_acc', ownerUserId: 9, sharedAccount: $shared);
        $c2 = $this->seedOnlineClient('site_a', 'shared_acc', ownerUserId: 6, sharedAccount: $shared);

        $uuid = $this->createV2Draft('Shared Account Lock', 'one_account_many_jobs', ['s-1', 's-2']);
        $this->controller->start($uuid);
        $camp = SaCampaign::query()->where('campaign_uuid', $uuid)->firstOrFail();

        $job = SaJob::query()->where('campaign_id', $camp->id)->firstOrFail();
        $owner = (int) $job->agent_id === (int) $c1['agent']->id ? $c1 : $c2;
        $other = $owner === $c1 ? $c2 : $c1;

        $claim = $this->orchestrator->claimNext(
            (string) $owner['agent']->agent_uuid,
            [(string) $owner['session']->session_uuid],
            (int) $owner['key']->id,
            'inst-lease-recovery',
        );
        self::assertTrue($claim['ok'] && is_array($claim['data']));
        $this->orchestrator->acknowledge(
            (string) $claim['data']['job_id'],
            ['attempt_id' => $claim['data']['attempt_id'], 'lease_token' => $claim['data']['lease_token']],
            (int) $owner['key']->id,
            'inst-lease-recovery',
        );

        // Other client must not receive a concurrent job for the locked shared account
        $otherClaim = $this->orchestrator->claimNext(
            (string) $other['agent']->agent_uuid,
            [(string) $other['session']->session_uuid],
            (int) $other['key']->id,
            'inst-lease-recovery',
        );
        self::assertTrue($otherClaim['ok']);
        self::assertNull($otherClaim['data']);
        self::assertSame(1, SaJob::query()->where('campaign_id', $camp->id)->count());
        self::assertSame(1, SaAccountExecutionLease::query()->where('account_id', $shared->id)->where('status', 'active')->count());
    }

    /**
     * @param  list<string>  $refs
     */
    private function createV2Draft(string $name, string $mode, array $refs): string
    {
        $contents = [];
        foreach ($refs as $ref) {
            $contents[] = [
                'external_ref' => $ref,
                'raw_source' => "[t]{$ref}[/t]",
                'assignments' => [['action_tag_key' => 'site_a.post']],
            ];
        }

        $resp = $this->controller->store(Request::create('/social-automation/api/campaigns', 'POST', [
            'plan_version' => 2,
            'name' => $name,
            'execution_mode' => $mode,
            'action_tags' => [
                ['action_tag_key' => 'site_a.post', 'shortcode_mapping' => ['entries' => [['shortcode' => 't', 'variable' => 'title']]]],
            ],
            'contents' => $contents,
        ]));
        self::assertSame(201, $resp->getStatusCode());

        return (string) $resp->getData(true)['data']['id'];
    }

    private function seedSite(): void
    {
        $site = SaSite::query()->create(['key' => 'site_a', 'name' => 'Site A', 'base_url' => 'https://a.example.com']);
        $path = SaPath::query()->create(['site_id' => $site->id, 'key' => 'post', 'name' => 'Post', 'url_pattern' => '/post']);
        $tag = SaActionTag::query()->create(['site_id' => $site->id, 'path_id' => $path->id, 'site_key' => 'site_a', 'path_key' => 'post', 'platform' => 'site_a', 'target' => 'post', 'action' => '', 'tag_key' => 'site_a.post', 'is_enabled' => true]);
        $pkg = SaPackage::query()->create(['package_key' => 'site_a.post', 'kind' => 'script', 'name' => 'Site A Script', 'is_enabled' => true, 'sharing_mode' => 'site_specific']);
        $rel = SaPackageRelease::query()->create([
            'package_id' => $pkg->id,
            'version' => '1.0.0',
            'content_digest' => hash('sha256', 'site_a'),
            'schema_version' => 1,
            'manifest' => ['variables' => [['key' => 'title', 'type' => 'string', 'required' => true]]],
            'status' => 'published',
            'published_at' => now(),
        ]);
        SaActionTagScript::query()->create(['action_tag_id' => $tag->id, 'action_tag_key' => $tag->tag_key, 'package_id' => $pkg->id, 'release_id' => $rel->id, 'script_id' => $pkg->package_key, 'script_version' => '1.0.0']);
    }

    /**
     * @return array{agent: SaAgent, account: SaAccount, session: SaBrowserSession, key: \Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey}
     */
    private function seedOnlineClient(
        string $siteKey,
        string $accName,
        ?int $ownerUserId = null,
        ?SaAccount $sharedAccount = null,
    ): array {
        $key = SaClientApiKey::query()->create([
            'installation_id' => 'inst-lease-recovery',
            'name' => 'Key '.$accName.uniqid('', true),
            'key_prefix' => 'rpa_client_'.substr(hash('sha256', $accName.uniqid('', true)), 0, 8),
            'key_hash' => hash('sha256', 'secret-'.$accName.uniqid('', true)),
            'created_by' => $ownerUserId,
            'issued_by' => $ownerUserId === 9 ? 2 : null,
        ]);

        $agent = SaAgent::query()->create([
            'agent_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-lease-recovery',
            'name' => 'Agent '.$accName,
            'availability' => AgentAvailability::Available->value,
            'active_jobs' => 0,
            'max_concurrency' => 2,
            'last_heartbeat_at' => now(),
            'agent_kind' => 'runner',
            'client_api_key_id' => $key->id,
        ]);
        $account = $sharedAccount ?? SaAccount::query()->create([
            'account_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-lease-recovery',
            'platform' => $siteKey,
            'platform_account_id' => $accName,
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

        return ['agent' => $agent, 'account' => $account, 'session' => $session, 'key' => $key];
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
            $table->unsignedBigInteger('client_api_key_id')->nullable();
            $table->timestamps();
        });
        $schema->create('sa_client_api_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('installation_id', 128);
            $table->string('name', 255)->nullable();
            $table->string('key_prefix', 64)->nullable();
            $table->string('key_hash', 128)->nullable();
            $table->text('encrypted_key')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
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
