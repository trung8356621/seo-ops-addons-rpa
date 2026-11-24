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
use Omnichannel\Addons\SocialAutomation\Http\Controllers\StaffCampaignContentController;
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

    private StaffCampaignContentController $contentController;

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
        $this->contentController = new StaffCampaignContentController($this->campaigns, $this->orchestrator, $access, $inst);
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
        $this->seedActionTagAndScript('threads', 'threads.create_post');

        foreach ([-1, 1.5, 10081, 525600, true, [60], 'abc'] as $badInterval) {
            $bad = $this->campaigns->create([
                'name' => 'Bad Interval',
                'plan_version' => 2,
                'action_tags' => [['action_tag_key' => 'threads.create_post', 'interval_minutes' => $badInterval]],
                'contents' => [],
            ], $this->installationId, 1);
            self::assertFalse($bad['ok'], 'Expected create rejection for '.json_encode($badInterval));
            self::assertSame(422, $bad['http'] ?? null);
            self::assertContains('invalid_interval_minutes', $bad['errors']);
        }

        $okZero = $this->campaigns->create([
            'name' => 'Threads 0m',
            'plan_version' => 2,
            'action_tags' => [['action_tag_key' => 'threads.create_post', 'interval_minutes' => 0]],
            'contents' => [],
        ], $this->installationId, 1);
        self::assertTrue($okZero['ok'], json_encode($okZero));
        self::assertSame(0, $okZero['data']['action_tags'][0]['interval_minutes']);

        $okMax = $this->campaigns->create([
            'name' => 'Threads 10080m',
            'plan_version' => 2,
            'action_tags' => [['action_tag_key' => 'threads.create_post', 'interval_minutes' => 10080]],
            'contents' => [],
        ], $this->installationId, 1);
        self::assertTrue($okMax['ok'], json_encode($okMax));
        self::assertSame(10080, $okMax['data']['action_tags'][0]['interval_minutes']);

        $campUuid = $okMax['data']['id'];
        $planTagUuid = $okMax['data']['action_tags'][0]['id'];

        // Update rejects 10081 and 525600, accepts 1440 and 10080
        foreach ([10081, 525600, -1, 1.5] as $badUpdate) {
            $badUpd = $this->campaigns->update($campUuid, $this->installationId, [
                'name' => 'Threads 10080m',
                'plan_version' => 2,
                'action_tags' => [['id' => $planTagUuid, 'action_tag_key' => 'threads.create_post', 'interval_minutes' => $badUpdate]],
                'contents' => [],
            ]);
            self::assertFalse($badUpd['ok'], 'Expected update rejection for '.json_encode($badUpdate));
            self::assertSame(422, $badUpd['http'] ?? null);
            self::assertContains('invalid_interval_minutes', $badUpd['errors'], json_encode($badUpd));
        }

        $okUpd = $this->campaigns->update($campUuid, $this->installationId, [
            'name' => 'Threads 1440m',
            'plan_version' => 2,
            'action_tags' => [['id' => $planTagUuid, 'action_tag_key' => 'threads.create_post', 'interval_minutes' => 1440]],
            'contents' => [],
        ]);
        self::assertTrue($okUpd['ok'], json_encode($okUpd));
        self::assertSame(1440, $okUpd['data']['action_tags'][0]['interval_minutes']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function patchInterval(string $campaignUuid, string $planTagUuid, array $payload): \Illuminate\Http\JsonResponse
    {
        $req = Request::create(
            "/social-automation/api/campaigns/{$campaignUuid}/action-tags/{$planTagUuid}/interval",
            'PATCH',
            $payload
        );

        return $this->controller->updateActionTagInterval($req, $campaignUuid, $planTagUuid);
    }

    public function test_update_action_tag_interval_status_gates_isolation_validation_and_auth(): void
    {
        $tFb = $this->seedActionTagAndScript('facebook', 'facebook.create_post');
        $setup = $this->createV2RunningCampaign([
            ['tag' => $tFb['tag'], 'pkg' => $tFb['pkg'], 'rel' => $tFb['rel'], 'count' => 1, 'interval_minutes' => 60],
        ]);
        $camp = $setup['campaign'];
        $camp->update(['status' => CampaignStatus::Draft->value]);
        $pt = $setup['plan_tags']['facebook.create_post'];

        // Draft -> 200
        $resDraft = $this->patchInterval($camp->campaign_uuid, $pt->plan_tag_uuid, ['interval_minutes' => 90]);
        self::assertSame(200, $resDraft->getStatusCode());
        $draftData = $resDraft->getData(true);
        self::assertTrue($draftData['ok']);
        self::assertSame($camp->campaign_uuid, $draftData['campaign_id']);
        self::assertSame($pt->plan_tag_uuid, $draftData['plan_tag_id']);
        self::assertSame(90, $draftData['interval_minutes']);
        self::assertNull($draftData['last_success_at']);
        self::assertNull($draftData['next_run_at']);
        self::assertSame(90, (int) $pt->fresh()->interval_minutes);

        // Running -> 200
        $camp->update(['status' => CampaignStatus::Running->value]);
        $resRun = $this->patchInterval($camp->campaign_uuid, $pt->plan_tag_uuid, ['interval_minutes' => 120]);
        self::assertSame(200, $resRun->getStatusCode());
        self::assertSame(120, (int) $pt->fresh()->interval_minutes);

        // Paused -> 200
        $camp->update(['status' => CampaignStatus::Paused->value]);
        $resPause = $this->patchInterval($camp->campaign_uuid, $pt->plan_tag_uuid, ['interval_minutes' => 180]);
        self::assertSame(200, $resPause->getStatusCode());
        self::assertSame(180, (int) $pt->fresh()->interval_minutes);

        // Completed -> 200
        $camp->update(['status' => CampaignStatus::Completed->value]);
        $resComp = $this->patchInterval($camp->campaign_uuid, $pt->plan_tag_uuid, ['interval_minutes' => 240]);
        self::assertSame(200, $resComp->getStatusCode());
        self::assertSame(240, (int) $pt->fresh()->interval_minutes);

        // Boundary 0 and 10080 -> 200
        $resZero = $this->patchInterval($camp->campaign_uuid, $pt->plan_tag_uuid, ['interval_minutes' => 0]);
        self::assertSame(200, $resZero->getStatusCode());
        self::assertSame(0, $resZero->getData(true)['interval_minutes']);

        $resMax = $this->patchInterval($camp->campaign_uuid, $pt->plan_tag_uuid, ['interval_minutes' => 10080]);
        self::assertSame(200, $resMax->getStatusCode());
        self::assertSame(10080, $resMax->getData(true)['interval_minutes']);

        // Idempotent same-value PATCH -> 200
        $resSame = $this->patchInterval($camp->campaign_uuid, $pt->plan_tag_uuid, ['interval_minutes' => 10080]);
        self::assertSame(200, $resSame->getStatusCode());
        self::assertSame(10080, $resSame->getData(true)['interval_minutes']);

        // Validation 422 cases
        $invalidPayloads = [
            [],
            ['interval_minutes' => null],
            ['interval_minutes' => ''],
            ['interval_minutes' => -1],
            ['interval_minutes' => 1.5],
            ['interval_minutes' => '1.5'],
            ['interval_minutes' => true],
            ['interval_minutes' => false],
            ['interval_minutes' => [60]],
            ['interval_minutes' => 'abc'],
            ['interval_minutes' => 10081],
            ['interval_minutes' => 525600],
        ];
        foreach ($invalidPayloads as $badPayload) {
            $badRes = $this->patchInterval($camp->campaign_uuid, $pt->plan_tag_uuid, $badPayload);
            self::assertSame(422, $badRes->getStatusCode(), 'Expected 422 for '.json_encode($badPayload));
            self::assertFalse($badRes->getData(true)['ok']);
        }
        self::assertSame(10080, (int) $pt->fresh()->interval_minutes);

        // Cancelled -> 409
        $camp->update(['status' => CampaignStatus::Cancelled->value]);
        $resCancel = $this->patchInterval($camp->campaign_uuid, $pt->plan_tag_uuid, ['interval_minutes' => 30]);
        self::assertSame(409, $resCancel->getStatusCode());
        self::assertSame(10080, (int) $pt->fresh()->interval_minutes);

        // Soft-deleted -> 404
        $camp->update(['status' => CampaignStatus::Running->value, 'deleted_at' => now()]);
        $resDeleted = $this->patchInterval($camp->campaign_uuid, $pt->plan_tag_uuid, ['interval_minutes' => 30]);
        self::assertSame(404, $resDeleted->getStatusCode());
        $camp->update(['deleted_at' => null]);

        // Foreign installation -> 404
        $camp->update(['installation_id' => 'other-inst']);
        self::assertSame(404, $this->patchInterval($camp->campaign_uuid, $pt->plan_tag_uuid, ['interval_minutes' => 30])->getStatusCode());
        $camp->update(['installation_id' => $this->installationId]);

        // Foreign / missing planTagUuid -> 404
        $otherSetup = $this->createV2RunningCampaign([
            ['tag' => $tFb['tag'], 'pkg' => $tFb['pkg'], 'rel' => $tFb['rel'], 'count' => 1, 'interval_minutes' => 60],
        ]);
        $otherPt = $otherSetup['plan_tags']['facebook.create_post'];
        self::assertSame(404, $this->patchInterval($camp->campaign_uuid, $otherPt->plan_tag_uuid, ['interval_minutes' => 30])->getStatusCode());
        self::assertSame(404, $this->patchInterval($camp->campaign_uuid, (string) \Illuminate\Support\Str::uuid(), ['interval_minutes' => 30])->getStatusCode());

        // Staff auth gate (403 AuthorizationException)
        $deniedAccess = new class extends \Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess
        {
            public function __construct() {}

            public function assertCanManageCampaigns(?\App\Models\User $user = null): void
            {
                throw new \Illuminate\Auth\Access\AuthorizationException('forbidden');
            }
        };
        $deniedController = new StaffCampaignController(
            $this->campaigns,
            $deniedAccess,
            app(\Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver::class),
        );
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $deniedController->updateActionTagInterval(
            Request::create('/', 'PATCH', ['interval_minutes' => 15]),
            $camp->campaign_uuid,
            $pt->plan_tag_uuid
        );
    }

    public function test_update_action_tag_interval_recalculates_next_run_without_dispatch_or_side_effects(): void
    {
        $tFb = $this->seedActionTagAndScript('facebook', 'facebook.create_post');
        $tWp = $this->seedActionTagAndScript('wordpress', 'wordpress.create_post');
        $cFb = $this->seedClient('facebook', 'fb-acc');
        $cWp = $this->seedClient('wordpress', 'wp-acc');

        $setup = $this->createV2RunningCampaign([
            ['tag' => $tFb['tag'], 'pkg' => $tFb['pkg'], 'rel' => $tFb['rel'], 'count' => 3, 'interval_minutes' => 120],
            ['tag' => $tWp['tag'], 'pkg' => $tWp['pkg'], 'rel' => $tWp['rel'], 'count' => 2, 'interval_minutes' => 60],
        ]);

        $camp = $setup['campaign'];
        $fbItems = $setup['contents']['facebook.create_post'];
        $wpItems = $setup['contents']['wordpress.create_post'];
        $ptFb = $setup['plan_tags']['facebook.create_post'];
        $ptWp = $setup['plan_tags']['wordpress.create_post'];
        $origReleaseId = $ptFb->release_id;
        $origContentDigest = $ptFb->script_content_digest;

        $t0 = \Carbon\CarbonImmutable::now()->subMinutes(90)->startOfSecond();
        $this->orchestrator->dispatchCampaign($camp, $t0);
        $this->claimAndFinish($cFb['agent'], $cFb['session'], 'succeeded');
        SaJob::query()->where('campaign_content_id', $fbItems[0]->id)->update(['finished_at' => $t0]);
        $this->orchestrator->dispatchCampaign($camp->fresh(), $t0->addMinutes(10));
        self::assertSame(CampaignContentStatus::Running->value, $wpItems[0]->fresh()->status);

        // Increasing FB interval from 120 -> 240 shifts next_run_at forward; WP stays 60; WP running job unaffected
        $resUp = $this->patchInterval($camp->campaign_uuid, $ptFb->plan_tag_uuid, ['interval_minutes' => 240]);
        self::assertSame(200, $resUp->getStatusCode());
        $upData = $resUp->getData(true);
        self::assertSame($t0->toIso8601String(), $upData['last_success_at']);
        self::assertSame($t0->addMinutes(240)->toIso8601String(), $upData['next_run_at']);
        self::assertSame(60, (int) $ptWp->fresh()->interval_minutes);
        self::assertSame(CampaignContentStatus::Running->value, $wpItems[0]->fresh()->status);

        // At t0 + 150m (elapsed past old 120m, before new 240m), scheduler still holds FB item 1
        $this->orchestrator->dispatchCampaign($camp->fresh(), $t0->addMinutes(150));
        self::assertSame(CampaignContentStatus::Pending->value, $fbItems[1]->fresh()->status);

        // Decreasing FB interval from 240 -> 30 shifts next_run_at to t0+30 (already past!) and updates next_recheck_at,
        // WITHOUT directly dispatching FB item 1 inside PATCH
        $camp->update(['next_recheck_at' => $t0->addMinutes(240)]);
        $resDown = $this->patchInterval($camp->campaign_uuid, $ptFb->plan_tag_uuid, ['interval_minutes' => 30]);
        self::assertSame(200, $resDown->getStatusCode());
        $downData = $resDown->getData(true);
        self::assertSame(30, $downData['interval_minutes']);
        self::assertSame($t0->addMinutes(30)->toIso8601String(), $downData['next_run_at']);
        self::assertSame(CampaignContentStatus::Pending->value, $fbItems[1]->fresh()->status);
        self::assertSame(0, SaJob::query()->where('campaign_content_id', $fbItems[1]->id)->count());
        self::assertTrue($camp->fresh()->next_recheck_at->lte(now()->addSeconds(5)));

        // Subsequent scheduler tick dispatches exactly 1 FB job (item 1, preserving order)
        $this->orchestrator->dispatchCampaign($camp->fresh(), now());
        self::assertSame(CampaignContentStatus::Running->value, $fbItems[1]->fresh()->status);
        self::assertSame(CampaignContentStatus::Pending->value, $fbItems[2]->fresh()->status);

        // While FB item 1 fails -> queue blocked by failure; updating interval must NOT unlock gate
        $this->claimAndFinish($cFb['agent'], $cFb['session'], 'failed', 'ERR_BOOM');
        $resWhileBlocked = $this->patchInterval($camp->campaign_uuid, $ptFb->plan_tag_uuid, ['interval_minutes' => 0]);
        self::assertSame(200, $resWhileBlocked->getStatusCode());
        $this->orchestrator->dispatchCampaign($camp->fresh(), now()->addMinutes(10));
        self::assertSame(CampaignContentStatus::FailedLocked->value, $fbItems[1]->fresh()->status);
        self::assertSame(CampaignContentStatus::Pending->value, $fbItems[2]->fresh()->status);

        // Pinning & sort_order preserved
        $ptFbFresh = $ptFb->fresh();
        self::assertSame($origReleaseId, $ptFbFresh->release_id);
        self::assertSame($origContentDigest, $ptFbFresh->script_content_digest);
        self::assertSame([1, 2, 3], SaCampaignContent::query()
            ->where('campaign_id', $camp->id)
            ->whereIn('id', [$fbItems[0]->id, $fbItems[1]->id, $fbItems[2]->id])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('sort_order')
            ->map(fn ($v) => (int) $v)
            ->all());
    }

    public function test_manual_retry_one_shot_gate_recovery_success_failure_unknown_and_idempotency(): void
    {
        $tagA = $this->seedActionTagAndScript('maytuicanvas', 'maytuicanvas.create_post');
        $client = $this->seedClient('maytuicanvas', 'may-acc-1');
        $setup = $this->createV2RunningCampaign([
            ['tag' => $tagA['tag'], 'pkg' => $tagA['pkg'], 'rel' => $tagA['rel'], 'count' => 3],
        ]);
        $camp = $setup['campaign'];
        $items = $setup['contents']['maytuicanvas.create_post'];
        $pt = $setup['plan_tags']['maytuicanvas.create_post'];
        $origReleaseId = $pt->release_id;

        // 1. Dispatch item 0 -> fails -> blocks Action Tag gate
        $this->orchestrator->dispatchCampaign($camp);
        $this->claimAndFinish($client['agent'], $client['session'], 'failed', 'ORC_ERROR');

        $job1 = SaJob::query()->where('campaign_content_id', $items[0]->id)->firstOrFail();
        self::assertSame(CampaignContentStatus::FailedLocked->value, $items[0]->fresh()->status);
        self::assertTrue($camp->fresh()->meta['action_tag_gates'][(string) $pt->id]['blocked']);
        self::assertSame(CampaignContentStatus::Pending->value, $items[1]->fresh()->status);

        // 7. Non-head Pending item (items[1]) CANNOT be retried to bypass gate
        $nonHeadResp = $this->contentController->retry(
            Request::create('/', 'POST', ['idempotency_key' => 'retry-nonhead']),
            $items[1]->content_uuid,
        );
        self::assertSame(422, $nonHeadResp->getStatusCode());

        // 2 & 3. Explicit Retry on failed item 0 creates ONE new recovery Job; old Failed Job 1 stays immutable
        $retry1 = $this->contentController->retry(
            Request::create('/', 'POST', ['idempotency_key' => 'retry-1']),
            $items[0]->content_uuid,
        );
        self::assertSame(200, $retry1->getStatusCode(), $retry1->getContent());
        self::assertSame(CampaignContentStatus::Running->value, $items[0]->fresh()->status);
        $jobsAfterRetry1 = SaJob::query()->where('campaign_content_id', $items[0]->id)->orderBy('id')->get();
        self::assertCount(2, $jobsAfterRetry1);
        self::assertSame('failed', $job1->fresh()->status);
        $job2 = $jobsAfterRetry1[1];
        self::assertNotSame($job1->id, $job2->id);
        self::assertSame('manual_retry', $job2->payload['execution_origin'] ?? null);
        // One-shot authorization consumed on reservation
        $freshMeta = $items[0]->fresh()->last_error_meta ?? [];
        self::assertArrayNotHasKey('gate_recovery_authorized', $freshMeta);
        self::assertArrayNotHasKey('gate_recovery_token', $freshMeta);
        self::assertArrayNotHasKey('manual_recovery_origin', $freshMeta);
        // Other contents remain blocked/pending
        self::assertSame(CampaignContentStatus::Pending->value, $items[1]->fresh()->status);

        // 8. Duplicate Retry (same key or new key while Running) does NOT create duplicate Jobs
        $dupSameKey = $this->contentController->retry(
            Request::create('/', 'POST', ['idempotency_key' => 'retry-1']),
            $items[0]->content_uuid,
        );
        self::assertSame(200, $dupSameKey->getStatusCode());
        self::assertTrue($dupSameKey->getData(true)['idempotent'] ?? false);
        self::assertCount(2, SaJob::query()->where('campaign_content_id', $items[0]->id)->get());

        $dupNewKey = $this->contentController->retry(
            Request::create('/', 'POST', ['idempotency_key' => 'retry-1b']),
            $items[0]->content_uuid,
        );
        self::assertSame(422, $dupNewKey->getStatusCode());
        self::assertCount(2, SaJob::query()->where('campaign_content_id', $items[0]->id)->get());

        // 5. Failed recovery keeps Action Tag blocked and does NOT advance items[1]
        $this->claimAndFinish($client['agent'], $client['session'], 'failed', 'STILL_FAILING');
        self::assertSame(CampaignContentStatus::FailedLocked->value, $items[0]->fresh()->status);
        self::assertTrue($camp->fresh()->meta['action_tag_gates'][(string) $pt->id]['blocked']);
        self::assertSame('STILL_FAILING', $camp->fresh()->meta['action_tag_gates'][(string) $pt->id]['reason']);
        $this->orchestrator->recheckDueCampaigns(now()->addMinutes(5));
        self::assertSame(CampaignContentStatus::Pending->value, $items[1]->fresh()->status);

        // 6. Retry again -> Unknown recovery keeps Action Tag blocked and does NOT advance items[1]
        $retry2 = $this->contentController->retry(
            Request::create('/', 'POST', ['idempotency_key' => 'retry-2']),
            $items[0]->content_uuid,
        );
        self::assertSame(200, $retry2->getStatusCode());
        $this->claimAndFinish($client['agent'], $client['session'], 'unknown', 'HUMAN_REQUIRED');
        self::assertSame(CampaignContentStatus::Unknown->value, $items[0]->fresh()->status);
        self::assertTrue($camp->fresh()->meta['action_tag_gates'][(string) $pt->id]['blocked']);
        $this->orchestrator->recheckDueCampaigns(now()->addMinutes(5));
        self::assertSame(CampaignContentStatus::Pending->value, $items[1]->fresh()->status);

        // 4 & 13. Retry Unknown -> Successful recovery unlocks Tag, releases lease, and advances items[1]
        $retry3 = $this->contentController->retry(
            Request::create('/', 'POST', ['idempotency_key' => 'retry-3']),
            $items[0]->content_uuid,
        );
        self::assertSame(200, $retry3->getStatusCode());
        $this->claimAndFinish($client['agent'], $client['session'], 'succeeded');
        self::assertSame(CampaignContentStatus::Success->value, $items[0]->fresh()->status);
        self::assertFalse($camp->fresh()->meta['action_tag_gates'][(string) $pt->id]['blocked']);
        $recoveryJob = SaJob::query()->where('campaign_content_id', $items[0]->id)->orderByDesc('id')->firstOrFail();
        self::assertSame('released', SaAccountExecutionLease::query()->where('job_id', $recoveryJob->id)->value('status'));
        self::assertSame(CampaignContentStatus::Running->value, $items[1]->fresh()->status);
        self::assertSame(1, SaAccountExecutionLease::query()->where('status', 'active')->count());
        self::assertSame($origReleaseId, $pt->fresh()->release_id);
    }

    public function test_legacy_pending_manual_retry_marker_does_not_auto_dispatch_and_pause_stays_paused(): void
    {
        $tagA = $this->seedActionTagAndScript('maytuicanvas', 'maytuicanvas.create_post');
        $client = $this->seedClient('maytuicanvas', 'may-acc-2');
        $setup = $this->createV2RunningCampaign([
            ['tag' => $tagA['tag'], 'pkg' => $tagA['pkg'], 'rel' => $tagA['rel'], 'count' => 3],
        ]);
        $camp = $setup['campaign'];
        $items = $setup['contents']['maytuicanvas.create_post'];
        $pt = $setup['plan_tags']['maytuicanvas.create_post'];

        // Simulate Campaign 9 Content 83 state: failed -> gate blocked -> legacy manual_retry left Content Pending without gate_recovery_authorized
        $this->orchestrator->dispatchCampaign($camp);
        $this->claimAndFinish($client['agent'], $client['session'], 'failed', 'ORC_ERROR');
        $item0 = $items[0]->fresh();
        $item0->status = CampaignContentStatus::Pending->value;
        $item0->current_job_id = null;
        $item0->last_error_code = null;
        $item0->last_error_message = null;
        $item0->last_error_meta = [
            'manual_recovery_origin' => 'manual_retry',
            'idempotency_key' => 'legacy-key',
        ];
        $item0->save();
        SaCampaignContentAssignment::query()
            ->where('campaign_content_id', $items[0]->id)
            ->update(['status' => CampaignContentStatus::FailedLocked->value]);

        // 9. Deployment / cron tick / resume must NOT auto-dispatch legacy Pending + manual_retry marker
        $this->orchestrator->dispatchCampaign($camp->fresh());
        $this->orchestrator->recheckDueCampaigns(now()->addMinutes(5));
        self::assertSame(CampaignContentStatus::Pending->value, $items[0]->fresh()->status);
        self::assertCount(1, SaJob::query()->where('campaign_content_id', $items[0]->id)->get());

        // 11. If Campaign is Paused, explicit Retry authorizes recovery WITHOUT silently resuming the Campaign
        $this->campaigns->pause($camp->campaign_uuid, $this->installationId);
        $retryWhilePaused = $this->contentController->retry(
            Request::create('/', 'POST', ['idempotency_key' => 'retry-paused']),
            $items[0]->content_uuid,
        );
        self::assertSame(200, $retryWhilePaused->getStatusCode());
        self::assertSame(CampaignStatus::Paused->value, $camp->fresh()->status);
        self::assertSame(CampaignContentStatus::Pending->value, $items[0]->fresh()->status);
        self::assertTrue((bool) ($items[0]->fresh()->last_error_meta['gate_recovery_authorized'] ?? false));

        // Resume now dispatches ONLY the authorized recovery Content (items[0]), keeping items[1] Pending
        $this->campaigns->resume($camp->campaign_uuid, $this->installationId);
        self::assertSame(CampaignContentStatus::Running->value, $items[0]->fresh()->status);
        self::assertSame(CampaignContentStatus::Pending->value, $items[1]->fresh()->status);
        self::assertCount(2, SaJob::query()->where('campaign_content_id', $items[0]->id)->get());

        // Pause while recovery Job is in-flight -> recovery Success unlocks gate, but does NOT advance items[1] until Resume
        $this->campaigns->pause($camp->campaign_uuid, $this->installationId);
        $this->claimAndFinish($client['agent'], $client['session'], 'succeeded');
        self::assertSame(CampaignContentStatus::Success->value, $items[0]->fresh()->status);
        self::assertFalse($camp->fresh()->meta['action_tag_gates'][(string) $pt->id]['blocked']);
        self::assertSame(CampaignStatus::Paused->value, $camp->fresh()->status);
        self::assertSame(CampaignContentStatus::Pending->value, $items[1]->fresh()->status);

        // Resume continues sequential execution with items[1]
        $this->campaigns->resume($camp->campaign_uuid, $this->installationId);
        self::assertSame(CampaignContentStatus::Running->value, $items[1]->fresh()->status);
    }

    public function test_detail_dto_can_retry_eligibility_contract(): void
    {
        $tagA = $this->seedActionTagAndScript('maytuicanvas', 'maytuicanvas.create_post');
        $client = $this->seedClient('maytuicanvas', 'may-acc-3');
        $setup = $this->createV2RunningCampaign([
            ['tag' => $tagA['tag'], 'pkg' => $tagA['pkg'], 'rel' => $tagA['rel'], 'count' => 4],
        ]);
        $camp = $setup['campaign'];
        $items = $setup['contents']['maytuicanvas.create_post'];

        // Before failure: Pending items have can_retry=false
        $dto0 = $this->campaigns->detailDto($camp->fresh());
        foreach ($dto0['contents'] as $row) {
            self::assertFalse($row['can_retry']);
        }

        // Dispatch item 0 -> fails -> blocks gate; FailedLocked item 0 has can_retry=true, Pending items 1..3 have can_retry=false
        $this->orchestrator->dispatchCampaign($camp);
        $this->claimAndFinish($client['agent'], $client['session'], 'failed', 'ORC_ERROR');
        $dtoFailed = $this->campaigns->detailDto($camp->fresh());
        $byIdFailed = collect($dtoFailed['contents'])->keyBy('id');
        self::assertTrue($byIdFailed[$items[0]->content_uuid]['can_retry']);
        self::assertFalse($byIdFailed[$items[1]->content_uuid]['can_retry']);
        self::assertFalse($byIdFailed[$items[2]->content_uuid]['can_retry']);
        self::assertFalse($byIdFailed[$items[3]->content_uuid]['can_retry']);

        // Simulate Content 83 legacy Pending state on blocked gate -> queue-head Pending has can_retry=true, items 1..3 have can_retry=false
        $item0 = $items[0]->fresh();
        $item0->status = CampaignContentStatus::Pending->value;
        $item0->current_job_id = null;
        $item0->last_error_meta = ['manual_recovery_origin' => 'manual_retry', 'idempotency_key' => 'legacy-83'];
        $item0->save();
        $dtoLegacyPending = $this->campaigns->detailDto($camp->fresh());
        $byIdLegacy = collect($dtoLegacyPending['contents'])->keyBy('id');
        self::assertSame('blocked_by_failure', $byIdLegacy[$items[0]->content_uuid]['queue_state']);
        self::assertTrue($byIdLegacy[$items[0]->content_uuid]['can_retry']);
        self::assertFalse($byIdLegacy[$items[1]->content_uuid]['can_retry']);
        self::assertFalse($byIdLegacy[$items[2]->content_uuid]['can_retry']);
        self::assertFalse($byIdLegacy[$items[3]->content_uuid]['can_retry']);

        // Explicit Retry -> recovery Job Running -> ALL items (including 0 and 1..3) have can_retry=false
        $retryResp = $this->contentController->retry(
            Request::create('/', 'POST', ['idempotency_key' => 'retry-dto-1']),
            $items[0]->content_uuid,
        );
        self::assertSame(200, $retryResp->getStatusCode());
        $dtoInFlight = $this->campaigns->detailDto($camp->fresh());
        foreach ($dtoInFlight['contents'] as $row) {
            self::assertFalse($row['can_retry']);
        }
        // Later blocked Content cannot retry while recovery Job is in flight
        $lateRetry = $this->contentController->retry(
            Request::create('/', 'POST', ['idempotency_key' => 'retry-dto-late']),
            $items[1]->content_uuid,
        );
        self::assertSame(422, $lateRetry->getStatusCode());

        // Unknown -> can_retry=true on head, false on others
        $this->claimAndFinish($client['agent'], $client['session'], 'unknown', 'HUMAN_REQUIRED');
        $dtoUnknown = $this->campaigns->detailDto($camp->fresh());
        $byIdUnknown = collect($dtoUnknown['contents'])->keyBy('id');
        self::assertTrue($byIdUnknown[$items[0]->content_uuid]['can_retry']);
        self::assertFalse($byIdUnknown[$items[1]->content_uuid]['can_retry']);
    }
}
