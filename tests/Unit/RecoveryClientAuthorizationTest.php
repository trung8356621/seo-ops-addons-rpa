<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Domain\RecoveryClientAuthorization;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ClientRecoveryController;
use Omnichannel\Addons\SocialAutomation\Http\Middleware\EnsureRpaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaArtifact;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaJobAttempt;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaRecoveryErrorTag;
use Omnichannel\Addons\SocialAutomation\Models\SaRecoveryVisualTemplate;
use Omnichannel\Addons\SocialAutomation\Services\RecoveryRegistryService;
use Omnichannel\Addons\SocialAutomation\Services\RecoveryRuntimeAssetService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use PHPUnit\Framework\TestCase;

final class RecoveryClientAuthorizationTest extends TestCase
{
    private Capsule $capsule;

    private ?Container $previousApp = null;

    private ?Container $previousContainer = null;

    private ClientRecoveryController $controller;

    private string $tempDiskDir;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite unavailable');
        }

        $this->previousApp = Facade::getFacadeApplication() instanceof Container ? Facade::getFacadeApplication() : null;
        $this->previousContainer = Container::getInstance();

        $app = new Container;
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $this->tempDiskDir = sys_get_temp_dir().'/sa_rec_auth_'.Str::random(8);
        @mkdir($this->tempDiskDir, 0777, true);
        Storage::swap(new \Illuminate\Filesystem\FilesystemManager($app));
        $app->instance('config', new \Illuminate\Config\Repository([
            'filesystems' => [
                'default' => 'local',
                'disks' => [
                    'local' => ['driver' => 'local', 'root' => $this->tempDiskDir],
                ],
            ],
        ]));
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

        $this->capsule = new Capsule;
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'default');
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'mysql');
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], SocialAutomationServiceConfig::CONNECTION);
        $this->capsule->setEventDispatcher(new Dispatcher($app));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        $app->instance('db', $this->capsule->getDatabaseManager());

        $this->createSchema();

        $this->controller = new ClientRecoveryController(
            new RecoveryRegistryService,
            new RecoveryRuntimeAssetService,
            new RecoveryClientAuthorization,
        );
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousApp);
        Container::setInstance($this->previousContainer);
        @array_map('unlink', glob($this->tempDiskDir.'/*') ?: []);
        @rmdir($this->tempDiskDir);
        parent::tearDown();
    }

    private function createSchema(): void
    {
        $def = $this->capsule->schema('mysql');
        $def->create('users', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('role', 32)->default('staff');
            $t->string('status', 32)->default('normal');
            $t->boolean('is_system')->default(false);
            $t->softDeletes();
            $t->timestamps();
        });

        $s = $this->capsule->schema(SocialAutomationServiceConfig::CONNECTION);
        $s->create('sa_client_api_keys', function (Blueprint $t): void {
            $t->id();
            $t->string('installation_id', 128);
            $t->string('name', 191);
            $t->string('key_prefix', 32)->nullable();
            $t->string('key_hash', 255)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('issued_by')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
        });
        $s->create('sa_agents', function (Blueprint $t): void {
            $t->id();
            $t->string('agent_uuid', 64);
            $t->string('installation_id', 128);
            $t->unsignedBigInteger('client_api_key_id')->nullable();
            $t->string('availability', 32)->default('available');
            $t->timestamps();
        });
        $s->create('sa_packages', function (Blueprint $t): void {
            $t->id();
            $t->string('package_key', 128);
            $t->string('kind', 32)->default('script');
            $t->string('name', 255);
            $t->boolean('is_enabled')->default(true);
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->timestamps();
        });
        $s->create('sa_package_releases', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('package_id');
            $t->string('version', 32);
            $t->string('content_digest', 64);
            $t->string('execution_digest', 64)->nullable();
            $t->json('runtime_compatibility')->nullable();
            $t->json('manifest')->nullable();
            $t->string('status', 32)->default('published');
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->timestamps();
        });
        $s->create('sa_campaigns', function (Blueprint $t): void {
            $t->id();
            $t->string('campaign_uuid', 64);
            $t->string('installation_id', 128);
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->string('status', 32)->default('running');
            $t->timestamps();
        });
        $s->create('sa_jobs', function (Blueprint $t): void {
            $t->id();
            $t->string('job_uuid', 64);
            $t->unsignedBigInteger('campaign_id')->nullable();
            $t->unsignedBigInteger('agent_id')->nullable();
            $t->string('status', 32)->default('failed');
            $t->timestamps();
        });
        $s->create('sa_job_attempts', function (Blueprint $t): void {
            $t->id();
            $t->string('attempt_uuid', 64);
            $t->unsignedBigInteger('job_id');
            $t->string('lease_token', 64)->nullable();
            $t->string('status', 32)->default('failed');
            $t->timestamps();
        });
        $s->create('sa_artifacts', function (Blueprint $t): void {
            $t->id();
            $t->string('artifact_uuid', 64);
            $t->unsignedBigInteger('attempt_id');
            $t->string('kind', 32)->default('screenshot');
            $t->string('mime', 64)->default('image/png');
            $t->string('checksum_sha256', 64);
            $t->string('storage_disk', 32)->default('local');
            $t->string('storage_path', 255);
            $t->timestamps();
        });
        $s->create('sa_recovery_error_tags', function (Blueprint $t): void {
            $t->id();
            $t->string('error_tag_uuid', 64);
            $t->unsignedBigInteger('owner_user_id');
            $t->string('installation_id', 128);
            $t->string('name', 191);
            $t->string('description', 255)->nullable();
            $t->string('platform', 64);
            $t->string('site_scope', 128)->nullable();
            $t->boolean('is_enabled')->default(true);
            $t->unsignedBigInteger('handler_package_id')->nullable();
            $t->unsignedBigInteger('handler_release_id')->nullable();
            $t->json('recovery_policy')->nullable();
            $t->unsignedInteger('max_attempts_per_step')->default(1);
            $t->timestamps();
        });
        $s->create('sa_recovery_visual_templates', function (Blueprint $t): void {
            $t->id();
            $t->string('template_uuid', 64);
            $t->unsignedBigInteger('error_tag_id');
            $t->unsignedBigInteger('source_attempt_id');
            $t->unsignedBigInteger('source_artifact_id');
            $t->json('modal_roi');
            $t->json('ocr_anchors')->nullable();
            $t->json('target_roi')->nullable();
            $t->json('recognition_config')->nullable();
            $t->decimal('match_threshold', 5, 4)->default(0.85);
            $t->unsignedInteger('version')->default(1);
            $t->boolean('is_enabled')->default(true);
            $t->timestamps();
        });
        $s->create('sa_recovery_results', function (Blueprint $t): void {
            $t->id();
            $t->string('recovery_uuid', 64);
            $t->unsignedBigInteger('attempt_id');
            $t->unsignedBigInteger('error_tag_id');
            $t->unsignedBigInteger('visual_template_id');
            $t->unsignedBigInteger('handler_release_id');
            $t->unsignedBigInteger('source_artifact_id');
            $t->string('failed_step_id', 64);
            $t->unsignedInteger('recovery_attempt_no');
            $t->decimal('match_confidence', 5, 4);
            $t->timestamp('handler_started_at')->nullable();
            $t->timestamp('handler_finished_at')->nullable();
            $t->string('verification_outcome', 32);
            $t->string('final_job_outcome', 32)->nullable();
            $t->json('runtime_input')->nullable();
            $t->json('evidence')->nullable();
            $t->string('idempotency_key', 128)->unique();
            $t->timestamps();
        });
    }

    public function test_staff_delegated_recovery_authorization_and_isolation_matrix(): void
    {
        $conn = $this->capsule->getConnection('mysql');
        $conn->table('users')->insert([
            ['id' => 2, 'parent_id' => null, 'name' => 'Manager 2', 'role' => 'owner', 'status' => 'normal', 'is_system' => false],
            ['id' => 9, 'parent_id' => 2, 'name' => 'Staff 9', 'role' => 'staff', 'status' => 'normal', 'is_system' => false],
            ['id' => 10, 'parent_id' => 99, 'name' => 'Other Staff 10', 'role' => 'staff', 'status' => 'normal', 'is_system' => false],
            ['id' => 99, 'parent_id' => null, 'name' => 'Other Owner 99', 'role' => 'owner', 'status' => 'normal', 'is_system' => false],
        ]);

        $ins = 'ins_qa_1';
        $keyStaff9 = SaClientApiKey::query()->create(['id' => 11, 'installation_id' => $ins, 'name' => 'staff client 9', 'created_by' => 9, 'issued_by' => 2]);
        $keyOtherStaff = SaClientApiKey::query()->create(['id' => 12, 'installation_id' => $ins, 'name' => 'other staff', 'created_by' => 10, 'issued_by' => 2]);
        $keyCrossIns = SaClientApiKey::query()->create(['id' => 13, 'installation_id' => 'ins_other', 'name' => 'cross ins', 'created_by' => 9, 'issued_by' => 2]);
        $keyManager2 = SaClientApiKey::query()->create(['id' => 14, 'installation_id' => $ins, 'name' => 'owner 2 key', 'created_by' => 2, 'issued_by' => 2]);

        $agent5 = SaAgent::query()->create(['agent_uuid' => (string) Str::uuid(), 'installation_id' => $ins, 'client_api_key_id' => $keyStaff9->id, 'availability' => 'available']);
        SaAgent::query()->create(['agent_uuid' => (string) Str::uuid(), 'installation_id' => $ins, 'client_api_key_id' => $keyOtherStaff->id, 'availability' => 'available']);
        SaAgent::query()->create(['agent_uuid' => (string) Str::uuid(), 'installation_id' => 'ins_other', 'client_api_key_id' => $keyCrossIns->id, 'availability' => 'available']);
        SaAgent::query()->create(['agent_uuid' => (string) Str::uuid(), 'installation_id' => $ins, 'client_api_key_id' => $keyManager2->id, 'availability' => 'available']);

        $pngBytes = 'fake-png-bytes';
        $sha = hash('sha256', $pngBytes);
        file_put_contents($this->tempDiskDir.'/shot.png', $pngBytes);

        $camp = SaCampaign::query()->create(['campaign_uuid' => (string) Str::uuid(), 'installation_id' => $ins, 'created_by_user_id' => 2]);
        $job = SaJob::query()->create(['job_uuid' => (string) Str::uuid(), 'campaign_id' => $camp->id, 'agent_id' => $agent5->id, 'status' => 'failed']);
        $attempt = SaJobAttempt::query()->create(['attempt_uuid' => (string) Str::uuid(), 'job_id' => $job->id, 'lease_token' => 'lease_tok_9', 'status' => 'failed']);
        $artifact = SaArtifact::query()->create(['artifact_uuid' => (string) Str::uuid(), 'attempt_id' => $attempt->id, 'kind' => 'screenshot', 'mime' => 'image/png', 'checksum_sha256' => $sha, 'storage_disk' => 'local', 'storage_path' => 'shot.png']);

        $pkg = SaPackage::query()->create(['package_key' => 'rec-handler', 'kind' => 'script', 'name' => 'Handler', 'is_enabled' => true, 'created_by_user_id' => 2]);
        $rel = SaPackageRelease::query()->create(['package_id' => $pkg->id, 'version' => '1', 'content_digest' => str_repeat('a', 64), 'execution_digest' => str_repeat('b', 64), 'runtime_compatibility' => ['recovery_handler_v1' => true], 'manifest' => ['runtime_contract' => 'recovery_handler_v1', 'steps' => []], 'status' => 'published', 'created_by_user_id' => 2]);
        $relWrong = SaPackageRelease::query()->create(['package_id' => $pkg->id, 'version' => '2', 'content_digest' => str_repeat('c', 64), 'execution_digest' => str_repeat('d', 64), 'runtime_compatibility' => ['recovery_handler_v1' => true], 'manifest' => ['runtime_contract' => 'recovery_handler_v1', 'steps' => []], 'status' => 'published', 'created_by_user_id' => 2]);

        $tag = SaRecoveryErrorTag::query()->create(['error_tag_uuid' => (string) Str::uuid(), 'owner_user_id' => 2, 'installation_id' => $ins, 'name' => 'Chaos Modal', 'platform' => 'qa_sandbox_facebook', 'is_enabled' => true, 'handler_package_id' => $pkg->id, 'handler_release_id' => $rel->id]);
        $tpl = SaRecoveryVisualTemplate::query()->create(['template_uuid' => (string) Str::uuid(), 'error_tag_id' => $tag->id, 'source_attempt_id' => $attempt->id, 'source_artifact_id' => $artifact->id, 'modal_roi' => ['x' => 0.1, 'y' => 0.1, 'width' => 0.5, 'height' => 0.5], 'is_enabled' => true]);

        // 1. Authorized Staff Client (User 9, parent_id=2) retrieves Manager 2's Recovery configuration
        $regStaff = $this->controller->registry($this->req($keyStaff9, 'GET', ['platform' => 'qa_sandbox_facebook']));
        self::assertSame(200, $regStaff->getStatusCode());
        self::assertCount(1, $regStaff->getData(true)['error_tags']);
        self::assertSame($tag->error_tag_uuid, $regStaff->getData(true)['error_tags'][0]['error_tag_id']);

        // 2. Normal owner-scoped access (Manager 2) remains functional
        $regOwner = $this->controller->registry($this->req($keyManager2, 'GET', ['platform' => 'qa_sandbox_facebook']));
        self::assertSame(200, $regOwner->getStatusCode());
        self::assertCount(1, $regOwner->getData(true)['error_tags']);

        // 3. Same installation alone (Staff 10 with parent_id=99) CANNOT retrieve Manager 2's Registry
        $regOther = $this->controller->registry($this->req($keyOtherStaff, 'GET', ['platform' => 'qa_sandbox_facebook']));
        self::assertSame(200, $regOther->getStatusCode());
        self::assertCount(0, $regOther->getData(true)['error_tags']);

        // 4. Cross-installation access denied
        $regCross = $this->controller->registry($this->req($keyCrossIns, 'GET', ['platform' => 'qa_sandbox_facebook']));
        self::assertSame(200, $regCross->getStatusCode());
        self::assertCount(0, $regCross->getData(true)['error_tags']);

        // 5. Template image & Handler pull succeed for Staff 9, denied for Other Staff & Cross-installation
        $imgOk = $this->controller->templateImage($this->req($keyStaff9, 'POST', ['visual_template_id' => $tpl->template_uuid]));
        self::assertSame(200, $imgOk->getStatusCode());
        self::assertSame(404, $this->controller->templateImage($this->req($keyOtherStaff, 'POST', ['visual_template_id' => $tpl->template_uuid]))->getStatusCode());
        self::assertSame(404, $this->controller->templateImage($this->req($keyCrossIns, 'POST', ['visual_template_id' => $tpl->template_uuid]))->getStatusCode());

        $pullOk = $this->controller->pullHandler($this->req($keyStaff9, 'POST', ['error_tag_id' => $tag->error_tag_uuid, 'release_id' => $rel->id]));
        self::assertSame(200, $pullOk->getStatusCode());

        // 6. Wrong Release denied
        $pullWrong = $this->controller->pullHandler($this->req($keyStaff9, 'POST', ['error_tag_id' => $tag->error_tag_uuid, 'release_id' => $relWrong->id]));
        self::assertSame(403, $pullWrong->getStatusCode());

        // 7. Disabled Handler denied
        $pkg->update(['is_enabled' => false]);
        $pullDisabled = $this->controller->pullHandler($this->req($keyStaff9, 'POST', ['error_tag_id' => $tag->error_tag_uuid, 'release_id' => $rel->id]));
        self::assertSame(403, $pullDisabled->getStatusCode());
        $pkg->update(['is_enabled' => true]);

        // 8. Stale Attempt/Lease denied, and valid Staff 9 Result report succeeds
        $staleRes = $this->controller->report($this->req($keyStaff9, 'POST', [
            'attempt_uuid' => $attempt->attempt_uuid, 'lease_token' => 'wrong_token',
            'error_tag_id' => $tag->id, 'visual_template_id' => $tpl->id, 'handler_release_id' => $rel->id,
            'failed_step_id' => 'step_1', 'recovery_attempt_no' => 1, 'match_confidence' => 0.95,
            'verification_outcome' => 'passed', 'idempotency_key' => 'rec-stale',
        ]));
        self::assertSame(409, $staleRes->getStatusCode());

        // Manager 2's other key cannot report for Agent 5's attempt
        $wrongAgentRes = $this->controller->report($this->req($keyManager2, 'POST', [
            'attempt_uuid' => $attempt->attempt_uuid, 'lease_token' => 'lease_tok_9',
            'error_tag_id' => $tag->id, 'visual_template_id' => $tpl->id, 'handler_release_id' => $rel->id,
            'failed_step_id' => 'step_1', 'recovery_attempt_no' => 1, 'match_confidence' => 0.95,
            'verification_outcome' => 'passed', 'idempotency_key' => 'rec-wrong-agent',
        ]));
        self::assertSame(422, $wrongAgentRes->getStatusCode());

        $okRes = $this->controller->report($this->req($keyStaff9, 'POST', [
            'attempt_uuid' => $attempt->attempt_uuid, 'lease_token' => 'lease_tok_9',
            'error_tag_id' => $tag->id, 'visual_template_id' => $tpl->id, 'handler_release_id' => $rel->id,
            'failed_step_id' => 'step_1', 'recovery_attempt_no' => 1, 'match_confidence' => 0.95,
            'verification_outcome' => 'passed', 'final_job_outcome' => 'resumed', 'idempotency_key' => 'rec-ok-1',
        ]));
        self::assertSame(201, $okRes->getStatusCode());
    }

    private function req(SaClientApiKey $key, string $method, array $data = []): Request
    {
        $r = Request::create('/', $method, $data);
        $r->attributes->set(EnsureRpaClientApiKey::REQUEST_CLIENT_KEY, $key);

        return $r;
    }
}
