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
use Omnichannel\Addons\SocialAutomation\Enums\AgentAvailability;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignContentStatus;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignStatus;
use Omnichannel\Addons\SocialAutomation\Enums\SessionReadyStatus;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\StaffCampaignController;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaAccountExecutionLease;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTagScript;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContent;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContentAssignment;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaJobAttempt;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaSessionAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Orchestration\CampaignOrchestrator;
use Omnichannel\Addons\SocialAutomation\Services\CampaignService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use PHPUnit\Framework\TestCase;

final class ActionTagSequentialQueueAndRecoveryTest extends TestCase
{
    private Capsule $capsule;

    private ?Container $previousApp = null;

    private ?Container $previousContainer = null;

    private CampaignOrchestrator $orchestrator;

    private CampaignService $campaigns;

    private StaffCampaignController $controller;

    private string $installationId = 'inst-seq-queue';

    private string|false $previousV2Flag = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite unavailable');
        }
        $this->previousV2Flag = getenv('SA_CAMPAIGN_V2_EXECUTION_ENABLED');
        putenv('SA_CAMPAIGN_V2_EXECUTION_ENABLED=true');

        $this->previousApp = Facade::getFacadeApplication() instanceof Container ? Facade::getFacadeApplication() : null;
        $this->previousContainer = Container::getInstance();

        $app = new Container;
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $app->instance(ResponseFactory::class, new class implements ResponseFactory
        {
            public function make($content = '', $status = 200, array $headers = []) { return new \Illuminate\Http\Response($content, $status, $headers); }
            public function noContent($status = 204, array $headers = []) { return new \Illuminate\Http\Response('', $status, $headers); }
            public function view($view, $data = [], $status = 200, array $headers = []) { return new \Illuminate\Http\Response('', $status, $headers); }
            public function json($data = [], $status = 200, array $headers = [], $options = 0) { return new JsonResponse($data, $status, $headers, $options); }
            public function jsonp($callback, $data = [], $status = 200, array $headers = [], $options = 0) { return new JsonResponse($data, $status, $headers, $options); }
            public function stream($callback, $status = 200, array $headers = []) { return new \Symfony\Component\HttpFoundation\StreamedResponse($callback, $status, $headers); }
            public function streamJson($data, $status = 200, $headers = [], $encodingOptions = 15) { return new JsonResponse($data, $status, $headers); }
            public function streamDownload($callback, $name = null, array $headers = [], $disposition = 'attachment') { return new \Symfony\Component\HttpFoundation\StreamedResponse($callback, 200, $headers); }
            public function download($file, $name = null, array $headers = [], $disposition = 'attachment') { return new \Symfony\Component\HttpFoundation\BinaryFileResponse($file, 200, $headers); }
            public function file($file, array $headers = []) { return new \Symfony\Component\HttpFoundation\BinaryFileResponse($file, 200, $headers); }
            public function redirectTo($path, $status = 302, $headers = [], $secure = null) { return new \Illuminate\Http\RedirectResponse($path, $status, $headers); }
            public function redirectToRoute($route, $parameters = [], $status = 302, $headers = []) { return new \Illuminate\Http\RedirectResponse('/', $status, $headers); }
            public function redirectToAction($action, $parameters = [], $status = 302, $headers = []) { return new \Illuminate\Http\RedirectResponse('/', $status, $headers); }
            public function redirectGuest($path, $status = 302, $headers = [], $secure = null) { return new \Illuminate\Http\RedirectResponse($path, $status, $headers); }
            public function redirectToIntended($default = '/', $status = 302, $headers = [], $secure = null) { return new \Illuminate\Http\RedirectResponse($default, $status, $headers); }
        });

        $this->capsule = new Capsule($app);
        $this->capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ], SocialAutomationServiceConfig::CONNECTION);
        $this->capsule->setEventDispatcher(new Dispatcher($app));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $app->instance('db', $this->capsule->getDatabaseManager());
        $app->instance('db.schema', $this->capsule->schema(SocialAutomationServiceConfig::CONNECTION));
        $app->instance('log', new class
        {
            public function warning(string $msg, array $ctx = []): void
            {
                fwrite(STDERR, '[LOG] '.$msg.' '.json_encode($ctx)."\n");
            }
            public function info(string $msg, array $ctx = []): void {}
            public function error(string $msg, array $ctx = []): void
            {
                fwrite(STDERR, '[LOG] '.$msg.' '.json_encode($ctx)."\n");
            }
        });

        $this->createSchema();

        $this->orchestrator = new CampaignOrchestrator;
        $this->campaigns = new CampaignService($this->orchestrator);
        $app->instance(CampaignOrchestrator::class, $this->orchestrator);
        $app->instance(CampaignService::class, $this->campaigns);

        $access = new class extends SocialAutomationAccess
        {
            public function __construct() {}
            public function assertCanAccess(?User $user = null): void {}
            public function assertCanManageCampaigns(?User $user = null): void {}
        };
        $inst = new class($this->installationId) extends SocialAutomationInstallationResolver
        {
            public function __construct(private readonly string $id) {}
            public function resolveInstallationId(): string { return $this->id; }
        };

        $this->controller = new StaffCampaignController($this->campaigns, $access, $inst);
    }

    protected function tearDown(): void
    {
        if ($this->previousV2Flag === false) {
            putenv('SA_CAMPAIGN_V2_EXECUTION_ENABLED');
        } else {
            putenv('SA_CAMPAIGN_V2_EXECUTION_ENABLED='.$this->previousV2Flag);
        }
        Facade::clearResolvedInstances();
        Container::setInstance($this->previousContainer);
        if ($this->previousApp instanceof Container) {
            Facade::setFacadeApplication($this->previousApp);
        }
        parent::tearDown();
    }

    private function createSchema(): void
    {
        $s = Schema::connection(SocialAutomationServiceConfig::CONNECTION);

        $s->create('sa_sites', function (Blueprint $t): void {
            $t->id();
            $t->string('site_key', 64)->unique();
            $t->string('name', 128);
            $t->string('base_url', 255)->nullable();
            $t->string('base_url_mode', 32)->nullable();
            $t->string('cms_type', 64)->nullable();
            $t->boolean('is_enabled')->default(true);
            $t->timestamps();
        });
        $s->create('sa_paths', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('site_id');
            $t->string('path_key', 64);
            $t->string('url_pattern', 255)->nullable();
            $t->json('path_inputs')->nullable();
            $t->boolean('is_authentication')->default(false);
            $t->timestamps();
        });
        $s->create('sa_action_tags', function (Blueprint $t): void {
            $t->id();
            $t->string('tag_key', 128)->unique();
            $t->unsignedBigInteger('site_id')->nullable();
            $t->unsignedBigInteger('path_id')->nullable();
            $t->string('site_key', 64)->nullable();
            $t->string('path_key', 64)->nullable();
            $t->string('action_key', 64)->nullable();
            $t->string('platform', 64)->nullable();
            $t->string('target', 64)->nullable();
            $t->string('action', 64)->nullable();
            $t->string('name', 255)->nullable();
            $t->boolean('is_enabled')->default(true);
            $t->timestamps();
        });
        $s->create('sa_packages', function (Blueprint $t): void {
            $t->id();
            $t->string('package_key', 128)->unique();
            $t->string('kind', 32);
            $t->string('name', 255);
            $t->boolean('is_enabled')->default(true);
            $t->boolean('is_primary')->default(false);
            $t->boolean('is_authentication')->default(false);
            $t->string('sharing_mode', 32)->default('site_specific');
            $t->string('language_label', 32)->nullable();
            $t->timestamps();
        });
        $s->create('sa_package_releases', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('package_id');
            $t->string('version', 64);
            $t->string('content_digest', 64);
            $t->string('execution_digest', 64)->nullable();
            $t->json('manifest');
            $t->json('runtime_compatibility')->nullable();
            $t->string('status', 32)->default('published');
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });
        $s->create('sa_action_tag_scripts', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('action_tag_id');
            $t->string('action_tag_key', 128);
            $t->string('script_id', 128);
            $t->string('script_version', 64);
            $t->unsignedBigInteger('package_id');
            $t->unsignedBigInteger('release_id');
            $t->timestamps();
        });
        $s->create('sa_agents', function (Blueprint $t): void {
            $t->id();
            $t->string('agent_uuid', 64)->unique();
            $t->string('installation_id', 128)->nullable();
            $t->unsignedBigInteger('client_api_key_id')->nullable();
            $t->string('agent_kind', 32)->default('client');
            $t->string('availability', 32)->default('online');
            $t->json('capabilities')->nullable();
            $t->unsignedInteger('max_concurrency')->default(2);
            $t->unsignedInteger('active_jobs')->default(0);
            $t->string('runtime_version', 64)->nullable();
            $t->timestamp('last_heartbeat_at')->nullable();
            $t->timestamps();
        });
        $s->create('sa_accounts', function (Blueprint $t): void {
            $t->id();
            $t->string('account_uuid', 64)->unique();
            $t->string('installation_id', 128);
            $t->string('platform', 64);
            $t->string('domain', 128)->nullable();
            $t->string('platform_account_id', 128)->nullable();
            $t->boolean('verified')->default(true);
            $t->string('identity_source', 64)->nullable();
            $t->string('username', 128)->nullable();
            $t->string('status', 32)->default('active');
            $t->string('lock_state', 32)->default('unlocked');
            $t->unsignedBigInteger('locked_job_id')->nullable();
            $t->unsignedBigInteger('locked_by_agent_id')->nullable();
            $t->timestamp('lock_expires_at')->nullable();
            $t->timestamps();
        });
        $s->create('sa_browser_sessions', function (Blueprint $t): void {
            $t->id();
            $t->string('session_uuid', 64)->unique();
            $t->unsignedBigInteger('agent_id');
            $t->string('ready_status', 32)->default('ready');
            $t->string('status', 32)->default('ready');
            $t->string('execution_lock_state', 32)->default('unlocked');
            $t->unsignedBigInteger('locked_job_id')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->timestamp('lock_expires_at')->nullable();
            $t->timestamp('last_task_at')->nullable();
            $t->timestamps();
        });
        $s->create('sa_session_accounts', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('browser_session_id');
            $t->unsignedBigInteger('account_id');
            $t->boolean('is_active')->default(true);
            $t->string('status', 32)->default('active');
            $t->boolean('is_primary')->default(true);
            $t->timestamps();
        });
        $s->create('sa_account_device_affinities', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('account_id')->unique();
            $t->unsignedBigInteger('agent_id')->nullable();
            $t->unsignedBigInteger('browser_session_id')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('affinity_until')->nullable();
            $t->timestamps();
        });
        $s->create('sa_tasks', function (Blueprint $t): void {
            $t->id();
            $t->string('task_uuid', 64)->nullable();
            $t->string('status', 32)->default('active');
            $t->timestamps();
        });
        $s->create('sa_campaigns', function (Blueprint $t): void {
            $t->id();
            $t->string('campaign_uuid', 64)->unique();
            $t->string('installation_id', 128);
            $t->unsignedInteger('plan_version')->default(2);
            $t->string('name', 255);
            $t->string('platform', 64)->default('multi');
            $t->string('status', 32)->default('draft');
            $t->string('execution_mode', 64)->default('one_account_many_jobs');
            $t->string('run_mode', 32)->default('live');
            $t->string('script_id', 128)->nullable();
            $t->string('script_version', 64)->nullable();
            $t->string('script_content_digest', 64)->nullable();
            $t->unsignedInteger('resource_recheck_seconds')->default(60);
            $t->json('meta')->nullable();
            $t->json('shortcode_mapping')->nullable();
            $t->unsignedInteger('shortcode_mapping_version')->default(0);
            $t->timestamp('started_at')->nullable();
            $t->timestamp('paused_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('next_recheck_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->unsignedBigInteger('deleted_by_user_id')->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->timestamps();
        });
        $s->create('sa_campaign_action_tags', function (Blueprint $t): void {
            $t->id();
            $t->string('plan_tag_uuid', 64)->unique();
            $t->unsignedBigInteger('campaign_id');
            $t->unsignedBigInteger('action_tag_id');
            $t->string('action_tag_key', 128);
            $t->unsignedBigInteger('site_id')->nullable();
            $t->string('site_key', 64);
            $t->string('cms_type', 64)->nullable();
            $t->string('platform', 64)->nullable();
            $t->unsignedBigInteger('package_id');
            $t->string('script_id', 128);
            $t->string('script_name', 255)->nullable();
            $t->unsignedBigInteger('release_id');
            $t->unsignedBigInteger('variant_group_id')->nullable();
            $t->string('variant_group_uuid', 64)->nullable();
            $t->string('script_version', 64);
            $t->string('script_content_digest', 64)->nullable();
            $t->string('script_execution_digest', 64)->nullable();
            $t->string('resolution_source', 64)->default('action_tag_direct');
            $t->string('source_action_tag_key', 128)->nullable();
            $t->json('cms_scope')->nullable();
            $t->json('manifest_snapshot')->nullable();
            $t->json('pinned_variants')->nullable();
            $t->json('shortcode_mapping')->nullable();
            $t->unsignedInteger('shortcode_mapping_version')->default(0);
            $t->boolean('is_enabled')->default(true);
            $t->unsignedInteger('sort_order')->default(1);
            $t->unsignedInteger('interval_minutes')->default(0);
            $t->timestamps();
        });
        $s->create('sa_campaign_contents', function (Blueprint $t): void {
            $t->id();
            $t->string('content_uuid', 64)->unique();
            $t->unsignedBigInteger('campaign_id');
            $t->unsignedBigInteger('article_id')->nullable();
            $t->unsignedInteger('sort_order')->default(1);
            $t->string('title', 255)->nullable();
            $t->json('input')->nullable();
            $t->string('source_format', 32)->default('shortcode');
            $t->text('raw_source')->nullable();
            $t->unsignedInteger('mapping_version')->default(0);
            $t->string('status', 32)->default('pending');
            $t->unsignedBigInteger('assigned_account_id')->nullable();
            $t->unsignedBigInteger('current_job_id')->nullable();
            $t->string('last_error_code', 64)->nullable();
            $t->text('last_error_message')->nullable();
            $t->json('last_error_meta')->nullable();
            $t->string('admin_action_idempotency', 128)->nullable();
            $t->timestamp('admin_action_at')->nullable();
            $t->timestamps();
        });
        $s->create('sa_campaign_content_assignments', function (Blueprint $t): void {
            $t->id();
            $t->string('assignment_uuid', 64)->unique();
            $t->unsignedBigInteger('campaign_id');
            $t->unsignedBigInteger('campaign_content_id');
            $t->unsignedBigInteger('campaign_action_tag_id');
            $t->unsignedBigInteger('package_id');
            $t->unsignedBigInteger('release_id');
            $t->string('script_id', 128);
            $t->string('script_version', 64);
            $t->string('source_format', 32)->default('shortcode');
            $t->json('shortcode_mapping')->nullable();
            $t->unsignedInteger('mapping_version')->default(0);
            $t->json('input')->nullable();
            $t->string('status', 32)->default('pending');
            $t->timestamps();
        });
        $s->create('sa_campaign_account_assignments', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('campaign_id');
            $t->unsignedBigInteger('account_id');
            $t->unsignedBigInteger('content_id')->nullable();
            $t->string('reason', 64)->nullable();
            $t->timestamps();
        });
        $s->create('sa_jobs', function (Blueprint $t): void {
            $t->id();
            $t->string('job_uuid', 64)->unique();
            $t->string('installation_id', 128)->nullable();
            $t->unsignedBigInteger('campaign_id')->nullable();
            $t->unsignedBigInteger('campaign_content_id')->nullable();
            $t->unsignedBigInteger('task_id')->nullable();
            $t->unsignedBigInteger('agent_id')->nullable();
            $t->unsignedBigInteger('browser_session_id')->nullable();
            $t->unsignedBigInteger('account_id')->nullable();
            $t->string('package_key', 128)->nullable();
            $t->string('package_version', 64)->nullable();
            $t->string('content_digest', 64)->nullable();
            $t->string('status', 32)->default('queued');
            $t->string('lease_token', 128)->nullable();
            $t->unsignedBigInteger('fencing_generation')->default(1);
            $t->timestamp('lease_expires_at')->nullable();
            $t->timestamp('reserved_at')->nullable();
            $t->timestamp('claimed_at')->nullable();
            $t->string('dispatch_source', 32)->nullable();
            $t->string('idempotency_key', 128)->nullable();
            $t->json('payload')->nullable();
            $t->json('result')->nullable();
            $t->string('error_code', 64)->nullable();
            $t->text('error_message')->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
        $s->create('sa_job_attempts', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('job_id');
            $t->string('attempt_uuid', 64)->unique();
            $t->unsignedInteger('attempt_no')->default(1);
            $t->unsignedBigInteger('agent_id')->nullable();
            $t->unsignedBigInteger('browser_session_id')->nullable();
            $t->unsignedBigInteger('account_id')->nullable();
            $t->unsignedBigInteger('fencing_generation')->default(1);
            $t->string('status', 32)->default('queued');
            $t->string('lease_token', 128)->nullable();
            $t->timestamp('lease_expires_at')->nullable();
            $t->json('progress')->nullable();
            $t->json('result')->nullable();
            $t->string('error_code', 64)->nullable();
            $t->text('error_message')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
        $s->create('sa_job_events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('job_id');
            $t->unsignedBigInteger('attempt_id')->nullable();
            $t->string('event_type', 64);
            $t->string('from_status', 32)->nullable();
            $t->string('to_status', 32);
            $t->json('payload')->nullable();
            $t->timestamp('occurred_at')->nullable();
            $t->timestamps();
        });
        $s->create('sa_account_execution_leases', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->unsignedBigInteger('agent_id')->nullable();
            $t->unsignedBigInteger('browser_session_id')->nullable();
            $t->unsignedBigInteger('job_id');
            $t->unsignedBigInteger('attempt_id')->nullable();
            $t->string('lease_token', 128)->nullable();
            $t->unsignedBigInteger('fencing_generation')->default(1);
            $t->string('status', 32)->default('active');
            $t->timestamp('acquired_at')->nullable();
            $t->timestamp('heartbeat_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('released_at')->nullable();
            $t->timestamps();
        });
    }

    /** @return array{tag: SaActionTag, pkg: SaPackage, rel: SaPackageRelease} */
    private function seedActionTagAndScript(string $siteKey, string $tagKey): array
    {
        $site = SaSite::query()->create(['site_key' => $siteKey, 'name' => ucfirst($siteKey), 'base_url' => "https://{$siteKey}.test", 'is_enabled' => true]);
        $path = SaPath::query()->create(['site_id' => $site->id, 'path_key' => 'create_post', 'url_pattern' => '/post']);
        $tag = SaActionTag::query()->create([
            'tag_key' => $tagKey,
            'site_id' => $site->id,
            'path_id' => $path->id,
            'site_key' => $siteKey,
            'path_key' => 'create_post',
            'action_key' => 'create_post',
            'platform' => $siteKey,
            'target' => 'create_post',
            'action' => 'create_post',
            'is_enabled' => true,
        ]);
        $pkg = SaPackage::query()->create(['package_key' => "pkg.{$siteKey}", 'kind' => 'script', 'name' => "Script {$siteKey}", 'is_enabled' => true]);
        $rel = SaPackageRelease::query()->create([
            'package_id' => $pkg->id,
            'version' => '1',
            'content_digest' => hash('sha256', $tagKey),
            'execution_digest' => hash('sha256', 'exec-'.$tagKey),
            'manifest' => ['script_id' => "pkg.{$siteKey}", 'variables' => [], 'steps' => [['id' => 's1', 'action' => 'CLICK']]],
            'status' => 'published',
            'published_at' => now(),
        ]);
        SaActionTagScript::query()->create([
            'action_tag_id' => $tag->id,
            'action_tag_key' => $tagKey,
            'script_id' => "pkg.{$siteKey}",
            'script_version' => '1',
            'package_id' => $pkg->id,
            'release_id' => $rel->id,
        ]);

        return ['tag' => $tag, 'pkg' => $pkg, 'rel' => $rel];
    }

    /** @return array{agent: SaAgent, session: SaBrowserSession, account: SaAccount} */
    private function seedClient(string $siteKey, string $accName, bool $online = true, ?SaAccount $sharedAccount = null): array
    {
        $agent = SaAgent::query()->create([
            'agent_uuid' => (string) Str::uuid(),
            'installation_id' => $this->installationId,
            'agent_kind' => 'client',
            'availability' => $online ? AgentAvailability::Available->value : 'offline',
            'last_heartbeat_at' => $online ? now() : now()->subHours(2),
            'max_concurrency' => 2,
        ]);
        $session = SaBrowserSession::query()->create([
            'session_uuid' => (string) Str::uuid(),
            'agent_id' => $agent->id,
            'ready_status' => SessionReadyStatus::Ready->value,
            'status' => 'ready',
            'execution_lock_state' => 'unlocked',
        ]);
        $account = $sharedAccount ?? SaAccount::query()->create([
            'account_uuid' => (string) Str::uuid(),
            'installation_id' => $this->installationId,
            'platform' => $siteKey,
            'domain' => "{$siteKey}.test",
            'platform_account_id' => $accName,
            'verified' => true,
            'identity_source' => 'browser',
            'username' => $accName,
            'status' => 'active',
            'lock_state' => 'unlocked',
        ]);
        SaSessionAccount::query()->create([
            'browser_session_id' => $session->id,
            'account_id' => $account->id,
            'is_active' => true,
            'status' => 'active',
            'is_primary' => true,
        ]);

        return ['agent' => $agent, 'session' => $session, 'account' => $account];
    }

    /**
     * @param  list<array{tag: SaActionTag, pkg: SaPackage, rel: SaPackageRelease, count: int, interval_minutes?: int}>  $tagSpecs
     * @return array{campaign: SaCampaign, plan_tags: array<string, SaCampaignActionTag>, contents: array<string, list<SaCampaignContent>>}
     */
    private function createV2RunningCampaign(array $tagSpecs, string $mode = 'one_account_many_jobs'): array
    {
        $campaign = SaCampaign::query()->create([
            'campaign_uuid' => (string) Str::uuid(),
            'installation_id' => $this->installationId,
            'plan_version' => 2,
            'name' => 'Seq Test',
            'platform' => 'multi',
            'status' => CampaignStatus::Running->value,
            'execution_mode' => $mode,
            'script_id' => 'v2:multi-tag',
            'script_version' => '2',
            'started_at' => now(),
            'next_recheck_at' => now(),
        ]);

        $planTags = [];
        $contentsByTag = [];
        $sort = 1;
        foreach ($tagSpecs as $i => $spec) {
            $tag = $spec['tag'];
            $pt = SaCampaignActionTag::query()->create([
                'plan_tag_uuid' => (string) Str::uuid(),
                'campaign_id' => $campaign->id,
                'action_tag_id' => $tag->id,
                'action_tag_key' => $tag->tag_key,
                'site_id' => $tag->site_id,
                'site_key' => $tag->site_key,
                'platform' => $tag->site_key,
                'package_id' => $spec['pkg']->id,
                'script_id' => $spec['pkg']->package_key,
                'release_id' => $spec['rel']->id,
                'script_version' => '1',
                'script_content_digest' => $spec['rel']->content_digest,
                'script_execution_digest' => $spec['rel']->execution_digest,
                'manifest_snapshot' => $spec['rel']->manifest,
                'sort_order' => $i + 1,
                'interval_minutes' => $spec['interval_minutes'] ?? 0,
            ]);
            $planTags[$tag->tag_key] = $pt;

            for ($k = 1; $k <= $spec['count']; $k++) {
                $c = SaCampaignContent::query()->create([
                    'content_uuid' => (string) Str::uuid(),
                    'campaign_id' => $campaign->id,
                    'sort_order' => $sort++,
                    'title' => "{$tag->site_key}-{$k}",
                    'input' => ['title' => "{$tag->site_key}-{$k}"],
                    'status' => CampaignContentStatus::Pending->value,
                ]);
                SaCampaignContentAssignment::query()->create([
                    'assignment_uuid' => (string) Str::uuid(),
                    'campaign_id' => $campaign->id,
                    'campaign_content_id' => $c->id,
                    'campaign_action_tag_id' => $pt->id,
                    'package_id' => $spec['pkg']->id,
                    'release_id' => $spec['rel']->id,
                    'script_id' => $spec['pkg']->package_key,
                    'script_version' => '1',
                    'input' => ['title' => "{$tag->site_key}-{$k}"],
                    'status' => CampaignContentStatus::Pending->value,
                ]);
                $contentsByTag[$tag->tag_key][] = $c;
            }
        }

        return ['campaign' => $campaign, 'plan_tags' => $planTags, 'contents' => $contentsByTag];
    }

    private function claimAndFinish(SaAgent $agent, SaBrowserSession $session, string $status, ?string $errorCode = null): array
    {
        $claim = $this->orchestrator->claimNext($agent->agent_uuid, [$session->session_uuid], null, $this->installationId);
        self::assertTrue($claim['ok'] && is_array($claim['data']));
        $p = $claim['data'];
        $this->orchestrator->acknowledge($p['job_id'], ['attempt_id' => $p['attempt_id'], 'lease_token' => $p['lease_token']], null, $this->installationId);
        return $this->orchestrator->result($p['job_id'], [
            'status' => $status,
            'attempt_id' => $p['attempt_id'],
            'lease_token' => $p['lease_token'],
            'error_code' => $errorCode,
        ], null, $this->installationId);
    }

    public function test_1_11_five_jobs_in_one_action_tag_execute_sequentially_with_no_duplicate_cron_ticks(): void
    {
        $t = $this->seedActionTagAndScript('site_a', 'site_a.create_post');
        $c1 = $this->seedClient('site_a', 'acc_1');
        $c2 = $this->seedClient('site_a', 'acc_2'); // Even with 2 clients, 1 Action Tag runs sequentially!
        $setup = $this->createV2RunningCampaign([['tag' => $t['tag'], 'pkg' => $t['pkg'], 'rel' => $t['rel'], 'count' => 5]]);
        $camp = $setup['campaign'];
        $items = $setup['contents']['site_a.create_post'];

        for ($i = 0; $i < 5; $i++) {
            $this->orchestrator->dispatchCampaign($camp);
            // 11. Repeated cron ticks create no duplicate Jobs
            $this->orchestrator->recheckDueCampaigns(now()->addMinutes(5));
            self::assertSame($i + 1, SaJob::query()->where('campaign_id', $camp->id)->count());

            for ($j = $i + 1; $j < 5; $j++) {
                self::assertSame(CampaignContentStatus::Pending->value, $items[$j]->fresh()->status);
            }

            $activeJob = SaJob::query()->where('campaign_id', $camp->id)->orderByDesc('id')->firstOrFail();
            $useClient = (int) $activeJob->agent_id === $c1['agent']->id ? $c1 : $c2;
            $this->claimAndFinish($useClient['agent'], $useClient['session'], 'succeeded');
            self::assertSame(CampaignContentStatus::Success->value, $items[$i]->fresh()->status);
        }

        self::assertSame(CampaignStatus::Completed->value, $camp->fresh()->status);
    }

    public function test_2_3_4_5_6_7_10_13_14_15_16_17_18_failure_gate_and_manual_success_recovery(): void
    {
        $tagA = $this->seedActionTagAndScript('maytuicanvas', 'maytuicanvas.create_post');
        $tagB = $this->seedActionTagAndScript('congtybalo', 'congtybalo.create_post');
        $clientA = $this->seedClient('maytuicanvas', 'acc_maytui');
        $clientB = $this->seedClient('congtybalo', 'acc_congty');

        $setup = $this->createV2RunningCampaign([
            ['tag' => $tagA['tag'], 'pkg' => $tagA['pkg'], 'rel' => $tagA['rel'], 'count' => 3],
            ['tag' => $tagB['tag'], 'pkg' => $tagB['pkg'], 'rel' => $tagB['rel'], 'count' => 2],
        ]);
        $camp = $setup['campaign'];
        $aItems = $setup['contents']['maytuicanvas.create_post'];
        $bItems = $setup['contents']['congtybalo.create_post'];
        $ptA = $setup['plan_tags']['maytuicanvas.create_post'];
        $ptB = $setup['plan_tags']['congtybalo.create_post'];

        // Unrelated Campaign 2 on same tag key
        $otherSetup = $this->createV2RunningCampaign([
            ['tag' => $tagA['tag'], 'pkg' => $tagA['pkg'], 'rel' => $tagA['rel'], 'count' => 1],
        ]);
        $otherCamp = $otherSetup['campaign'];

        $this->orchestrator->dispatchCampaign($camp);

        // 2. First Job in Tag A fails -> remaining Tag A items stay Pending (not WaitingResources)
        $this->claimAndFinish($clientA['agent'], $clientA['session'], 'failed', 'ORC_ERROR');
        self::assertSame(CampaignContentStatus::FailedLocked->value, $aItems[0]->fresh()->status);
        self::assertSame(CampaignContentStatus::Pending->value, $aItems[1]->fresh()->status);
        self::assertSame(CampaignContentStatus::Pending->value, $aItems[2]->fresh()->status);

        // Detail API exposes Blocked by Failure for following Tag A items
        $detail = $this->campaigns->detailDto($camp->fresh());
        $a2Dto = collect($detail['contents'])->firstWhere('id', $aItems[1]->content_uuid);
        self::assertSame('blocked_by_failure', $a2Dto['queue_state']);
        self::assertNotNull($a2Dto['blocked_by_gate']);
        self::assertSame('ORC_ERROR', $a2Dto['blocked_by_gate']['reason']);

        // 10. Independent Tag B continues despite Tag A failure
        $this->claimAndFinish($clientB['agent'], $clientB['session'], 'succeeded');
        self::assertSame(CampaignContentStatus::Success->value, $bItems[0]->fresh()->status);
        $this->orchestrator->recheckDueCampaigns(now()->addMinutes(5));
        self::assertSame(CampaignContentStatus::Running->value, $bItems[1]->fresh()->status);
        $this->claimAndFinish($clientB['agent'], $clientB['session'], 'succeeded');
        self::assertSame(CampaignContentStatus::Success->value, $bItems[1]->fresh()->status);

        // 6. Success from Tag B did NOT unlock Tag A
        self::assertTrue($camp->fresh()->meta['action_tag_gates'][(string) $ptA->id]['blocked']);
        self::assertSame(CampaignContentStatus::Pending->value, $aItems[1]->fresh()->status);

        // 7. Success from unrelated Campaign did NOT unlock Tag A
        $this->orchestrator->dispatchCampaign($otherCamp);
        $this->claimAndFinish($clientA['agent'], $clientA['session'], 'succeeded');
        self::assertTrue($camp->fresh()->meta['action_tag_gates'][(string) $ptA->id]['blocked']);

        // 5. Manual failure does NOT unlock Tag A
        $manualFailResp = $this->controller->manualTestActionTag(
            Request::create('/', 'POST'),
            $camp->campaign_uuid,
            $ptA->plan_tag_uuid,
        );
        self::assertSame(201, $manualFailResp->getStatusCode(), $manualFailResp->getContent());
        $this->claimAndFinish($clientA['agent'], $clientA['session'], 'failed', 'STILL_BROKEN');
        self::assertTrue($camp->fresh()->meta['action_tag_gates'][(string) $ptA->id]['blocked']);
        self::assertSame('STILL_BROKEN', $camp->fresh()->meta['action_tag_gates'][(string) $ptA->id]['reason']);

        // 15. Paused Campaign: manual Success unlocks gate, but does NOT dispatch until Resume
        $this->campaigns->pause($camp->campaign_uuid, $this->installationId);
        $manualOkWhilePaused = $this->controller->manualTestActionTag(
            Request::create('/', 'POST'),
            $camp->campaign_uuid,
            $ptA->plan_tag_uuid,
        );
        self::assertSame(201, $manualOkWhilePaused->getStatusCode());
        $this->claimAndFinish($clientA['agent'], $clientA['session'], 'succeeded');
        self::assertFalse($camp->fresh()->meta['action_tag_gates'][(string) $ptA->id]['blocked']);
        self::assertSame(CampaignStatus::Paused->value, $camp->fresh()->status);
        self::assertSame(CampaignContentStatus::Pending->value, $aItems[1]->fresh()->status);

        // 4 & 14 & 17 & 18. Resume continues remaining Pending items in order; old FailedLocked & pins untouched
        $this->campaigns->resume($camp->campaign_uuid, $this->installationId);
        self::assertSame(CampaignContentStatus::Running->value, $aItems[1]->fresh()->status);
        self::assertSame(CampaignContentStatus::Pending->value, $aItems[2]->fresh()->status);
        self::assertSame(CampaignContentStatus::FailedLocked->value, $aItems[0]->fresh()->status);
        self::assertSame($tagA['rel']->id, $ptA->fresh()->release_id);
        self::assertSame('1', $ptA->fresh()->script_version);

        // 13. Unknown Job also blocks queue and is never retried automatically
        $this->claimAndFinish($clientA['agent'], $clientA['session'], 'unknown', 'HUMAN_REQUIRED');
        self::assertSame(CampaignContentStatus::Unknown->value, $aItems[1]->fresh()->status);
        self::assertTrue($camp->fresh()->meta['action_tag_gates'][(string) $ptA->id]['blocked']);
        $this->orchestrator->recheckDueCampaigns(now()->addMinutes(5));
        self::assertSame(CampaignContentStatus::Unknown->value, $aItems[1]->fresh()->status);
        self::assertSame(CampaignContentStatus::Pending->value, $aItems[2]->fresh()->status);

        // 16. Cancelled / soft-deleted Campaign cannot dispatch even after gate unlock
        $this->campaigns->cancel($camp->campaign_uuid, $this->installationId);
        $manualCancelled = $this->controller->manualTestActionTag(
            Request::create('/', 'POST'),
            $camp->campaign_uuid,
            $ptA->plan_tag_uuid,
        );
        self::assertSame(409, $manualCancelled->getStatusCode());
    }

    public function test_8_9_12_only_head_enters_waiting_resources_and_multi_client_shared_account_lease_safe(): void
    {
        $t = $this->seedActionTagAndScript('baloquatang', 'baloquatang.create_post');
        // Offline client initially
        $sharedAcc = SaAccount::query()->create([
            'account_uuid' => (string) Str::uuid(),
            'installation_id' => $this->installationId,
            'platform' => 'baloquatang',
            'domain' => 'baloquatang.test',
            'platform_account_id' => 'shared-1',
            'verified' => true,
            'identity_source' => 'browser',
            'username' => 'shared_acc',
            'status' => 'active',
            'lock_state' => 'unlocked',
        ]);
        $c1 = $this->seedClient('baloquatang', 'shared_acc', online: false, sharedAccount: $sharedAcc);
        $c2 = $this->seedClient('baloquatang', 'shared_acc', online: false, sharedAccount: $sharedAcc);

        $setup = $this->createV2RunningCampaign([['tag' => $t['tag'], 'pkg' => $t['pkg'], 'rel' => $t['rel'], 'count' => 3]]);
        $camp = $setup['campaign'];
        $items = $setup['contents']['baloquatang.create_post'];

        // 8 & 9. Only head (item 0) becomes WaitingResources; items 1 & 2 remain Pending; next_recheck_at set
        $this->orchestrator->dispatchCampaign($camp);
        self::assertSame(CampaignContentStatus::WaitingResources->value, $items[0]->fresh()->status);
        self::assertSame(CampaignContentStatus::Pending->value, $items[1]->fresh()->status);
        self::assertSame(CampaignContentStatus::Pending->value, $items[2]->fresh()->status);
        self::assertNotNull($camp->fresh()->next_recheck_at);

        // 12. Both clients come online sharing one Account -> only 1 Job & 1 active Lease created
        $c1['agent']->update(['availability' => AgentAvailability::Available->value, 'last_heartbeat_at' => now()]);
        $c2['agent']->update(['availability' => AgentAvailability::Available->value, 'last_heartbeat_at' => now()]);

        $this->orchestrator->recheckDueCampaigns(now()->addMinutes(65));
        self::assertSame(CampaignContentStatus::Running->value, $items[0]->fresh()->status);
        self::assertSame(CampaignContentStatus::Pending->value, $items[1]->fresh()->status);
        self::assertSame(1, SaAccountExecutionLease::query()->where('account_id', $sharedAcc->id)->where('status', 'active')->count());
    }

    public function test_posting_interval_240_independent_tags_pause_resume_restart_and_manual_reset(): void
    {
        $tagFb = $this->seedActionTagAndScript('facebook', 'facebook.create_post');
        $tagWp = $this->seedActionTagAndScript('wordpress', 'wordpress.create_post');
        $cFb = $this->seedClient('facebook', 'acc_fb');
        $cWp = $this->seedClient('wordpress', 'acc_wp');

        // 2, 3, 4, 5, 6, 12, 19. FB interval=240, WP interval=0; first Job eligible immediately
        $setup = $this->createV2RunningCampaign([
            ['tag' => $tagFb['tag'], 'pkg' => $tagFb['pkg'], 'rel' => $tagFb['rel'], 'count' => 4, 'interval_minutes' => 240],
            ['tag' => $tagWp['tag'], 'pkg' => $tagWp['pkg'], 'rel' => $tagWp['rel'], 'count' => 2, 'interval_minutes' => 0],
        ]);
        $camp = $setup['campaign'];
        $fbItems = $setup['contents']['facebook.create_post'];
        $wpItems = $setup['contents']['wordpress.create_post'];
        $ptFb = $setup['plan_tags']['facebook.create_post'];

        // Legacy compatibility: simulate stale waiting_resources on fbItems[2] from old scheduler
        $fbItems[2]->update(['status' => CampaignContentStatus::WaitingResources->value, 'last_error_code' => 'waiting_resources']);

        $t0 = \Carbon\CarbonImmutable::now();
        $this->orchestrator->dispatchCampaign($camp, $t0);
        self::assertSame(CampaignContentStatus::Running->value, $fbItems[0]->fresh()->status);
        self::assertSame(CampaignContentStatus::Running->value, $wpItems[0]->fresh()->status);
        // Non-head stale waiting_resources cleaned up to pending
        self::assertSame(CampaignContentStatus::Pending->value, $fbItems[2]->fresh()->status);

        // Complete FB job 1 & WP job 1 at 08:00
        $this->claimAndFinish($cFb['agent'], $cFb['session'], 'succeeded');
        $this->claimAndFinish($cWp['agent'], $cWp['session'], 'succeeded');
        SaJob::query()->where('campaign_content_id', $fbItems[0]->id)->update(['finished_at' => $t0]);
        SaJob::query()->where('campaign_content_id', $wpItems[0]->id)->update(['finished_at' => $t0]);

        // At 09:00 (only 60m elapsed): WP (interval=0) dispatches immediately; FB (interval=240) stays Pending
        $t0900 = $t0->addMinutes(60);
        $this->orchestrator->dispatchCampaign($camp, $t0900);
        self::assertSame(CampaignContentStatus::Running->value, $wpItems[1]->fresh()->status);
        self::assertSame(CampaignContentStatus::Pending->value, $fbItems[1]->fresh()->status);
        self::assertSame(0, SaJob::query()->where('campaign_content_id', $fbItems[1]->id)->count());

        // 12. Waiting for interval exposes waiting_interval + next_run_at in detailDto (never waiting_resources)
        $detail = $this->campaigns->detailDto($camp->fresh());
        $fbTagDto = collect($detail['action_tags'])->firstWhere('id', $ptFb->plan_tag_uuid);
        self::assertSame(240, $fbTagDto['interval_minutes']);
        self::assertNotNull($fbTagDto['last_success_at']);
        self::assertSame($t0->addMinutes(240)->toIso8601String(), $fbTagDto['next_run_at']);
        $fb2Dto = collect($detail['contents'])->firstWhere('id', $fbItems[1]->content_uuid);
        self::assertSame('waiting_interval', $fb2Dto['queue_state']);
        self::assertSame($t0->addMinutes(240)->toIso8601String(), $fb2Dto['next_run_at']);

        // 14. Pause at 09:00, Resume at 10:00 -> FB job 2 STILL waits until 12:00
        $this->campaigns->pause($camp->campaign_uuid, $this->installationId);
        $this->campaigns->resume($camp->campaign_uuid, $this->installationId);
        $this->orchestrator->dispatchCampaign($camp->fresh(), $t0->addMinutes(120));
        self::assertSame(CampaignContentStatus::Pending->value, $fbItems[1]->fresh()->status);

        // 16. Simulate fresh Orchestrator instance (restart safety) at exact 12:00 (240m elapsed) -> dispatches 1 Job
        $restartedOrch = new CampaignOrchestrator;
        $restartedOrch->dispatchCampaign($camp->fresh(), $t0->addMinutes(240));
        self::assertSame(CampaignContentStatus::Running->value, $fbItems[1]->fresh()->status);
        self::assertSame(CampaignContentStatus::Pending->value, $fbItems[2]->fresh()->status);

        // 15. Pause again; let interval expire while paused, then Resume -> dispatches at most 1 Job (never batch)
        $this->claimAndFinish($cFb['agent'], $cFb['session'], 'failed', 'ERR_2');
        $this->campaigns->pause($camp->campaign_uuid, $this->installationId);
        // 7 & 8. Manual Success at 15:00 unlocks gate and resets interval timing to 15:00 + 240m = 19:00
        $manualOk = $this->controller->manualTestActionTag(Request::create('/', 'POST'), $camp->campaign_uuid, $ptFb->plan_tag_uuid);
        self::assertSame(201, $manualOk->getStatusCode());
        $this->claimAndFinish($cFb['agent'], $cFb['session'], 'succeeded');
        $t1500 = $t0->addHours(7);
        $manualJobUuid = $manualOk->getData(true)['job']['job_uuid'];
        SaJob::query()->where('job_uuid', $manualJobUuid)->update(['finished_at' => $t1500]);

        $this->campaigns->resume($camp->campaign_uuid, $this->installationId);
        // At 16:00 (only 60m after manual success), item 2 must still be Pending
        $restartedOrch->dispatchCampaign($camp->fresh(), $t1500->addMinutes(60));
        self::assertSame(CampaignContentStatus::Pending->value, $fbItems[2]->fresh()->status);

        // At 20:00 (300m after manual success, well past 240m), only item 2 dispatches (never batch 2 & 3)
        $restartedOrch->dispatchCampaign($camp->fresh(), $t1500->addMinutes(300));
        self::assertSame(CampaignContentStatus::Running->value, $fbItems[2]->fresh()->status);
        self::assertSame(CampaignContentStatus::Pending->value, $fbItems[3]->fresh()->status);
    }

    public function test_v2_plan_service_validates_and_persists_interval_minutes(): void
    {
        $t = $this->seedActionTagAndScript('threads', 'threads.create_post');

        $bad = $this->campaigns->create([
            'name' => 'Bad Interval',
            'plan_version' => 2,
            'action_tags' => [['action_tag_key' => 'threads.create_post', 'interval_minutes' => -5]],
            'contents' => [],
        ], $this->installationId, 1);
        self::assertFalse($bad['ok']);
        self::assertContains('invalid_interval_minutes', $bad['errors']);

        $ok = $this->campaigns->create([
            'name' => 'Threads 1440m',
            'plan_version' => 2,
            'action_tags' => [['action_tag_key' => 'threads.create_post', 'interval_minutes' => 1440]],
            'contents' => [],
        ], $this->installationId, 1);
        self::assertTrue($ok['ok'], json_encode($ok));
        self::assertSame(1440, $ok['data']['action_tags'][0]['interval_minutes']);
    }
}
