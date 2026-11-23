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
use Omnichannel\Addons\SocialAutomation\Enums\CampaignAttemptStatus;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignContentStatus;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignStatus;
use Omnichannel\Addons\SocialAutomation\Enums\JobStatus;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\StaffCampaignController;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaAccountExecutionLease;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaArtifact;
use Omnichannel\Addons\SocialAutomation\Models\SaAuditLog;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContent;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaJobAttempt;
use Omnichannel\Addons\SocialAutomation\Orchestration\CampaignOrchestrator;
use Omnichannel\Addons\SocialAutomation\Services\CampaignContentHistoryService;
use Omnichannel\Addons\SocialAutomation\Services\CampaignService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CampaignSoftDeleteTest extends TestCase
{
    private Capsule $capsule;

    private ?Container $previousApp = null;

    private string $root;

    private StaffCampaignController $controller;

    private CampaignService $campaigns;

    private CampaignOrchestrator $orchestrator;

    private bool $denyAccess = false;

    private bool $denyManage = false;

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
                return 77;
            }
        });

        $test = $this;
        $access = new class($test) extends SocialAutomationAccess {
            public function __construct(private readonly CampaignSoftDeleteTest $test) {}

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
                return 'inst-soft-delete-test';
            }
        };
        $app->instance(SocialAutomationInstallationResolver::class, $installation);
        $app->instance(ResponseFactory::class, $this->makeResponseFactory());

        $this->createBaseSchema();
        $this->runMigration($this->root.'/database/migrations/2026_11_02_150000_create_sa_campaign_tables.php');
        $this->runMigration($this->root.'/database/migrations/2026_11_20_100000_add_campaign_shortcode_v2_columns.php');
        $this->runMigration($this->root.'/database/migrations/2026_11_21_140000_create_sa_campaign_v2_tables.php');
        $this->runMigration($this->root.'/database/migrations/2026_11_23_000000_create_sa_article_library_tables.php');
        $this->runMigration($this->root.'/database/migrations/2026_11_23_030000_add_soft_delete_to_sa_campaigns.php');

        $this->orchestrator = new CampaignOrchestrator;
        $this->campaigns = new CampaignService($this->orchestrator);
        $this->controller = new StaffCampaignController($this->campaigns, $access, $installation);
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

    public function isManageDenied(): bool
    {
        return $this->denyManage;
    }

    public function test_01_cancelled_campaign_with_no_jobs_soft_deletes(): void
    {
        $campaign = $this->createCampaign(CampaignStatus::Cancelled);

        $res = $this->controller->destroy($this->makeStaffDeleteRequest(77), $campaign->campaign_uuid);
        self::assertSame(200, $res->getStatusCode());
        $body = $res->getData(true);
        self::assertTrue($body['ok']);
        self::assertTrue($body['success']);
        self::assertSame($campaign->campaign_uuid, $body['campaign_id']);
        self::assertNotEmpty($body['deleted_at']);

        $fresh = SaCampaign::query()->find($campaign->id);
        self::assertNotNull($fresh?->deleted_at);
        self::assertSame(77, $fresh?->deleted_by_user_id);
        self::assertSame(CampaignStatus::Cancelled->value, $fresh?->status);

        // Repeated delete returns explicit documented conflict
        $repeatRes = $this->controller->destroy($this->makeStaffDeleteRequest(77), $campaign->campaign_uuid);
        self::assertSame(409, $repeatRes->getStatusCode());
        self::assertSame('campaign_already_deleted', $repeatRes->getData(true)['reason']);
    }

    public function test_02_03_04_07_08_09_11_cancelled_campaign_with_unknown_job_active_lease_and_queued_attempt_soft_deletes_without_mutating_evidence(): void
    {
        [$campaign, $content, $job, $attempt, $lease, $artifact, $tag, $agent, $session] = $this->seedCancelledCampaignWithEvidence(
            JobStatus::Unknown,
            CampaignAttemptStatus::Queued,
            'active',
        );

        // Also add a second unknown attempt on the same job
        $attempt2 = SaJobAttempt::query()->create([
            'attempt_uuid' => (string) Str::uuid(),
            'job_id' => $job->id,
            'attempt_no' => 2,
            'status' => CampaignAttemptStatus::Unknown->value,
            'agent_id' => $agent->id,
            'browser_session_id' => $session->id,
            'started_at' => now()->subMinutes(2),
            'finished_at' => null,
        ]);

        // 2, 3, 4. Soft delete succeeds despite Unknown Job, active-status Lease, and queued/unknown Attempts
        $res = $this->controller->destroy($this->makeStaffDeleteRequest(77), $campaign->campaign_uuid);
        self::assertSame(200, $res->getStatusCode());
        $body = $res->getData(true);
        self::assertTrue($body['success']);
        self::assertSame($campaign->campaign_uuid, $body['campaign_id']);

        $fresh = SaCampaign::query()->find($campaign->id);
        self::assertInstanceOf(SaCampaign::class, $fresh);
        self::assertSame(CampaignStatus::Cancelled->value, $fresh->status);
        self::assertNotNull($fresh->deleted_at);
        self::assertSame(77, $fresh->deleted_by_user_id);

        // 9. Audit event recorded
        $audit = SaAuditLog::query()->where('subject_id', $campaign->id)->where('action', 'campaign.soft_deleted')->first();
        self::assertInstanceOf(SaAuditLog::class, $audit);
        self::assertSame(77, (int) $audit->actor_id);

        // 7. Hidden from default list and detail
        $listRes = $this->controller->index();
        self::assertSame(200, $listRes->getStatusCode());
        self::assertCount(0, $listRes->getData(true)['data']);
        self::assertSame(404, $this->controller->show($campaign->campaign_uuid)->getStatusCode());

        // 8. Job, Attempt, Lease, Artifact, Content, ActionTag counts and states completely unchanged
        self::assertSame(1, SaJob::query()->where('campaign_id', $campaign->id)->count());
        self::assertSame(2, SaJobAttempt::query()->where('job_id', $job->id)->count());
        self::assertSame(1, SaAccountExecutionLease::query()->where('job_id', $job->id)->count());
        self::assertSame(1, SaArtifact::query()->where('job_id', $job->id)->count());

        self::assertSame(JobStatus::Unknown->value, SaJob::query()->find($job->id)?->status);
        self::assertSame(CampaignAttemptStatus::Queued->value, SaJobAttempt::query()->find($attempt->id)?->status);
        self::assertSame(CampaignAttemptStatus::Unknown->value, SaJobAttempt::query()->find($attempt2->id)?->status);
        self::assertSame('active', SaAccountExecutionLease::query()->find($lease->id)?->status);
        self::assertNull(SaAccountExecutionLease::query()->find($lease->id)?->released_at);
        self::assertSame($artifact->artifact_uuid, SaArtifact::query()->find($artifact->id)?->artifact_uuid);
        self::assertSame($tag->plan_tag_uuid, SaCampaignActionTag::query()->find($tag->id)?->plan_tag_uuid);

        // 11. Existing history and audit relationships remain available
        $freshContent = SaCampaignContent::query()->find($content->id);
        self::assertSame($campaign->id, $freshContent?->campaign?->id);
        $history = (new CampaignContentHistoryService)->attemptsByContentId($fresh, [$freshContent]);
        self::assertCount(2, $history[$content->id] ?? []);
        self::assertSame($attempt->attempt_uuid, $history[$content->id][0]['attempt_uuid']);
        self::assertSame($attempt2->attempt_uuid, $history[$content->id][1]['attempt_uuid']);

        // Existing start/resume/pause/cancel/update APIs reject deleted Campaigns
        self::assertSame(404, $this->controller->start($campaign->campaign_uuid)->getStatusCode());
        self::assertSame(404, $this->controller->resume($campaign->campaign_uuid)->getStatusCode());
        self::assertSame(404, $this->controller->pause($campaign->campaign_uuid)->getStatusCode());
        self::assertSame(404, $this->controller->cancel($campaign->campaign_uuid)->getStatusCode());
        self::assertSame(404, $this->controller->update(Request::create('/', 'PUT', ['name' => 'Updated']), $campaign->campaign_uuid)->getStatusCode());
    }

    public function test_05_06_non_cancelled_campaigns_rejected(): void
    {
        foreach ([CampaignStatus::Running, CampaignStatus::Paused, CampaignStatus::Draft, CampaignStatus::Completed] as $status) {
            $campaign = $this->createCampaign($status);
            $res = $this->controller->destroy($this->makeStaffDeleteRequest(77), $campaign->campaign_uuid);

            self::assertSame(409, $res->getStatusCode(), "Expected 409 for status {$status->value}");
            $body = $res->getData(true);
            self::assertFalse($body['success']);
            self::assertSame('CAMPAIGN_DELETE_BLOCKED', $body['code']);
            self::assertSame('campaign_not_cancelled', $body['reason']);
            self::assertNull(SaCampaign::query()->find($campaign->id)?->deleted_at);
        }
    }

    public function test_10_unauthorized_and_csrf_protection_enforced(): void
    {
        $campaign = $this->createCampaign(CampaignStatus::Cancelled);

        $this->denyManage = true;
        try {
            $this->controller->destroy($this->makeStaffDeleteRequest(77), $campaign->campaign_uuid);
            self::fail('Expected HttpException 403');
        } catch (HttpException $e) {
            self::assertSame(403, $e->getStatusCode());
        } finally {
            $this->denyManage = false;
        }
        self::assertNull(SaCampaign::query()->find($campaign->id)?->deleted_at);

        $providerSource = file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');
        self::assertIsString($providerSource);
        self::assertStringContainsString('VerifyCsrfToken::class', $providerSource);
        self::assertStringContainsString("Route::delete('/{id}', [StaffCampaignController::class, 'destroy'])", $providerSource);
    }

    public function test_12_no_new_dispatch_or_claim_from_cancelled_or_deleted_campaign(): void
    {
        [$campaign, $content, $job, , , , , $agent, $session] = $this->seedCancelledCampaignWithEvidence(
            JobStatus::Reserved,
            CampaignAttemptStatus::Queued,
            'active',
        );

        // Soft-delete the cancelled campaign
        $res = $this->controller->destroy($this->makeStaffDeleteRequest(77), $campaign->campaign_uuid);
        self::assertSame(200, $res->getStatusCode());

        // Add a pending content row to ensure dispatch/recheck ignores cancelled+deleted campaigns
        SaCampaignContent::query()->create([
            'content_uuid' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'external_ref' => 'ext-pending',
            'sort_order' => 2,
            'status' => CampaignContentStatus::Pending->value,
            'input' => ['title' => 'Should Never Dispatch'],
        ]);

        // Dispatch & scheduler recheck must not touch cancelled/deleted campaign
        $dispatchRes = $this->orchestrator->dispatchCampaign($campaign->fresh());
        self::assertFalse($dispatchRes['ok']);
        self::assertSame(0, $dispatchRes['dispatched']);

        $recheckRes = $this->orchestrator->recheckDueCampaigns();
        self::assertSame(0, $recheckRes['checked']);
        self::assertSame(0, $recheckRes['dispatched']);

        // Claim must NOT claim a leftover Reserved job belonging to a cancelled/deleted campaign
        $claimRes = $this->orchestrator->claimNext(
            $agent->agent_uuid,
            [$session->session_uuid],
            null,
            'inst-soft-delete-test',
        );
        self::assertTrue($claimRes['ok']);
        self::assertNull($claimRes['data']);
        self::assertSame(JobStatus::Reserved->value, SaJob::query()->find($job->id)?->status);
    }

    private function makeStaffDeleteRequest(int $userId): Request
    {
        $req = Request::create('/social-automation/api/campaigns/delete', 'DELETE');
        $req->setUserResolver(static function () use ($userId): object {
            return new class($userId) {
                public function __construct(public int $id) {}
            };
        });

        return $req;
    }

    private function createCampaign(CampaignStatus $status): SaCampaign
    {
        return SaCampaign::query()->create([
            'campaign_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-soft-delete-test',
            'name' => 'Soft Delete Test '.$status->value,
            'platform' => 'wordpress',
            'execution_mode' => 'one_account_one_job',
            'run_mode' => 'live',
            'status' => $status->value,
            'plan_version' => 2,
            'script_id' => 'wp-create-post',
            'script_version' => '1.0.0',
            'resource_recheck_seconds' => 3600,
            'cancelled_at' => $status === CampaignStatus::Cancelled ? now() : null,
        ]);
    }

    /**
     * @return array{0: SaCampaign, 1: SaCampaignContent, 2: SaJob, 3: SaJobAttempt, 4: SaAccountExecutionLease, 5: SaArtifact, 6: SaCampaignActionTag, 7: SaAgent, 8: SaBrowserSession}
     */
    private function seedCancelledCampaignWithEvidence(
        JobStatus $jobStatus,
        CampaignAttemptStatus $attemptStatus,
        string $leaseStatus,
    ): array {
        $campaign = $this->createCampaign(CampaignStatus::Cancelled);

        $account = SaAccount::query()->create([
            'account_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-soft-delete-test',
            'platform' => 'wordpress',
            'username' => 'wp-user-1',
            'status' => 'active',
        ]);
        $agent = SaAgent::query()->create([
            'agent_uuid' => (string) Str::uuid(),
            'installation_id' => 'inst-soft-delete-test',
            'name' => 'Agent 1',
            'status' => 'online',
            'availability' => 'available',
        ]);
        $session = SaBrowserSession::query()->create([
            'session_uuid' => (string) Str::uuid(),
            'agent_id' => $agent->id,
            'account_id' => $account->id,
            'status' => 'ready',
        ]);

        $tag = SaCampaignActionTag::query()->create([
            'plan_tag_uuid' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'action_tag_id' => 1,
            'action_tag_key' => 'wp.create_post',
            'site_key' => 'wp',
            'package_id' => 1,
            'script_id' => 'wp-create-post',
            'release_id' => 1,
            'script_version' => '1.0.0',
            'resolution_source' => 'global',
        ]);

        $content = SaCampaignContent::query()->create([
            'content_uuid' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'external_ref' => 'ext-1',
            'sort_order' => 1,
            'status' => CampaignContentStatus::Success->value,
            'input' => ['title' => 'Preserved Title'],
            'assigned_account_id' => $account->id,
        ]);

        $job = SaJob::query()->create([
            'job_uuid' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'campaign_content_id' => $content->id,
            'agent_id' => $agent->id,
            'browser_session_id' => $session->id,
            'account_id' => $account->id,
            'package_key' => 'wp-create-post',
            'package_version' => '1.0.0',
            'status' => $jobStatus->value,
            'idempotency_key' => (string) Str::uuid(),
            'started_at' => now()->subMinutes(5),
            'finished_at' => $jobStatus->isTerminal() ? now()->subMinutes(1) : null,
        ]);

        $attempt = SaJobAttempt::query()->create([
            'attempt_uuid' => (string) Str::uuid(),
            'job_id' => $job->id,
            'attempt_no' => 1,
            'status' => $attemptStatus->value,
            'agent_id' => $agent->id,
            'browser_session_id' => $session->id,
            'account_id' => $account->id,
            'started_at' => now()->subMinutes(5),
            'finished_at' => $attemptStatus->isTerminal() ? now()->subMinutes(1) : null,
        ]);

        $lease = SaAccountExecutionLease::query()->create([
            'account_id' => $account->id,
            'agent_id' => $agent->id,
            'browser_session_id' => $session->id,
            'job_id' => $job->id,
            'attempt_id' => $attempt->id,
            'lease_token' => (string) Str::uuid(),
            'status' => $leaseStatus,
            'acquired_at' => now()->subMinutes(5),
            'released_at' => $leaseStatus === 'released' ? now()->subMinutes(1) : null,
        ]);

        $artifact = SaArtifact::query()->create([
            'artifact_uuid' => (string) Str::uuid(),
            'job_id' => $job->id,
            'attempt_id' => $attempt->id,
            'kind' => 'screenshot',
            'mime' => 'image/png',
            'size_bytes' => 1234,
            'checksum_sha256' => hash('sha256', 'evidence'),
        ]);

        return [$campaign, $content, $job, $attempt, $lease, $artifact, $tag, $agent, $session];
    }

    private function createBaseSchema(): void
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;
        $schema = Schema::connection($conn);

        $schema->create('sa_agents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('agent_uuid')->unique();
            $table->string('installation_id', 128)->nullable();
            $table->string('name', 128)->nullable();
            $table->string('status', 32)->default('offline');
            $table->string('availability', 32)->default('available');
            $table->unsignedBigInteger('client_api_key_id')->nullable();
            $table->timestamps();
        });

        $schema->create('sa_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('account_uuid')->unique();
            $table->string('installation_id', 128)->nullable();
            $table->string('platform', 64)->nullable();
            $table->string('username', 191)->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();
        });

        $schema->create('sa_browser_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('session_uuid')->unique();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('status', 32)->default('ready');
            $table->timestamps();
        });

        $schema->create('sa_tasks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('task_uuid')->unique();
            $table->string('name', 255)->nullable();
            $table->timestamps();
        });

        $schema->create('sa_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('job_uuid')->unique();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('browser_session_id')->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('package_key', 128)->nullable();
            $table->string('package_version', 64)->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('idempotency_key', 64)->unique();
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        $schema->create('sa_job_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('attempt_uuid')->unique();
            $table->unsignedBigInteger('job_id');
            $table->unsignedInteger('attempt_no')->default(1);
            $table->string('status', 32)->default('started');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('result')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamps();
        });

        $schema->create('sa_account_execution_leases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('browser_session_id')->nullable();
            $table->unsignedBigInteger('job_id');
            $table->string('status', 32)->default('active');
            $table->timestamp('acquired_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });

        $schema->create('sa_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('artifact_uuid')->unique();
            $table->unsignedBigInteger('job_id')->nullable();
            $table->unsignedBigInteger('attempt_id')->nullable();
            $table->string('kind', 64);
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum_sha256', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        $schema->create('sa_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type', 64)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 128);
            $table->string('subject_type', 128)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->nullable();
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
            public function make($content = '', $status = 200, array $headers = []) { return new JsonResponse($content, $status, $headers); }
            public function noContent($status = 204, array $headers = []) { return new JsonResponse(null, $status, $headers); }
            public function view($view, $data = [], $status = 200, array $headers = []) { return new JsonResponse($data, $status, $headers); }
            public function json($data = [], $status = 200, array $headers = [], $options = 0) { return new JsonResponse($data, $status, $headers, $options); }
            public function jsonp($callback, $data = [], $status = 200, array $headers = [], $options = 0) { return new JsonResponse($data, $status, $headers, $options); }
            public function stream($callback, $status = 200, array $headers = []) { return new JsonResponse(null, $status, $headers); }
            public function streamJson($data, $status = 200, $headers = [], $encodingOptions = 15) { return new JsonResponse($data, $status, $headers); }
            public function streamDownload($callback, $name = null, array $headers = [], $disposition = 'attachment') { return new JsonResponse(null, 200, $headers); }
            public function download($file, $name = null, array $headers = [], $disposition = 'attachment') { return new JsonResponse(null, 200, $headers); }
            public function file($file, array $headers = []) { return new JsonResponse(null, 200, $headers); }
            public function redirectTo($path, $status = 302, $headers = [], $secure = null) { return new JsonResponse(null, $status, $headers); }
            public function redirectToRoute($route, $parameters = [], $status = 302, $headers = []) { return new JsonResponse(null, $status, $headers); }
            public function redirectToAction($action, $parameters = [], $status = 302, $headers = []) { return new JsonResponse(null, $status, $headers); }
            public function redirectGuest($path, $status = 302, $headers = [], $secure = null) { return new JsonResponse(null, $status, $headers); }
            public function redirectToIntended($default = '/', $status = 302, $headers = [], $secure = null) { return new JsonResponse(null, $status, $headers); }
        };
    }
}
