<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use App\Models\User;
use Omnichannel\Addons\SocialAutomation\Domain\RpaClientApiKeyGenerator;
use Omnichannel\Addons\SocialAutomation\Domain\StaffClientEnrollmentBootstrap;
use Omnichannel\Addons\SocialAutomation\Repositories\InMemoryRpaClientApiKeyRepository;
use Omnichannel\Addons\SocialAutomation\Services\StaffClientEnrollmentService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * CP17A.2 — Ordinary Staff may manage OWN enrollment; Owner inventory stays admin-only.
 */
final class StaffClientEnrollmentPermissionTest extends TestCase
{
    private string $root;

    private SocialAutomationAccess $access;

    private StaffClientEnrollmentService $enrollment;

    private InMemoryRpaClientApiKeyRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
        $resolver = new SocialAutomationServiceResolver;
        $this->access = new SocialAutomationAccess($resolver);
        $this->access->setForcedActiveForTesting(true);

        $installation = new SocialAutomationInstallationResolver;
        $installation->setForcedInstallationId('ins_cp17a2');
        $this->repository = new InMemoryRpaClientApiKeyRepository;
        $this->enrollment = new StaffClientEnrollmentService(
            new StaffClientEnrollmentBootstrap,
            new RpaClientApiKeyGenerator,
            $installation,
            $this->repository,
            null,
        );
    }

    private function user(int $id, string $role, string $status = User::STATUS_NORMAL): User
    {
        $user = new User;
        $user->id = $id;
        $user->role = $role;
        $user->status = $status;

        return $user;
    }

    public function test_authorized_staff_get_connection_gate_allowed(): void
    {
        $staff = $this->user(42, User::ROLE_STAFF);
        self::assertTrue($this->access->canAccess($staff));
        $this->access->assertCanAccess($staff);

        $state = $this->enrollment->connectionState(42);
        self::assertFalse($state['connected']);
        self::assertNotNull($state['bootstrap']['raw_key'] ?? null);
    }

    public function test_authorized_staff_bootstrap_mutate_gate_allowed(): void
    {
        $staff = $this->user(42, User::ROLE_STAFF);
        self::assertTrue($this->access->canMutate($staff));
        self::assertTrue($this->access->canManageClientKeys($staff));
        $this->access->assertCanMutate($staff);

        $result = $this->enrollment->issueBootstrapKey(42);
        self::assertTrue($result['ok']);
        self::assertStringStartsWith('rpa_client_', (string) $result['bootstrap']['raw_key']);
    }

    public function test_authorized_staff_disconnect_own_client_allowed(): void
    {
        $staff = $this->user(42, User::ROLE_STAFF);
        $this->access->assertCanMutate($staff);

        $bootstrap = new StaffClientEnrollmentBootstrap;
        $issued = $bootstrap->issue(42, 'ins_cp17a2');
        $this->enrollment->materializeFromBootstrap($bootstrap->parse($issued['raw_key']) ?? [], $issued['raw_key']);

        $result = $this->enrollment->disconnect(42);
        self::assertTrue($result['ok']);
        self::assertNull($this->repository->findActiveByCreator('ins_cp17a2', 42));
    }

    public function test_staff_cannot_disconnect_another_staff_client(): void
    {
        $bootstrap = new StaffClientEnrollmentBootstrap;
        $issued = $bootstrap->issue(42, 'ins_cp17a2');
        $this->enrollment->materializeFromBootstrap($bootstrap->parse($issued['raw_key']) ?? [], $issued['raw_key']);

        $other = $this->enrollment->disconnect(99);
        self::assertFalse($other['ok']);
        self::assertSame(StaffClientEnrollmentService::ERROR_NOT_CONNECTED, $other['error']);
        self::assertNotNull($this->repository->findActiveByCreator('ins_cp17a2', 42));
    }

    public function test_unauthorized_user_denied(): void
    {
        $blocked = $this->user(7, User::ROLE_STAFF, User::STATUS_BLOCK);
        self::assertFalse($this->access->canAccess($blocked));
        self::assertFalse($this->access->canMutate($blocked));

        try {
            $this->access->assertCanAccess($blocked);
            self::fail('Expected 403 for blocked staff');
        } catch (HttpException $e) {
            self::assertSame(403, $e->getStatusCode());
        }

        $this->access->setForcedActiveForTesting(false);
        $staff = $this->user(42, User::ROLE_STAFF);
        self::assertFalse($this->access->canAccess($staff));
        self::assertFalse($this->access->canMutate($staff));
    }

    public function test_owner_inventory_remains_owner_admin_only(): void
    {
        $staff = $this->user(42, User::ROLE_STAFF);
        $owner = $this->user(1, User::ROLE_OWNER);
        $admin = $this->user(2, User::ROLE_ADMIN);

        self::assertFalse($this->access->canAccessAdminPages($staff));
        self::assertTrue($this->access->canAccessAdminPages($owner));
        self::assertTrue($this->access->canAccessAdminPages($admin));

        try {
            $this->access->assertCanAccessAdminPages($staff);
            self::fail('Expected 403 for staff on owner inventory');
        } catch (HttpException $e) {
            self::assertSame(403, $e->getStatusCode());
        }

        $this->access->assertCanAccessAdminPages($owner);
        $this->access->assertCanAccessAdminPages($admin);
    }

    public function test_controller_gates_match_business_rules(): void
    {
        $ctrl = (string) file_get_contents($this->root.'/src/Http/Controllers/StaffClientEnrollmentController.php');
        self::assertStringContainsString('assertCanAccess()', $ctrl);
        self::assertSame(2, substr_count($ctrl, 'assertCanMutate()'));
        self::assertStringContainsString('assertCanAccessAdminPages()', $ctrl);
        self::assertStringContainsString('issueBootstrapKey', $ctrl);
        self::assertStringContainsString('disconnect', $ctrl);
        self::assertStringContainsString('ownerConnections', $ctrl);

        // Own-slot only: session user id, never body staff_user_id.
        self::assertStringContainsString('authenticatedUserId', $ctrl);
        self::assertStringContainsString('session-derived', $ctrl);
    }
}
