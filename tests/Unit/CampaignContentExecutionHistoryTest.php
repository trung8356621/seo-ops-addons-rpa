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
use Omnichannel\Addons\SocialAutomation\Enums\CampaignContentStatus;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignStatus;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\StaffCampaignContentController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\StaffCampaignController;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaArtifact;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContent;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaJobAttempt;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Orchestration\CampaignOrchestrator;
use Omnichannel\Addons\SocialAutomation\Services\CampaignService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CampaignContentExecutionHistoryTest extends TestCase
{
    private Capsule $capsule;

    private ?Container $previousApp = null;

    private string $root;

    private StaffCampaignContentController $contentController;

    private StaffCampaignController $campaignController;

    private bool $denyAccess = false;

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

        $test = $this;
        $access = new class($test) extends SocialAutomationAccess {
            public function __construct(private readonly CampaignContentExecutionHistoryTest $test) {}

            public function assertCanAccess(?User $user = null): void
            {
                if ($this->test->isAccessDenied()) {
                    throw new HttpException(403, 'Forbidden');
                }
            }

            public function assertCanManageCampaigns(?User $user = null): void {}
        };
        $app->instance(SocialAutomationAccess::class, $access);

        $installation = new class extends SocialAutomationInstallationResolver {
            public function __construct() {}

            public function resolveInstallationId(): string
            {
                return 'inst-history-test';
            }
        };
        $app->instance(SocialAutomationInstallationResolver::class, $installation);
        $app->instance(ResponseFactory::class, $this->makeResponseFactory());

        $this->createBaseSchema();
        $this->runMigration($this->root.'/database/migrations/2026_11_02_150000_create_sa_campaign_tables.php');
        $this->runMigration($this->root.'/database/migrations/2026_11_20_100000_add_campaign_shortcode_v2_columns.php');
        $this->runMigration($this->root.'/database/migrations/2026_11_21_140000_create_sa_campaign_v2_tables.php');
        $this->runMigration($this->root.'/database/migrations/2026_11_23_000000_create_sa_article_library_tables.php');

        $orchestrator = new CampaignOrchestrator;
        $campaigns = new CampaignService($orchestrator);
        $this->contentController = new StaffCampaignContentController($campaigns, $orchestrator, $access, $installation);
        $this->campaignController = new StaffCampaignController($campaigns, $access, $installation);
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

    public function isAccessDenied(): bool
    {
        return $this->denyAccess;
    }

    public function test_per_content_execution_history_across_multiple_attempts_states_and_disabled_script(): void
    {
        // Script is currently disabled — historical records must still be returned cleanly
        SaPackage::query()->create([
            'package_key' => 'wp-create-post',
            'kind' => 'script',
            'name' => 'WP Create Post',
            'is_enabled' => false,
        ]);

        $agent = SaAgent::query()->create([
            'agent_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-history-test',
            'name' => 'client-A',
        ]);
        $account = SaAccount::query()->create([
            'account_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-history-test',
            'platform' => 'baloquatang',
        ]);
        $session = SaBrowserSession::query()->create([
            'session_uuid' => (string) Str::uuid(),
            'agent_id' => $agent->id,
            'account_id' => $account->id,
        ]);

        $campaign = SaCampaign::query()->create([
            'campaign_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-history-test',
            'name' => 'History Audit Campaign',
            'platform' => 'baloquatang',
            'execution_mode' => 'one_account_one_job',
            'run_mode' => 'live',
            'status' => CampaignStatus::Paused->value,
            'plan_version' => 2,
            'script_id' => 'v2:multi-tag',
            'script_version' => '2',
        ]);

        // 1. Failed Locked Content with multiple Attempts across Jobs + Artifact
        $cFailed = SaCampaignContent::query()->create([
            'content_uuid' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'sort_order' => 1,
            'status' => CampaignContentStatus::FailedLocked->value,
            'assigned_account_id' => $account->id,
            'last_error_code' => 'SCROLL_CONTAINER_CHANGED',
            'last_error_message' => 'Scroll container changed before action',
            'input' => ['wp_title' => 'Failed Post'],
        ]);

        $job1 = SaJob::query()->create([
            'job_uuid' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'campaign_content_id' => $cFailed->id,
            'agent_id' => $agent->id,
            'browser_session_id' => $session->id,
            'account_id' => $account->id,
            'package_key' => 'wp-create-post',
            'package_version' => '1.0.0',
            'status' => 'failed',
            'duration_ms' => 4200,
        ]);
        $att1a = SaJobAttempt::query()->create([
            'attempt_uuid' => (string) Str::uuid(),
            'job_id' => $job1->id,
            'attempt_no' => 1,
            'status' => 'failed',
            'agent_id' => $agent->id,
            'browser_session_id' => $session->id,
            'error_code' => 'TIMEOUT',
            'error_message' => 'Timed out waiting selector',
            'progress' => ['step' => 0],
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
        ]);
        $att1b = SaJobAttempt::query()->create([
            'attempt_uuid' => (string) Str::uuid(),
            'job_id' => $job1->id,
            'attempt_no' => 2,
            'status' => 'failed',
            'agent_id' => $agent->id,
            'browser_session_id' => $session->id,
            'error_code' => 'SCROLL_CONTAINER_CHANGED',
            'error_message' => 'Scroll container changed before action',
            'result' => ['failed_step' => 1],
            'started_at' => now()->subMinutes(8),
            'finished_at' => now()->subMinutes(7),
        ]);
        $art1b = SaArtifact::query()->create([
            'artifact_uuid' => (string) Str::uuid(),
            'job_id' => $job1->id,
            'attempt_id' => $att1b->id,
            'kind' => 'screenshot',
            'mime' => 'image/png',
            'size_bytes' => 1234,
            'checksum_sha256' => str_repeat('a', 64),
            'metadata' => ['phase' => 'failed_step', 'screenshot_role' => 'variant_failure'],
        ]);

        // 2. Unknown Content with missing optional error/ended_at/artifacts
        $cUnknown = SaCampaignContent::query()->create([
            'content_uuid' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'sort_order' => 2,
            'status' => CampaignContentStatus::Unknown->value,
            'input' => ['wp_title' => 'Unknown Post'],
        ]);
        $job2 = SaJob::query()->create([
            'job_uuid' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'campaign_content_id' => $cUnknown->id,
            'package_key' => 'wp-create-post',
            'package_version' => '1.0.0',
            'status' => 'unknown',
        ]);
        $att2 = SaJobAttempt::query()->create([
            'attempt_uuid' => (string) Str::uuid(),
            'job_id' => $job2->id,
            'attempt_no' => 1,
            'status' => 'unknown',
            'started_at' => now()->subMinutes(5),
        ]);

        // 3. Waiting Resources Content with NO Jobs/Attempts
        $cWaiting = SaCampaignContent::query()->create([
            'content_uuid' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'sort_order' => 3,
            'status' => CampaignContentStatus::WaitingResources->value,
            'input' => ['wp_title' => 'Waiting Post'],
        ]);

        // Call GET /social-automation/api/campaigns/{id}/contents
        $res = $this->contentController->index($campaign->campaign_uuid);
        self::assertSame(200, $res->getStatusCode());
        $rows = json_decode((string) $res->getContent(), true)['data'];
        self::assertCount(3, $rows);

        // Content 1 (Failed Locked, 2 Attempts in deterministic order)
        self::assertSame($cFailed->content_uuid, $rows[0]['content_uuid']);
        self::assertSame($cFailed->content_uuid, $rows[0]['id']);
        self::assertCount(2, $rows[0]['attempts']);

        self::assertSame($att1a->attempt_uuid, $rows[0]['attempts'][0]['attempt_uuid']);
        self::assertSame(1, $rows[0]['attempts'][0]['attempt_no']);
        self::assertSame(0, $rows[0]['attempts'][0]['step']);
        self::assertSame([], $rows[0]['attempts'][0]['artifacts']);

        self::assertSame($job1->id, $rows[0]['attempts'][1]['job_id']);
        self::assertSame($job1->job_uuid, $rows[0]['attempts'][1]['job_uuid']);
        self::assertSame($att1b->attempt_uuid, $rows[0]['attempts'][1]['attempt_uuid']);
        self::assertSame(2, $rows[0]['attempts'][1]['attempt_no']);
        self::assertSame(1, $rows[0]['attempts'][1]['step']);
        self::assertSame('SCROLL_CONTAINER_CHANGED', $rows[0]['attempts'][1]['error_code']);
        self::assertSame($agent->agent_uuid, $rows[0]['attempts'][1]['agent_uuid']);
        self::assertSame($session->session_uuid, $rows[0]['attempts'][1]['browser_uuid']);
        self::assertCount(1, $rows[0]['attempts'][1]['artifacts']);
        self::assertSame($art1b->artifact_uuid, $rows[0]['attempts'][1]['artifacts'][0]['artifact_uuid']);
        self::assertArrayNotHasKey('url', $rows[0]['attempts'][1]['artifacts'][0]);

        // Content 2 (Unknown, null missing fields, no cross-Content leakage)
        self::assertSame($cUnknown->content_uuid, $rows[1]['content_uuid']);
        self::assertCount(1, $rows[1]['attempts']);
        self::assertSame($job2->job_uuid, $rows[1]['attempts'][0]['job_uuid']);
        self::assertSame($att2->attempt_uuid, $rows[1]['attempts'][0]['attempt_uuid']);
        self::assertSame('unknown', $rows[1]['attempts'][0]['status']);
        self::assertNull($rows[1]['attempts'][0]['error_code']);
        self::assertNull($rows[1]['attempts'][0]['ended_at']);
        self::assertSame([], $rows[1]['attempts'][0]['artifacts']);

        // Content 3 (Waiting Resources, empty attempts — no fake Attempt)
        self::assertSame($cWaiting->content_uuid, $rows[2]['content_uuid']);
        self::assertSame([], $rows[2]['attempts']);

        // Campaign Detail endpoint compatibility also includes content_uuid & attempts
        $detailRes = $this->campaignController->show($campaign->campaign_uuid);
        self::assertSame(200, $detailRes->getStatusCode());
        $detailData = json_decode((string) $detailRes->getContent(), true)['data'];
        self::assertCount(3, $detailData['contents']);
        self::assertCount(2, $detailData['contents'][0]['attempts']);
        self::assertSame([], $detailData['contents'][2]['attempts']);
    }

    public function test_unauthorized_access_rejected_on_contents_history(): void
    {
        $this->denyAccess = true;
        $this->expectException(HttpException::class);
        $this->contentController->index((string) Str::uuid());
    }

    private function createBaseSchema(): void
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;

        Schema::connection($conn)->create('sa_packages', function (Blueprint $table): void {
            $table->id();
            $table->string('package_key', 191)->unique();
            $table->string('kind', 32);
            $table->string('name', 255);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        Schema::connection($conn)->create('sa_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('account_uuid')->unique();
            $table->string('installation_id', 128);
            $table->string('platform', 64);
            $table->timestamps();
        });

        Schema::connection($conn)->create('sa_agents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('agent_uuid')->unique();
            $table->string('installation_id', 128);
            $table->string('name', 191)->nullable();
            $table->timestamps();
        });

        Schema::connection($conn)->create('sa_browser_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('session_uuid')->unique();
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('account_id')->nullable();
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

        Schema::connection($conn)->create('sa_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('artifact_uuid')->unique();
            $table->unsignedBigInteger('job_id');
            $table->unsignedBigInteger('attempt_id')->nullable();
            $table->string('kind', 32);
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum_sha256', 64)->nullable();
            $table->string('storage_disk', 64)->nullable();
            $table->string('storage_path', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('retain_until')->nullable();
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
