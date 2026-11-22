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
use Omnichannel\Addons\SocialAutomation\Enums\ArticleStatus;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignContentStatus;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignStatus;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\StaffArticleController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\StaffCampaignArticleController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\StaffCampaignController;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTagScript;
use Omnichannel\Addons\SocialAutomation\Models\SaArticle;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContent;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContentAssignment;
use Omnichannel\Addons\SocialAutomation\Models\SaCmsType;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaJobAttempt;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Orchestration\CampaignOrchestrator;
use Omnichannel\Addons\SocialAutomation\Services\ArticleLibraryService;
use Omnichannel\Addons\SocialAutomation\Services\CampaignArticleAssignmentService;
use Omnichannel\Addons\SocialAutomation\Services\CampaignService;
use Omnichannel\Addons\SocialAutomation\Services\ScriptResolutionService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ArticleLibraryAndCampaignAssignmentTest extends TestCase
{
    private Capsule $capsule;

    private ?Container $previousApp = null;

    private string $root;

    private ArticleLibraryService $articles;

    private CampaignArticleAssignmentService $assignments;

    private CampaignService $campaigns;

    private StaffArticleController $articleController;

    private StaffCampaignArticleController $campaignArticleController;

    private StaffCampaignController $campaignController;

    private bool $denyAccess = false;

    private bool $denyManage = false;

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
        $app->instance('auth', new class {
            public function id(): int
            {
                return 42;
            }
        });

        $test = $this;
        $access = new class($test) extends SocialAutomationAccess {
            public function __construct(private readonly ArticleLibraryAndCampaignAssignmentTest $test) {}

            public function assertCanAccess(?User $user = null): void
            {
                if ($this->test->isAccessDenied()) {
                    throw new HttpException(403, 'Forbidden');
                }
            }

            public function assertCanManageCampaigns(?User $user = null): void
            {
                if ($this->test->isManageDenied()) {
                    throw new HttpException(403, 'Forbidden');
                }
            }
        };
        $app->instance(SocialAutomationAccess::class, $access);

        $installation = new class extends SocialAutomationInstallationResolver {
            public function __construct() {}

            public function resolveInstallationId(): string
            {
                return 'inst-article-test';
            }
        };
        $app->instance(SocialAutomationInstallationResolver::class, $installation);
        $app->instance(ScriptResolutionService::class, new ScriptResolutionService);
        $app->instance(ResponseFactory::class, $this->makeResponseFactory());

        $this->createBaseSchema();
        $this->runMigration($this->root.'/database/migrations/2026_11_02_150000_create_sa_campaign_tables.php');
        $this->runMigration($this->root.'/database/migrations/2026_11_20_100000_add_campaign_shortcode_v2_columns.php');
        $this->runMigration($this->root.'/database/migrations/2026_11_21_140000_create_sa_campaign_v2_tables.php');
        $this->runMigration($this->root.'/database/migrations/2026_11_23_000000_create_sa_article_library_tables.php');

        $this->articles = new ArticleLibraryService;
        $this->assignments = new CampaignArticleAssignmentService;
        $orchestrator = new CampaignOrchestrator;
        $this->campaigns = new CampaignService($orchestrator);
        $this->articleController = new StaffArticleController($this->articles, $access, $installation);
        $this->campaignArticleController = new StaffCampaignArticleController($this->assignments, $access, $installation);
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

    public function isAccessDenied(): bool
    {
        return $this->denyAccess;
    }

    public function isManageDenied(): bool
    {
        return $this->denyManage;
    }

    public function test_article_creation_editing_filtering_and_single_action_tag_validation(): void
    {
        $fx = $this->seedSitesAndTags();

        // Reject if campaign_id is passed
        $rejectCampaign = $this->articles->create([
            'title' => 'Invalid With Campaign',
            'campaign_id' => (string) Str::uuid(),
            'action_tag_key' => 'baloquatang.create_post',
            'input' => ['wp_title' => 'T', 'wp_body' => 'B'],
        ], 'inst-article-test', 42);
        self::assertFalse($rejectCampaign['ok']);
        self::assertContains('campaign_id_not_allowed', $rejectCampaign['errors']);

        // Reject multiple action_tags
        $rejectMulti = $this->articles->create([
            'title' => 'Invalid Multi Tag',
            'action_tags' => ['baloquatang.create_post', 'congtybalo.create_post'],
            'action_tag_key' => 'baloquatang.create_post',
            'input' => ['wp_title' => 'T', 'wp_body' => 'B'],
        ], 'inst-article-test', 42);
        self::assertFalse($rejectMulti['ok']);
        self::assertContains('single_action_tag_required', $rejectMulti['errors']);

        // Reject missing required Script variable for ready status
        $rejectMissingVar = $this->articles->create([
            'title' => 'Missing Body',
            'action_tag_key' => 'baloquatang.create_post',
            'status' => 'ready',
            'input' => ['wp_title' => 'Only Title'],
        ], 'inst-article-test', 42);
        self::assertFalse($rejectMissingVar['ok']);

        // Create independent Article without campaign_id
        $createdA = $this->articles->create([
            'title' => 'Balo Quatang SEO Post 1',
            'external_ref' => 'art-001',
            'action_tag_key' => 'baloquatang.create_post',
            'status' => 'ready',
            'input' => ['wp_title' => 'Initial Title', 'wp_body' => 'Initial Body'],
        ], 'inst-article-test', 42);
        self::assertTrue($createdA['ok'], json_encode($createdA));
        self::assertSame(0, SaCampaign::query()->count());
        self::assertSame('baloquatang.create_post', $createdA['data']['action_tag_key']);
        self::assertSame($fx['site_a']->id, $createdA['data']['site_id']);
        self::assertSame('baloquatang', $createdA['data']['site_key']);
        self::assertSame(ArticleStatus::Ready->value, $createdA['data']['status']);

        // Create second Article on Site B (draft)
        $createdB = $this->articles->create([
            'title' => 'Congtybalo Draft',
            'action_tag_id' => $fx['tag_b']->id,
            'status' => 'draft',
            'input' => ['wp_title' => 'Draft Title'],
        ], 'inst-article-test', 42);
        self::assertTrue($createdB['ok']);
        self::assertSame('congtybalo', $createdB['data']['site_key']);

        // Edit Article without Campaign
        $updatedA = $this->articles->update($createdA['data']['id'], 'inst-article-test', [
            'title' => 'Balo Quatang SEO Post 1 (Edited)',
            'input' => ['wp_title' => 'Edited Title', 'wp_body' => 'Edited Body'],
        ]);
        self::assertTrue($updatedA['ok']);
        self::assertSame('Edited Title', $updatedA['data']['input']['wp_title']);

        // Filter by site_key, action_tag_key, status
        $bySite = $this->articles->list('inst-article-test', ['site_key' => 'baloquatang']);
        self::assertCount(1, $bySite);
        self::assertSame($createdA['data']['id'], $bySite[0]['id']);

        $byTag = $this->articles->list('inst-article-test', ['action_tag_id' => $fx['tag_b']->id]);
        self::assertCount(1, $byTag);
        self::assertSame($createdB['data']['id'], $byTag[0]['id']);

        $byStatus = $this->articles->list('inst-article-test', ['status' => 'draft']);
        self::assertCount(1, $byStatus);
        self::assertSame($createdB['data']['id'], $byStatus[0]['id']);
    }

    public function test_multiple_campaigns_reuse_one_article_with_independent_statuses_and_immutable_snapshots(): void
    {
        $fx = $this->seedSitesAndTags();

        $artRes = $this->articles->create([
            'title' => 'Reusable Balo Article',
            'external_ref' => 'reuse-1',
            'action_tag_key' => 'baloquatang.create_post',
            'input' => ['wp_title' => 'V1 Title', 'wp_body' => 'V1 Body'],
        ], 'inst-article-test', 42);
        self::assertTrue($artRes['ok']);
        $articleUuid = $artRes['data']['id'];

        // Create two independent V2 Campaign Drafts targeting baloquatang.create_post
        $camp1 = $this->createV2Draft('Campaign Batch 1', $fx['tag_a'], $fx['rel_a1'], $fx['pkg_a']);
        $camp2 = $this->createV2Draft('Campaign Batch 2', $fx['tag_a'], $fx['rel_a1'], $fx['pkg_a']);

        // Assign the SAME Article to both Campaigns
        $assign1 = $this->assignments->assignArticles($camp1['id'], 'inst-article-test', ['article_ids' => [$articleUuid]]);
        $assign2 = $this->assignments->assignArticles($camp2['id'], 'inst-article-test', ['article_ids' => [$articleUuid]]);
        self::assertTrue($assign1['ok']);
        self::assertTrue($assign2['ok']);

        // Reject duplicate assignment of same Article in Campaign 1
        $dup = $this->assignments->assignArticles($camp1['id'], 'inst-article-test', ['article_ids' => [$articleUuid]]);
        self::assertFalse($dup['ok']);
        self::assertContains('duplicate_article_assignment', $dup['errors']);

        // Publish a NEWER Script Release v2.0.0 AFTER Campaign 1 Draft was created
        $relA2 = SaPackageRelease::query()->create([
            'package_id' => $fx['pkg_a']->id,
            'version' => '2.0.0',
            'status' => 'published',
            'manifest' => $fx['rel_a1']->manifest,
            'content_digest' => sha1('rel-a2'),
            'execution_digest' => sha1('exec-a2'),
            'published_at' => now(),
        ]);

        // Start Campaign 1: snapshots Article V1 and keeps pinned Release 1.0.0 (not 2.0.0)
        $start1 = $this->campaigns->start($camp1['id'], 'inst-article-test');
        self::assertTrue($start1['ok']);
        self::assertSame(CampaignStatus::Running->value, $start1['data']['status']);

        $c1Model = SaCampaign::query()->where('campaign_uuid', $camp1['id'])->firstOrFail();
        $cc1 = SaCampaignContent::query()->where('campaign_id', $c1Model->id)->firstOrFail();
        $ca1 = SaCampaignContentAssignment::query()->where('campaign_content_id', $cc1->id)->firstOrFail();
        self::assertNotNull($cc1->snapshot_at);
        self::assertSame('V1 Title', $cc1->input['wp_title']);
        self::assertSame('V1 Title', $ca1->input['wp_title']);
        self::assertSame($fx['rel_a1']->id, (int) $ca1->release_id);
        self::assertSame('1.0.0', $ca1->script_version);
        self::assertNotSame($relA2->id, (int) $ca1->release_id);

        // Edit the source Article AFTER Campaign 1 started
        $editAfterStart = $this->articles->update($articleUuid, 'inst-article-test', [
            'title' => 'Reusable Balo Article (V2 Updated)',
            'input' => ['wp_title' => 'V2 Title', 'wp_body' => 'V2 Body'],
        ]);
        self::assertTrue($editAfterStart['ok']);

        // Campaign 1 MUST remain immutable with V1 Title / V1 Body!
        $cc1->refresh();
        $ca1->refresh();
        self::assertSame('V1 Title', $cc1->input['wp_title']);
        self::assertSame('V1 Title', $ca1->input['wp_title']);
        self::assertSame('V1 Title', $cc1->snapshot_payload['input']['wp_title']);

        // Campaign 2 (still Draft) picks up V2 Title when it starts!
        $start2 = $this->campaigns->start($camp2['id'], 'inst-article-test');
        self::assertTrue($start2['ok']);
        $c2Model = SaCampaign::query()->where('campaign_uuid', $camp2['id'])->firstOrFail();
        $cc2 = SaCampaignContent::query()->where('campaign_id', $c2Model->id)->firstOrFail();
        self::assertSame('V2 Title', $cc2->input['wp_title']);

        // Independent execution statuses per Campaign item
        $cc1->status = CampaignContentStatus::Success->value;
        $cc1->save();
        $cc2->refresh();
        self::assertNotSame(CampaignContentStatus::Success->value, $cc2->status);
        $articleModel = SaArticle::query()->where('article_uuid', $articleUuid)->firstOrFail();
        self::assertSame(ArticleStatus::Ready->value, $articleModel->status);

        // Pause & Resume Campaign 1 preserves pinned release & V1 snapshot; assigning/removing in Running/Paused is rejected
        $pause1 = $this->campaigns->pause($camp1['id'], 'inst-article-test');
        self::assertTrue($pause1['ok']);
        $removePaused = $this->assignments->removeAssignment($camp1['id'], 'inst-article-test', $articleUuid);
        self::assertFalse($removePaused['ok']);
        self::assertContains('campaign_not_draft', $removePaused['errors']);

        $resume1 = $this->campaigns->resume($camp1['id'], 'inst-article-test');
        self::assertTrue($resume1['ok']);
        $cc1->refresh();
        self::assertSame('V1 Title', $cc1->input['wp_title']);
        self::assertSame($fx['rel_a1']->id, (int) $ca1->fresh()->release_id);
    }

    public function test_invalid_site_tag_assignment_rejection_and_compatible_listing(): void
    {
        $fx = $this->seedSitesAndTags();

        $artA = $this->articles->create([
            'title' => 'Site A Article',
            'action_tag_key' => 'baloquatang.create_post',
            'input' => ['wp_title' => 'A', 'wp_body' => 'Body A'],
        ], 'inst-article-test', 42);
        $artB = $this->articles->create([
            'title' => 'Site B Article',
            'action_tag_key' => 'congtybalo.create_post',
            'input' => ['wp_title' => 'B', 'wp_body' => 'Body B'],
        ], 'inst-article-test', 42);
        self::assertTrue($artA['ok']);
        self::assertTrue($artB['ok']);

        // Campaign Draft only configures baloquatang.create_post
        $camp = $this->createV2Draft('Site A Only Campaign', $fx['tag_a'], $fx['rel_a1'], $fx['pkg_a']);

        // Compatible listing returns only artA
        $compat = $this->articles->listCompatible('inst-article-test', ['campaign_id' => $camp['id']]);
        self::assertTrue($compat['ok']);
        self::assertCount(1, $compat['data']);
        self::assertSame($artA['data']['id'], $compat['data'][0]['id']);

        // Assigning artB (congtybalo.create_post) is rejected
        $badAssign = $this->assignments->assignArticles($camp['id'], 'inst-article-test', [
            'article_ids' => [$artB['data']['id']],
        ]);
        self::assertFalse($badAssign['ok']);
        self::assertContains('incompatible_article_action_tag', $badAssign['errors']);
    }

    public function test_legacy_campaign_compatibility_preserves_contents_jobs_and_attempts(): void
    {
        $fx = $this->seedSitesAndTags();

        // Seed a legacy Campaign (no article_id, no snapshot_at) with historical SaJob & SaJobAttempt
        $legacyCampaign = SaCampaign::query()->create([
            'campaign_uuid' => '50704312-9635-4529-9b6f-f7285a9e0ca3',
            'installation_id' => 'inst-article-test',
            'name' => 'Legacy 15-Article Campaign',
            'platform' => 'baloquatang',
            'execution_mode' => 'one_account_one_job',
            'run_mode' => 'live',
            'status' => CampaignStatus::Paused->value,
            'plan_version' => 2,
            'script_id' => 'v2:multi-tag',
            'script_version' => '2',
            'started_at' => now()->subHour(),
            'paused_at' => now()->subMinutes(5),
        ]);

        $legacyContent = SaCampaignContent::query()->create([
            'content_uuid' => (string) Str::uuid(),
            'campaign_id' => $legacyCampaign->id,
            'article_id' => null,
            'article_uuid' => null,
            'external_ref' => 'legacy-item-1',
            'sort_order' => 1,
            'status' => CampaignContentStatus::FailedLocked->value,
            'input' => ['wp_title' => 'Legacy Inline Title', 'wp_body' => 'Legacy Inline Body'],
            'source_format' => 'shortcode',
        ]);

        $job = SaJob::query()->create([
            'job_uuid' => (string) Str::uuid(),
            'campaign_id' => $legacyCampaign->id,
            'campaign_content_id' => $legacyContent->id,
            'package_key' => $fx['pkg_a']->package_key,
            'package_version' => '1.0.0',
            'status' => 'failed',
            'idempotency_key' => 'legacy-idem-1',
        ]);
        SaJobAttempt::query()->create([
            'attempt_uuid' => (string) Str::uuid(),
            'job_id' => $job->id,
            'attempt_no' => 1,
            'status' => 'failed',
        ]);

        $detail = $this->campaigns->detail($legacyCampaign->campaign_uuid, 'inst-article-test');
        self::assertNotNull($detail);
        self::assertCount(1, $detail['contents']);
        self::assertNull($detail['contents'][0]['article_id']);
        self::assertNull($detail['contents'][0]['snapshot_at']);
        self::assertSame('Legacy Inline Title', $detail['contents'][0]['input']['wp_title']);

        $listed = $this->assignments->listAssignments($legacyCampaign->campaign_uuid, 'inst-article-test');
        self::assertTrue($listed['ok']);
        self::assertCount(1, $listed['data']);
        self::assertTrue($listed['data'][0]['is_legacy_inline']);
        self::assertSame(1, SaJob::query()->where('campaign_content_id', $legacyContent->id)->count());
        self::assertSame(1, SaJobAttempt::query()->where('job_id', $job->id)->count());
    }

    public function test_authorization_enforced_on_article_and_assignment_endpoints(): void
    {
        $this->denyAccess = true;
        $this->expectException(HttpException::class);
        $this->articleController->index(Request::create('/social-automation/api/articles', 'GET'));
    }

    public function test_authorization_enforced_on_campaign_article_assignment_write(): void
    {
        $this->denyManage = true;
        $this->expectException(HttpException::class);
        $this->campaignArticleController->store(
            Request::create('/social-automation/api/campaigns/'.Str::uuid().'/articles', 'POST', ['article_ids' => []]),
            (string) Str::uuid(),
        );
    }

    public function test_concurrent_campaign_assignment_and_start_preserve_consistency(): void
    {
        $fx = $this->seedSitesAndTags();

        $art1 = $this->articles->create([
            'title' => 'Concurrent Art 1',
            'action_tag_key' => 'baloquatang.create_post',
            'input' => ['wp_title' => 'C1', 'wp_body' => 'Body 1'],
        ], 'inst-article-test', 42);
        $art2 = $this->articles->create([
            'title' => 'Concurrent Art 2',
            'action_tag_key' => 'baloquatang.create_post',
            'input' => ['wp_title' => 'C2', 'wp_body' => 'Body 2'],
        ], 'inst-article-test', 42);
        self::assertTrue($art1['ok']);
        self::assertTrue($art2['ok']);

        $camp = $this->createV2Draft('Concurrent Test Campaign', $fx['tag_a'], $fx['rel_a1'], $fx['pkg_a']);

        // Assign first article, then start
        $a1 = $this->assignments->assignArticles($camp['id'], 'inst-article-test', ['article_ids' => [$art1['data']['id']]]);
        self::assertTrue($a1['ok']);

        $start = $this->campaigns->start($camp['id'], 'inst-article-test');
        self::assertTrue($start['ok']);

        // Any assignment arriving after Start committed MUST be rejected cleanly
        $a2 = $this->assignments->assignArticles($camp['id'], 'inst-article-test', ['article_ids' => [$art2['data']['id']]]);
        self::assertFalse($a2['ok']);
        self::assertContains('campaign_not_draft', $a2['errors']);

        // Only 1 content & 1 snapshotted assignment exist
        $cModel = SaCampaign::query()->where('campaign_uuid', $camp['id'])->firstOrFail();
        self::assertSame(1, SaCampaignContent::query()->where('campaign_id', $cModel->id)->count());
        self::assertNotNull(SaCampaignContent::query()->where('campaign_id', $cModel->id)->firstOrFail()->snapshot_at);
    }

    /**
     * @return array<string, mixed>
     */
    private function createV2Draft(string $name, SaActionTag $tag, SaPackageRelease $rel, SaPackage $pkg): array
    {
        $res = $this->campaignController->store(Request::create('/social-automation/api/campaigns', 'POST', [
            'plan_version' => 2,
            'name' => $name,
            'run_mode' => 'live',
            'execution_mode' => 'one_account_one_job',
            'action_tags' => [
                [
                    'action_tag_key' => $tag->tag_key,
                    'release_id' => $rel->id,
                    'script_id' => $pkg->package_key,
                    'script_version' => $rel->version,
                    'resolution_source' => 'site_specific',
                ],
            ],
        ]));
        self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());
        $decoded = json_decode((string) $res->getContent(), true);

        return $decoded['data'];
    }

    /**
     * @return array<string, mixed>
     */
    private function seedSitesAndTags(): array
    {
        $cms = SaCmsType::query()->create([
            'key' => 'wordpress',
            'name' => 'WordPress',
            'is_active' => true,
        ]);

        $siteA = SaSite::query()->create([
            'key' => 'baloquatang',
            'name' => 'Balo Quatang',
            'domain' => 'baloquatang.net',
            'cms_type_id' => $cms->id,
            'cms_type' => 'wordpress',
            'is_active' => true,
        ]);
        $siteB = SaSite::query()->create([
            'key' => 'congtybalo',
            'name' => 'Congty Balo',
            'domain' => 'congtybalo.com',
            'cms_type_id' => $cms->id,
            'cms_type' => 'wordpress',
            'is_active' => true,
        ]);

        $path = SaPath::query()->create([
            'site_id' => $siteA->id,
            'key' => 'create_post',
            'name' => 'Create Post',
            'url_pattern' => '/wp-admin/post-new.php',
            'is_authentication' => false,
        ]);

        $tagA = SaActionTag::query()->create([
            'site_id' => $siteA->id,
            'path_id' => $path->id,
            'site_key' => 'baloquatang',
            'path_key' => 'create_post',
            'tag_key' => 'baloquatang.create_post',
            'platform' => 'baloquatang',
            'target' => 'create_post',
            'action' => '',
            'is_enabled' => true,
        ]);
        $tagB = SaActionTag::query()->create([
            'site_id' => $siteB->id,
            'path_id' => $path->id,
            'site_key' => 'congtybalo',
            'path_key' => 'create_post',
            'tag_key' => 'congtybalo.create_post',
            'platform' => 'congtybalo',
            'target' => 'create_post',
            'action' => '',
            'is_enabled' => true,
        ]);

        $manifest = [
            'schema_version' => 1,
            'variables' => [
                ['key' => 'wp_title', 'type' => 'string', 'required' => true],
                ['key' => 'wp_body', 'type' => 'text', 'required' => true],
            ],
            'steps' => [['action' => 'type', 'target' => 'wp_title']],
        ];

        $pkgA = SaPackage::query()->create([
            'package_key' => 'baloquatang.create_post',
            'kind' => 'script',
            'name' => 'WP Balo Create Post',
            'is_enabled' => true,
            'sharing_mode' => SaPackage::SHARING_SITE_SPECIFIC,
        ]);
        $relA1 = SaPackageRelease::query()->create([
            'package_id' => $pkgA->id,
            'version' => '1.0.0',
            'status' => 'published',
            'schema_version' => 1,
            'manifest' => array_merge($manifest, ['action_tag_key' => 'baloquatang.create_post']),
            'content_digest' => sha1('rel-a1'),
            'execution_digest' => sha1('exec-a1'),
            'published_at' => now(),
        ]);
        SaActionTagScript::query()->create([
            'action_tag_id' => $tagA->id,
            'action_tag_key' => 'baloquatang.create_post',
            'package_id' => $pkgA->id,
            'release_id' => $relA1->id,
            'script_id' => $pkgA->package_key,
            'script_version' => '1.0.0',
        ]);

        $pkgB = SaPackage::query()->create([
            'package_key' => 'congtybalo.create_post',
            'kind' => 'script',
            'name' => 'WP Congtybalo Create Post',
            'is_enabled' => true,
            'sharing_mode' => SaPackage::SHARING_SITE_SPECIFIC,
        ]);
        $relB1 = SaPackageRelease::query()->create([
            'package_id' => $pkgB->id,
            'version' => '1.0.0',
            'status' => 'published',
            'schema_version' => 1,
            'manifest' => array_merge($manifest, ['action_tag_key' => 'congtybalo.create_post']),
            'content_digest' => sha1('rel-b1'),
            'execution_digest' => sha1('exec-b1'),
            'published_at' => now(),
        ]);
        SaActionTagScript::query()->create([
            'action_tag_id' => $tagB->id,
            'action_tag_key' => 'congtybalo.create_post',
            'package_id' => $pkgB->id,
            'release_id' => $relB1->id,
            'script_id' => $pkgB->package_key,
            'script_version' => '1.0.0',
        ]);

        return [
            'site_a' => $siteA,
            'site_b' => $siteB,
            'tag_a' => $tagA,
            'tag_b' => $tagB,
            'pkg_a' => $pkgA,
            'rel_a1' => $relA1,
            'pkg_b' => $pkgB,
            'rel_b1' => $relB1,
        ];
    }

    private function createBaseSchema(): void
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;

        Schema::connection($conn)->create('sa_cms_types', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name', 128);
            $table->boolean('is_active')->default(true);
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
            $table->string('username', 191)->nullable();
            $table->string('status', 32)->default('active');
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

        Schema::connection($conn)->create('sa_account_execution_leases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('job_id');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });

        Schema::connection($conn)->create('sa_agents', function (Blueprint $table): void {
            $table->id();
            $table->string('installation_id', 128);
            $table->string('status', 32)->default('online');
            $table->timestamps();
        });

        Schema::connection($conn)->create('sa_browser_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('account_id')->nullable();
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
