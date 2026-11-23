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
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ScriptRegistry\StaffScriptRegistryController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\StaffCampaignController;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTagScript;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaCmsPathTemplate;
use Omnichannel\Addons\SocialAutomation\Models\SaCmsType;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptVariantGroup;
use Omnichannel\Addons\SocialAutomation\Models\SaSessionAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Orchestration\CampaignOrchestrator;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptRegistryUiService;
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
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ScriptOperationalConfigTest extends TestCase
{
    private Capsule $capsule;

    private ?Container $previousApp = null;

    private string $root;

    private StaffScriptRegistryController $registryController;

    private ScriptResolutionService $resolver;

    private ScriptVariantGroupService $groups;

    private CampaignService $campaigns;

    private CampaignOrchestrator $orchestrator;

    private ActionScriptDistributionService $distribution;

    private ArticleLibraryService $articles;

    private CampaignArticleAssignmentService $assignments;

    private StaffCampaignController $campaignController;

    private bool $denyMutate = false;

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
        $app->instance('log', new class {
            public function __call(string $method, array $args): void {}
        });
        $app->instance('auth', new class {
            public function id(): int
            {
                return 42;
            }
        });

        $test = $this;
        $access = new class($test) extends SocialAutomationAccess {
            public function __construct(private readonly ScriptOperationalConfigTest $test) {}

            public function assertCanAccess(?User $user = null): void {}

            public function assertCanManageCampaigns(?User $user = null): void {}

            public function assertCanMutate(?User $user = null): void
            {
                if ($this->test->isMutateDenied()) {
                    throw new HttpException(403, 'Forbidden');
                }
            }
        };
        $app->instance(SocialAutomationAccess::class, $access);

        $installation = new class extends SocialAutomationInstallationResolver {
            public function __construct() {}

            public function resolveInstallationId(): string
            {
                return 'inst-opcfg-test';
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
        $this->runMigration($this->root.'/database/migrations/2026_11_23_020000_add_script_operational_config_columns.php');

        $this->resolver = new ScriptResolutionService;
        $this->groups = new ScriptVariantGroupService($this->resolver);
        $ui = new ScriptRegistryUiService;
        $this->registryController = new StaffScriptRegistryController($ui, $access, $this->resolver, $this->groups);
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

    public function isMutateDenied(): bool
    {
        return $this->denyMutate;
    }

    public function test_reproduces_and_resolves_unique_global_scope_constraint_across_variants(): void
    {
        $fx = $this->seedWordPressFixture();

        // Previously threw SQLSTATE[23000] 1062 Duplicate entry 'wordpress:create_post' for sa_packages_global_scope_unique.
        $res = $this->groups->create([
            'name' => 'WP Create Post',
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
        self::assertTrue($res['ok'], json_encode($res));
        self::assertCount(2, $res['data']['variants']);
        self::assertSame('wordpress:create_post', $fx['pkg_vi']->fresh()->global_scope_key);
        self::assertNull($fx['pkg_en']->fresh()->global_scope_key);
    }

    public function test_simplified_script_config_vi_primary_en_fallback_switch_primary_disable_and_duplicate_language(): void
    {
        $fx = $this->seedWordPressFixture();

        // 1. Configure VI as primary on wordpress:create_post (already cms_global owner) -> auto-creates internal group
        $r1 = $this->registryController->updateConfig(
            Request::create('/config', 'PATCH', [
                'language_label' => 'VI',
                'is_enabled' => true,
                'is_primary' => true,
            ]),
            $fx['pkg_vi']->package_key,
        );
        self::assertSame(200, $r1->getStatusCode(), (string) $r1->getContent());
        $d1 = json_decode((string) $r1->getContent(), true);
        self::assertSame('VI', $d1['config']['language_label']);
        self::assertTrue($d1['config']['is_primary']);
        self::assertCount(1, $d1['variant_group']['variants']);

        // 2. Configure EN as fallback variant without manual group creation
        $r2 = $this->registryController->updateConfig(
            Request::create('/config', 'PATCH', [
                'language_label' => 'EN',
                'is_enabled' => true,
                'is_primary' => false,
            ]),
            $fx['pkg_en']->package_key,
        );
        self::assertSame(200, $r2->getStatusCode(), (string) $r2->getContent());
        $d2 = json_decode((string) $r2->getContent(), true);
        self::assertSame('VI', $d2['variant_group']['variants'][0]['language_label']);
        self::assertSame('EN', $d2['variant_group']['variants'][1]['language_label']);

        // 3. Reject a second enabled Script with the same language_label ('VI')
        $dupLang = $this->registryController->updateConfig(
            Request::create('/config', 'PATCH', [
                'language_label' => 'VI',
                'is_enabled' => true,
                'is_primary' => false,
            ]),
            $fx['pkg_vi_old']->package_key,
        );
        self::assertSame(422, $dupLang->getStatusCode());
        self::assertSame('duplicate_language_label', json_decode((string) $dupLang->getContent(), true)['error']);

        // 4. Reject disabled script as primary
        $badDisable = $this->registryController->updateConfig(
            Request::create('/config', 'PATCH', [
                'language_label' => 'EN',
                'is_enabled' => false,
                'is_primary' => true,
            ]),
            $fx['pkg_en']->package_key,
        );
        self::assertSame(422, $badDisable->getStatusCode());

        // 5. Switch primary from VI to EN -> EN becomes priority 1, VI becomes priority 2, global_scope_key transfers cleanly
        $switchRes = $this->registryController->updateConfig(
            Request::create('/config', 'PATCH', [
                'language_label' => 'EN',
                'is_enabled' => true,
                'is_primary' => true,
            ]),
            $fx['pkg_en']->package_key,
        );
        self::assertSame(200, $switchRes->getStatusCode(), (string) $switchRes->getContent());
        $switchData = json_decode((string) $switchRes->getContent(), true);
        self::assertTrue($fx['pkg_en']->fresh()->is_primary);
        self::assertFalse($fx['pkg_vi']->fresh()->is_primary);
        self::assertSame('wordpress:create_post', $fx['pkg_en']->fresh()->global_scope_key);
        self::assertNull($fx['pkg_vi']->fresh()->global_scope_key);
        self::assertSame('EN', $switchData['variant_group']['variants'][0]['language_label']);
        self::assertSame('VI', $switchData['variant_group']['variants'][1]['language_label']);

        // 6. Start a Campaign -> pins [EN, VI]
        $campUuid = $this->createAndStartWordPressCampaign($fx['rel_en'], $fx['pkg_en']);
        $campModel = SaCampaign::query()->where('campaign_uuid', $campUuid)->firstOrFail();
        $planTag = SaCampaignActionTag::query()->where('campaign_id', $campModel->id)->firstOrFail();
        self::assertCount(2, $planTag->pinned_variants);
        self::assertSame('EN', $planTag->pinned_variants[0]['language_label']);
        self::assertSame('VI', $planTag->pinned_variants[1]['language_label']);

        // 7. Disable VI afterward -> disabled from new resolution, but existing started Campaign snapshot remains unchanged!
        $disableVi = $this->registryController->updateConfig(
            Request::create('/config', 'PATCH', [
                'is_enabled' => false,
            ]),
            $fx['pkg_vi']->package_key,
        );
        self::assertSame(200, $disableVi->getStatusCode());
        self::assertFalse($fx['pkg_vi']->fresh()->is_enabled);

        // New resolution only returns EN (VI is disabled)
        $resolvedAfterDisable = $this->resolver->resolveForTag($fx['tag_a']);
        self::assertTrue($resolvedAfterDisable['ok']);
        self::assertCount(1, $resolvedAfterDisable['variant_group']['variants']);
        self::assertSame('EN', $resolvedAfterDisable['variant_group']['variants'][0]['language_label']);

        // Started Campaign snapshot is untouched (still has EN + VI!)
        self::assertCount(2, $planTag->fresh()->pinned_variants);

        // 8. Claim + Pull on the started Campaign works for both pinned variants
        $claimed = $this->orchestrator->claimNext($fx['agent']->agent_uuid, [$fx['session']->session_uuid], null, 'inst-opcfg-test');
        self::assertTrue($claimed['ok'], json_encode($claimed));
        $claimData = $claimed['data'];
        self::assertCount(2, $claimData['script_variants']);
        self::assertSame('EN', $claimData['script_variants'][0]['language_label']);

        $pullEn = $this->distribution->pullForJob(
            'inst-opcfg-test',
            $claimData['job_id'],
            $claimData['attempt_id'],
            $claimData['lease_token'],
            $claimData['account_id'],
            $fx['pkg_en']->package_key,
            '1.0.0',
            $fx['rel_en']->id,
        );
        self::assertTrue($pullEn['ok']);
    }

    public function test_site_specific_isolation_and_authorization(): void
    {
        $fx = $this->seedWordPressFixture();

        // Site-specific script for site_b.create_post configured as site_action stays strictly isolated from site_a & cms_global
        $resSiteB = $this->registryController->updateConfig(
            Request::create('/config', 'PATCH', [
                'language_label' => 'VI',
                'is_enabled' => true,
                'is_primary' => true,
                'action_scope' => 'site_action',
            ]),
            $fx['pkg_site_b']->package_key,
        );
        self::assertSame(200, $resSiteB->getStatusCode(), (string) $resSiteB->getContent());
        $dataB = json_decode((string) $resSiteB->getContent(), true);
        self::assertSame('site_action', $dataB['variant_group']['scope_type']);
        self::assertSame('site_b.create_post', $dataB['variant_group']['action_tag_key']);
        self::assertSame(1, SaScriptVariantGroup::query()->count());

        // Authorization check
        $this->denyMutate = true;
        $this->expectException(HttpException::class);
        $this->registryController->updateConfig(
            Request::create('/config', 'PATCH', ['is_enabled' => false]),
            $fx['pkg_site_b']->package_key,
        );
    }

    public function test_old_vi_disabled_replaced_by_new_vi_primary_with_en_fallback_and_effective_sharing_roles(): void
    {
        $fx = $this->seedWordPressFixture();

        // 1. Configure old VI as primary and EN as fallback; start Campaign 1
        $this->registryController->updateConfig(
            Request::create('/config', 'PATCH', ['language_label' => 'VI', 'is_enabled' => true, 'is_primary' => true]),
            $fx['pkg_vi']->package_key,
        );
        $this->registryController->updateConfig(
            Request::create('/config', 'PATCH', ['language_label' => 'EN', 'is_enabled' => true, 'is_primary' => false]),
            $fx['pkg_en']->package_key,
        );

        // Verify effective_sharing_role on owner vs fallback variant vs standalone site_specific
        $ownerDetail = json_decode((string) $this->registryController->show($fx['pkg_vi']->package_key)->getContent(), true)['script'];
        $fallbackDetail = json_decode((string) $this->registryController->show($fx['pkg_en']->package_key)->getContent(), true)['script'];
        $standaloneDetail = json_decode((string) $this->registryController->show($fx['pkg_site_b']->package_key)->getContent(), true)['script'];

        self::assertSame('cms_global_owner', $ownerDetail['effective_sharing_role']);
        self::assertSame('wordpress:create_post', $ownerDetail['global_scope']['scope_key']);
        self::assertSame('wordpress:create_post', $ownerDetail['effective_global_scope']['scope_key']);

        self::assertSame('cms_global_variant', $fallbackDetail['effective_sharing_role']);
        self::assertNull($fallbackDetail['global_scope']); // Never pretends fallback owns DB global_scope_key
        self::assertSame('wordpress:create_post', $fallbackDetail['effective_global_scope']['scope_key']);

        self::assertSame('site_specific', $standaloneDetail['effective_sharing_role']);
        self::assertNull($standaloneDetail['global_scope']);
        self::assertNull($standaloneDetail['effective_global_scope']);

        $camp1Uuid = $this->createAndStartWordPressCampaign($fx['rel_vi'], $fx['pkg_vi']);
        $camp1 = SaCampaign::query()->where('campaign_uuid', $camp1Uuid)->firstOrFail();
        $planTag1 = SaCampaignActionTag::query()->where('campaign_id', $camp1->id)->firstOrFail();
        self::assertSame($fx['pkg_vi']->package_key, $planTag1->pinned_variants[0]['package_key']);

        // 2. Disable old VI (UI changed) -> excluded from active Variant Group, stays in Script Registry
        $disRes = $this->registryController->updateConfig(
            Request::create('/config', 'PATCH', ['is_enabled' => false]),
            $fx['pkg_vi']->package_key,
        );
        self::assertSame(200, $disRes->getStatusCode());
        self::assertFalse($fx['pkg_vi']->fresh()->is_enabled);

        // 3. Configure new VI as primary on the same Action scope (succeeds cleanly without 1062!)
        $newViRes = $this->registryController->updateConfig(
            Request::create('/config', 'PATCH', ['language_label' => 'VI', 'is_enabled' => true, 'is_primary' => true]),
            $fx['pkg_vi_old']->package_key,
        );
        self::assertSame(200, $newViRes->getStatusCode(), (string) $newViRes->getContent());
        $newViData = json_decode((string) $newViRes->getContent(), true);
        self::assertCount(2, $newViData['variant_group']['variants']);
        self::assertSame($fx['pkg_vi_old']->package_key, $newViData['variant_group']['variants'][0]['package_key']);
        self::assertSame('VI', $newViData['variant_group']['variants'][0]['language_label']);
        self::assertSame($fx['pkg_en']->package_key, $newViData['variant_group']['variants'][1]['package_key']);
        self::assertSame('EN', $newViData['variant_group']['variants'][1]['language_label']);

        // Old disabled VI was NOT re-enabled!
        self::assertFalse($fx['pkg_vi']->fresh()->is_enabled);

        // Attempting to re-enable old VI while new VI is enabled with 'VI' MUST reject with duplicate_language_label
        $reEnableOld = $this->registryController->updateConfig(
            Request::create('/config', 'PATCH', ['language_label' => 'VI', 'is_enabled' => true]),
            $fx['pkg_vi']->package_key,
        );
        self::assertSame(422, $reEnableOld->getStatusCode());
        self::assertSame('duplicate_language_label', json_decode((string) $reEnableOld->getContent(), true)['error']);

        // New resolution picks new VI (priority 1) + EN (priority 2), excluding old disabled VI
        $resolved = $this->resolver->resolveForTag($fx['tag_a']);
        self::assertTrue($resolved['ok']);
        self::assertSame($fx['pkg_vi_old']->package_key, $resolved['package']->package_key);
        self::assertCount(2, $resolved['variant_group']['variants']);

        // Existing started Campaign 1 snapshot remains immutable (still pinned to old VI!)
        self::assertSame($fx['pkg_vi']->package_key, $planTag1->fresh()->pinned_variants[0]['package_key']);
    }

    private function createAndStartWordPressCampaign(SaPackageRelease $rel, SaPackage $pkg): string
    {
        $art = $this->articles->create([
            'title' => 'Op Config Article',
            'action_tag_key' => 'baloquatang.create_post',
            'input' => ['wp_title' => 'T', 'wp_body' => 'B'],
        ], 'inst-opcfg-test', 42);
        self::assertTrue($art['ok'], json_encode($art));

        $campRes = $this->campaignController->store(Request::create('/social-automation/api/campaigns', 'POST', [
            'plan_version' => 2,
            'name' => 'Op Config Campaign',
            'run_mode' => 'live',
            'execution_mode' => 'one_account_one_job',
            'action_tags' => [
                [
                    'action_tag_key' => 'baloquatang.create_post',
                    'release_id' => $rel->id,
                    'script_id' => $pkg->package_key,
                    'script_version' => '1.0.0',
                    'resolution_source' => 'cms_global',
                ],
            ],
        ]));
        self::assertSame(201, $campRes->getStatusCode(), (string) $campRes->getContent());
        $campUuid = json_decode((string) $campRes->getContent(), true)['data']['id'];

        $assigned = $this->assignments->assignArticles($campUuid, 'inst-opcfg-test', ['article_ids' => [$art['data']['id']]]);
        self::assertTrue($assigned['ok']);

        $started = $this->campaigns->start($campUuid, 'inst-opcfg-test');
        self::assertTrue($started['ok'], json_encode($started));

        return $campUuid;
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

        $siteA = SaSite::query()->create([
            'key' => 'baloquatang',
            'name' => 'Balo Quatang',
            'domain' => 'baloquatang.net',
            'base_url_mode' => 'fixed',
            'cms_type_id' => $cms->id,
            'cms_type' => 'wordpress',
            'is_active' => true,
        ]);
        $pathA = SaPath::query()->create([
            'site_id' => $siteA->id,
            'key' => 'create_post',
            'name' => 'Create Post',
            'url_pattern' => '/wp-admin/post-new.php',
            'is_authentication' => false,
            'cms_template_id' => $tpl->id,
        ]);
        $tagA = SaActionTag::query()->create([
            'site_id' => $siteA->id,
            'path_id' => $pathA->id,
            'site_key' => 'baloquatang',
            'path_key' => 'create_post',
            'tag_key' => 'baloquatang.create_post',
            'platform' => 'baloquatang',
            'target' => 'create_post',
            'action' => '',
            'is_enabled' => true,
        ]);

        $siteB = SaSite::query()->create([
            'key' => 'site_b',
            'name' => 'Site B',
            'domain' => 'siteb.net',
            'base_url_mode' => 'fixed',
            'cms_type_id' => $cms->id,
            'cms_type' => 'wordpress',
            'is_active' => true,
        ]);
        $pathB = SaPath::query()->create([
            'site_id' => $siteB->id,
            'key' => 'create_post',
            'name' => 'Create Post',
            'url_pattern' => '/wp-admin/post-new.php',
            'is_authentication' => false,
            'cms_template_id' => $tpl->id,
        ]);
        $tagB = SaActionTag::query()->create([
            'site_id' => $siteB->id,
            'path_id' => $pathB->id,
            'site_key' => 'site_b',
            'path_key' => 'create_post',
            'tag_key' => 'site_b.create_post',
            'platform' => 'site_b',
            'target' => 'create_post',
            'action' => '',
            'is_enabled' => true,
        ]);

        $manifest = [
            'schema_version' => 1,
            'action_tag_key' => 'baloquatang.create_post',
            'is_authentication' => false,
            'variables' => [
                ['key' => 'wp_title', 'type' => 'string', 'required' => true],
                ['key' => 'wp_body', 'type' => 'text', 'required' => true],
            ],
            'steps' => [['action' => 'type', 'target' => 'wp_title']],
        ];

        $pkgVi = SaPackage::query()->create([
            'package_key' => 'wp-create-post-vi',
            'kind' => 'script',
            'name' => 'create post wp',
            'is_enabled' => true,
            'sharing_mode' => SaPackage::SHARING_CMS_GLOBAL,
            'global_cms_type' => 'wordpress',
            'global_cms_template_id' => $tpl->id,
            'global_cms_path_key' => 'create_post',
            'global_scope_key' => 'wordpress:create_post',
        ]);
        $relVi = SaPackageRelease::query()->create([
            'package_id' => $pkgVi->id,
            'version' => '1.0.0',
            'status' => 'published',
            'schema_version' => 1,
            'manifest' => $manifest,
            'content_digest' => sha1('vi-1'),
            'execution_digest' => sha1('exec-vi-1'),
            'published_at' => now(),
        ]);

        $pkgEn = SaPackage::query()->create([
            'package_key' => 'wp-create-post-en',
            'kind' => 'script',
            'name' => 'create post wp en',
            'is_enabled' => true,
            'sharing_mode' => SaPackage::SHARING_SITE_SPECIFIC,
        ]);
        $relEn = SaPackageRelease::query()->create([
            'package_id' => $pkgEn->id,
            'version' => '1.0.0',
            'status' => 'published',
            'schema_version' => 1,
            'manifest' => $manifest,
            'content_digest' => sha1('en-1'),
            'execution_digest' => sha1('exec-en-1'),
            'published_at' => now(),
        ]);

        $pkgViOld = SaPackage::query()->create([
            'package_key' => 'wp-create-post-vi-old',
            'kind' => 'script',
            'name' => 'create post wp old',
            'is_enabled' => true,
            'sharing_mode' => SaPackage::SHARING_SITE_SPECIFIC,
        ]);
        SaPackageRelease::query()->create([
            'package_id' => $pkgViOld->id,
            'version' => '1.0.0',
            'status' => 'published',
            'schema_version' => 1,
            'manifest' => $manifest,
            'content_digest' => sha1('vi-old'),
            'execution_digest' => sha1('exec-vi-old'),
            'published_at' => now(),
        ]);

        $pkgSiteB = SaPackage::query()->create([
            'package_key' => 'site-b-create-post',
            'kind' => 'script',
            'name' => 'Site B Create Post',
            'is_enabled' => true,
            'sharing_mode' => SaPackage::SHARING_SITE_SPECIFIC,
        ]);
        $relSiteB = SaPackageRelease::query()->create([
            'package_id' => $pkgSiteB->id,
            'version' => '1.0.0',
            'status' => 'published',
            'schema_version' => 1,
            'manifest' => array_merge($manifest, ['action_tag_key' => 'site_b.create_post']),
            'content_digest' => sha1('site-b'),
            'execution_digest' => sha1('exec-site-b'),
            'published_at' => now(),
        ]);
        SaActionTagScript::query()->create([
            'action_tag_id' => $tagB->id,
            'action_tag_key' => 'site_b.create_post',
            'package_id' => $pkgSiteB->id,
            'release_id' => $relSiteB->id,
            'script_id' => $pkgSiteB->package_key,
            'script_version' => '1.0.0',
        ]);

        $agent = SaAgent::query()->create([
            'agent_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-opcfg-test',
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
            'installation_id' => 'inst-opcfg-test',
            'platform' => 'baloquatang',
            'platform_account_id' => 'wp_admin',
            'username' => 'wp_admin',
            'verified' => true,
            'status' => 'active',
        ]);
        $session = SaBrowserSession::query()->create([
            'session_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-opcfg-test',
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

        return [
            'tpl' => $tpl,
            'tag_a' => $tagA,
            'tag_b' => $tagB,
            'pkg_vi' => $pkgVi,
            'rel_vi' => $relVi,
            'pkg_en' => $pkgEn,
            'rel_en' => $relEn,
            'pkg_vi_old' => $pkgViOld,
            'pkg_site_b' => $pkgSiteB,
            'agent' => $agent,
            'session' => $session,
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
            $table->string('global_scope_key', 191)->nullable()->unique('sa_packages_global_scope_unique');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->string('sync_source', 64)->nullable();
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
            $table->string('sync_source', 64)->nullable();
            $table->timestamps();
        });

        Schema::connection($conn)->create('sa_package_dependencies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('release_id');
            $table->string('dependency_key', 191)->nullable();
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
