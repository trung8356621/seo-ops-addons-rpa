<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use App\Models\User;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignContentStatus;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignStatus;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ClientCampaignJobController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\StaffCampaignController;
use Omnichannel\Addons\SocialAutomation\Http\Middleware\EnsureRpaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaAccountExecutionLease;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaArtifact;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContent;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaJobAttempt;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaSessionAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Orchestration\CampaignOrchestrator;
use Omnichannel\Addons\SocialAutomation\Services\CampaignService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CampaignResumeHistoryArtifactTest extends TestCase
{
    private Capsule $capsule;

    private ?Container $previousApp = null;

    private ?Container $previousContainer = null;

    private string $tempStorageDir;

    private CampaignOrchestrator $orchestrator;

    private CampaignService $campaigns;

    private ClientCampaignJobController $clientJobs;

    private StaffCampaignController $staffCampaigns;

    private string $installationId = 'inst-resume-test';

    private bool $canAccess = true;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite unavailable');
        }

        $this->previousApp = Facade::getFacadeApplication() instanceof Container
            ? Facade::getFacadeApplication()
            : null;
        $this->previousContainer = Container::getInstance();

        $this->tempStorageDir = sys_get_temp_dir().'/sa_resume_art_'.bin2hex(random_bytes(6));
        @mkdir($this->tempStorageDir, 0777, true);

        $app = new Container;
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $app->instance('config', new class($this->tempStorageDir) implements \ArrayAccess
        {
            /** @var array<string, mixed> */
            private array $items;

            public function __construct(string $root)
            {
                $this->items = [
                    'app.timezone' => 'UTC',
                    'filesystems.default' => 'local',
                    'filesystems.disks.local' => ['driver' => 'local', 'root' => $root],
                ];
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->items[$key] ?? $default;
            }

            public function offsetExists(mixed $offset): bool
            {
                return array_key_exists((string) $offset, $this->items);
            }

            public function offsetGet(mixed $offset): mixed
            {
                return $this->items[(string) $offset] ?? null;
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
                $this->items[(string) $offset] = $value;
            }

            public function offsetUnset(mixed $offset): void
            {
                unset($this->items[(string) $offset]);
            }
        });

        $app->singleton('files', fn () => new \Illuminate\Filesystem\Filesystem);
        $app->singleton('filesystem', fn ($c) => new FilesystemManager($c));
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
        $app->instance(ResponseFactory::class, new class implements ResponseFactory
        {
            public function make($content = '', $status = 200, array $headers = [])
            {
                return new \Illuminate\Http\Response($content, $status, $headers);
            }

            public function noContent($status = 204, array $headers = [])
            {
                return new \Illuminate\Http\Response('', $status, $headers);
            }

            public function view($view, $data = [], $status = 200, array $headers = [])
            {
                return new \Illuminate\Http\Response('', $status, $headers);
            }

            public function json($data = [], $status = 200, array $headers = [], $options = 0)
            {
                return new \Illuminate\Http\JsonResponse($data, $status, $headers, $options);
            }

            public function jsonp($callback, $data = [], $status = 200, array $headers = [], $options = 0)
            {
                return new \Illuminate\Http\JsonResponse($data, $status, $headers, $options);
            }

            public function stream($callback, $status = 200, array $headers = [])
            {
                return new StreamedResponse($callback, $status, $headers);
            }

            public function streamJson($data, $status = 200, $headers = [], $encodingOptions = 15)
            {
                return new \Illuminate\Http\JsonResponse($data, $status, $headers);
            }

            public function streamDownload($callback, $name = null, array $headers = [], $disposition = 'attachment')
            {
                return new StreamedResponse($callback, 200, $headers);
            }

            public function download($file, $name = null, array $headers = [], $disposition = 'attachment')
            {
                return new BinaryFileResponse($file, 200, $headers);
            }

            public function file($file, array $headers = [])
            {
                return new BinaryFileResponse($file, 200, $headers);
            }

            public function redirectTo($path, $status = 302, $headers = [], $secure = null)
            {
                return new \Illuminate\Http\RedirectResponse($path, $status, $headers);
            }

            public function redirectToRoute($route, $parameters = [], $status = 302, $headers = [])
            {
                return new \Illuminate\Http\RedirectResponse('/', $status, $headers);
            }

            public function redirectToAction($action, $parameters = [], $status = 302, $headers = [])
            {
                return new \Illuminate\Http\RedirectResponse('/', $status, $headers);
            }

            public function redirectGuest($path, $status = 302, $headers = [], $secure = null)
            {
                return new \Illuminate\Http\RedirectResponse($path, $status, $headers);
            }

            public function redirectToIntended($default = '/', $status = 302, $headers = [], $secure = null)
            {
                return new \Illuminate\Http\RedirectResponse($default, $status, $headers);
            }
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

        $this->createSchema();

        $this->orchestrator = new CampaignOrchestrator;
        $this->campaigns = new CampaignService($this->orchestrator);
        $this->clientJobs = new ClientCampaignJobController($this->orchestrator);

        $access = new class($this) extends SocialAutomationAccess
        {
            public function __construct(private readonly CampaignResumeHistoryArtifactTest $t) {}

            public function assertCanAccess(?User $user = null): void
            {
                if (! $this->t->canAccessFlag()) {
                    throw new \RuntimeException('forbidden');
                }
            }

            public function assertCanManageCampaigns(?User $user = null): void
            {
                $this->assertCanAccess($user);
            }
        };
        $inst = new class($this) extends SocialAutomationInstallationResolver
        {
            public function __construct(private readonly CampaignResumeHistoryArtifactTest $t) {}

            public function resolveInstallationId(): string
            {
                return $this->t->currentInstallationId();
            }
        };

        $this->staffCampaigns = new StaffCampaignController($this->campaigns, $access, $inst);
    }

    public function canAccessFlag(): bool
    {
        return $this->canAccess;
    }

    public function currentInstallationId(): string
    {
        return $this->installationId;
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tempStorageDir);
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

        $s->create('sa_client_api_keys', function (Blueprint $t): void {
            $t->id();
            $t->string('installation_id', 128);
            $t->string('name', 128)->nullable();
            $t->string('key_hash', 64)->nullable();
            $t->timestamp('revoked_at')->nullable();
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
            $t->unsignedInteger('max_concurrency')->default(1);
            $t->unsignedInteger('active_jobs')->default(0);
            $t->string('runtime_version', 64)->nullable();
            $t->timestamp('last_heartbeat_at')->nullable();
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
        $s->create('sa_sites', function (Blueprint $t): void {
            $t->id();
            $t->string('site_key', 64)->unique();
            $t->string('name', 128);
            $t->string('base_url', 255)->nullable();
            $t->boolean('is_enabled')->default(true);
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
        $s->create('sa_packages', function (Blueprint $t): void {
            $t->id();
            $t->string('package_key', 128)->unique();
            $t->string('kind', 32);
            $t->string('name', 255);
            $t->boolean('is_enabled')->default(true);
            $t->timestamps();
        });
        $s->create('sa_package_releases', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('package_id');
            $t->string('version', 64);
            $t->string('content_digest', 64);
            $t->json('manifest');
            $t->string('status', 32)->default('published');
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });
        $s->create('sa_action_tags', function (Blueprint $t): void {
            $t->id();
            $t->string('tag_key', 128)->unique();
            $t->string('site_key', 64)->nullable();
            $t->string('path_key', 64)->nullable();
            $t->string('action_key', 64)->nullable();
            $t->string('platform', 64)->nullable();
            $t->string('target', 64)->nullable();
            $t->string('action', 64)->nullable();
            $t->boolean('is_enabled')->default(true);
            $t->timestamps();
        });
        $s->create('sa_campaigns', function (Blueprint $t): void {
            $t->id();
            $t->string('campaign_uuid', 64)->unique();
            $t->string('installation_id', 128);
            $t->unsignedInteger('plan_version')->default(1);
            $t->string('name', 255);
            $t->string('platform', 64)->default('facebook');
            $t->string('status', 32)->default('draft');
            $t->string('execution_mode', 64)->default('one_account_one_job');
            $t->string('run_mode', 32)->default('live');
            $t->string('script_id', 128)->nullable();
            $t->string('script_version', 64)->nullable();
            $t->string('script_content_digest', 64)->nullable();
            $t->unsignedInteger('resource_recheck_seconds')->default(60);
            $t->json('meta')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('paused_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('next_recheck_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->unsignedBigInteger('deleted_by_user_id')->nullable();
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
        $s->create('sa_campaign_account_assignments', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('campaign_id');
            $t->unsignedBigInteger('account_id');
            $t->unsignedBigInteger('content_id')->nullable();
            $t->string('reason', 64)->nullable();
            $t->timestamps();
        });
        $s->create('sa_tasks', function (Blueprint $t): void {
            $t->id();
            $t->string('task_uuid', 64)->nullable();
            $t->string('status', 32)->default('active');
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
        $s->create('sa_artifacts', function (Blueprint $t): void {
            $t->id();
            $t->string('artifact_uuid', 64)->unique();
            $t->unsignedBigInteger('job_id');
            $t->unsignedBigInteger('attempt_id')->nullable();
            $t->string('kind', 32);
            $t->string('mime', 128)->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->string('storage_disk', 64)->default('local');
            $t->string('storage_path', 512)->nullable();
            $t->string('checksum_sha256', 64)->nullable();
            $t->json('metadata')->nullable();
            $t->timestamp('retain_until')->nullable();
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

    /**
     * @return array{key: SaClientApiKey, agent: SaAgent, session: SaBrowserSession, account: SaAccount, campaign: SaCampaign}
     */
    private function seedEnvironment(bool $agentOnline = true): array
    {
        $key = SaClientApiKey::query()->create([
            'installation_id' => $this->installationId,
            'name' => 'Electron Test',
            'key_hash' => hash('sha256', 'test-key'),
        ]);
        $agent = SaAgent::query()->create([
            'agent_uuid' => (string) Str::uuid(),
            'installation_id' => $this->installationId,
            'client_api_key_id' => $key->id,
            'agent_kind' => 'client',
            'availability' => $agentOnline ? 'online' : 'offline',
            'last_heartbeat_at' => $agentOnline ? now() : now()->subHours(2),
        ]);
        $session = SaBrowserSession::query()->create([
            'session_uuid' => (string) Str::uuid(),
            'agent_id' => $agent->id,
            'ready_status' => 'ready',
            'status' => 'ready',
            'execution_lock_state' => 'unlocked',
        ]);
        $account = SaAccount::query()->create([
            'account_uuid' => (string) Str::uuid(),
            'installation_id' => $this->installationId,
            'platform' => 'facebook',
            'domain' => 'facebook.com',
            'platform_account_id' => 'fb-1001',
            'verified' => true,
            'identity_source' => 'browser',
            'username' => 'acc1',
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
        $pkg = SaPackage::query()->create([
            'package_key' => 'fb.post',
            'kind' => 'script',
            'name' => 'FB Post',
            'is_enabled' => true,
        ]);
        $digest = str_repeat('a', 64);
        SaPackageRelease::query()->create([
            'package_id' => $pkg->id,
            'version' => '1',
            'content_digest' => $digest,
            'manifest' => ['script_id' => 'fb.post', 'variables' => [], 'steps' => [['id' => 's1', 'action' => 'CLICK']]],
            'status' => 'published',
            'published_at' => now(),
        ]);
        $campaign = SaCampaign::query()->create([
            'campaign_uuid' => (string) Str::uuid(),
            'installation_id' => $this->installationId,
            'plan_version' => 1,
            'name' => 'Camp 1',
            'platform' => 'facebook',
            'status' => 'draft',
            'script_id' => 'fb.post',
            'script_version' => '1',
            'script_content_digest' => $digest,
            'resource_recheck_seconds' => 60,
        ]);

        return ['key' => $key, 'agent' => $agent, 'session' => $session, 'account' => $account, 'campaign' => $campaign];
    }

    public function test_1_2_3_4_5_6_resume_scheduler_waiting_resources_idempotency_and_safety(): void
    {
        // Start offline -> content becomes waiting_resources
        $env = $this->seedEnvironment(agentOnline: false);
        $campaign = $env['campaign'];

        $cPending = SaCampaignContent::query()->create([
            'content_uuid' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'sort_order' => 1,
            'title' => 'Article 1',
            'input' => ['text' => 'hello'],
            'status' => CampaignContentStatus::Pending->value,
        ]);
        $cUnknown = SaCampaignContent::query()->create([
            'content_uuid' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'sort_order' => 2,
            'title' => 'Unknown Article',
            'status' => CampaignContentStatus::Unknown->value,
        ]);
        $cFailed = SaCampaignContent::query()->create([
            'content_uuid' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'sort_order' => 3,
            'title' => 'Failed Article',
            'status' => CampaignContentStatus::FailedLocked->value,
        ]);

        // Existing lease on another account must stay protected
        $otherAccount = SaAccount::query()->create([
            'account_uuid' => (string) Str::uuid(),
            'installation_id' => $this->installationId,
            'platform' => 'facebook',
            'username' => 'acc_leased',
            'status' => 'active',
            'lock_state' => 'locked',
        ]);
        $protectedLease = SaAccountExecutionLease::query()->create([
            'account_id' => $otherAccount->id,
            'job_id' => 9999,
            'lease_token' => 'lease-keep',
            'status' => 'active',
            'expires_at' => now()->addMinutes(5),
        ]);

        $started = $this->campaigns->start($campaign->campaign_uuid, $this->installationId);
        self::assertTrue($started['ok'], json_encode($started));
        self::assertContains($cPending->fresh()->status, [
            CampaignContentStatus::Pending->value,
            CampaignContentStatus::WaitingResources->value,
        ]);
        self::assertNotNull($campaign->fresh()->next_recheck_at);

        // 1. Pause -> Resume restores scheduler eligibility
        $paused = $this->campaigns->pause($campaign->campaign_uuid, $this->installationId);
        self::assertTrue($paused['ok']);
        self::assertSame(CampaignStatus::Paused->value, $campaign->fresh()->status);

        // Bring agent online, then Resume -> dispatches waiting_resources
        $env['agent']->update(['availability' => 'online', 'last_heartbeat_at' => now()]);
        $resumed = $this->campaigns->resume($campaign->campaign_uuid, $this->installationId);
        self::assertTrue($resumed['ok']);
        self::assertSame(CampaignStatus::Running->value, $campaign->fresh()->status);
        self::assertSame(1, SaJob::query()->where('campaign_id', $campaign->id)->count());

        // 3. Repeated Resume does not duplicate Jobs
        $resumedAgain = $this->campaigns->resume($campaign->campaign_uuid, $this->installationId);
        self::assertTrue($resumedAgain['ok']);
        self::assertSame(1, SaJob::query()->where('campaign_id', $campaign->id)->count());

        // 4. Unknown and FailedLocked never retried implicitly
        self::assertSame(CampaignContentStatus::Unknown->value, $cUnknown->fresh()->status);
        self::assertSame(CampaignContentStatus::FailedLocked->value, $cFailed->fresh()->status);

        // 6. Existing leases remain protected
        self::assertSame('active', $protectedLease->fresh()->status);

        // 5. Cancelled and soft-deleted Campaigns do not dispatch
        $cancelled = $this->campaigns->cancel($campaign->campaign_uuid, $this->installationId);
        self::assertTrue($cancelled['ok']);
        $resumeCancelled = $this->campaigns->resume($campaign->campaign_uuid, $this->installationId);
        self::assertFalse($resumeCancelled['ok']);
        self::assertSame(['invalid_campaign_status'], $resumeCancelled['errors']);

        $campaign->update(['deleted_at' => now()]);
        $recheck = $this->orchestrator->recheckDueCampaigns(now());
        self::assertSame(0, $recheck['dispatched']);
    }

    public function test_7_8_9_10_11_12_result_history_and_authenticated_screenshot_upload_retrieval(): void
    {
        $env = $this->seedEnvironment(agentOnline: true);
        $campaign = $env['campaign'];

        SaCampaignContent::query()->create([
            'content_uuid' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'sort_order' => 1,
            'title' => 'Article Success',
            'input' => ['text' => 'hello'],
            'status' => CampaignContentStatus::Pending->value,
        ]);

        $started = $this->campaigns->start($campaign->campaign_uuid, $this->installationId);
        self::assertTrue($started['ok'], json_encode($started));

        $claim = $this->orchestrator->claimNext(
            $env['agent']->agent_uuid,
            [$env['session']->session_uuid],
            $env['key']->id,
            $this->installationId,
        );
        self::assertTrue($claim['ok'], json_encode($claim));
        $jobUuid = (string) $claim['data']['job_id'];
        $attemptUuid = (string) $claim['data']['attempt_id'];
        $leaseToken = (string) $claim['data']['lease_token'];

        // 9. Screenshot upload associates with correct Job & Attempt
        $pngBytes = "\x89PNG\r\n\x1a\nfake-png-evidence-bytes";
        $uploadReq = Request::create('/api/v1/social-automation/client/jobs/'.$jobUuid.'/artifacts', 'POST', [
            'attempt_uuid' => $attemptUuid,
            'kind' => 'screenshot',
            'mime' => 'image/png',
            'outcome' => 'success',
            'phase' => 'final',
            'screenshot_role' => 'final_result',
            'variant_id' => 'var-en',
            'step' => 'final',
            'content_base64' => base64_encode($pngBytes),
        ]);
        $uploadReq->attributes->set(EnsureRpaClientApiKey::REQUEST_CLIENT_KEY, $env['key']);
        $uploadResp = $this->clientJobs->uploadArtifact($uploadReq, $jobUuid);
        self::assertSame(201, $uploadResp->getStatusCode());
        $uploadData = $uploadResp->getData(true);
        self::assertTrue($uploadData['ok']);
        self::assertFalse($uploadData['idempotent']);
        $artifactUuid = $uploadData['artifact']['artifact_uuid'];

        // 12. Duplicate artifact upload is idempotent (no duplicate DB row)
        $dupResp = $this->clientJobs->uploadArtifact($uploadReq, $jobUuid);
        self::assertSame(200, $dupResp->getStatusCode());
        self::assertTrue($dupResp->getData(true)['idempotent']);
        self::assertSame(1, SaArtifact::query()->count());

        // 7 & 8. Job Success updates Campaign statistics & Attempt History
        $resultRes = $this->orchestrator->result($jobUuid, [
            'attempt_id' => $attemptUuid,
            'lease_token' => $leaseToken,
            'status' => 'succeeded',
            'step_count' => 5,
            'duration_ms' => 1420,
            'artifacts' => [[
                'artifact_uuid' => $artifactUuid,
                'kind' => 'screenshot',
                'mime' => 'image/png',
                'outcome' => 'success',
                'phase' => 'final',
            ]],
        ], $env['key']->id, $this->installationId);
        self::assertTrue($resultRes['ok'], json_encode($resultRes));

        $detail = $this->campaigns->detailDto($campaign->fresh());
        self::assertSame(1, $detail['summary']['success']);
        self::assertSame(CampaignStatus::Completed->value, $detail['status']);
        $history = $detail['contents'][0]['attempts'];
        self::assertNotEmpty($history);
        $terminalAttempt = end($history);
        self::assertSame('succeeded', $terminalAttempt['status']);
        self::assertSame(5, $terminalAttempt['step_count']);
        self::assertSame(1420, $terminalAttempt['duration_ms']);
        self::assertCount(1, $terminalAttempt['artifacts']);
        self::assertTrue($terminalAttempt['artifacts'][0]['has_binary']);
        self::assertSame('/social-automation/api/campaigns/artifacts/'.$artifactUuid, $terminalAttempt['artifacts'][0]['download_url']);

        // 10. Staff can retrieve authorized screenshot
        $dlResp = $this->staffCampaigns->downloadArtifact($artifactUuid);
        self::assertNotInstanceOf(\Illuminate\Http\JsonResponse::class, $dlResp);
        self::assertSame(200, $dlResp->getStatusCode());
        self::assertSame('image/png', $dlResp->headers->get('Content-Type'));
        self::assertSame('nosniff', $dlResp->headers->get('X-Content-Type-Options'));

        // 11. Unauthorized access rejected:
        // (a) Foreign installation cannot download
        $this->installationId = 'other-installation';
        $foreignDl = $this->staffCampaigns->downloadArtifact($artifactUuid);
        self::assertSame(404, $foreignDl->getStatusCode());
        $this->installationId = 'inst-resume-test';

        // (b) Path traversal / non-UUID rejected
        $badUuid = $this->staffCampaigns->downloadArtifact('../etc/passwd');
        self::assertSame(404, $badUuid->getStatusCode());

        // (c) Unauthenticated Client upload rejected (401)
        $unauthReq = Request::create('/api/v1/social-automation/client/jobs/'.$jobUuid.'/artifacts', 'POST', [
            'content_base64' => base64_encode($pngBytes),
        ]);
        $unauthResp = $this->clientJobs->uploadArtifact($unauthReq, $jobUuid);
        self::assertSame(401, $unauthResp->getStatusCode());

        // (d) Staff gate rejection
        $this->canAccess = false;
        $this->expectException(\RuntimeException::class);
        $this->staffCampaigns->downloadArtifact($artifactUuid);
    }

    private function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if (! is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? $this->deleteDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
