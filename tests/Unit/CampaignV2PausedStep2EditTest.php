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

final class CampaignV2PausedStep2EditTest extends TestCase
{
    private Capsule $capsule;

    private ?Container $previousApp = null;

    private string $root;

    private StaffCampaignController $controller;

    private CampaignOrchestrator $orchestrator;

    private string $currentInstallation = 'inst-paused-edit';

    private bool $denyManage = false;

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

        $self = $this;
        $access = new class($self) extends SocialAutomationAccess {
            public function __construct(private readonly CampaignV2PausedStep2EditTest $test) {}

            public function assertCanAccess(?User $user = null): void {}

            public function assertCanManageCampaigns(?User $user = null): void
            {
                if ($this->test->shouldDenyManage()) {
                    throw new \RuntimeException('Forbidden: unauthorized staff');
                }
            }
        };
        $inst = new class($self) extends SocialAutomationInstallationResolver {
            public function __construct(private readonly CampaignV2PausedStep2EditTest $test) {}

            public function resolveInstallationId(): string
            {
                return $this->test->getCurrentInstallation();
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
        $this->runMigration($this->root.'/database/migrations/2026_11_23_030000_add_soft_delete_to_sa_campaigns.php');
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

    public function getCurrentInstallation(): string
    {
        return $this->currentInstallation;
    }

    public function shouldDenyManage(): bool
    {
        return $this->denyManage;
    }

    public function test_new_v2_campaign_defaults_to_one_account_many_jobs_and_explicit_one_account_one_job_is_preserved(): void
    {
        $this->seedSite('maytuicanvas');

        $defaultResp = $this->controller->store(Request::create('/campaigns', 'POST', [
            'plan_version' => 2,
            'name' => 'Default Mode V2',
            'action_tags' => [['action_tag_key' => 'maytuicanvas.create_post']],
            'contents' => [[
                'external_ref' => 'd-1',
                'raw_source' => '[t]One[/t]',
                'assignments' => [['action_tag_key' => 'maytuicanvas.create_post', 'input' => ['title' => 'One']]],
            ]],
        ]));
        self::assertSame(201, $defaultResp->getStatusCode());
        self::assertSame('one_account_many_jobs', $defaultResp->getData(true)['data']['execution_mode']);

        $explicitResp = $this->controller->store(Request::create('/campaigns', 'POST', [
            'plan_version' => 2,
            'name' => 'Explicit Mode A',
            'execution_mode' => 'one_account_one_job',
            'action_tags' => [['action_tag_key' => 'maytuicanvas.create_post']],
            'contents' => [[
                'external_ref' => 'e-1',
                'raw_source' => '[t]One[/t]',
                'assignments' => [['action_tag_key' => 'maytuicanvas.create_post', 'input' => ['title' => 'One']]],
            ]],
        ]));
        self::assertSame(201, $explicitResp->getStatusCode());
        self::assertSame('one_account_one_job', $explicitResp->getData(true)['data']['execution_mode']);
    }

    public function test_paused_campaign_can_change_execution_mode_and_resume_uses_new_mode_preserving_history(): void
    {
        $this->seedSite('maytuicanvas');
        $client = $this->seedOnlineClient('maytuicanvas', 'acc_mtc');

        $uuid = $this->createV2Draft('Campaign 9 Like', 'one_account_one_job', [
            ['site_key' => 'maytuicanvas', 'ref' => 'art-1'],
            ['site_key' => 'maytuicanvas', 'ref' => 'art-2'],
        ]);
        $this->controller->start($uuid);
        $camp = SaCampaign::query()->where('campaign_uuid', $uuid)->firstOrFail();

        // Complete art-1 on Account 3
        $claim1 = $this->orchestrator->claimNext((string) $client['agent']->agent_uuid, [(string) $client['session']->session_uuid], null, 'inst-paused-edit');
        $p1 = $claim1['data'];
        $this->orchestrator->acknowledge((string) $p1['job_id'], ['attempt_id' => $p1['attempt_id'], 'lease_token' => $p1['lease_token']], null, 'inst-paused-edit');
        $this->orchestrator->result((string) $p1['job_id'], ['status' => 'succeeded', 'attempt_id' => $p1['attempt_id'], 'lease_token' => $p1['lease_token']], null, 'inst-paused-edit');

        // In one_account_one_job, art-2 stays waiting_resources
        $this->orchestrator->recheckDueCampaigns(now()->addMinutes(65));
        $art2 = SaCampaignContent::query()->where('campaign_id', $camp->id)->where('external_ref', 'art-2')->firstOrFail();
        self::assertSame(CampaignContentStatus::WaitingResources->value, $art2->status);
        self::assertSame(1, SaJob::query()->where('campaign_id', $camp->id)->count());

        // Pause Campaign
        $pauseResp = $this->controller->pause($uuid);
        self::assertSame(200, $pauseResp->getStatusCode());

        // Create historical unknown job on another content (not retried) or check Job 1 attempt
        $job1 = SaJob::query()->where('campaign_id', $camp->id)->firstOrFail();
        $attempt1 = SaJobAttempt::query()->where('job_id', $job1->id)->orderByDesc('id')->firstOrFail();
        $attemptStatusBefore = (string) $attempt1->status;
        $planTag = SaCampaignActionTag::query()->where('campaign_id', $camp->id)->firstOrFail();
        $origReleaseId = $planTag->release_id;

        // Update execution_mode -> one_account_many_jobs while Paused
        $updateResp = $this->controller->update(Request::create('/campaigns/'.$uuid, 'PUT', [
            'plan_version' => 2,
            'execution_mode' => 'one_account_many_jobs',
            'resource_recheck_seconds' => 120,
        ]), $uuid);
        self::assertSame(200, $updateResp->getStatusCode());
        $data = $updateResp->getData(true)['data'];
        self::assertSame('one_account_many_jobs', $data['execution_mode']);
        self::assertSame('paused', $data['status']);

        $camp->refresh();
        self::assertSame(CampaignStatus::Paused->value, $camp->status);
        self::assertNull($camp->next_recheck_at);
        // No job dispatched on save
        self::assertSame(1, SaJob::query()->where('campaign_id', $camp->id)->count());

        // Historical job, attempt, and release pin remain untouched
        $job1->refresh();
        $attempt1->refresh();
        $planTag->refresh();
        self::assertSame('completed', $job1->status);
        self::assertSame($attemptStatusBefore, (string) $attempt1->status);
        self::assertSame($origReleaseId, $planTag->release_id);

        // Resume re-evaluates persisted execution_mode and dispatches art-2 sequentially on same Account
        $resumeResp = $this->controller->resume($uuid);
        self::assertSame(200, $resumeResp->getStatusCode());
        self::assertSame(2, SaJob::query()->where('campaign_id', $camp->id)->count());

        // Overlapping cron tick must not duplicate dispatch
        $tick = $this->orchestrator->recheckDueCampaigns(now()->addMinutes(66));
        self::assertSame(0, $tick['dispatched']);
        self::assertSame(2, SaJob::query()->where('campaign_id', $camp->id)->count());

        // Completed job 1 was never restarted
        $job1->refresh();
        self::assertSame('completed', $job1->status);
    }

    public function test_paused_campaign_enforces_step1_immutability_and_protects_executed_content(): void
    {
        $this->seedSite('maytuicanvas');
        $this->seedSite('congtybalo');
        $client = $this->seedOnlineClient('maytuicanvas', 'acc_mtc');

        $uuid = $this->createV2Draft('Locked Step 1', 'one_account_one_job', [
            ['site_key' => 'maytuicanvas', 'ref' => 's-1'],
            ['site_key' => 'maytuicanvas', 'ref' => 's-2'],
        ]);
        $this->controller->start($uuid);
        $camp = SaCampaign::query()->where('campaign_uuid', $uuid)->firstOrFail();

        $claim = $this->orchestrator->claimNext((string) $client['agent']->agent_uuid, [(string) $client['session']->session_uuid], null, 'inst-paused-edit');
        $p = $claim['data'];
        $this->orchestrator->acknowledge((string) $p['job_id'], ['attempt_id' => $p['attempt_id'], 'lease_token' => $p['lease_token']], null, 'inst-paused-edit');
        $this->orchestrator->result((string) $p['job_id'], ['status' => 'succeeded', 'attempt_id' => $p['attempt_id'], 'lease_token' => $p['lease_token']], null, 'inst-paused-edit');

        $this->controller->pause($uuid);
        $planTag = SaCampaignActionTag::query()->where('campaign_id', $camp->id)->firstOrFail();
        $c1 = SaCampaignContent::query()->where('campaign_id', $camp->id)->where('external_ref', 's-1')->firstOrFail();
        $c2 = SaCampaignContent::query()->where('campaign_id', $camp->id)->where('external_ref', 's-2')->firstOrFail();

        // 1. Reject name change
        $rName = $this->controller->update(Request::create('/campaigns/'.$uuid, 'PUT', [
            'plan_version' => 2,
            'name' => 'Hacked Name',
        ]), $uuid);
        self::assertSame(422, $rName->getStatusCode());
        self::assertContains('step1_immutable_after_start', $rName->getData(true)['error_codes']);

        // 2. Reject run_mode change
        $rRun = $this->controller->update(Request::create('/campaigns/'.$uuid, 'PUT', [
            'plan_version' => 2,
            'run_mode' => 'test',
        ]), $uuid);
        self::assertSame(422, $rRun->getStatusCode());
        self::assertContains('step1_immutable_after_start', $rRun->getData(true)['error_codes']);

        // 3. Reject adding new Action Tag
        $rAddTag = $this->controller->update(Request::create('/campaigns/'.$uuid, 'PUT', [
            'plan_version' => 2,
            'action_tags' => [
                ['id' => $planTag->plan_tag_uuid, 'action_tag_key' => 'maytuicanvas.create_post'],
                ['action_tag_key' => 'congtybalo.create_post'],
            ],
        ]), $uuid);
        self::assertSame(422, $rAddTag->getStatusCode());
        self::assertContains('step1_immutable_after_start', $rAddTag->getData(true)['error_codes']);

        // 4. Reject release_id change
        $rRel = $this->controller->update(Request::create('/campaigns/'.$uuid, 'PUT', [
            'plan_version' => 2,
            'action_tags' => [
                ['id' => $planTag->plan_tag_uuid, 'action_tag_key' => 'maytuicanvas.create_post', 'release_id' => 9999],
            ],
        ]), $uuid);
        self::assertSame(409, $rRel->getStatusCode());
        self::assertContains('release_pin_mismatch', $rRel->getData(true)['error_codes']);

        // 5. Reject modifying executed content s-1
        $rModExec = $this->controller->update(Request::create('/campaigns/'.$uuid, 'PUT', [
            'plan_version' => 2,
            'contents' => [
                [
                    'id' => $c1->content_uuid,
                    'external_ref' => 's-1-modified',
                    'raw_source' => '[t]Title s-1[/t]',
                    'sort_order' => 1,
                    'assignments' => [['action_tag_key' => 'maytuicanvas.create_post', 'input' => ['title' => 'Title s-1']]],
                ],
                [
                    'id' => $c2->content_uuid,
                    'external_ref' => 's-2',
                    'raw_source' => '[t]Title s-2[/t]',
                    'sort_order' => 2,
                    'assignments' => [['action_tag_key' => 'maytuicanvas.create_post', 'input' => ['title' => 'Title s-2']]],
                ],
            ],
        ]), $uuid);
        self::assertSame(422, $rModExec->getStatusCode());
        self::assertContains('executed_content_immutable', $rModExec->getData(true)['error_codes']);

        // 6. Reject deleting executed content s-1
        $rDelExec = $this->controller->update(Request::create('/campaigns/'.$uuid, 'PUT', [
            'plan_version' => 2,
            'contents' => [
                [
                    'id' => $c2->content_uuid,
                    'external_ref' => 's-2',
                    'raw_source' => '[t]Title s-2[/t]',
                    'sort_order' => 2,
                    'assignments' => [['action_tag_key' => 'maytuicanvas.create_post', 'input' => ['title' => 'Title s-2']]],
                ],
            ],
        ]), $uuid);
        self::assertSame(422, $rDelExec->getStatusCode());
        self::assertContains('executed_content_immutable', $rDelExec->getData(true)['error_codes']);

        // 7. Allow safe update of pending s-2 + interval_minutes while keeping s-1 intact
        $rSafe = $this->controller->update(Request::create('/campaigns/'.$uuid, 'PUT', [
            'plan_version' => 2,
            'execution_mode' => 'one_account_many_jobs',
            'action_tags' => [
                ['id' => $planTag->plan_tag_uuid, 'action_tag_key' => 'maytuicanvas.create_post', 'interval_minutes' => 15],
            ],
            'contents' => [
                [
                    'id' => $c1->content_uuid,
                    'external_ref' => 's-1',
                    'raw_source' => '[t]Title s-1[/t]',
                    'sort_order' => 1,
                    'assignments' => [['action_tag_key' => 'maytuicanvas.create_post', 'input' => ['title' => 'Title s-1']]],
                ],
                [
                    'id' => $c2->content_uuid,
                    'external_ref' => 's-2-updated',
                    'raw_source' => '[t]Updated s-2[/t]',
                    'sort_order' => 2,
                    'assignments' => [['action_tag_key' => 'maytuicanvas.create_post', 'input' => ['title' => 'Updated s-2']]],
                ],
            ],
        ]), $uuid);
        self::assertSame(200, $rSafe->getStatusCode());
        $c2->refresh();
        $planTag->refresh();
        self::assertSame('s-2-updated', $c2->external_ref);
        self::assertSame(15, (int) $planTag->interval_minutes);

        // 8. Inline interval PATCH still works
        $patchResp = $this->controller->updateActionTagInterval(
            Request::create('/campaigns/'.$uuid.'/action-tags/'.$planTag->plan_tag_uuid.'/interval', 'PATCH', ['interval_minutes' => 30]),
            $uuid,
            (string) $planTag->plan_tag_uuid
        );
        self::assertSame(200, $patchResp->getStatusCode());
        $planTag->refresh();
        self::assertSame(30, (int) $planTag->interval_minutes);
    }

    public function test_running_cancelled_completed_deleted_foreign_and_unauthorized_are_rejected(): void
    {
        $this->seedSite('maytuicanvas');
        $uuid = $this->createV2Draft('Status Guards', 'one_account_one_job', [
            ['site_key' => 'maytuicanvas', 'ref' => 'g-1'],
        ]);
        $camp = SaCampaign::query()->where('campaign_uuid', $uuid)->firstOrFail();

        foreach ([CampaignStatus::Running->value, CampaignStatus::Cancelled->value, CampaignStatus::Completed->value] as $status) {
            $camp->update(['status' => $status]);
            $resp = $this->controller->update(Request::create('/campaigns/'.$uuid, 'PUT', [
                'plan_version' => 2,
                'execution_mode' => 'one_account_many_jobs',
            ]), $uuid);
            self::assertSame(422, $resp->getStatusCode());
            self::assertContains('v2_campaign_not_draft', $resp->getData(true)['error_codes']);
        }

        // Deleted -> 404
        $camp->update(['status' => CampaignStatus::Paused->value, 'deleted_at' => now()]);
        $delResp = $this->controller->update(Request::create('/campaigns/'.$uuid, 'PUT', [
            'plan_version' => 2,
            'execution_mode' => 'one_account_many_jobs',
        ]), $uuid);
        self::assertSame(404, $delResp->getStatusCode());

        // Foreign installation -> 404
        $camp->update(['deleted_at' => null]);
        $this->currentInstallation = 'inst-foreign';
        $foreignResp = $this->controller->update(Request::create('/campaigns/'.$uuid, 'PUT', [
            'plan_version' => 2,
            'execution_mode' => 'one_account_many_jobs',
        ]), $uuid);
        self::assertSame(404, $foreignResp->getStatusCode());

        // Unauthorized staff -> exception
        $this->currentInstallation = 'inst-paused-edit';
        $this->denyManage = true;
        $this->expectException(\RuntimeException::class);
        $this->controller->update(Request::create('/campaigns/'.$uuid, 'PUT', [
            'plan_version' => 2,
            'execution_mode' => 'one_account_many_jobs',
        ]), $uuid);
    }

    /**
     * @param  list<array{site_key: string, ref: string}>  $items
     */
    private function createV2Draft(string $name, string $mode, array $items): string
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
                    'interval_minutes' => 0,
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

        return (string) $payload['data']['id'];
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
    private function seedOnlineClient(string $siteKey, string $accName): array
    {
        $agent = SaAgent::query()->create([
            'agent_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-paused-edit',
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
            'installation_id' => 'inst-paused-edit',
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
