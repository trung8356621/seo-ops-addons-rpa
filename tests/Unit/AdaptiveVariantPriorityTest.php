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
use Omnichannel\Addons\SocialAutomation\Domain\VariantPreferenceService;
use Omnichannel\Addons\SocialAutomation\Enums\AgentAvailability;
use Omnichannel\Addons\SocialAutomation\Enums\SessionReadyStatus;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\StaffCampaignController;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTagScript;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptVariantGroup;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptVariantGroupItem;
use Omnichannel\Addons\SocialAutomation\Models\SaSessionAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Orchestration\CampaignOrchestrator;
use Omnichannel\Addons\SocialAutomation\Services\CampaignService;
use Omnichannel\Addons\SocialAutomation\Services\ScriptResolutionService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use PHPUnit\Framework\TestCase;

final class AdaptiveVariantPriorityTest extends TestCase
{
    private Capsule $capsule;

    private ?Container $previousApp = null;

    private string $root;

    private StaffCampaignController $controller;

    private CampaignOrchestrator $orchestrator;

    private VariantPreferenceService $preferences;

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
                return 'inst-vg-prio';
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
        $this->runMigration($this->root.'/database/migrations/2026_11_23_010000_create_sa_script_variant_groups_tables.php');
        $this->runMigration($this->root.'/database/migrations/2026_11_23_030000_add_soft_delete_to_sa_campaigns.php');
        $this->runMigration($this->root.'/database/migrations/2026_11_23_040000_add_interval_minutes_to_sa_campaign_action_tags.php');

        CampaignV2ExecutionPolicy::setTestOverride(true);

        $this->preferences = new VariantPreferenceService;
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

    public function test_adaptive_vi_en_learning_cycle_pause_resume_and_pins_preserved(): void
    {
        $fx = $this->seedSiteWithVariants('maytuicanvas');
        $client = $this->seedOnlineClient('maytuicanvas', 'acc_mtc');

        $uuid = $this->createV2Campaign('Adaptive VI/EN', [
            ['site_key' => 'maytuicanvas', 'ref' => 'art-1'],
            ['site_key' => 'maytuicanvas', 'ref' => 'art-2'],
            ['site_key' => 'maytuicanvas', 'ref' => 'art-3'],
        ]);
        $this->controller->start($uuid);
        $camp = SaCampaign::query()->where('campaign_uuid', $uuid)->firstOrFail();
        $planTag = SaCampaignActionTag::query()->where('campaign_id', $camp->id)->firstOrFail();
        $origReleaseId = $planTag->release_id;

        // 1. No history -> default pinned order [VI, EN], preferred_variant null
        $claim1 = $this->orchestrator->claimNext((string) $client['agent']->agent_uuid, [(string) $client['session']->session_uuid], null, 'inst-vg-prio');
        self::assertTrue($claim1['ok']);
        $d1 = $claim1['data'];
        self::assertSame('VI', $d1['script_variants'][0]['language_label']);
        self::assertSame('EN', $d1['script_variants'][1]['language_label']);
        self::assertArrayNotHasKey('preferred_variant', $d1);

        // Staff DTO shows unlearned []
        $detail0 = $this->controller->show($uuid)->getData(true)['data'];
        self::assertSame([], $detail0['action_tags'][0]['variant_preferences']);

        // 2. Job 1: VI safe_not_found -> EN succeeds -> learns EN
        $this->orchestrator->acknowledge((string) $d1['job_id'], ['attempt_id' => $d1['attempt_id'], 'lease_token' => $d1['lease_token']], null, 'inst-vg-prio');
        $res1 = $this->orchestrator->result((string) $d1['job_id'], [
            'attempt_id' => $d1['attempt_id'],
            'lease_token' => $d1['lease_token'],
            'status' => 'succeeded',
            'result' => [
                'variant_attempts' => [
                    ['variant_id' => $fx['vi_uuid'], 'language_label' => 'VI', 'status' => 'safe_not_found', 'failed_step' => 0],
                    ['variant_id' => $fx['en_uuid'], 'language_label' => 'EN', 'status' => 'succeeded'],
                ],
            ],
        ], null, 'inst-vg-prio');
        self::assertTrue($res1['ok']);

        // Duplicate terminal result is idempotent
        $dup1 = $this->orchestrator->result((string) $d1['job_id'], [
            'attempt_id' => $d1['attempt_id'],
            'lease_token' => $d1['lease_token'],
            'status' => 'succeeded',
            'result' => ['variant_id' => $fx['vi_uuid']],
        ], null, 'inst-vg-prio');
        self::assertTrue($dup1['ok']);
        self::assertTrue($dup1['idempotent'] ?? false);

        // Pause & Resume preserves preference
        $this->controller->pause($uuid);
        $this->controller->resume($uuid);

        // 3. Job 2 (art-2) tries EN first + sets preferred_variant
        $claim2 = $this->orchestrator->claimNext((string) $client['agent']->agent_uuid, [(string) $client['session']->session_uuid], null, 'inst-vg-prio');
        self::assertTrue($claim2['ok']);
        $d2 = $claim2['data'];
        self::assertSame('EN', $d2['script_variants'][0]['language_label']);
        self::assertSame('VI', $d2['script_variants'][1]['language_label']);
        self::assertSame($fx['en_uuid'], $d2['preferred_variant']['variant_id']);
        self::assertSame('EN', $d2['preferred_variant']['language_label']);

        // Job 2: EN safe failure, VI succeeds -> flips preference back to VI
        $this->orchestrator->acknowledge((string) $d2['job_id'], ['attempt_id' => $d2['attempt_id'], 'lease_token' => $d2['lease_token']], null, 'inst-vg-prio');
        $res2 = $this->orchestrator->result((string) $d2['job_id'], [
            'attempt_id' => $d2['attempt_id'],
            'lease_token' => $d2['lease_token'],
            'status' => 'succeeded',
            'result' => [
                'variant_attempts' => [
                    ['variant_id' => $fx['en_uuid'], 'language_label' => 'EN', 'status' => 'safe_not_found', 'failed_step' => 0],
                    ['variant_id' => $fx['vi_uuid'], 'language_label' => 'VI', 'status' => 'succeeded'],
                ],
            ],
        ], null, 'inst-vg-prio');
        self::assertTrue($res2['ok'], json_encode($res2));

        // 4. Next tick dispatches Job 3 (art-3), which tries VI first
        $this->orchestrator->recheckDueCampaigns(now()->addMinute());
        $claim3 = $this->orchestrator->claimNext((string) $client['agent']->agent_uuid, [(string) $client['session']->session_uuid], null, 'inst-vg-prio');
        self::assertTrue($claim3['ok']);
        $d3 = $claim3['data'];
        self::assertNotNull($d3, json_encode(['res2' => $res2, 'jobs' => SaJob::query()->get(['id', 'status'])->toArray()]));
        self::assertSame('VI', $d3['script_variants'][0]['language_label']);
        self::assertSame($fx['vi_uuid'], $d3['preferred_variant']['variant_id']);

        // Script Release pin was never mutated
        $planTag->refresh();
        self::assertSame($origReleaseId, $planTag->release_id);
        self::assertSame('VI', $planTag->pinned_variants[0]['language_label']);
    }

    public function test_failures_unknown_and_stale_delayed_results_do_not_flip_preference(): void
    {
        $fx = $this->seedSiteWithVariants('maytuicanvas');
        $client = $this->seedOnlineClient('maytuicanvas', 'acc_mtc');

        // Seed Job 1 (older) still running, Job 2 (newer) already completed with EN
        $jobOld = SaJob::query()->create([
            'job_uuid' => (string) Str::uuid(),
            'account_id' => $client['account']->id,
            'status' => 'running',
            'idempotency_key' => 'old-job',
            'payload' => [
                'installation_id' => 'inst-vg-prio',
                'action_tag_key' => 'maytuicanvas.create_post',
                'variant_group_id' => $fx['vg_uuid'],
                'script_variants' => $fx['variants'],
            ],
        ]);
        $jobNew = SaJob::query()->create([
            'job_uuid' => (string) Str::uuid(),
            'account_id' => $client['account']->id,
            'status' => 'completed',
            'idempotency_key' => 'new-job',
            'finished_at' => now(),
            'payload' => [
                'installation_id' => 'inst-vg-prio',
                'action_tag_key' => 'maytuicanvas.create_post',
                'variant_group_id' => $fx['vg_uuid'],
                'script_variants' => $fx['variants'],
            ],
            'result' => [
                'succeeded_variant' => ['variant_id' => $fx['en_uuid'], 'language_label' => 'EN', 'package_key' => $fx['en_pkg'], 'release_id' => $fx['en_rel']],
            ],
        ]);

        // Older job finishes later with VI -> newer Job (higher id) still wins
        $jobOld->update([
            'status' => 'completed',
            'finished_at' => now()->addMinute(),
            'result' => [
                'succeeded_variant' => ['variant_id' => $fx['vi_uuid'], 'language_label' => 'VI', 'package_key' => $fx['vi_pkg'], 'release_id' => $fx['vi_rel']],
            ],
        ]);
        $pref = $this->preferences->resolveForAccount('inst-vg-prio', $client['account']->id, 'maytuicanvas.create_post', $fx['vg_uuid']);
        self::assertSame($fx['en_uuid'], $pref['preferred_variant_id']);

        // Failed / Unknown jobs never change preference
        SaJob::query()->create([
            'job_uuid' => (string) Str::uuid(),
            'account_id' => $client['account']->id,
            'status' => 'failed',
            'idempotency_key' => 'fail-job',
            'payload' => [
                'installation_id' => 'inst-vg-prio',
                'action_tag_key' => 'maytuicanvas.create_post',
                'variant_group_id' => $fx['vg_uuid'],
                'script_variants' => $fx['variants'],
            ],
            'result' => ['variant_id' => $fx['vi_uuid']],
        ]);
        SaJob::query()->create([
            'job_uuid' => (string) Str::uuid(),
            'account_id' => $client['account']->id,
            'status' => 'unknown',
            'idempotency_key' => 'unk-job',
            'payload' => [
                'installation_id' => 'inst-vg-prio',
                'action_tag_key' => 'maytuicanvas.create_post',
                'variant_group_id' => $fx['vg_uuid'],
                'script_variants' => $fx['variants'],
            ],
        ]);
        $prefAfterFail = $this->preferences->resolveForAccount('inst-vg-prio', $client['account']->id, 'maytuicanvas.create_post', $fx['vg_uuid']);
        self::assertSame($fx['en_uuid'], $prefAfterFail['preferred_variant_id']);
    }

    public function test_scope_isolation_by_account_action_tag_variant_group_and_staff_dto(): void
    {
        $fx = $this->seedSiteWithVariants('maytuicanvas');
        $c1 = $this->seedOnlineClient('maytuicanvas', 'acc_1');
        $c2 = $this->seedOnlineClient('maytuicanvas', 'acc_2');

        // Account 1 prefers EN, Account 2 prefers VI
        SaJob::query()->create([
            'job_uuid' => (string) Str::uuid(),
            'account_id' => $c1['account']->id,
            'status' => 'completed',
            'idempotency_key' => 'acc1-en',
            'finished_at' => now(),
            'payload' => [
                'installation_id' => 'inst-vg-prio',
                'action_tag_key' => 'maytuicanvas.create_post',
                'variant_group_id' => $fx['vg_uuid'],
                'script_variants' => $fx['variants'],
            ],
            'result' => [
                'succeeded_variant' => ['variant_id' => $fx['en_uuid'], 'language_label' => 'EN', 'package_key' => $fx['en_pkg'], 'release_id' => $fx['en_rel']],
            ],
        ]);
        SaJob::query()->create([
            'job_uuid' => (string) Str::uuid(),
            'account_id' => $c2['account']->id,
            'status' => 'completed',
            'idempotency_key' => 'acc2-vi',
            'finished_at' => now(),
            'payload' => [
                'installation_id' => 'inst-vg-prio',
                'action_tag_key' => 'maytuicanvas.create_post',
                'variant_group_id' => $fx['vg_uuid'],
                'script_variants' => $fx['variants'],
            ],
            'result' => [
                'succeeded_variant' => ['variant_id' => $fx['vi_uuid'], 'language_label' => 'VI', 'package_key' => $fx['vi_pkg'], 'release_id' => $fx['vi_rel']],
            ],
        ]);

        // Isolated by account, action_tag, variant_group
        self::assertSame($fx['en_uuid'], $this->preferences->resolveForAccount('inst-vg-prio', $c1['account']->id, 'maytuicanvas.create_post', $fx['vg_uuid'])['preferred_variant_id']);
        self::assertSame($fx['vi_uuid'], $this->preferences->resolveForAccount('inst-vg-prio', $c2['account']->id, 'maytuicanvas.create_post', $fx['vg_uuid'])['preferred_variant_id']);
        self::assertNull($this->preferences->resolveForAccount('inst-vg-prio', $c1['account']->id, 'other.create_post', $fx['vg_uuid']));
        self::assertNull($this->preferences->resolveForAccount('inst-vg-prio', $c1['account']->id, 'maytuicanvas.create_post', 'other-vg'));

        // Incompatible preferred variant falls back to default order
        $incompat = $this->preferences->applyPreferenceToVariants(
            [['variant_id' => 'different-v', 'language_label' => 'FR', 'package_key' => 'p', 'release_id' => 99]],
            'inst-vg-prio',
            $c1['account']->id,
            'maytuicanvas.create_post',
            $fx['vg_uuid'],
        );
        self::assertNull($incompat['preferred_variant']);
        self::assertSame('FR', $incompat['script_variants'][0]['language_label']);

        // Staff DTO exposes account-scoped list (not flattened universal preference)
        $uuid = $this->createV2Campaign('Multi Account DTO', [['site_key' => 'maytuicanvas', 'ref' => 'dto-1']]);
        $detail = $this->controller->show($uuid)->getData(true)['data'];
        $prefs = $detail['action_tags'][0]['variant_preferences'];
        self::assertCount(2, $prefs);
        self::assertSame($c2['account']->id, $prefs[0]['account_id']);
        self::assertSame('VI', $prefs[0]['preferred_language_label']);
        self::assertSame($c1['account']->id, $prefs[1]['account_id']);
        self::assertSame('EN', $prefs[1]['preferred_language_label']);
    }

    public function test_manual_test_learns_only_on_confirmed_success_not_creation_or_failure(): void
    {
        $fx = $this->seedSiteWithVariants('maytuicanvas');
        $client = $this->seedOnlineClient('maytuicanvas', 'acc_mtc');
        $uuid = $this->createV2Campaign('Manual Test Learn', [['site_key' => 'maytuicanvas', 'ref' => 'mt-1']]);
        $camp = SaCampaign::query()->where('campaign_uuid', $uuid)->firstOrFail();
        $planTag = SaCampaignActionTag::query()->where('campaign_id', $camp->id)->firstOrFail();

        // Manual test creation alone learns nothing
        $m1 = $this->controller->manualTestActionTag(
            Request::create('/campaigns/'.$uuid.'/action-tags/'.$planTag->plan_tag_uuid.'/manual-test', 'POST', [
                'account_id' => $client['account']->id,
            ]),
            $uuid,
            (string) $planTag->plan_tag_uuid,
        );
        self::assertSame(201, $m1->getStatusCode(), (string) $m1->getContent());
        self::assertNull($this->preferences->resolveForAccount('inst-vg-prio', $client['account']->id, 'maytuicanvas.create_post', $fx['vg_uuid']));

        $claim = $this->orchestrator->claimNext((string) $client['agent']->agent_uuid, [(string) $client['session']->session_uuid], null, 'inst-vg-prio');
        $d = $claim['data'];
        $this->orchestrator->acknowledge((string) $d['job_id'], ['attempt_id' => $d['attempt_id'], 'lease_token' => $d['lease_token']], null, 'inst-vg-prio');
        $this->orchestrator->result((string) $d['job_id'], [
            'attempt_id' => $d['attempt_id'],
            'lease_token' => $d['lease_token'],
            'status' => 'succeeded',
            'result' => [
                'selected_variant' => ['variant_id' => $fx['en_uuid'], 'language_label' => 'EN'],
            ],
        ], null, 'inst-vg-prio');

        $pref = $this->preferences->resolveForAccount('inst-vg-prio', $client['account']->id, 'maytuicanvas.create_post', $fx['vg_uuid']);
        self::assertSame($fx['en_uuid'], $pref['preferred_variant_id']);
    }

    /**
     * @param  list<array{site_key: string, ref: string}>  $items
     */
    private function createV2Campaign(string $name, array $items): string
    {
        $tags = [];
        $contents = [];
        $seen = [];
        foreach ($items as $i => $item) {
            $sk = $item['site_key'];
            if (! isset($seen[$sk])) {
                $seen[$sk] = true;
                $tags[] = ['action_tag_key' => $sk.'.create_post', 'interval_minutes' => 0];
            }
            $contents[] = [
                'external_ref' => $item['ref'],
                'raw_source' => '[t]Title '.$item['ref'].'[/t]',
                'sort_order' => $i + 1,
                'assignments' => [['action_tag_key' => $sk.'.create_post', 'input' => ['title' => 'Title '.$item['ref']]]],
            ];
        }

        $res = $this->controller->store(Request::create('/campaigns', 'POST', [
            'plan_version' => 2,
            'name' => $name,
            'run_mode' => 'live',
            'execution_mode' => 'one_account_many_jobs',
            'action_tags' => $tags,
            'contents' => $contents,
        ]));
        $payload = json_decode((string) $res->getContent(), true);
        self::assertSame(201, $res->getStatusCode(), json_encode($payload));

        return (string) $payload['data']['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function seedSiteWithVariants(string $siteKey): array
    {
        $site = SaSite::query()->create(['key' => $siteKey, 'name' => ucfirst($siteKey), 'base_url' => 'https://'.$siteKey.'.com', 'cms_type' => 'wordpress']);
        $path = SaPath::query()->create(['site_id' => $site->id, 'key' => 'create_post', 'name' => 'Create Post', 'url_pattern' => '/wp-admin/post-new.php']);
        $tag = SaActionTag::query()->create([
            'site_id' => $site->id, 'path_id' => $path->id, 'site_key' => $siteKey,
            'path_key' => 'create_post', 'platform' => $siteKey, 'target' => 'create_post',
            'action' => '', 'tag_key' => $siteKey.'.create_post', 'is_enabled' => true,
        ]);

        $pkgVi = SaPackage::query()->create(['package_key' => $siteKey.'.vi', 'kind' => 'script', 'name' => 'VI Script', 'is_enabled' => true, 'sharing_mode' => 'site_specific']);
        $relVi = SaPackageRelease::query()->create([
            'package_id' => $pkgVi->id, 'version' => '1.0.0', 'content_digest' => hash('sha256', 'vi'),
            'schema_version' => 1, 'manifest' => ['variables' => [['key' => 'title', 'type' => 'string', 'required' => true]]],
            'status' => 'published', 'published_at' => now(),
        ]);
        $pkgEn = SaPackage::query()->create(['package_key' => $siteKey.'.en', 'kind' => 'script', 'name' => 'EN Script', 'is_enabled' => true, 'sharing_mode' => 'site_specific']);
        $relEn = SaPackageRelease::query()->create([
            'package_id' => $pkgEn->id, 'version' => '1.0.0', 'content_digest' => hash('sha256', 'en'),
            'schema_version' => 1, 'manifest' => ['variables' => [['key' => 'title', 'type' => 'string', 'required' => true]]],
            'status' => 'published', 'published_at' => now(),
        ]);

        $vgUuid = (string) Str::uuid();
        $vg = SaScriptVariantGroup::query()->create([
            'group_uuid' => $vgUuid,
            'name' => $tag->tag_key,
            'scope_type' => SaScriptVariantGroup::SCOPE_SITE_ACTION,
            'action_tag_key' => $tag->tag_key,
            'is_enabled' => true,
        ]);
        $viUuid = (string) Str::uuid();
        $enUuid = (string) Str::uuid();
        SaScriptVariantGroupItem::query()->create([
            'variant_uuid' => $viUuid, 'group_id' => $vg->id, 'package_id' => $pkgVi->id,
            'package_key' => $pkgVi->package_key, 'release_id' => $relVi->id,
            'script_version' => '1.0.0', 'language_label' => 'VI', 'priority' => 1, 'is_enabled' => true,
        ]);
        SaScriptVariantGroupItem::query()->create([
            'variant_uuid' => $enUuid, 'group_id' => $vg->id, 'package_id' => $pkgEn->id,
            'package_key' => $pkgEn->package_key, 'release_id' => $relEn->id,
            'script_version' => '1.0.0', 'language_label' => 'EN', 'priority' => 2, 'is_enabled' => true,
        ]);

        SaActionTagScript::query()->create([
            'action_tag_id' => $tag->id, 'action_tag_key' => $tag->tag_key,
            'package_id' => $pkgVi->id, 'release_id' => $relVi->id,
            'script_id' => $pkgVi->package_key, 'script_version' => '1.0.0',
        ]);

        return [
            'vg_uuid' => $vgUuid,
            'vi_uuid' => $viUuid, 'vi_pkg' => $pkgVi->package_key, 'vi_rel' => $relVi->id,
            'en_uuid' => $enUuid, 'en_pkg' => $pkgEn->package_key, 'en_rel' => $relEn->id,
            'variants' => [
                ['variant_id' => $viUuid, 'language_label' => 'VI', 'package_key' => $pkgVi->package_key, 'release_id' => $relVi->id, 'priority' => 1],
                ['variant_id' => $enUuid, 'language_label' => 'EN', 'package_key' => $pkgEn->package_key, 'release_id' => $relEn->id, 'priority' => 2],
            ],
        ];
    }

    /**
     * @return array{agent: SaAgent, account: SaAccount, session: SaBrowserSession}
     */
    private function seedOnlineClient(string $siteKey, string $accName): array
    {
        $agent = SaAgent::query()->create([
            'agent_uuid' => (string) Str::uuid(), 'installation_id' => 'inst-vg-prio',
            'name' => 'Runner '.$accName, 'availability' => AgentAvailability::Available->value,
            'active_jobs' => 0, 'max_concurrency' => 2, 'capabilities' => ['wordpress'],
            'runtime_version' => '2.0.0', 'last_heartbeat_at' => now(), 'agent_kind' => 'runner',
        ]);
        $account = SaAccount::query()->create([
            'account_uuid' => (string) Str::uuid(), 'installation_id' => 'inst-vg-prio',
            'name' => $accName, 'platform' => $siteKey, 'domain' => $siteKey.'.com',
            'verified' => false, 'identity_source' => 'inventory',
        ]);
        $session = SaBrowserSession::query()->create([
            'session_uuid' => (string) Str::uuid(), 'agent_id' => $agent->id, 'account_id' => $account->id,
            'ready_status' => SessionReadyStatus::Ready->value, 'dispatch_enabled' => true, 'session_state' => 'candidate',
        ]);
        SaSessionAccount::query()->create(['browser_session_id' => $session->id, 'account_id' => $account->id, 'is_active' => true]);

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
            $table->string('name', 255)->nullable();
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
