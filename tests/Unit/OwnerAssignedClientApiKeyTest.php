<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Contracts\ClientApiKeyAssigneeDirectoryContract;
use Omnichannel\Addons\SocialAutomation\Domain\ClientApiKeyAssignmentPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\RpaClientApiKeyGenerator;
use Omnichannel\Addons\SocialAutomation\Domain\RpaClientApiKeyValidationResult;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\RpaClientApiKeyManagementController;
use Omnichannel\Addons\SocialAutomation\Http\Middleware\EnsureRpaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Repositories\InMemoryRpaClientApiKeyRepository;
use Omnichannel\Addons\SocialAutomation\Services\RpaClientApiKeyService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Owner-assigned Client API Keys — designated owner (created_by) vs minting actor (issued_by).
 */
final class OwnerAssignedClientApiKeyTest extends TestCase
{
    private InMemoryRpaClientApiKeyRepository $repository;

    private RpaClientApiKeyService $keyService;

    private SocialAutomationAccess $access;

    private InMemoryAssigneeDirectory $directory;

    private ClientApiKeyAssignmentPolicy $policy;

    private RpaClientApiKeyManagementController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $installation = new SocialAutomationInstallationResolver;
        $installation->setForcedInstallationId('ins_owner_assign');
        $this->repository = new InMemoryRpaClientApiKeyRepository;
        $this->keyService = new RpaClientApiKeyService(
            new RpaClientApiKeyGenerator,
            $installation,
            $this->repository,
        );

        $resolver = new SocialAutomationServiceResolver;
        $this->access = new SocialAutomationAccess($resolver);
        $this->access->setForcedActiveForTesting(true);

        $this->directory = new InMemoryAssigneeDirectory;
        $this->policy = new ClientApiKeyAssignmentPolicy($this->access, $this->directory);
        $this->controller = new RpaClientApiKeyManagementController(
            $this->keyService,
            $this->access,
            $this->policy,
        );
    }

    private function user(int $id, string $role, ?int $parentId = null, string $status = User::STATUS_NORMAL): User
    {
        $user = new User;
        $user->id = $id;
        $user->role = $role;
        $user->status = $status;
        $user->name = "User {$id}";
        $user->email = "user{$id}@example.test";
        $user->parent_id = $parentId;
        $user->is_system = false;

        return $user;
    }

    private function requestFor(User $actor, array $payload = []): Request
    {
        $req = Request::create('/social-automation/api/client-keys', 'POST', $payload);
        $req->setUserResolver(static fn () => $actor);

        return $req;
    }

    public function test_owner_creates_key_for_self_legacy_name_only(): void
    {
        $owner = $this->user(1, User::ROLE_OWNER);
        $this->directory->seed([$owner]);

        $res = $this->controller->store($this->requestFor($owner, ['name' => 'Owner Self Key']));
        self::assertSame(201, $res->getStatusCode());
        $body = $res->getData(true);
        self::assertTrue($body['ok']);
        self::assertArrayHasKey('raw_key', $body);
        self::assertStringStartsWith('rpa_client_', $body['raw_key']);
        self::assertSame(1, $body['key']['created_by']);
        self::assertSame(1, $body['key']['issued_by']);
        self::assertCount(1, $this->repository->list('ins_owner_assign'));
    }

    public function test_owner_creates_key_for_eligible_staff(): void
    {
        $owner = $this->user(1, User::ROLE_OWNER);
        $staff = $this->user(42, User::ROLE_STAFF, 1);
        $this->directory->seed([$owner, $staff]);

        $res = $this->controller->store($this->requestFor($owner, [
            'name' => 'QA Staff Key',
            'assigned_to_user_id' => 42,
        ]));
        self::assertSame(201, $res->getStatusCode());
        $body = $res->getData(true);
        self::assertSame(42, $body['key']['created_by']);
        self::assertSame(1, $body['key']['issued_by']);
        self::assertNotSame($body['key']['created_by'], $body['key']['issued_by']);
        self::assertSame(
            42,
            (int) $this->repository->findActiveByCreator('ins_owner_assign', 42)?->created_by
        );
    }

    public function test_non_owner_cannot_assign_arbitrary_user(): void
    {
        $staff = $this->user(42, User::ROLE_STAFF, 1);
        $other = $this->user(99, User::ROLE_STAFF, 1);
        $this->directory->seed([$staff, $other]);

        $res = $this->controller->store($this->requestFor($staff, [
            'name' => 'Hijack',
            'assigned_to_user_id' => 99,
        ]));
        self::assertSame(403, $res->getStatusCode());
        $body = $res->getData(true);
        self::assertSame(ClientApiKeyAssignmentPolicy::ERROR_ASSIGNMENT_FORBIDDEN, $body['error']);
        self::assertCount(0, $this->repository->list('ins_owner_assign'));
    }

    public function test_invalid_user_id_rejected(): void
    {
        $owner = $this->user(1, User::ROLE_OWNER);
        $this->directory->seed([$owner]);

        $res = $this->controller->store($this->requestFor($owner, [
            'name' => 'Bad',
            'assigned_to_user_id' => 404404,
        ]));
        self::assertSame(422, $res->getStatusCode());
        $body = $res->getData(true);
        self::assertSame(ClientApiKeyAssignmentPolicy::ERROR_ASSIGNED_USER_INVALID, $body['error']);

        $res2 = $this->controller->store($this->requestFor($owner, [
            'name' => 'Bad',
            'assigned_to_user_id' => 'not-int',
        ]));
        self::assertSame(422, $res2->getStatusCode());
    }

    public function test_ineligible_staff_outside_account_rejected(): void
    {
        $owner = $this->user(1, User::ROLE_OWNER);
        $foreign = $this->user(77, User::ROLE_STAFF, 999);
        $this->directory->seed([$owner]); // foreign not in eligible list

        $res = $this->controller->store($this->requestFor($owner, [
            'name' => 'Foreign',
            'assigned_to_user_id' => 77,
        ]));
        self::assertSame(422, $res->getStatusCode());
        // findById returns null when not seeded → invalid; seed foreign but keep ineligible
        $this->directory->seed([$owner, $foreign]);
        $this->directory->setEligibleOnly([$owner]);

        $res2 = $this->controller->store($this->requestFor($owner, [
            'name' => 'Foreign',
            'assigned_to_user_id' => 77,
        ]));
        self::assertSame(422, $res2->getStatusCode());
        self::assertSame(ClientApiKeyAssignmentPolicy::ERROR_ASSIGNED_USER_INELIGIBLE, $res2->getData(true)['error']);
    }

    public function test_frontend_created_by_cannot_impersonate_actor(): void
    {
        $owner = $this->user(1, User::ROLE_OWNER);
        $staff = $this->user(42, User::ROLE_STAFF, 1);
        $this->directory->seed([$owner, $staff]);

        $req = $this->requestFor($owner, [
            'name' => 'Ignore forged created_by',
            'created_by' => 42,
            'issued_by' => 42,
        ]);
        $res = $this->controller->store($req);
        self::assertSame(201, $res->getStatusCode());
        $body = $res->getData(true);
        // Without assigned_to, owner remains self despite forged created_by.
        self::assertSame(1, $body['key']['created_by']);
        self::assertSame(1, $body['key']['issued_by']);
    }

    public function test_raw_key_returned_exactly_once_on_create_not_on_list(): void
    {
        $owner = $this->user(1, User::ROLE_OWNER);
        $this->directory->seed([$owner]);

        $created = $this->controller->store($this->requestFor($owner, ['name' => 'Once']));
        $raw = $created->getData(true)['raw_key'];
        self::assertNotSame('', $raw);

        $listReq = Request::create('/social-automation/api/client-keys', 'GET');
        $listReq->setUserResolver(static fn () => $owner);
        $list = $this->controller->index($listReq)->getData(true);
        self::assertArrayNotHasKey('raw_key', $list);
        self::assertArrayNotHasKey('raw_key', $list['keys'][0]);
        self::assertSame('rpa_client_', substr((string) $list['keys'][0]['key_prefix'], 0, 11));
    }

    public function test_existing_client_validates_and_registers_identity_unchanged(): void
    {
        $created = $this->keyService->create('Legacy Compat', 5, 5);
        $raw = $created['raw_key'];

        $result = $this->keyService->validate($raw, 'ins_owner_assign');
        self::assertTrue($result->valid);
        self::assertInstanceOf(SaClientApiKey::class, $result->key);
        self::assertSame(5, (int) $result->key->created_by);

        $middleware = new EnsureRpaClientApiKey($this->keyService);
        $req = Request::create('/api/v1/social-automation/client/validate', 'GET');
        $req->headers->set('Authorization', 'Bearer '.$raw);
        $response = $middleware->handle($req, static fn () => new JsonResponse(['ok' => true]));
        self::assertSame(200, $response->getStatusCode());
    }

    public function test_existing_inventory_unaffected_by_new_assigned_key(): void
    {
        $existing = $this->keyService->create('client-A', null, null);
        $before = $this->repository->countForInstallation('ins_owner_assign');
        self::assertSame(1, $before);

        $owner = $this->user(1, User::ROLE_OWNER);
        $staff = $this->user(42, User::ROLE_STAFF, 1);
        $this->directory->seed([$owner, $staff]);
        $this->controller->store($this->requestFor($owner, [
            'name' => 'QA_SANDBOX_FACEBOOK',
            'assigned_to_user_id' => 42,
        ]));

        self::assertSame(2, $this->repository->countForInstallation('ins_owner_assign'));
        self::assertNotNull($this->repository->find((int) $existing['key']->id, 'ins_owner_assign'));
        self::assertNull($existing['key']->revoked_at);
    }

    public function test_assignable_users_owner_only(): void
    {
        $owner = $this->user(1, User::ROLE_OWNER);
        $staff = $this->user(42, User::ROLE_STAFF, 1);
        $this->directory->seed([$owner, $staff]);

        $req = Request::create('/social-automation/api/client-keys/assignable-users', 'GET');
        $req->setUserResolver(static fn () => $owner);
        $res = $this->controller->assignableUsers($req);
        self::assertSame(200, $res->getStatusCode());
        self::assertCount(2, $res->getData(true)['users']);

        $staffReq = Request::create('/social-automation/api/client-keys/assignable-users', 'GET');
        $staffReq->setUserResolver(static fn () => $staff);
        try {
            $this->controller->assignableUsers($staffReq);
            self::fail('Expected 403');
        } catch (HttpException $e) {
            self::assertSame(403, $e->getStatusCode());
        }
    }

    public function test_validation_failure_codes_are_stable(): void
    {
        self::assertSame('assignment_forbidden', ClientApiKeyAssignmentPolicy::ERROR_ASSIGNMENT_FORBIDDEN);
        self::assertSame('assigned_user_invalid', ClientApiKeyAssignmentPolicy::ERROR_ASSIGNED_USER_INVALID);
        self::assertSame('assigned_user_ineligible', ClientApiKeyAssignmentPolicy::ERROR_ASSIGNED_USER_INELIGIBLE);
        self::assertNull(RpaClientApiKeyValidationResult::failure(
            RpaClientApiKeyValidationResult::ERROR_INVALID_KEY,
            'x'
        )->key);
    }
}

/**
 * @internal
 */
final class InMemoryAssigneeDirectory implements ClientApiKeyAssigneeDirectoryContract
{
    /** @var array<int, User> */
    private array $users = [];

    /** @var list<int>|null */
    private ?array $eligibleIds = null;

    /**
     * @param  list<User>  $users
     */
    public function seed(array $users): void
    {
        $this->users = [];
        foreach ($users as $user) {
            $this->users[(int) $user->id] = $user;
        }
        $this->eligibleIds = null;
    }

    /**
     * @param  list<User>  $users
     */
    public function setEligibleOnly(array $users): void
    {
        $this->eligibleIds = array_map(static fn (User $u): int => (int) $u->id, $users);
    }

    public function findById(int $userId): ?User
    {
        return $this->users[$userId] ?? null;
    }

    public function listEligibleAssignees(User $actor): array
    {
        $out = [];
        foreach ($this->users as $user) {
            if ($this->eligibleIds !== null && ! in_array((int) $user->id, $this->eligibleIds, true)) {
                continue;
            }
            if ((string) $actor->role === User::ROLE_OWNER) {
                if ((int) $user->id === (int) $actor->id) {
                    $out[] = $user;
                    continue;
                }
                if ((string) $user->role === User::ROLE_STAFF && (int) ($user->parent_id ?? 0) === (int) $actor->id) {
                    $out[] = $user;
                }
                continue;
            }
            if ((string) $actor->role === User::ROLE_ADMIN) {
                $out[] = $user;
            }
        }

        return $out;
    }
}
