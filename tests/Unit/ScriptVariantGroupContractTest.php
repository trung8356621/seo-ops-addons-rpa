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
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ScriptRegistry\StaffScriptVariantGroupController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\StaffCampaignController;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaArtifact;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContent;
use Omnichannel\Addons\SocialAutomation\Models\SaCmsPathTemplate;
use Omnichannel\Addons\SocialAutomation\Models\SaCmsType;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaJobAttempt;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaSessionAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Orchestration\CampaignOrchestrator;
use Omnichannel\Addons\SocialAutomation\Services\ActionScriptDistributionService;
use Omnichannel\Addons\SocialAutomation\Services\ArticleLibraryService;
use Omnichannel\Addons\SocialAutomation\Services\CampaignArticleAssignmentService;
use Omnichannel\Addons\SocialAutomation\Services\CampaignService;
use Omnichannel\Addons\SocialAutomation\Services\ScriptResolutionService;
use Omnichannel\Addons\SocialAutomation\Services\ScriptVariantGroupService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use PHPUnit\Framework\TestCase;

final class ScriptVariantGroupContractTest extends TestCase
{
    private Capsule $capsule;

    private ?Container $previousApp = null;

    private string $root;

    private ScriptResolutionService $resolver;

    private ScriptVariantGroupService $groups;

    private StaffScriptVariantGroupController $groupController;

    private CampaignService $campaigns;

    private CampaignOrchestrator $orchestrator;

    private ActionScriptDistributionService $distribution;

    private ArticleLibraryService $articles;

    private CampaignArticleAssignmentService $assignments;

    private StaffCampaignController $campaignController;

    /** @var list<array<int, mixed>> */
    public array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite unavailable');
        }

        putenv('SA_CAMPAIGN_V2_EXECUTION_ENABLED=true');

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
        $test = $this;
        $app->instance('log', new class($test) {
            public function __construct(private readonly ScriptVariantGroupContractTest $test) {}

            public function __call(string $method, array $args): void
            {
                $this->test->logs[] = [$method, $args];
            }
        });
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

            public function assertCanMutate(?User $user = null): void {}
        };
        $app->instance(SocialAutomationAccess::class, $access);

        $installation = new class extends SocialAutomationInstallationResolver {
            public function __construct() {}

            public function resolveInstallationId(): string
            {
                return 'inst-vg-test';
            }
        };
        $app->instance(SocialAutomationInstallationResolver::class, $installation);
        $app->instance(ResponseFactory::class, $this->makeResponseFactory());

        $this->createBaseSchema();
        $this->runMigration($this->root.'/database/migrations/2026_11_02_150000_create_sa_campaign_tables.php');
        $this->runMigration($this->root.'/database/migrations/2026_11_20_100000_add_campaign_shortcode_v2_columns.php');
        $this->runMigration($this->root.'/database/migrations/2026_11_21_140000_create_sa_campaign_v2_tables.php');
        $this->runMigration($this->root.'/database/migrations/2026_11_23_000000_create_sa_article_library_tables.php');
        $this->runMigration($this->root.'/database/migrations/2026_11_23_010000_create_sa_script_variant_groups_tables.php');

        $this->resolver = new ScriptResolutionService;
        $this->groups = new ScriptVariantGroupService($this->resolver);
        $this->groupController = new StaffScriptVariantGroupController($this->groups, $access);
        $this->orchestrator = new CampaignOrchestrator;
        $this->campaigns = new CampaignService($this->orchestrator);
        $this->distribution = new ActionScriptDistributionService;
        $this->articles = new ArticleLibraryService;
        $this->assignments = new CampaignArticleAssignmentService;
        $this->campaignController = new StaffCampaignController($this->campaigns, $access, $installation);
    }

    protected function tearDown(): void
    {
        putenv('SA_CAMPAIGN_V2_EXECUTION_ENABLED');
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

    public function test_one_two_three_variant_groups_duplicate_labels_and_reorder(): void
    {
        $fx = $this->seedWordPressFixture();

        // 1-variant group
        $createRes = $this->groupController->store(Request::create('/variant-groups', 'POST', [
            'name' => 'WP Create Post Variants',
            'scope_type' => 'cms_global',
            'global_scope_key' => 'wordpress:create_post',
            'cms_type' => 'wordpress',
            'cms_template_id' => $fx['tpl']->id,
            'cms_path_key' => 'create_post',
            'variants' => [
                [
                    'package_key' => $fx['pkg_vi']->package_key,
                    'release_id' => $fx['rel_vi']->id,
                    'language_label' => 'vi',
                ],
            ],
        ]));
        self::assertSame(201, $createRes->getStatusCode(), (string) $createRes->getContent());
        $groupData = json_decode((string) $createRes->getContent(), true)['group'];
        self::assertCount(1, $groupData['variants']);
        self::assertSame('VI', $groupData['variants'][0]['language_label']);
        self::assertSame(1, $groupData['variants'][0]['priority']);

        // Reject duplicate language label
        $dupLabel = $this->groups->addVariant($groupData['id'], [
            'package_key' => $fx['pkg_en']->package_key,
            'release_id' => $fx['rel_en']->id,
            'language_label' => 'VI',
        ]);
        self::assertFalse($dupLabel['ok']);
        self::assertSame('duplicate_language_label', $dupLabel['error']);

        // 2-variant group (VI -> EN)
        $addEn = $this->groups->addVariant($groupData['id'], [
            'package_key' => $fx['pkg_en']->package_key,
            'release_id' => $fx['rel_en']->id,
            'language_label' => 'EN',
        ]);
        self::assertTrue($addEn['ok']);
        self::assertCount(2, $addEn['data']['variants']);

        // 3-variant group (VI -> EN -> JA)
        $addJa = $this->groups->addVariant($groupData['id'], [
            'package_key' => $fx['pkg_ja']->package_key,
            'release_id' => $fx['rel_ja']->id,
            'language_label' => 'JA',
        ]);
        self::assertTrue($addJa['ok']);
        $variants = $addJa['data']['variants'];
        self::assertCount(3, $variants);

        // Reorder to EN -> VI -> JA
        $reordered = $this->groups->reorder($groupData['id'], [
            $variants[1]['id'],
            $variants[0]['id'],
            $variants[2]['id'],
        ]);
        self::assertTrue($reordered['ok']);
        self::assertSame('EN', $reordered['data']['variants'][0]['language_label']);
        self::assertSame(1, $reordered['data']['variants'][0]['priority']);
        self::assertSame('VI', $reordered['data']['variants'][1]['language_label']);
        self::assertSame(2, $reordered['data']['variants'][1]['priority']);
        self::assertSame('JA', $reordered['data']['variants'][2]['language_label']);
        self::assertSame(3, $reordered['data']['variants'][2]['priority']);
    }

    public function test_cms_global_ownership_and_legacy_conflict_rules(): void
    {
        $fx = $this->seedWordPressFixture();

        // Create Variant Group owning wordpress:create_post with VI + EN
        $created = $this->groups->create([
            'name' => 'WP Create Post Group',
            'scope_type' => 'cms_global',
            'global_scope_key' => 'wordpress:create_post',
            'cms_type' => 'wordpress',
            'cms_template_id' => $fx['tpl']->id,
            'cms_path_key' => 'create_post',
            'variants' => [
                ['package_key' => $fx['pkg_vi']->package_key, 'release_id' => $fx['rel_vi']->id, 'language_label' => 'VI'],
                ['package_key' => $fx['pkg_en']->package_key, 'release_id' => $fx['rel_en']->id, 'language_label' => 'EN'],
            ],
        ], 42);
        self::assertTrue($created['ok']);

        // Member packages can call setGlobalSharing('cms_global') without conflict
        $shVi = $this->resolver->setGlobalSharing($fx['pkg_vi']->fresh(), SaPackage::SHARING_CMS_GLOBAL);
        $shEn = $this->resolver->setGlobalSharing($fx['pkg_en']->fresh(), SaPackage::SHARING_CMS_GLOBAL);
        self::assertTrue($shVi['ok']);
        self::assertTrue($shEn['ok']);

        // Package OUTSIDE the owning group MUST still fail with 409 global_scope_conflict!
        $outside = $this->resolver->setGlobalSharing($fx['pkg_ja']->fresh(), SaPackage::SHARING_CMS_GLOBAL);
        self::assertFalse($outside['ok']);
        self::assertSame(409, $outside['http']);
        self::assertSame('global_scope_conflict', $outside['error']);

        // Second group for same global_scope_key MUST fail with 409 global_scope_conflict!
        $secondGroup = $this->groups->create([
            'name' => 'Duplicate Scope Group',
            'scope_type' => 'cms_global',
            'global_scope_key' => 'wordpress:create_post',
        ], 42);
        self::assertFalse($secondGroup['ok']);
        self::assertSame(409, $secondGroup['http']);
        self::assertSame('global_scope_conflict', $secondGroup['error']);

        // Wrong CMS/Path package MUST be rejected from joining the group
        $wrongScope = $this->groups->addVariant($created['data']['id'], [
            'package_key' => $fx['pkg_other_path']->package_key,
            'release_id' => $fx['rel_other_path']->id,
            'language_label' => 'FR',
        ]);
        self::assertFalse($wrongScope['ok']);
        self::assertSame('incompatible_variant_scope', $wrongScope['error']);

        // Resolve via Action Tag returns VI primary + ordered variant_group [VI, EN]
        $resolved = $this->resolver->resolveForTag($fx['tag']);
        self::assertTrue($resolved['ok']);
        self::assertSame('cms_global', $resolved['source']);
        self::assertSame($fx['pkg_vi']->package_key, $resolved['package']->package_key);
        self::assertCount(2, $resolved['variant_group']['variants']);
        self::assertSame('VI', $resolved['variant_group']['variants'][0]['language_label']);
        self::assertSame('EN', $resolved['variant_group']['variants'][1]['language_label']);
    }

    public function test_legacy_script_registry_conflict_preserved_when_no_variant_group_owns_scope(): void
    {
        $fx = $this->seedWordPressFixture();

        $r1 = $this->resolver->setGlobalSharing($fx['pkg_vi'], SaPackage::SHARING_CMS_GLOBAL);
        self::assertTrue($r1['ok']);

        // Without a Variant Group, a second cms_global package on the same scope MUST still conflict
        $r2 = $this->resolver->setGlobalSharing($fx['pkg_en'], SaPackage::SHARING_CMS_GLOBAL);
        self::assertFalse($r2['ok']);
        self::assertSame(409, $r2['http']);
        self::assertSame('global_scope_conflict', $r2['error']);
    }

    public function test_campaign_immutable_pinning_claim_pull_and_artifact_metadata_with_single_job_attempt(): void
    {
        $fx = $this->seedWordPressFixture();

        // Create VI -> EN Variant Group
        $vg = $this->groups->create([
            'name' => 'WP VI->EN Fallback',
            'scope_type' => 'cms_global',
            'global_scope_key' => 'wordpress:create_post',
            'cms_type' => 'wordpress',
            'cms_template_id' => $fx['tpl']->id,
            'cms_path_key' => 'create_post',
            'variants' => [
                ['package_key' => $fx['pkg_vi']->package_key, 'release_id' => $fx['rel_vi']->id, 'language_label' => 'VI'],
                ['package_key' => $fx['pkg_en']->package_key, 'release_id' => $fx['rel_en']->id, 'language_label' => 'EN'],
            ],
        ], 42);
        self::assertTrue($vg['ok']);
        $groupUuid = $vg['data']['id'];

        // Seed online Agent + Ready BrowserSession + Active Account for baloquatang
        $agent = SaAgent::query()->create([
            'agent_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-vg-test',
            'name' => 'client-A',
            'availability' => 'available',
            'active_jobs' => 0,
            'max_concurrency' => 2,
            'agent_kind' => 'runner',
            'last_heartbeat_at' => now(),
            'capabilities' => ['runner_version' => '1.0.0', 'runtime_version' => '1.0.0'],
        ]);
        $account = SaAccount::query()->create([
            'account_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-vg-test',
            'platform' => 'baloquatang',
            'platform_account_id' => 'wp_admin',
            'username' => 'wp_admin',
            'verified' => true,
            'status' => 'active',
        ]);
        $session = SaBrowserSession::query()->create([
            'session_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-vg-test',
            'agent_id' => $agent->id,
            'account_id' => $account->id,
            'platform' => 'baloquatang',
            'ready_status' => 'ready',
            'session_state' => 'verified',
            'dispatch_enabled' => true,
            'last_seen_at' => now(),
        ]);
        SaSessionAccount::query()->create([
            'browser_session_id' => $session->id,
            'account_id' => $account->id,
            'is_active' => true,
        ]);

        // Create Article + Campaign V2 Draft
        $art = $this->articles->create([
            'title' => 'Variant Test Post',
            'action_tag_key' => 'baloquatang.create_post',
            'input' => ['wp_title' => 'Hello', 'wp_body' => 'World'],
        ], 'inst-vg-test', 42);
        self::assertTrue($art['ok'], json_encode($art));

        $campRes = $this->campaignController->store(Request::create('/social-automation/api/campaigns', 'POST', [
            'plan_version' => 2,
            'name' => 'Variant Pinning Campaign',
            'run_mode' => 'live',
            'execution_mode' => 'one_account_one_job',
            'action_tags' => [
                [
                    'action_tag_key' => 'baloquatang.create_post',
                    'release_id' => $fx['rel_vi']->id,
                    'script_id' => $fx['pkg_vi']->package_key,
                    'script_version' => '1.0.0',
                    'resolution_source' => 'cms_global',
                ],
            ],
        ]));
        self::assertSame(201, $campRes->getStatusCode(), (string) $campRes->getContent());
        $campUuid = json_decode((string) $campRes->getContent(), true)['data']['id'];

        $assigned = $this->assignments->assignArticles($campUuid, 'inst-vg-test', ['article_ids' => [$art['data']['id']]]);
        self::assertTrue($assigned['ok']);

        // Start Campaign -> pins [VI, EN] and dispatches 1 Job
        $started = $this->campaigns->start($campUuid, 'inst-vg-test');
        self::assertTrue($started['ok']);

        // Mutate Variant Group AFTER Start (reorder EN -> VI)
        $reordered = $this->groups->reorder($groupUuid, [
            $vg['data']['variants'][1]['id'],
            $vg['data']['variants'][0]['id'],
        ]);
        self::assertTrue($reordered['ok']);

        // Campaign pinned_variants MUST remain [VI, EN] (immutable!)
        $campModel = SaCampaign::query()->where('campaign_uuid', $campUuid)->firstOrFail();
        $planTag = SaCampaignActionTag::query()->where('campaign_id', $campModel->id)->firstOrFail();
        self::assertSame('VI', $planTag->pinned_variants[0]['language_label']);
        self::assertSame('EN', $planTag->pinned_variants[1]['language_label']);

        // Only ONE Job created (no duplicate Job execution)
        self::assertSame(1, SaJob::query()->where('campaign_id', $campModel->id)->count(), json_encode($this->logs));

        // Claim Job -> returns ordered script_variants [VI, EN] + fallback_policy
        $claimed = $this->orchestrator->claimNext($agent->agent_uuid, [$session->session_uuid], null, 'inst-vg-test');
        self::assertTrue($claimed['ok'], json_encode($claimed));
        $claimData = $claimed['data'];
        self::assertCount(2, $claimData['script_variants']);
        self::assertSame('VI', $claimData['script_variants'][0]['language_label']);
        self::assertSame('EN', $claimData['script_variants'][1]['language_label']);
        self::assertSame('first_step_not_found_before_mutation', $claimData['fallback_policy']['allowed_only_on']);

        // Pull Variant 1 (VI) AND Variant 2 (EN) using the SAME Job/Attempt identity
        $pullVi = $this->distribution->pullForJob(
            'inst-vg-test',
            $claimData['job_id'],
            $claimData['attempt_id'],
            $claimData['lease_token'],
            $claimData['account_id'],
            $fx['pkg_vi']->package_key,
            '1.0.0',
            $fx['rel_vi']->id,
        );
        self::assertTrue($pullVi['ok']);
        self::assertSame('VI', $pullVi['release']['variant_context']['language_label']);

        $pullEn = $this->distribution->pullForJob(
            'inst-vg-test',
            $claimData['job_id'],
            $claimData['attempt_id'],
            $claimData['lease_token'],
            $claimData['account_id'],
            $fx['pkg_en']->package_key,
            '1.0.0',
            $fx['rel_en']->id,
        );
        self::assertTrue($pullEn['ok'], json_encode($pullEn));
        self::assertSame('EN', $pullEn['release']['variant_context']['language_label']);

        // Unpinned variant (JA) MUST be rejected with 403 release_not_pinned_to_job
        $pullJa = $this->distribution->pullForJob(
            'inst-vg-test',
            $claimData['job_id'],
            $claimData['attempt_id'],
            $claimData['lease_token'],
            $claimData['account_id'],
            $fx['pkg_ja']->package_key,
            '1.0.0',
            $fx['rel_ja']->id,
        );
        self::assertFalse($pullJa['ok']);
        self::assertSame(403, $pullJa['http']);
        self::assertContains('release_not_pinned_to_job', $pullJa['errors']);

        // Record failure screenshot for VI + final success screenshot for EN under the SAME Job/Attempt
        $jobModel = SaJob::query()->where('job_uuid', $claimData['job_id'])->firstOrFail();
        $attemptModel = SaJobAttempt::query()->where('attempt_uuid', $claimData['attempt_id'])->firstOrFail();
        $attemptCountBeforeResult = SaJobAttempt::query()->where('job_id', $jobModel->id)->count();

        $artFailUuid = (string) Str::uuid();
        $artOkUuid = (string) Str::uuid();
        SaArtifact::query()->create([
            'artifact_uuid' => $artFailUuid,
            'job_id' => $jobModel->id,
            'kind' => 'screenshot',
            'mime' => 'image/png',
            'size_bytes' => 1024,
            'checksum_sha256' => str_repeat('a', 64),
            'storage_path' => 'sa/fail-vi.png',
        ]);
        SaArtifact::query()->create([
            'artifact_uuid' => $artOkUuid,
            'job_id' => $jobModel->id,
            'kind' => 'screenshot',
            'mime' => 'image/png',
            'size_bytes' => 2048,
            'checksum_sha256' => str_repeat('b', 64),
            'storage_path' => 'sa/ok-en.png',
        ]);

        $completed = $this->orchestrator->result(
            $claimData['job_id'],
            [
                'attempt_id' => $claimData['attempt_id'],
                'lease_token' => $claimData['lease_token'],
                'status' => 'succeeded',
                'result' => [
                    'post_url' => 'https://baloquatang.net/?p=123',
                    'variant_attempts' => [
                        ['variant_id' => $vg['data']['variants'][0]['id'], 'language_label' => 'VI', 'status' => 'safe_not_found', 'failed_step' => 0],
                        ['variant_id' => $vg['data']['variants'][1]['id'], 'language_label' => 'EN', 'status' => 'succeeded'],
                    ],
                ],
                'artifacts' => [
                    [
                        'artifact_uuid' => $artFailUuid,
                        'kind' => 'screenshot',
                        'mime' => 'image/png',
                        'size_bytes' => 1024,
                        'checksum_sha256' => str_repeat('a', 64),
                        'outcome' => 'failed',
                        'variant_id' => $vg['data']['variants'][0]['id'],
                        'language_label' => 'VI',
                        'priority' => 1,
                        'failed_step' => 0,
                        'screenshot_role' => 'variant_failure',
                        'metadata' => ['phase' => 'failed_step', 'outcome' => 'failed'],
                    ],
                    [
                        'artifact_uuid' => $artOkUuid,
                        'kind' => 'screenshot',
                        'mime' => 'image/png',
                        'size_bytes' => 2048,
                        'checksum_sha256' => str_repeat('b', 64),
                        'outcome' => 'success',
                        'variant_id' => $vg['data']['variants'][1]['id'],
                        'language_label' => 'EN',
                        'priority' => 2,
                        'screenshot_role' => 'final_success',
                        'metadata' => ['phase' => 'final', 'outcome' => 'success'],
                    ],
                ],
            ],
            null,
            'inst-vg-test',
        );
        self::assertTrue($completed['ok'], json_encode($completed));

        // Still only 1 Job and the SAME Attempt count (no new Attempt created during fallback), with both artifacts linked & annotated
        self::assertSame(1, SaJob::query()->where('campaign_id', $campModel->id)->count());
        self::assertSame($attemptCountBeforeResult, SaJobAttempt::query()->where('job_id', $jobModel->id)->count());

        $savedFail = SaArtifact::query()->where('artifact_uuid', $artFailUuid)->firstOrFail();
        $savedOk = SaArtifact::query()->where('artifact_uuid', $artOkUuid)->firstOrFail();
        self::assertSame($attemptModel->id, $savedFail->attempt_id);
        self::assertSame($attemptModel->id, $savedOk->attempt_id);
        self::assertSame('variant_failure', $savedFail->metadata['screenshot_role']);
        self::assertSame(0, $savedFail->metadata['failed_step']);
        self::assertSame('VI', $savedFail->metadata['language_label']);
        self::assertSame('final_success', $savedOk->metadata['screenshot_role']);
        self::assertSame('EN', $savedOk->metadata['language_label']);
    }

    /**
     * @return array<string, mixed>
     */
    private function seedWordPressFixture(): array
    {
        $cms = SaCmsType::query()->create(['key' => 'wordpress', 'name' => 'WordPress', 'is_enabled' => true]);
        $tpl = SaCmsPathTemplate::query()->create([
            'cms_type_id' => $cms->id,
            'key' => 'create_post',
            'name' => 'Create Post',
            'url_pattern' => '/wp-admin/post-new.php',
            'is_authentication' => false,
        ]);
        $tplOther = SaCmsPathTemplate::query()->create([
            'cms_type_id' => $cms->id,
            'key' => 'edit_profile',
            'name' => 'Edit Profile',
            'url_pattern' => '/wp-admin/profile.php',
            'is_authentication' => false,
        ]);

        $site = SaSite::query()->create([
            'key' => 'baloquatang',
            'name' => 'Balo Quatang',
            'domain' => 'baloquatang.net',
            'base_url_mode' => 'fixed',
            'cms_type_id' => $cms->id,
            'cms_type' => 'wordpress',
            'is_active' => true,
        ]);
        $path = SaPath::query()->create([
            'site_id' => $site->id,
            'key' => 'create_post',
            'name' => 'Create Post',
            'url_pattern' => '/wp-admin/post-new.php',
            'is_authentication' => false,
            'cms_template_id' => $tpl->id,
        ]);
        $tag = SaActionTag::query()->create([
            'site_id' => $site->id,
            'path_id' => $path->id,
            'site_key' => 'baloquatang',
            'path_key' => 'create_post',
            'tag_key' => 'baloquatang.create_post',
            'platform' => 'baloquatang',
            'target' => 'create_post',
            'action' => '',
            'is_enabled' => true,
        ]);

        $pathOther = SaPath::query()->create([
            'site_id' => $site->id,
            'key' => 'edit_profile',
            'name' => 'Edit Profile',
            'url_pattern' => '/wp-admin/profile.php',
            'is_authentication' => false,
            'cms_template_id' => $tplOther->id,
        ]);
        SaActionTag::query()->create([
            'site_id' => $site->id,
            'path_id' => $pathOther->id,
            'site_key' => 'baloquatang',
            'path_key' => 'edit_profile',
            'tag_key' => 'baloquatang.edit_profile',
            'platform' => 'baloquatang',
            'target' => 'edit_profile',
            'action' => '',
            'is_enabled' => true,
        ]);

        $manifest = [
            'schema_version' => 1,
            'action_tag_key' => 'baloquatang.create_post',
            'is_authentication' => false,
            'cms_type' => 'wordpress',
            'cms_template_id' => $tpl->id,
            'cms_path_key' => 'create_post',
            'variables' => [
                ['key' => 'wp_title', 'type' => 'string', 'required' => true],
                ['key' => 'wp_body', 'type' => 'text', 'required' => true],
            ],
            'steps' => [['action' => 'type', 'target' => 'wp_title']],
        ];

        $pkgVi = SaPackage::query()->create([
            'package_key' => 'wp-global-create-post-vi',
            'kind' => 'script',
            'name' => 'WP Global Create Post VI',
            'is_enabled' => true,
            'sharing_mode' => SaPackage::SHARING_SITE_SPECIFIC,
            'global_cms_type' => 'wordpress',
            'global_cms_template_id' => $tpl->id,
            'global_cms_path_key' => 'create_post',
        ]);
        $relVi = SaPackageRelease::query()->create([
            'package_id' => $pkgVi->id,
            'version' => '1.0.0',
            'status' => 'published',
            'schema_version' => 1,
            'manifest' => $manifest,
            'content_digest' => sha1('vi-1.0.0'),
            'execution_digest' => sha1('exec-vi-1.0.0'),
            'published_at' => now(),
        ]);

        $pkgEn = SaPackage::query()->create([
            'package_key' => 'wp-global-create-post-en',
            'kind' => 'script',
            'name' => 'WP Global Create Post EN',
            'is_enabled' => true,
            'sharing_mode' => SaPackage::SHARING_SITE_SPECIFIC,
            'global_cms_type' => 'wordpress',
            'global_cms_template_id' => $tpl->id,
            'global_cms_path_key' => 'create_post',
        ]);
        $relEn = SaPackageRelease::query()->create([
            'package_id' => $pkgEn->id,
            'version' => '1.0.0',
            'status' => 'published',
            'schema_version' => 1,
            'manifest' => $manifest,
            'content_digest' => sha1('en-1.0.0'),
            'execution_digest' => sha1('exec-en-1.0.0'),
            'published_at' => now(),
        ]);

        $pkgJa = SaPackage::query()->create([
            'package_key' => 'wp-global-create-post-ja',
            'kind' => 'script',
            'name' => 'WP Global Create Post JA',
            'is_enabled' => true,
            'sharing_mode' => SaPackage::SHARING_SITE_SPECIFIC,
            'global_cms_type' => 'wordpress',
            'global_cms_template_id' => $tpl->id,
            'global_cms_path_key' => 'create_post',
        ]);
        $relJa = SaPackageRelease::query()->create([
            'package_id' => $pkgJa->id,
            'version' => '1.0.0',
            'status' => 'published',
            'schema_version' => 1,
            'manifest' => $manifest,
            'content_digest' => sha1('ja-1.0.0'),
            'execution_digest' => sha1('exec-ja-1.0.0'),
            'published_at' => now(),
        ]);

        $pkgOther = SaPackage::query()->create([
            'package_key' => 'wp-global-edit-profile',
            'kind' => 'script',
            'name' => 'WP Edit Profile',
            'is_enabled' => true,
            'sharing_mode' => SaPackage::SHARING_SITE_SPECIFIC,
            'global_cms_type' => 'wordpress',
            'global_cms_template_id' => $tplOther->id,
            'global_cms_path_key' => 'edit_profile',
        ]);
        $relOther = SaPackageRelease::query()->create([
            'package_id' => $pkgOther->id,
            'version' => '1.0.0',
            'status' => 'published',
            'schema_version' => 1,
            'manifest' => array_merge($manifest, ['action_tag_key' => 'baloquatang.edit_profile', 'cms_path_key' => 'edit_profile', 'cms_template_id' => $tplOther->id]),
            'content_digest' => sha1('other-1.0.0'),
            'execution_digest' => sha1('exec-other-1.0.0'),
            'published_at' => now(),
        ]);

        return [
            'cms' => $cms,
            'tpl' => $tpl,
            'site' => $site,
            'path' => $path,
            'tag' => $tag,
            'pkg_vi' => $pkgVi,
            'rel_vi' => $relVi,
            'pkg_en' => $pkgEn,
            'rel_en' => $relEn,
            'pkg_ja' => $pkgJa,
            'rel_ja' => $relJa,
            'pkg_other_path' => $pkgOther,
            'rel_other_path' => $relOther,
        ];
    }

    private function createBaseSchema(): void
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;

        Schema::connection($conn)->create('sa_cms_types', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name', 128);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        Schema::connection($conn)->create('sa_cms_path_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cms_type_id');
            $table->string('key', 128);
            $table->string('name', 255);
            $table->string('url_pattern', 512)->nullable();
            $table->json('path_inputs')->nullable();
            $table->boolean('is_authentication')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::connection($conn)->create('sa_sites', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 128)->unique();
            $table->string('name', 191);
            $table->string('domain', 255)->nullable();
            $table->string('base_url_mode', 32)->default('fixed');
            $table->string('base_url', 255)->nullable();
            $table->unsignedBigInteger('cms_type_id')->nullable();
            $table->string('cms_type', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::connection($conn)->create('sa_paths', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('key', 128);
            $table->string('name', 255);
            $table->string('url_pattern', 512)->nullable();
            $table->json('path_inputs')->nullable();
            $table->boolean('is_authentication')->default(false);
            $table->unsignedBigInteger('cms_template_id')->nullable();
            $table->boolean('is_override')->default(false);
            $table->unsignedInteger('template_version')->default(1);
            $table->timestamps();
        });

        Schema::connection($conn)->create('sa_action_tags', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('path_id')->nullable();
            $table->string('site_key', 128)->nullable();
            $table->string('path_key', 128)->nullable();
            $table->string('action_key', 128)->nullable();
            $table->string('tag_key', 191)->unique();
            $table->string('platform', 128)->nullable();
            $table->string('target', 128)->nullable();
            $table->string('action', 128)->default('');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        Schema::connection($conn)->create('sa_packages', function (Blueprint $table): void {
            $table->id();
            $table->string('package_key', 191)->unique();
            $table->string('kind', 32);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_authentication')->default(false);
            $table->string('sharing_mode', 32)->default('site_specific');
            $table->string('global_cms_type', 64)->nullable();
            $table->unsignedBigInteger('global_cms_template_id')->nullable();
            $table->string('global_cms_path_key', 128)->nullable();
            $table->string('global_scope_key', 191)->nullable();
            $table->timestamps();
        });

        Schema::connection($conn)->create('sa_package_releases', function (Blueprint $table): void {
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

        Schema::connection($conn)->create('sa_action_tag_scripts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('action_tag_id')->nullable();
            $table->string('action_tag_key', 191);
            $table->unsignedBigInteger('package_id')->nullable();
            $table->unsignedBigInteger('release_id')->nullable();
            $table->string('script_id', 191)->nullable();
            $table->string('script_version', 64)->nullable();
            $table->timestamps();
        });

        Schema::connection($conn)->create('sa_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('account_uuid')->unique();
            $table->string('installation_id', 128);
            $table->string('platform', 64);
            $table->string('platform_account_id', 191)->nullable();
            $table->string('username', 191)->nullable();
            $table->string('site_url', 255)->nullable();
            $table->boolean('verified')->default(true);
            $table->string('status', 32)->default('active');
            $table->timestamps();
        });

        Schema::connection($conn)->create('sa_agents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('agent_uuid')->unique();
            $table->string('installation_id', 128);
            $table->string('name', 191)->nullable();
            $table->string('availability', 32)->default('available');
            $table->unsignedInteger('active_jobs')->default(0);
            $table->unsignedInteger('max_concurrency')->default(2);
            $table->string('agent_kind', 32)->default('runner');
            $table->string('runtime_version', 64)->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->json('capabilities')->nullable();
            $table->timestamps();
        });

        Schema::connection($conn)->create('sa_browser_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('session_uuid')->unique();
            $table->string('installation_id', 128)->nullable();
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('platform', 64)->nullable();
            $table->string('ready_status', 32)->default('ready');
            $table->string('session_state', 32)->default('verified');
            $table->boolean('dispatch_enabled')->default(true);
            $table->json('capabilities')->nullable();
            $table->unsignedBigInteger('locked_job_id')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('lock_expires_at')->nullable();
            $table->timestamp('last_task_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::connection($conn)->create('sa_session_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('browser_session_id');
            $table->unsignedBigInteger('account_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::connection($conn)->create('sa_account_device_affinities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id')->unique();
            $table->unsignedBigInteger('agent_id');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('affinity_until')->nullable();
            $table->timestamps();
        });

        Schema::connection($conn)->create('sa_tasks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('task_uuid')->unique();
            $table->timestamps();
        });

        Schema::connection($conn)->create('sa_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('job_uuid')->unique();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('browser_session_id')->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('package_key', 191);
            $table->string('package_version', 64);
            $table->string('content_digest', 64)->nullable();
            $table->string('status', 32);
            $table->string('idempotency_key', 191)->nullable();
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('dispatch_source', 32)->nullable();
            $table->timestamps();
        });

        Schema::connection($conn)->create('sa_job_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('attempt_uuid')->unique();
            $table->unsignedBigInteger('job_id');
            $table->unsignedInteger('attempt_no');
            $table->string('status', 32);
            $table->json('result')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::connection($conn)->create('sa_job_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('job_id');
            $table->unsignedBigInteger('attempt_id')->nullable();
            $table->string('event_type', 64);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
        });

        Schema::connection($conn)->create('sa_account_execution_leases', function (Blueprint $table): void {
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

        Schema::connection($conn)->create('sa_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('artifact_uuid')->unique();
            $table->unsignedBigInteger('job_id');
            $table->unsignedBigInteger('attempt_id')->nullable();
            $table->string('kind', 32);
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum_sha256', 64)->nullable();
            $table->string('storage_path', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function runMigration(string $path): void
    {
        $migration = require $path;
        $migration->up();
    }

    private function makeResponseFactory(): ResponseFactory
    {
        return new class implements ResponseFactory {
            public function make($content = '', $status = 200, array $headers = [])
            {
                return new JsonResponse($content, $status, $headers);
            }

            public function noContent($status = 204, array $headers = [])
            {
                return new JsonResponse(null, $status, $headers);
            }

            public function view($view, $data = [], $status = 200, array $headers = [])
            {
                return new JsonResponse($data, $status, $headers);
            }

            public function json($data = [], $status = 200, array $headers = [], $options = 0)
            {
                return new JsonResponse($data, $status, $headers, $options);
            }

            public function jsonp($callback, $data = [], $status = 200, array $headers = [], $options = 0)
            {
                return new JsonResponse($data, $status, $headers, $options);
            }

            public function stream($callback, $status = 200, array $headers = [])
            {
                return new JsonResponse(null, $status, $headers);
            }

            public function streamJson($data, $status = 200, $headers = [], $encodingOptions = 15)
            {
                return new JsonResponse($data, $status, $headers);
            }

            public function streamDownload($callback, $name = null, array $headers = [], $disposition = 'attachment')
            {
                return new JsonResponse(null, 200, $headers);
            }

            public function download($file, $name = null, array $headers = [], $disposition = 'attachment')
            {
                return new JsonResponse(null, 200, $headers);
            }

            public function file($file, array $headers = [])
            {
                return new JsonResponse(null, 200, $headers);
            }

            public function redirectTo($path, $status = 302, $headers = [], $secure = null)
            {
                return new JsonResponse(null, $status, $headers);
            }

            public function redirectToRoute($route, $parameters = [], $status = 302, $headers = [])
            {
                return new JsonResponse(null, $status, $headers);
            }

            public function redirectToAction($action, $parameters = [], $status = 302, $headers = [])
            {
                return new JsonResponse(null, $status, $headers);
            }

            public function redirectGuest($path, $status = 302, $headers = [], $secure = null)
            {
                return new JsonResponse(null, $status, $headers);
            }

            public function redirectToIntended($default = '/', $status = 302, $headers = [], $secure = null)
            {
                return new JsonResponse(null, $status, $headers);
            }
        };
    }
}
