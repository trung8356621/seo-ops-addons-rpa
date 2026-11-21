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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\StaffCampaignController;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTagScript;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignAccountAssignment;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContent;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContentAssignment;
use Omnichannel\Addons\SocialAutomation\Models\SaCmsPathTemplate;
use Omnichannel\Addons\SocialAutomation\Models\SaCmsType;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaJobAttempt;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Orchestration\CampaignOrchestrator;
use Omnichannel\Addons\SocialAutomation\Services\CampaignService;
use Omnichannel\Addons\SocialAutomation\Services\ScriptResolutionService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use PHPUnit\Framework\TestCase;

final class CampaignV2DraftPersistenceTest extends TestCase
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
                return 'inst-v2-test';
            }
        });
        $app->instance(ScriptResolutionService::class, new ScriptResolutionService);
        $app->instance(ResponseFactory::class, $this->makeResponseFactory());

        $this->createBaseSchema();
        $this->runMigration($this->root.'/database/migrations/2026_11_02_150000_create_sa_campaign_tables.php');
        $this->runMigration($this->root.'/database/migrations/2026_11_20_100000_add_campaign_shortcode_v2_columns.php');
        $this->runMigration($this->root.'/database/migrations/2026_11_21_140000_create_sa_campaign_v2_tables.php');

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

    public function test_01_02_03_04_10_11_15_create_v2_multi_tag_draft_across_sites_and_cms_with_round_trip(): void
    {
        $fx = $this->seedMultiSiteFixture();

        // Zero accounts exist in sa_accounts — Campaign = resource-independent planning artifact.
        self::assertSame(0, SaAccount::query()->count());

        $payload = [
            'plan_version' => 2,
            'name' => 'Multi-Site V2 Launch',
            'run_mode' => 'live',
            'execution_mode' => 'one_account_one_job',
            'action_tags' => [
                [
                    'action_tag_key' => 'wp_site_a.create_post',
                    'release_id' => $fx['rel_wp_site_a']->id,
                    'script_id' => $fx['pkg_wp_site_a']->package_key,
                    'script_version' => '1.0.0',
                    'resolution_source' => 'site_specific',
                    'shortcode_mapping' => [
                        'entries' => [
                            ['shortcode' => 'title', 'variable' => 'wp_title'],
                            ['shortcode' => 'body', 'variable' => 'wp_body'],
                        ],
                    ],
                ],
                [
                    'action_tag_key' => 'wp_site_b.create_post',
                    'release_id' => $fx['rel_wp_global']->id,
                    'script_id' => $fx['pkg_wp_global']->package_key,
                    'script_version' => '2.1.0',
                    'resolution_source' => 'cms_global',
                    'shortcode_mapping' => [
                        'entries' => [
                            ['shortcode' => 'title', 'variable' => 'global_headline'],
                            ['shortcode' => 'body', 'variable' => 'global_content'],
                        ],
                    ],
                ],
                [
                    'action_tag_key' => 'social_x.publish',
                    'release_id' => $fx['rel_social_x']->id,
                    'shortcode_mapping' => [
                        'entries' => [
                            ['shortcode' => 'title', 'variable' => 'tweet_text'],
                        ],
                    ],
                ],
            ],
            'contents' => [
                [
                    'external_ref' => 'art-shared-001',
                    'raw_source' => '[title]Launch Day[/title][body]Full article body[/body]',
                    'assignments' => [
                        ['action_tag_key' => 'wp_site_a.create_post'],
                        ['action_tag_key' => 'wp_site_b.create_post'],
                        ['action_tag_key' => 'social_x.publish'],
                    ],
                ],
                [
                    'external_ref' => 'art-x-only-002',
                    'raw_source' => '[title]Quick Tweet[/title]',
                    'assignments' => [
                        [
                            'action_tag_key' => 'social_x.publish',
                            'input' => ['tweet_text' => 'Override direct tweet'],
                        ],
                    ],
                ],
            ],
        ];

        $resp = $this->controller->store($this->makeRequest($payload));
        self::assertSame(201, $resp->getStatusCode());
        $created = $resp->getData(true);
        self::assertTrue($created['ok']);

        $data = $created['data'];
        self::assertSame(2, $data['plan_version']);
        self::assertSame('draft', $data['status']);
        self::assertNull($data['account_id']);
        self::assertCount(3, $data['action_tags']);
        self::assertCount(2, $data['contents']);

        // Verify resolution sources + immutable release pins
        self::assertSame('site_specific', $data['action_tags'][0]['resolution_source']);
        self::assertSame($fx['rel_wp_site_a']->id, $data['action_tags'][0]['release_id']);
        self::assertSame('wordpress', $data['action_tags'][0]['cms_type']);

        self::assertSame('cms_global', $data['action_tags'][1]['resolution_source']);
        self::assertSame($fx['rel_wp_global']->id, $data['action_tags'][1]['release_id']);
        self::assertSame('wordpress', $data['action_tags'][1]['cms_type']);

        self::assertSame('site_specific', $data['action_tags'][2]['resolution_source']);
        self::assertSame($fx['rel_social_x']->id, $data['action_tags'][2]['release_id']);
        self::assertNull($data['action_tags'][2]['cms_type']);

        // Verify no Content record duplication: 2 contents, 4 assignments
        self::assertSame(2, SaCampaignContent::query()->count());
        self::assertSame(4, SaCampaignContentAssignment::query()->count());
        self::assertSame(3, SaCampaignActionTag::query()->count());

        // Verify shared content produced distinct typed inputs per Tag's Script schema
        $sharedAssigns = $data['contents'][0]['assignments'];
        self::assertCount(3, $sharedAssigns);
        self::assertSame(['wp_title' => 'Launch Day', 'wp_body' => 'Full article body'], $sharedAssigns[0]['input']);
        self::assertSame(['global_headline' => 'Launch Day', 'global_content' => 'Full article body'], $sharedAssigns[1]['input']);
        self::assertSame(['tweet_text' => 'Launch Day'], $sharedAssigns[2]['input']);

        // Direct input override on second content
        self::assertSame(['tweet_text' => 'Override direct tweet'], $data['contents'][1]['assignments'][0]['input']);

        // Phase 6.11: GET detail round-trip correctness
        $showResp = $this->controller->show($data['id']);
        self::assertSame(200, $showResp->getStatusCode());
        $shown = $showResp->getData(true);
        self::assertTrue($shown['ok']);
        self::assertSame($data['id'], $shown['data']['id']);
        self::assertSame($data['action_tags'], $shown['data']['action_tags']);
        self::assertSame($data['contents'], $shown['data']['contents']);

        // Phase 6.15: No Jobs or Claims or Account Assignments created
        self::assertSame(0, SaJob::query()->count());
        self::assertSame(0, SaJobAttempt::query()->count());
        self::assertSame(0, SaCampaignAccountAssignment::query()->count());
    }

    public function test_04_immutable_release_pin_survives_newer_published_release(): void
    {
        $fx = $this->seedMultiSiteFixture();

        $resp = $this->controller->store($this->makeRequest([
            'plan_version' => 2,
            'name' => 'Pinned Release Campaign',
            'action_tags' => [
                ['action_tag_key' => 'wp_site_a.create_post'],
            ],
            'contents' => [],
        ]));
        self::assertSame(201, $resp->getStatusCode());
        $campaignUuid = $resp->getData(true)['data']['id'];
        $pinnedReleaseId = $resp->getData(true)['data']['action_tags'][0]['release_id'];
        self::assertSame($fx['rel_wp_site_a']->id, $pinnedReleaseId);

        // Publish a newer release v1.1.0 for the same package
        $newRelease = SaPackageRelease::query()->create([
            'package_id' => $fx['pkg_wp_site_a']->id,
            'version' => '1.1.0',
            'content_digest' => hash('sha256', 'v1.1.0'),
            'schema_version' => 1,
            'manifest' => ['variables' => [['key' => 'wp_title', 'type' => 'string', 'required' => true]]],
            'status' => 'published',
            'published_at' => now()->addMinute(),
        ]);
        SaActionTagScript::query()
            ->where('action_tag_id', $fx['tag_wp_site_a']->id)
            ->update(['release_id' => $newRelease->id, 'script_version' => '1.1.0']);

        // Saved Campaign plan remains pinned to original release
        $detail = $this->controller->show($campaignUuid)->getData(true)['data'];
        self::assertSame($fx['rel_wp_site_a']->id, $detail['action_tags'][0]['release_id']);
        self::assertSame('1.0.0', $detail['action_tags'][0]['script_version']);
    }

    public function test_05_rejects_stale_or_forged_release_id_with_conflict(): void
    {
        $fx = $this->seedMultiSiteFixture();

        $resp = $this->controller->store($this->makeRequest([
            'plan_version' => 2,
            'name' => 'Stale Release Test',
            'action_tags' => [
                [
                    'action_tag_key' => 'wp_site_a.create_post',
                    'release_id' => 999999,
                ],
            ],
            'contents' => [],
        ]));

        self::assertSame(409, $resp->getStatusCode());
        $body = $resp->getData(true);
        self::assertFalse($body['ok']);
        self::assertContains('release_pin_mismatch', $body['error_codes']);
        self::assertArrayHasKey('action_tags.0.release_id', $body['errors']);
        self::assertSame(0, SaCampaign::query()->count());
    }

    public function test_06_rejects_missing_and_disabled_action_tags(): void
    {
        $fx = $this->seedMultiSiteFixture();
        $fx['tag_wp_site_a']->update(['is_enabled' => false]);

        $disabledResp = $this->controller->store($this->makeRequest([
            'plan_version' => 2,
            'name' => 'Disabled Tag',
            'action_tags' => [['action_tag_key' => 'wp_site_a.create_post']],
        ]));
        self::assertSame(422, $disabledResp->getStatusCode());
        self::assertContains('action_tag_disabled', $disabledResp->getData(true)['error_codes']);
        self::assertArrayHasKey('action_tags.0.action_tag_key', $disabledResp->getData(true)['errors']);

        $missingResp = $this->controller->store($this->makeRequest([
            'plan_version' => 2,
            'name' => 'Missing Tag',
            'action_tags' => [['action_tag_key' => 'nonexistent.tag']],
        ]));
        self::assertSame(422, $missingResp->getStatusCode());
        self::assertContains('action_tag_not_found', $missingResp->getData(true)['error_codes']);
        self::assertSame(0, SaCampaign::query()->count());
    }

    public function test_07_09_12_incompatible_mapping_and_transaction_rollback(): void
    {
        $this->seedMultiSiteFixture();

        // Unknown variable in shortcode_mapping -> 422, zero partial rows persisted
        $resp = $this->controller->store($this->makeRequest([
            'plan_version' => 2,
            'name' => 'Bad Mapping Rollback',
            'action_tags' => [
                [
                    'action_tag_key' => 'wp_site_a.create_post',
                    'shortcode_mapping' => [
                        'entries' => [
                            ['shortcode' => 'title', 'variable' => 'nonexistent_var'],
                        ],
                    ],
                ],
            ],
            'contents' => [
                [
                    'external_ref' => 'art-1',
                    'raw_source' => '[title]Hi[/title]',
                    'assignments' => [['action_tag_key' => 'wp_site_a.create_post']],
                ],
            ],
        ]));

        self::assertSame(422, $resp->getStatusCode());
        self::assertArrayHasKey('action_tags.0.shortcode_mapping', $resp->getData(true)['errors']);
        self::assertSame(0, SaCampaign::query()->count());
        self::assertSame(0, SaCampaignActionTag::query()->count());
        self::assertSame(0, SaCampaignContent::query()->count());
        self::assertSame(0, SaCampaignContentAssignment::query()->count());

        // Second content fails -> entire transaction rolls back (first content & campaign not saved)
        $resp2 = $this->controller->store($this->makeRequest([
            'plan_version' => 2,
            'name' => 'Content Assignment Failure Rollback',
            'action_tags' => [
                [
                    'action_tag_key' => 'wp_site_a.create_post',
                    'shortcode_mapping' => [
                        'entries' => [
                            ['shortcode' => 'title', 'variable' => 'wp_title'],
                            ['shortcode' => 'body', 'variable' => 'wp_body'],
                        ],
                    ],
                ],
            ],
            'contents' => [
                [
                    'external_ref' => 'art-ok',
                    'raw_source' => '[title]Good[/title][body]Body[/body]',
                    'assignments' => [['action_tag_key' => 'wp_site_a.create_post']],
                ],
                [
                    'external_ref' => 'art-bad',
                    'raw_source' => '[title]Missing body[/title]',
                    'assignments' => [['action_tag_key' => 'wp_site_a.create_post']],
                ],
            ],
        ]));

        self::assertSame(422, $resp2->getStatusCode());
        self::assertArrayHasKey('contents.1.assignments.0', $resp2->getData(true)['errors']);
        self::assertSame(0, SaCampaign::query()->count());
        self::assertSame(0, SaCampaignContent::query()->count());
    }

    public function test_13_legacy_campaign_create_and_detail_remain_compatible(): void
    {
        $fx = $this->seedMultiSiteFixture();

        $resp = $this->controller->store($this->makeRequest([
            'name' => 'Legacy Single Script Campaign',
            'platform' => 'wp_site_a',
            'execution_mode' => 'one_account_one_job',
            'run_mode' => 'live',
            'script_id' => $fx['pkg_wp_site_a']->package_key,
            'script_version' => '1.0.0',
            'contents' => [
                [
                    'external_ref' => 'legacy-1',
                    'input' => ['wp_title' => 'Hello', 'wp_body' => 'World'],
                ],
            ],
        ]));

        self::assertSame(201, $resp->getStatusCode());
        $body = $resp->getData(true);
        self::assertTrue($body['ok']);
        self::assertSame(1, $body['data']['plan_version']);
        self::assertSame($fx['pkg_wp_site_a']->package_key, $body['data']['script_id']);

        $detail = $this->controller->show($body['data']['id'])->getData(true)['data'];
        self::assertSame(1, $detail['plan_version']);
        self::assertCount(1, $detail['contents']);
        self::assertSame(['wp_title' => 'Hello', 'wp_body' => 'World'], $detail['contents'][0]['input']);
    }

    public function test_14_legacy_run_and_orchestrator_cannot_execute_v2_drafts(): void
    {
        $this->seedMultiSiteFixture();

        $resp = $this->controller->store($this->makeRequest([
            'plan_version' => 2,
            'name' => 'Execution Boundary Draft',
            'action_tags' => [['action_tag_key' => 'wp_site_a.create_post']],
        ]));
        self::assertSame(201, $resp->getStatusCode());
        $uuid = $resp->getData(true)['data']['id'];

        $startResp = $this->controller->start($uuid);
        self::assertSame(422, $startResp->getStatusCode());
        self::assertSame(['v2_campaign_execution_unsupported'], $startResp->getData(true)['errors']);

        $resumeResp = $this->controller->resume($uuid);
        self::assertSame(422, $resumeResp->getStatusCode());
        self::assertSame(['v2_campaign_execution_unsupported'], $resumeResp->getData(true)['errors']);

        $campaign = SaCampaign::query()->where('campaign_uuid', $uuid)->firstOrFail();
        self::assertSame('draft', $campaign->status);

        $dispatchRes = $this->orchestrator->dispatchCampaign($campaign);
        self::assertFalse($dispatchRes['ok']);
        self::assertSame(0, $dispatchRes['dispatched']);
        self::assertSame(['v2_campaign_execution_unsupported'], $dispatchRes['errors']);
        self::assertSame(0, SaJob::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function seedMultiSiteFixture(): array
    {
        $wpCms = SaCmsType::query()->create([
            'key' => 'wordpress',
            'name' => 'WordPress',
            'is_enabled' => true,
        ]);
        $wpTpl = SaCmsPathTemplate::query()->create([
            'cms_type_id' => $wpCms->id,
            'key' => 'create_post',
            'name' => 'Create Post',
            'url_pattern' => '/wp-admin/post-new.php',
            'is_authentication' => false,
        ]);

        $siteA = SaSite::query()->create([
            'key' => 'wp_site_a',
            'name' => 'WP Site A',
            'base_url' => 'https://site-a.example.com',
            'cms_type' => 'wordpress',
        ]);
        $pathA = SaPath::query()->create([
            'site_id' => $siteA->id,
            'key' => 'create_post',
            'name' => 'Create Post',
            'url_pattern' => '/wp-admin/post-new.php',
            'cms_template_id' => $wpTpl->id,
            'is_authentication' => false,
        ]);
        $tagA = SaActionTag::query()->create([
            'site_id' => $siteA->id,
            'path_id' => $pathA->id,
            'site_key' => 'wp_site_a',
            'path_key' => 'create_post',
            'platform' => 'wp_site_a',
            'target' => 'create_post',
            'action' => '',
            'tag_key' => 'wp_site_a.create_post',
            'is_enabled' => true,
        ]);

        $pkgA = SaPackage::query()->create([
            'package_key' => 'wp_site_a.create_post',
            'kind' => 'script',
            'name' => 'WP Site A Script',
            'is_enabled' => true,
            'sharing_mode' => SaPackage::SHARING_SITE_SPECIFIC,
        ]);
        $relA = SaPackageRelease::query()->create([
            'package_id' => $pkgA->id,
            'version' => '1.0.0',
            'content_digest' => hash('sha256', 'wp-a-1.0.0'),
            'schema_version' => 1,
            'manifest' => [
                'action_tag_key' => 'wp_site_a.create_post',
                'variables' => [
                    ['key' => 'wp_title', 'type' => 'string', 'required' => true],
                    ['key' => 'wp_body', 'type' => 'text', 'required' => true],
                ],
            ],
            'status' => 'published',
            'published_at' => now(),
        ]);
        SaActionTagScript::query()->create([
            'action_tag_id' => $tagA->id,
            'action_tag_key' => $tagA->tag_key,
            'package_id' => $pkgA->id,
            'release_id' => $relA->id,
            'script_id' => $pkgA->package_key,
            'script_version' => '1.0.0',
        ]);

        // Site B (WordPress, no site-specific script -> resolves to CMS Global)
        $siteB = SaSite::query()->create([
            'key' => 'wp_site_b',
            'name' => 'WP Site B',
            'base_url' => 'https://site-b.example.com',
            'cms_type' => 'wordpress',
        ]);
        $pathB = SaPath::query()->create([
            'site_id' => $siteB->id,
            'key' => 'create_post',
            'name' => 'Create Post',
            'url_pattern' => '/wp-admin/post-new.php',
            'cms_template_id' => $wpTpl->id,
            'is_authentication' => false,
        ]);
        $tagB = SaActionTag::query()->create([
            'site_id' => $siteB->id,
            'path_id' => $pathB->id,
            'site_key' => 'wp_site_b',
            'path_key' => 'create_post',
            'platform' => 'wp_site_b',
            'target' => 'create_post',
            'action' => '',
            'tag_key' => 'wp_site_b.create_post',
            'is_enabled' => true,
        ]);

        $pkgGlobal = SaPackage::query()->create([
            'package_key' => 'wp_global.create_post',
            'kind' => 'script',
            'name' => 'WP Global Create Post',
            'is_enabled' => true,
            'sharing_mode' => SaPackage::SHARING_CMS_GLOBAL,
            'global_cms_type' => 'wordpress',
            'global_cms_template_id' => $wpTpl->id,
            'global_cms_path_key' => 'create_post',
            'global_scope_key' => 'wordpress:create_post',
        ]);
        $relGlobal = SaPackageRelease::query()->create([
            'package_id' => $pkgGlobal->id,
            'version' => '2.1.0',
            'content_digest' => hash('sha256', 'wp-global-2.1.0'),
            'schema_version' => 1,
            'manifest' => [
                'is_authentication' => false,
                'variables' => [
                    ['key' => 'global_headline', 'type' => 'string', 'required' => true],
                    ['key' => 'global_content', 'type' => 'text', 'required' => true],
                ],
            ],
            'status' => 'published',
            'published_at' => now(),
        ]);

        // Site X (non-CMS social platform)
        $siteX = SaSite::query()->create([
            'key' => 'social_x',
            'name' => 'Social X',
            'base_url' => 'https://x.com',
            'cms_type' => null,
        ]);
        $pathX = SaPath::query()->create([
            'site_id' => $siteX->id,
            'key' => 'publish',
            'name' => 'Publish',
            'url_pattern' => '/compose/post',
            'is_authentication' => false,
        ]);
        $tagX = SaActionTag::query()->create([
            'site_id' => $siteX->id,
            'path_id' => $pathX->id,
            'site_key' => 'social_x',
            'path_key' => 'publish',
            'platform' => 'social_x',
            'target' => 'publish',
            'action' => '',
            'tag_key' => 'social_x.publish',
            'is_enabled' => true,
        ]);
        $pkgX = SaPackage::query()->create([
            'package_key' => 'social_x.publish',
            'kind' => 'script',
            'name' => 'Social X Publish',
            'is_enabled' => true,
            'sharing_mode' => SaPackage::SHARING_SITE_SPECIFIC,
        ]);
        $relX = SaPackageRelease::query()->create([
            'package_id' => $pkgX->id,
            'version' => '1.0.0',
            'content_digest' => hash('sha256', 'x-1.0.0'),
            'schema_version' => 1,
            'manifest' => [
                'variables' => [
                    ['key' => 'tweet_text', 'type' => 'string', 'required' => true],
                ],
            ],
            'status' => 'published',
            'published_at' => now(),
        ]);
        SaActionTagScript::query()->create([
            'action_tag_id' => $tagX->id,
            'action_tag_key' => $tagX->tag_key,
            'package_id' => $pkgX->id,
            'release_id' => $relX->id,
            'script_id' => $pkgX->package_key,
            'script_version' => '1.0.0',
        ]);

        return [
            'tag_wp_site_a' => $tagA,
            'pkg_wp_site_a' => $pkgA,
            'rel_wp_site_a' => $relA,
            'tag_wp_site_b' => $tagB,
            'pkg_wp_global' => $pkgGlobal,
            'rel_wp_global' => $relGlobal,
            'tag_social_x' => $tagX,
            'pkg_social_x' => $pkgX,
            'rel_social_x' => $relX,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function makeRequest(array $payload): Request
    {
        return Request::create('/social-automation/api/campaigns', 'POST', $payload);
    }

    private function runMigration(string $path): void
    {
        $migration = require $path;
        $migration->up();
    }

    private function createBaseSchema(): void
    {
        $schema = Schema::connection(SocialAutomationServiceConfig::CONNECTION);

        $schema->create('sa_cms_types', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name', 128);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        $schema->create('sa_cms_path_templates', function (Blueprint $table): void {
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
            $table->unsignedBigInteger('cms_template_id')->nullable();
            $table->boolean('is_override')->default(false);
            $table->unsignedInteger('template_version')->default(1);
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
            $table->unsignedBigInteger('auth_script_id')->nullable();
            $table->timestamps();
        });

        $schema->create('sa_packages', function (Blueprint $table): void {
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

        $schema->create('sa_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('account_uuid')->unique();
            $table->string('installation_id', 128);
            $table->string('platform', 64);
            $table->timestamps();
        });

        $schema->create('sa_tasks', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        $schema->create('sa_jobs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->timestamps();
        });

        $schema->create('sa_job_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('job_id');
            $table->uuid('attempt_uuid')->unique();
            $table->unsignedInteger('attempt_no')->default(1);
            $table->string('error_code', 64)->nullable();
            $table->json('result')->nullable();
            $table->timestamp('finished_at')->nullable();
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
