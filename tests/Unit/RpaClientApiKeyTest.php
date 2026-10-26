<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Auth\RpaAdminRoleGate;
use Omnichannel\Addons\SocialAutomation\Domain\RpaClientApiKeyGenerator;
use Omnichannel\Addons\SocialAutomation\Domain\RpaClientApiKeyValidationResult;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\RpaClientApiController;
use Omnichannel\Addons\SocialAutomation\Http\Middleware\EnsureRpaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Repositories\InMemoryRpaClientApiKeyRepository;
use Omnichannel\Addons\SocialAutomation\Services\RpaClientApiKeyService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceResolver;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1 + Fix Staff Access & Lazy Provisioning tests.
 * Requirements A through K.
 */
final class RpaClientApiKeyTest extends TestCase
{
    private RpaClientApiKeyGenerator $generator;
    private SocialAutomationInstallationResolver $installationResolver;
    private InMemoryRpaClientApiKeyRepository $repository;
    private RpaClientApiKeyService $keyService;
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = dirname(__DIR__, 2);
        $this->generator = new RpaClientApiKeyGenerator();
        $this->installationResolver = new SocialAutomationInstallationResolver();
        $this->installationResolver->setForcedInstallationId('ins_test_alpha');
        $this->repository = new InMemoryRpaClientApiKeyRepository();
        $this->keyService = new RpaClientApiKeyService(
            $this->generator,
            $this->installationResolver,
            $this->repository
        );
    }

    /**
     * Requirement A: Open page without Key -> 0 DB writes.
     */
    public function test_a_open_page_without_key_does_zero_db_writes(): void
    {
        self::assertCount(0, $this->repository->list('ins_test_alpha'));

        $data = $this->keyService->getActiveOrCandidateKey('ins_test_alpha');

        // Returned candidate state
        self::assertFalse($data['is_activated']);
        self::assertSame('candidate', $data['status']);
        self::assertSame('Chưa kích hoạt', $data['status_label']);
        self::assertStringStartsWith('rpa_client_', $data['raw_key']);
        self::assertNotNull($data['candidate_token']);

        // ZERO database writes must have occurred
        self::assertCount(0, $this->repository->list('ins_test_alpha'));
    }

    /**
     * Requirement B: Candidate Key cannot authenticate.
     */
    public function test_b_candidate_key_cannot_authenticate(): void
    {
        $candidateData = $this->keyService->getActiveOrCandidateKey('ins_test_alpha');
        $candidateRawKey = $candidateData['raw_key'];

        // Service validation fails
        $result = $this->keyService->validate($candidateRawKey);
        self::assertFalse($result->valid);
        self::assertSame(RpaClientApiKeyValidationResult::ERROR_INVALID_KEY, $result->errorCode);

        // Middleware rejection
        $middleware = new EnsureRpaClientApiKey($this->keyService);
        $req = Request::create('/api/v1/social-automation/client/validate', 'GET');
        $req->headers->set('Authorization', 'Bearer ' . $candidateRawKey);

        $response = $middleware->handle($req, fn () => new JsonResponse(['ok' => true]));
        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * Requirement C: Click Copy -> Key is activated.
     */
    public function test_c_copy_triggers_atomic_activation(): void
    {
        $candidateData = $this->keyService->getActiveOrCandidateKey('ins_test_alpha');
        $candidateToken = (string) $candidateData['candidate_token'];

        $activation = $this->keyService->activateCandidateKey($candidateToken, 101);

        self::assertTrue($activation['ok']);
        self::assertFalse($activation['already_active']);
        self::assertSame('Đã kích hoạt và sao chép', $activation['message']);
        self::assertSame($candidateData['raw_key'], $activation['raw_key']);

        // Exactly 1 row in repository
        self::assertCount(1, $this->repository->list('ins_test_alpha'));
        $persisted = $this->repository->firstUsable('ins_test_alpha');
        self::assertNotNull($persisted);
        self::assertSame(101, $persisted->created_by);
        self::assertTrue($persisted->isActive());
    }

    /**
     * Requirement D: Activated Key authenticates successfully.
     */
    public function test_d_activated_key_authenticates_successfully(): void
    {
        $candidateData = $this->keyService->getActiveOrCandidateKey('ins_test_alpha');
        $this->keyService->activateCandidateKey((string) $candidateData['candidate_token'], 101);

        $result = $this->keyService->validate($candidateData['raw_key']);
        self::assertTrue($result->valid);
        self::assertNull($result->errorCode);
        self::assertNotNull($result->key);
        self::assertNotNull($result->key->last_used_at);

        // Middleware returns 200
        $middleware = new EnsureRpaClientApiKey($this->keyService);
        $req = Request::create('/api/v1/social-automation/client/validate', 'GET');
        $req->headers->set('Authorization', 'Bearer ' . $candidateData['raw_key']);
        $resp = $middleware->handle($req, fn () => new JsonResponse(['ok' => true]));
        self::assertSame(200, $resp->getStatusCode());
    }

    /**
     * Requirement E: Reload -> same Key is displayed.
     */
    public function test_e_reload_displays_same_activated_key(): void
    {
        $candidateData = $this->keyService->getActiveOrCandidateKey('ins_test_alpha');
        $this->keyService->activateCandidateKey((string) $candidateData['candidate_token'], 101);

        // Simulate page reload
        $reloaded = $this->keyService->getActiveOrCandidateKey('ins_test_alpha');

        self::assertTrue($reloaded['is_activated']);
        self::assertSame('active', $reloaded['status']);
        self::assertSame('Đã kích hoạt', $reloaded['status_label']);
        self::assertSame($candidateData['raw_key'], $reloaded['raw_key']);
        self::assertNull($reloaded['candidate_token']);
        self::assertCount(1, $this->repository->list('ins_test_alpha'));
    }

    /**
     * Requirement F: Repeated Copy -> no duplicate.
     */
    public function test_f_repeated_copy_does_not_create_duplicate_key(): void
    {
        $candidateData = $this->keyService->getActiveOrCandidateKey('ins_test_alpha');
        $token = (string) $candidateData['candidate_token'];

        // First copy
        $res1 = $this->keyService->activateCandidateKey($token, 101);
        self::assertTrue($res1['ok']);
        self::assertFalse($res1['already_active']);

        // Second copy
        $res2 = $this->keyService->activateCandidateKey($token, 101);
        self::assertTrue($res2['ok']);
        self::assertTrue($res2['already_active']);

        self::assertCount(1, $this->repository->list('ins_test_alpha'));
    }

    /**
     * Requirement G: Two Staff activate concurrently -> only one default Key is persisted.
     */
    public function test_g_concurrent_activation_persists_only_one_key(): void
    {
        // Staff 1 and Staff 2 open page concurrently
        $candidate1 = $this->keyService->getActiveOrCandidateKey('ins_test_alpha');
        $candidate2 = $this->keyService->getActiveOrCandidateKey('ins_test_alpha');

        // Staff 1 activates first
        $act1 = $this->keyService->activateCandidateKey((string) $candidate1['candidate_token'], 101);
        self::assertTrue($act1['ok']);

        // Staff 2 activates moments later
        $act2 = $this->keyService->activateCandidateKey((string) $candidate2['candidate_token'], 102);
        self::assertTrue($act2['ok']);
        self::assertTrue($act2['already_active']);

        // Still exactly 1 key in repository
        self::assertCount(1, $this->repository->list('ins_test_alpha'));
        self::assertSame($act1['raw_key'], $act2['raw_key']);
    }

    /**
     * Requirement H: Staff access & authorization boundary.
     * Authorized Staff can access; blocked / system users receive 403.
     */
    public function test_h_authorized_staff_allowed_and_unauthorized_users_denied(): void
    {
        $serviceResolver = new SocialAutomationServiceResolver();
        $access = new SocialAutomationAccess($serviceResolver);
        $access->setForcedActiveForTesting(true);

        // 1. Authorized Staff
        $staff = new User();
        $staff->id = 5;
        $staff->role = User::ROLE_STAFF;
        $staff->status = User::STATUS_NORMAL;
        $staff->parent_id = 1;

        self::assertTrue($access->canAccess($staff));
        self::assertTrue($access->canManageClientKeys($staff));
        self::assertTrue($access->canMutate($staff));

        // 2. Owner & Admin
        $owner = new User();
        $owner->id = 1;
        $owner->role = User::ROLE_OWNER;
        $owner->status = User::STATUS_NORMAL;

        $admin = new User();
        $admin->id = 2;
        $admin->role = User::ROLE_ADMIN;
        $admin->status = User::STATUS_NORMAL;

        self::assertTrue($access->canAccess($owner));
        self::assertTrue($access->canAccess($admin));

        // 3. Blocked User
        $blocked = new User();
        $blocked->id = 99;
        $blocked->role = User::ROLE_STAFF;
        $blocked->status = User::STATUS_BLOCK;
        self::assertFalse($access->canAccess($blocked));

        // 4. System User
        $sys = new User();
        $sys->id = 15;
        $sys->role = User::ROLE_ADMIN;
        $sys->is_system = true;
        self::assertFalse($access->canAccess($sys));

        // 5. Inactive Service
        $access->setForcedActiveForTesting(false);
        self::assertFalse($access->canAccess($staff));
    }

    /**
     * Requirement I: Expired or tampered candidate token is rejected.
     */
    public function test_i_expired_or_tampered_candidate_is_rejected(): void
    {
        // 1. Tampered signature
        $candidate = $this->keyService->getActiveOrCandidateKey('ins_test_alpha');
        $tamperedToken = $candidate['candidate_token'] . 'tampered';
        $res1 = $this->keyService->activateCandidateKey($tamperedToken, 101);
        self::assertFalse($res1['ok']);
        self::assertSame('invalid_or_expired_candidate', $res1['error']);

        // 2. Expired token (TTL = -10 seconds)
        $expiredToken = $this->keyService->issueCandidateToken('rpa_client_11223344_secret', 'ins_test_alpha', -10);
        $res2 = $this->keyService->activateCandidateKey($expiredToken, 101);
        self::assertFalse($res2['ok']);
        self::assertSame('invalid_or_expired_candidate', $res2['error']);

        // 3. Cross-installation candidate token
        $crossToken = $this->keyService->issueCandidateToken('rpa_client_11223344_secret', 'ins_other_installation', 900);
        $res3 = $this->keyService->activateCandidateKey($crossToken, 101);
        self::assertFalse($res3['ok']);
        self::assertSame('installation_mismatch', $res3['error']);
    }

    /**
     * Requirement J: Failed activation never reports copy success.
     */
    public function test_j_failed_activation_never_reports_copy_success(): void
    {
        $invalidToken = 'invalid.payload.signature';
        $result = $this->keyService->activateCandidateKey($invalidToken, 101);

        self::assertFalse($result['ok']);
        self::assertArrayNotHasKey('raw_key', $result);
        self::assertCount(0, $this->repository->list('ins_test_alpha'));
    }

    /**
     * Requirement K: Existing RPA Admin API authentication unaffected.
     * RpaAdminRoleGate strictly requires admin|owner and continues to reject staff for script sync.
     */
    public function test_k_existing_rpa_admin_auth_gate_unaffected(): void
    {
        $rpaAdminGate = new RpaAdminRoleGate();

        $staff = new User(['role' => User::ROLE_STAFF, 'status' => User::STATUS_NORMAL]);
        $staff->role = User::ROLE_STAFF;
        $admin = new User(['role' => User::ROLE_ADMIN, 'status' => User::STATUS_NORMAL]);
        $admin->role = User::ROLE_ADMIN;
        $owner = new User(['role' => User::ROLE_OWNER, 'status' => User::STATUS_NORMAL]);
        $owner->role = User::ROLE_OWNER;

        // RPA Creator admin gate remains strictly admin/owner
        self::assertFalse($rpaAdminGate->allows($staff));
        self::assertSame('role_staff_forbidden', $rpaAdminGate->denyReason($staff));
        self::assertTrue($rpaAdminGate->allows($admin));
        self::assertTrue($rpaAdminGate->allows($owner));
    }
}
