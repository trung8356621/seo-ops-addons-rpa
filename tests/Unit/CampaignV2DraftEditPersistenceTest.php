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
use Omnichannel\Addons\SocialAutomation\Http\Controllers\StaffCampaignController;
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

final class CampaignV2DraftEditPersistenceTest extends TestCase
{
    private Capsule $capsule;

    private ?Container $previousApp = null;

    private string $root;

    private StaffCampaignController $controller;

    private CampaignService $campaigns;

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
                return 'inst-v2-edit-test';
            }
        });
        $app->instance(ScriptResolutionService::class, new ScriptResolutionService);
        $app->instance(ResponseFactory::class, $this->makeResponseFactory());

        $this->createBaseSchema();
        $this->runMigration($this->root.'/database/migrations/2026_11_02_150000_create_sa_campaign_tables.php');
        $this->runMigration($this->root.'/database/migrations/2026_11_20_100000_add_campaign_shortcode_v2_columns.php');
        $this->runMigration($this->root.'/database/migrations/2026_11_21_140000_create_sa_campaign_v2_tables.php');

        $this->campaigns = new CampaignService(new CampaignOrchestrator);
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

    public function test_01_02_03_04_05_14_update_v2_draft_reconciles_tags_contents_assignments_and_preserves_stable_ids(): void
    {
        $fx = $this->seedFixture();

        $createResp = $this->controller->store($this->makeRequest('POST', [
            'plan_version' => 2,
            'name' => 'Initial V2 Draft',
            'action_tags' => [
                [
                    'action_tag_key' => 'wp_a.create_post',
                    'shortcode_mapping' => [
                        'entries' => [
                            ['shortcode' => 'title', 'variable' => 'title'],
                            ['shortcode' => 'body', 'variable' => 'body'],
                        ],
                    ],
                ],
                [
                    'action_tag_key' => 'social_x.publish',
                    'shortcode_mapping' => [
                        'entries' => [
                            ['shortcode' => 'title', 'variable' => 'tweet_text'],
                        ],
                    ],
                ],
            ],
            'contents' => [
                [
                    'external_ref' => 'art-1',
                    'raw_source' => '[title]First[/title][body]Body One[/body]',
                    'assignments' => [
                        ['action_tag_key' => 'wp_a.create_post'],
                        ['action_tag_key' => 'social_x.publish'],
                    ],
                ],
                [
                    'external_ref' => 'art-2',
                    'raw_source' => '[title]Second[/title][body]To Remove[/body]',
                    'assignments' => [
                        ['action_tag_key' => 'wp_a.create_post'],
                    ],
                ],
            ],
        ]));
        self::assertSame(201, $createResp->getStatusCode());
        $initial = $createResp->getData(true)['data'];
        self::assertSame(1, $initial['revision']);

        $uuid = $initial['id'];
        $keptTagUuid = $initial['action_tags'][0]['id']; // wp_a.create_post
        $keptContentUuid = $initial['contents'][0]['id']; // art-1
        $keptAssignUuid = $initial['contents'][0]['assignments'][0]['id']; // art-1 -> wp_a.create_post

        // Update: rename, keep wp_a.create_post, remove social_x.publish, add wp_b.create_post (CMS Global),
        // edit art-1 text + add assignment to wp_b.create_post, remove art-2, add art-3.
        $updateResp = $this->controller->update($this->makeRequest('PUT', [
            'plan_version' => 2,
            'revision' => 1,
            'name' => 'Updated V2 Draft Name',
            'action_tags' => [
                [
                    'id' => $keptTagUuid,
                    'action_tag_key' => 'wp_a.create_post',
                    'release_id' => $fx['rel_wp_a']->id,
                    'shortcode_mapping' => [
                        'entries' => [
                            ['shortcode' => 'title', 'variable' => 'title'],
                            ['shortcode' => 'body', 'variable' => 'body'],
                        ],
                    ],
                ],
                [
                    'action_tag_key' => 'wp_b.create_post',
                    'shortcode_mapping' => [
                        'entries' => [
                            ['shortcode' => 'title', 'variable' => 'global_title'],
                            ['shortcode' => 'body', 'variable' => 'global_body'],
                        ],
                    ],
                ],
            ],
            'contents' => [
                [
                    'id' => $keptContentUuid,
                    'external_ref' => 'art-1',
                    'raw_source' => '[title]Updated First[/title][body]Updated Body[/body]',
                    'assignments' => [
                        ['id' => $keptAssignUuid, 'action_tag_key' => 'wp_a.create_post'],
                        ['action_tag_key' => 'wp_b.create_post'],
                    ],
                ],
                [
                    'external_ref' => 'art-3',
                    'raw_source' => '[title]Brand New[/title][body]New Body[/body]',
                    'assignments' => [
                        ['action_tag_key' => 'wp_b.create_post'],
                    ],
                ],
            ],
        ]), $uuid);

        self::assertSame(200, $updateResp->getStatusCode());
        $updated = $updateResp->getData(true)['data'];

        self::assertSame('Updated V2 Draft Name', $updated['name']);
        self::assertSame(2, $updated['revision']);
        self::assertNull($updated['account_id']);
        self::assertCount(2, $updated['action_tags']);
        self::assertCount(2, $updated['contents']);

        // Stable IDs preserved for unchanged records
        self::assertSame($keptTagUuid, $updated['action_tags'][0]['id']);
        self::assertSame($keptContentUuid, $updated['contents'][0]['id']);
        self::assertSame($keptAssignUuid, $updated['contents'][0]['assignments'][0]['id']);

        // New tag resolved via CMS Global
        self::assertSame('wp_b.create_post', $updated['action_tags'][1]['action_tag_key']);
        self::assertSame('cms_global', $updated['action_tags'][1]['resolution_source']);
        self::assertSame($fx['rel_wp_global']->id, $updated['action_tags'][1]['release_id']);

        // Updated content & assignments
        self::assertSame(['title' => 'Updated First', 'body' => 'Updated Body'], $updated['contents'][0]['assignments'][0]['input']);
        self::assertSame(['global_title' => 'Updated First', 'global_body' => 'Updated Body'], $updated['contents'][0]['assignments'][1]['input']);
        self::assertSame(['global_title' => 'Brand New', 'global_body' => 'New Body'], $updated['contents'][1]['assignments'][0]['input']);

        // Removed tag/content/assignment cleaned up in DB
        self::assertSame(2, SaCampaignActionTag::query()->count());
        self::assertSame(2, SaCampaignContent::query()->count());
        self::assertSame(3, SaCampaignContentAssignment::query()->count());

        // GET round-trip matches
        $shown = $this->controller->show($uuid)->getData(true)['data'];
        self::assertSame($updated['action_tags'], $shown['action_tags']);
        self::assertSame($updated['contents'], $shown['contents']);

        // No Jobs or Claims created
        self::assertSame(0, SaJob::query()->count());
        self::assertSame(0, SaJobAttempt::query()->count());
        self::assertSame(0, SaCampaignAccountAssignment::query()->count());
    }

    public function test_06_07_pinned_release_preserved_and_stale_or_forged_release_rejected(): void
    {
        $fx = $this->seedFixture();

        $createResp = $this->controller->store($this->makeRequest('POST', [
            'plan_version' => 2,
            'name' => 'Pin Safety Draft',
            'action_tags' => [
                ['action_tag_key' => 'wp_a.create_post'],
            ],
            'contents' => [],
        ]));
        $initial = $createResp->getData(true)['data'];
        $uuid = $initial['id'];
        $tagUuid = $initial['action_tags'][0]['id'];

        // Publish a newer release v1.1.0 for wp_a
        $newerRel = SaPackageRelease::query()->create([
            'package_id' => $fx['pkg_wp_a']->id,
            'version' => '1.1.0',
            'content_digest' => hash('sha256', 'wp-a-1.1.0'),
            'schema_version' => 1,
            'manifest' => ['variables' => [['key' => 'title', 'type' => 'string', 'required' => true]]],
            'status' => 'published',
            'published_at' => now()->addMinute(),
        ]);
        SaActionTagScript::query()
            ->where('action_tag_id', $fx['tag_wp_a']->id)
            ->update(['release_id' => $newerRel->id, 'script_version' => '1.1.0']);

        // Updating Draft without changing release_id preserves original pin (NOT upgraded to v1.1.0)
        $okResp = $this->controller->update($this->makeRequest('PUT', [
            'plan_version' => 2,
            'name' => 'Pin Preserved',
            'action_tags' => [
                ['id' => $tagUuid, 'action_tag_key' => 'wp_a.create_post', 'release_id' => $fx['rel_wp_a']->id],
            ],
            'contents' => [],
        ]), $uuid);
        self::assertSame(200, $okResp->getStatusCode());
        self::assertSame($fx['rel_wp_a']->id, $okResp->getData(true)['data']['action_tags'][0]['release_id']);
        self::assertSame('1.0.0', $okResp->getData(true)['data']['action_tags'][0]['script_version']);

        // Attempting to pass forged/upgraded release_id on existing tag -> 409 release_pin_mismatch
        $forgedResp = $this->controller->update($this->makeRequest('PUT', [
            'plan_version' => 2,
            'name' => 'Forged Pin',
            'action_tags' => [
                ['id' => $tagUuid, 'action_tag_key' => 'wp_a.create_post', 'release_id' => $newerRel->id],
            ],
            'contents' => [],
        ]), $uuid);
        self::assertSame(409, $forgedResp->getStatusCode());
        self::assertContains('release_pin_mismatch', $forgedResp->getData(true)['error_codes']);
    }

    public function test_08_09_rejects_cross_campaign_ids_and_duplicate_assignments(): void
    {
        $this->seedFixture();

        $campA = $this->controller->store($this->makeRequest('POST', [
            'plan_version' => 2,
            'name' => 'Campaign A',
            'action_tags' => [['action_tag_key' => 'wp_a.create_post', 'shortcode_mapping' => ['entries' => [['shortcode' => 't', 'variable' => 'title'], ['shortcode' => 'b', 'variable' => 'body']]]]],
            'contents' => [['external_ref' => 'a-1', 'raw_source' => '[t]A[/t][b]B[/b]', 'assignments' => [['action_tag_key' => 'wp_a.create_post']]]],
        ]))->getData(true)['data'];

        $campB = $this->controller->store($this->makeRequest('POST', [
            'plan_version' => 2,
            'name' => 'Campaign B',
            'action_tags' => [['action_tag_key' => 'wp_a.create_post']],
            'contents' => [],
        ]))->getData(true)['data'];

        // Cross-campaign tag ID
        $crossTagResp = $this->controller->update($this->makeRequest('PUT', [
            'plan_version' => 2,
            'name' => 'Cross Tag',
            'action_tags' => [['id' => $campA['action_tags'][0]['id'], 'action_tag_key' => 'wp_a.create_post']],
            'contents' => [],
        ]), $campB['id']);
        self::assertSame(422, $crossTagResp->getStatusCode());
        self::assertContains('cross_campaign_tag_reference', $crossTagResp->getData(true)['error_codes']);

        // Cross-campaign content ID
        $crossContentResp = $this->controller->update($this->makeRequest('PUT', [
            'plan_version' => 2,
            'name' => 'Cross Content',
            'action_tags' => [['id' => $campB['action_tags'][0]['id'], 'action_tag_key' => 'wp_a.create_post', 'shortcode_mapping' => ['entries' => [['shortcode' => 't', 'variable' => 'title'], ['shortcode' => 'b', 'variable' => 'body']]]]],
            'contents' => [['id' => $campA['contents'][0]['id'], 'raw_source' => '[t]X[/t][b]Y[/b]', 'assignments' => [['action_tag_key' => 'wp_a.create_post']]]],
        ]), $campB['id']);
        self::assertSame(422, $crossContentResp->getStatusCode());
        self::assertContains('cross_campaign_content_reference', $crossContentResp->getData(true)['error_codes']);

        // Duplicate assignment on same content
        $dupAssignResp = $this->controller->update($this->makeRequest('PUT', [
            'plan_version' => 2,
            'name' => 'Dup Assign',
            'action_tags' => [['id' => $campB['action_tags'][0]['id'], 'action_tag_key' => 'wp_a.create_post', 'shortcode_mapping' => ['entries' => [['shortcode' => 't', 'variable' => 'title'], ['shortcode' => 'b', 'variable' => 'body']]]]],
            'contents' => [['raw_source' => '[t]X[/t][b]Y[/b]', 'assignments' => [['action_tag_key' => 'wp_a.create_post'], ['action_tag_key' => 'wp_a.create_post']]]],
        ]), $campB['id']);
        self::assertSame(422, $dupAssignResp->getStatusCode());
        self::assertContains('duplicate_content_tag_assignment', $dupAssignResp->getData(true)['error_codes']);
    }

    public function test_10_11_concurrent_update_returns_409_and_rollback_on_failure(): void
    {
        $this->seedFixture();

        $created = $this->controller->store($this->makeRequest('POST', [
            'plan_version' => 2,
            'name' => 'Concurrency Draft',
            'action_tags' => [['action_tag_key' => 'wp_a.create_post']],
            'contents' => [],
        ]))->getData(true)['data'];
        $uuid = $created['id'];
        $tagUuid = $created['action_tags'][0]['id'];

        // First update succeeds (revision 1 -> 2)
        $first = $this->controller->update($this->makeRequest('PUT', [
            'plan_version' => 2,
            'revision' => 1,
            'name' => 'Winner Edit',
            'action_tags' => [['id' => $tagUuid, 'action_tag_key' => 'wp_a.create_post']],
            'contents' => [],
        ]), $uuid);
        self::assertSame(200, $first->getStatusCode());
        self::assertSame(2, $first->getData(true)['data']['revision']);

        // Second concurrent update with stale revision 1 -> 409
        $stale = $this->controller->update($this->makeRequest('PUT', [
            'plan_version' => 2,
            'revision' => 1,
            'name' => 'Stale Edit',
            'action_tags' => [['id' => $tagUuid, 'action_tag_key' => 'wp_a.create_post']],
            'contents' => [],
        ]), $uuid);
        self::assertSame(409, $stale->getStatusCode());
        self::assertContains('campaign_revision_conflict', $stale->getData(true)['error_codes']);

        // Validation failure rolls back completely (name & revision unchanged)
        $badBodyResp = $this->controller->update($this->makeRequest('PUT', [
            'plan_version' => 2,
            'revision' => 2,
            'name' => 'Should Rollback',
            'action_tags' => [['id' => $tagUuid, 'action_tag_key' => 'wp_a.create_post', 'shortcode_mapping' => ['entries' => [['shortcode' => 'body', 'variable' => 'nonexistent']]]]],
            'contents' => [],
        ]), $uuid);
        self::assertSame(422, $badBodyResp->getStatusCode());

        $after = $this->controller->show($uuid)->getData(true)['data'];
        self::assertSame('Winner Edit', $after['name']);
        self::assertSame(2, $after['revision']);
    }

    public function test_12_13_rejects_non_draft_v2_and_partial_payload_and_preserves_v1_put(): void
    {
        $fx = $this->seedFixture();

        $v2 = $this->controller->store($this->makeRequest('POST', [
            'plan_version' => 2,
            'name' => 'Non-Draft Guard',
            'action_tags' => [['action_tag_key' => 'wp_a.create_post']],
            'contents' => [],
        ]))->getData(true)['data'];

        // Partial payload missing contents -> 422 (never silently deletes)
        $partialResp = $this->controller->update($this->makeRequest('PUT', [
            'plan_version' => 2,
            'name' => 'Partial',
            'action_tags' => [['action_tag_key' => 'wp_a.create_post']],
        ]), $v2['id']);
        self::assertSame(422, $partialResp->getStatusCode());
        self::assertContains('contents_required', $partialResp->getData(true)['error_codes']);

        // Non-draft V2 cannot be edited
        SaCampaign::query()->where('campaign_uuid', $v2['id'])->update(['status' => 'cancelled']);
        $nonDraftResp = $this->controller->update($this->makeRequest('PUT', [
            'plan_version' => 2,
            'name' => 'Edit Cancelled',
            'action_tags' => [['action_tag_key' => 'wp_a.create_post']],
            'contents' => [],
        ]), $v2['id']);
        self::assertSame(422, $nonDraftResp->getStatusCode());
        self::assertContains('v2_campaign_not_draft', $nonDraftResp->getData(true)['error_codes']);

        // V1 PUT compatibility preserved
        $v1 = $this->controller->store($this->makeRequest('POST', [
            'name' => 'Legacy V1',
            'platform' => 'wp_a',
            'execution_mode' => 'one_account_one_job',
            'run_mode' => 'live',
            'script_id' => $fx['pkg_wp_a']->package_key,
            'script_version' => '1.0.0',
            'contents' => [['external_ref' => 'l-1', 'input' => ['title' => 'T', 'body' => 'B']]],
        ]))->getData(true)['data'];

        $v1PutResp = $this->controller->update($this->makeRequest('PUT', [
            'name' => 'Legacy V1 Renamed',
        ]), $v1['id']);
        self::assertSame(200, $v1PutResp->getStatusCode());
        self::assertSame('Legacy V1 Renamed', $v1PutResp->getData(true)['data']['name']);
        self::assertSame(1, $v1PutResp->getData(true)['data']['plan_version']);
    }

    /**
     * @return array<string, mixed>
     */
    private function seedFixture(): array
    {
        $wpCms = SaCmsType::query()->create(['key' => 'wordpress', 'name' => 'WordPress', 'is_enabled' => true]);
        $wpTpl = SaCmsPathTemplate::query()->create([
            'cms_type_id' => $wpCms->id,
            'key' => 'create_post',
            'name' => 'Create Post',
            'url_pattern' => '/wp-admin/post-new.php',
            'is_authentication' => false,
        ]);

        $siteA = SaSite::query()->create(['key' => 'wp_a', 'name' => 'WP A', 'base_url' => 'https://a.example.com', 'cms_type' => 'wordpress']);
        $pathA = SaPath::query()->create(['site_id' => $siteA->id, 'key' => 'create_post', 'name' => 'Create Post', 'url_pattern' => '/wp-admin/post-new.php', 'cms_template_id' => $wpTpl->id, 'is_authentication' => false]);
        $tagA = SaActionTag::query()->create(['site_id' => $siteA->id, 'path_id' => $pathA->id, 'site_key' => 'wp_a', 'path_key' => 'create_post', 'platform' => 'wp_a', 'target' => 'create_post', 'action' => '', 'tag_key' => 'wp_a.create_post', 'is_enabled' => true]);
        $pkgA = SaPackage::query()->create(['package_key' => 'wp_a.create_post', 'kind' => 'script', 'name' => 'WP A Script', 'is_enabled' => true, 'sharing_mode' => SaPackage::SHARING_SITE_SPECIFIC]);
        $relA = SaPackageRelease::query()->create([
            'package_id' => $pkgA->id,
            'version' => '1.0.0',
            'content_digest' => hash('sha256', 'wp-a-1.0.0'),
            'schema_version' => 1,
            'manifest' => ['variables' => [['key' => 'title', 'type' => 'string', 'required' => true], ['key' => 'body', 'type' => 'text', 'required' => true]]],
            'status' => 'published',
            'published_at' => now(),
        ]);
        SaActionTagScript::query()->create(['action_tag_id' => $tagA->id, 'action_tag_key' => $tagA->tag_key, 'package_id' => $pkgA->id, 'release_id' => $relA->id, 'script_id' => $pkgA->package_key, 'script_version' => '1.0.0']);

        $siteB = SaSite::query()->create(['key' => 'wp_b', 'name' => 'WP B', 'base_url' => 'https://b.example.com', 'cms_type' => 'wordpress']);
        $pathB = SaPath::query()->create(['site_id' => $siteB->id, 'key' => 'create_post', 'name' => 'Create Post', 'url_pattern' => '/wp-admin/post-new.php', 'cms_template_id' => $wpTpl->id, 'is_authentication' => false]);
        $tagB = SaActionTag::query()->create(['site_id' => $siteB->id, 'path_id' => $pathB->id, 'site_key' => 'wp_b', 'path_key' => 'create_post', 'platform' => 'wp_b', 'target' => 'create_post', 'action' => '', 'tag_key' => 'wp_b.create_post', 'is_enabled' => true]);
        $pkgGlobal = SaPackage::query()->create(['package_key' => 'wp_global.create_post', 'kind' => 'script', 'name' => 'WP Global', 'is_enabled' => true, 'sharing_mode' => SaPackage::SHARING_CMS_GLOBAL, 'global_cms_type' => 'wordpress', 'global_cms_template_id' => $wpTpl->id, 'global_cms_path_key' => 'create_post', 'global_scope_key' => 'wordpress:create_post']);
        $relGlobal = SaPackageRelease::query()->create([
            'package_id' => $pkgGlobal->id,
            'version' => '2.0.0',
            'content_digest' => hash('sha256', 'wp-global-2.0.0'),
            'schema_version' => 1,
            'manifest' => ['is_authentication' => false, 'variables' => [['key' => 'global_title', 'type' => 'string', 'required' => true], ['key' => 'global_body', 'type' => 'text', 'required' => true]]],
            'status' => 'published',
            'published_at' => now(),
        ]);

        $siteX = SaSite::query()->create(['key' => 'social_x', 'name' => 'Social X', 'base_url' => 'https://x.com', 'cms_type' => null]);
        $pathX = SaPath::query()->create(['site_id' => $siteX->id, 'key' => 'publish', 'name' => 'Publish', 'url_pattern' => '/compose/post', 'is_authentication' => false]);
        $tagX = SaActionTag::query()->create(['site_id' => $siteX->id, 'path_id' => $pathX->id, 'site_key' => 'social_x', 'path_key' => 'publish', 'platform' => 'social_x', 'target' => 'publish', 'action' => '', 'tag_key' => 'social_x.publish', 'is_enabled' => true]);
        $pkgX = SaPackage::query()->create(['package_key' => 'social_x.publish', 'kind' => 'script', 'name' => 'Social X', 'is_enabled' => true, 'sharing_mode' => SaPackage::SHARING_SITE_SPECIFIC]);
        $relX = SaPackageRelease::query()->create([
            'package_id' => $pkgX->id,
            'version' => '1.0.0',
            'content_digest' => hash('sha256', 'x-1.0.0'),
            'schema_version' => 1,
            'manifest' => ['variables' => [['key' => 'tweet_text', 'type' => 'string', 'required' => true]]],
            'status' => 'published',
            'published_at' => now(),
        ]);
        SaActionTagScript::query()->create(['action_tag_id' => $tagX->id, 'action_tag_key' => $tagX->tag_key, 'package_id' => $pkgX->id, 'release_id' => $relX->id, 'script_id' => $pkgX->package_key, 'script_version' => '1.0.0']);

        return [
            'tag_wp_a' => $tagA,
            'pkg_wp_a' => $pkgA,
            'rel_wp_a' => $relA,
            'tag_wp_b' => $tagB,
            'rel_wp_global' => $relGlobal,
            'tag_social_x' => $tagX,
            'rel_social_x' => $relX,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function makeRequest(string $method, array $payload): Request
    {
        return Request::create('/social-automation/api/campaigns', $method, $payload);
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
