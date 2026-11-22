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
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTagScript;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaSessionAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Orchestration\CampaignOrchestrator;
use Omnichannel\Addons\SocialAutomation\Services\ActionEntryUrlResolver;
use Omnichannel\Addons\SocialAutomation\Services\ActionScriptDistributionService;
use Omnichannel\Addons\SocialAutomation\Services\CampaignService;
use Omnichannel\Addons\SocialAutomation\Services\ScriptResolutionService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use PHPUnit\Framework\TestCase;

final class ActionEntryUrlResolverTest extends TestCase
{
    private Capsule $capsule;

    private ?Container $previousApp = null;

    private string $root;

    private StaffCampaignController $campaignsController;

    private CampaignOrchestrator $orchestrator;

    private ActionEntryUrlResolver $resolver;

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
                return 'inst-url-test';
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
        $this->campaignsController = new StaffCampaignController(
            new CampaignService($this->orchestrator),
            $access,
            $inst,
        );
        $this->resolver = new ActionEntryUrlResolver;
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

    public function test_resolves_execution_context_in_claim_and_script_pull_for_wordpress_site(): void
    {
        $fx = $this->seedSiteAndClient('baloquatang', 'https://baloquatang.net', '/wp-admin/post-new.php');

        $createResp = $this->campaignsController->store(Request::create('/social-automation/api/campaigns', 'POST', [
            'plan_version' => 2,
            'name' => 'WP Entry URL Test',
            'action_tags' => [
                ['action_tag_key' => 'baloquatang.create_post', 'shortcode_mapping' => ['entries' => [['shortcode' => 't', 'variable' => 'title']]]],
            ],
            'contents' => [
                ['external_ref' => 'wp-1', 'raw_source' => '[t]Post Title[/t]', 'assignments' => [['action_tag_key' => 'baloquatang.create_post']]],
            ],
        ]));
        self::assertSame(201, $createResp->getStatusCode());
        $uuid = $createResp->getData(true)['data']['id'];

        $this->campaignsController->start($uuid);

        $claim = $this->orchestrator->claimNext((string) $fx['agent']->agent_uuid, [(string) $fx['session']->session_uuid], null, 'inst-url-test');
        self::assertTrue($claim['ok']);
        $payload = $claim['data'];

        self::assertIsArray($payload['execution_context']);
        self::assertSame('https://baloquatang.net', $payload['execution_context']['base_url']);
        self::assertSame('/wp-admin/post-new.php', $payload['execution_context']['url_pattern']);
        self::assertSame('https://baloquatang.net/wp-admin/post-new.php', $payload['execution_context']['resolved_url']);
        self::assertFalse($payload['execution_context']['is_authentication']);
        self::assertSame('baloquatang', $payload['execution_context']['site_key']);
        self::assertSame('baloquatang.create_post', $payload['execution_context']['action_tag_key']);

        // Script pull also includes identical execution_context
        $pull = (new ActionScriptDistributionService)->pullForJob(
            'inst-url-test',
            (string) $payload['job_id'],
            (string) $payload['attempt_id'],
            (string) $payload['lease_token'],
            (string) $payload['account_id'],
            (string) $payload['package_key'],
            (string) $payload['version'],
            (int) $payload['release_id'],
        );
        self::assertTrue($pull['ok']);
        self::assertSame($payload['execution_context'], $pull['release']['execution_context']);
    }

    public function test_relative_and_same_origin_absolute_and_account_fallback_urls(): void
    {
        // Relative without leading slash + fallback to account.site_url when site.base_url is null
        $fx = $this->seedSiteAndClient('congtybalo', null, 'wp-admin/post-new.php', accountSiteUrl: 'https://congtybalo.com');
        $job = new SaJob(['package_key' => 'congtybalo.create_post', 'payload' => ['action_tag_key' => 'congtybalo.create_post']]);

        $ctx = $this->resolver->resolveForJob($job, $fx['account']);
        self::assertNotNull($ctx);
        self::assertSame('https://congtybalo.com', $ctx['base_url']);
        self::assertSame('https://congtybalo.com/wp-admin/post-new.php', $ctx['resolved_url']);

        // Same-origin absolute URL
        $fx['path']->update(['url_pattern' => 'https://congtybalo.com/wp-admin/post-new.php?post_type=post']);
        $ctxAbs = $this->resolver->resolveForJob($job, $fx['account']);
        self::assertNotNull($ctxAbs);
        self::assertSame('https://congtybalo.com/wp-admin/post-new.php?post_type=post', $ctxAbs['resolved_url']);
    }

    public function test_rejects_unsafe_cross_origin_auth_path_mismatched_account_and_missing_url(): void
    {
        $fx = $this->seedSiteAndClient('maytuicanvas', 'https://maytuicanvas.com', '/wp-admin/post-new.php');
        $job = new SaJob(['package_key' => 'maytuicanvas.create_post', 'payload' => ['action_tag_key' => 'maytuicanvas.create_post']]);

        // Cross-origin absolute URL rejected
        $fx['path']->update(['url_pattern' => 'https://evil.example.com/wp-admin/post-new.php']);
        self::assertNull($this->resolver->resolveForJob($job, $fx['account']));

        // Unsafe schemes / protocol-relative / userinfo rejected
        foreach (['javascript:alert(1)', 'file:///etc/passwd', '//evil.com/x', 'https://user:pass@maytuicanvas.com/wp-admin/'] as $unsafe) {
            $fx['path']->update(['url_pattern' => $unsafe]);
            self::assertNull($this->resolver->resolveForJob($job, $fx['account']), "Failed rejecting {$unsafe}");
        }

        // Authentication path rejected for Campaign Action execution
        $fx['path']->update(['url_pattern' => '/wp-login.php', 'is_authentication' => true]);
        self::assertNull($this->resolver->resolveForJob($job, $fx['account']));

        // Multi-site isolation: account from different site rejected
        $fx['path']->update(['url_pattern' => '/wp-admin/post-new.php', 'is_authentication' => false]);
        $otherAccount = SaAccount::query()->create([
            'account_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-url-test',
            'platform' => 'baloquatang',
            'verified' => true,
        ]);
        self::assertNull($this->resolver->resolveForJob($job, $otherAccount));

        // Missing url_pattern returns null (never invents /wp-admin/post-new.php)
        $fx['path']->update(['url_pattern' => '']);
        self::assertNull($this->resolver->resolveForJob($job, $fx['account']));
    }

    /**
     * @return array{site: SaSite, path: SaPath, tag: SaActionTag, account: SaAccount, agent: SaAgent, session: SaBrowserSession}
     */
    private function seedSiteAndClient(string $siteKey, ?string $baseUrl, string $urlPattern, ?string $accountSiteUrl = null): array
    {
        $site = SaSite::query()->create(['key' => $siteKey, 'name' => strtoupper($siteKey), 'base_url' => $baseUrl, 'cms_type' => 'wordpress']);
        $path = SaPath::query()->create(['site_id' => $site->id, 'key' => 'create_post', 'name' => 'Create Post', 'url_pattern' => $urlPattern, 'is_authentication' => false]);
        $tag = SaActionTag::query()->create(['site_id' => $site->id, 'path_id' => $path->id, 'site_key' => $siteKey, 'path_key' => 'create_post', 'platform' => $siteKey, 'target' => 'create_post', 'action' => '', 'tag_key' => "{$siteKey}.create_post", 'is_enabled' => true]);
        $pkg = SaPackage::query()->create(['package_key' => "{$siteKey}.create_post", 'kind' => 'script', 'name' => "{$siteKey} Script", 'is_enabled' => true, 'sharing_mode' => 'site_specific']);
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

        $agent = SaAgent::query()->create([
            'agent_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-url-test',
            'name' => 'Agent '.$siteKey,
            'availability' => AgentAvailability::Available->value,
            'active_jobs' => 0,
            'max_concurrency' => 2,
            'last_heartbeat_at' => now(),
            'agent_kind' => 'runner',
        ]);
        $account = SaAccount::query()->create([
            'account_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-url-test',
            'platform' => $siteKey,
            'site_url' => $accountSiteUrl,
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

        return compact('site', 'path', 'tag', 'account', 'agent', 'session');
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
            $table->string('site_url', 512)->nullable();
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
