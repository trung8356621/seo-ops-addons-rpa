<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use App\Models\User;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceHealth;
use PHPUnit\Framework\TestCase;

/**
 * CP17B.3 — bootstrap.user.role is server-derived from authenticated User.
 */
final class SocialAutomationBootstrapRoleTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    private function user(int $id, string $role, string $name): User
    {
        $user = new User;
        $user->id = $id;
        $user->role = $role;
        $user->name = $name;

        return $user;
    }

    public function test_authenticated_staff_role(): void
    {
        $payload = SocialAutomationServiceHealth::publicUser($this->user(7, User::ROLE_STAFF, 'Staff Name'));
        self::assertSame(7, $payload['id']);
        self::assertSame('Staff Name', $payload['name']);
        self::assertSame('staff', $payload['role']);
        self::assertSame(User::ROLE_STAFF, $payload['role']);
    }

    public function test_authenticated_owner_role(): void
    {
        $payload = SocialAutomationServiceHealth::publicUser($this->user(1, User::ROLE_OWNER, 'Owner Name'));
        self::assertSame('owner', $payload['role']);
        self::assertSame(User::ROLE_OWNER, $payload['role']);
    }

    public function test_authenticated_admin_role(): void
    {
        $payload = SocialAutomationServiceHealth::publicUser($this->user(2, User::ROLE_ADMIN, 'Admin Name'));
        self::assertSame('admin', $payload['role']);
        self::assertSame(User::ROLE_ADMIN, $payload['role']);
    }

    public function test_null_user_preserves_null_and_auth_gate_unchanged(): void
    {
        self::assertNull(SocialAutomationServiceHealth::publicUser(null));

        $ctrl = (string) file_get_contents($this->root.'/src/Http/Controllers/SocialAutomationBootstrapController.php');
        self::assertStringContainsString('assertCanAccess()', $ctrl);
        self::assertStringContainsString('$this->health->bootstrap()', $ctrl);
    }

    public function test_existing_bootstrap_user_fields_preserved_and_no_secrets(): void
    {
        $payload = SocialAutomationServiceHealth::publicUser($this->user(7, User::ROLE_STAFF, 'Staff Name'));
        self::assertSame(['id', 'name', 'role'], array_keys($payload));
        self::assertArrayNotHasKey('password', $payload);
        self::assertArrayNotHasKey('email', $payload);
        self::assertArrayNotHasKey('remember_token', $payload);

        $src = (string) file_get_contents($this->root.'/src/Support/SocialAutomationServiceHealth.php');
        self::assertStringContainsString("'service' =>", $src);
        self::assertStringContainsString("'user' => self::publicUser", $src);
        self::assertStringContainsString("'config' => \$config->toArray()", $src);
        self::assertStringContainsString("'slug' => SocialAutomationServiceResolver::SLUG", $src);
        self::assertStringContainsString("'active' => \$config->active", $src);
        self::assertStringContainsString("'version' => \$config->version", $src);
        self::assertStringContainsString("'scope' => 'global'", $src);
    }

    public function test_role_is_server_derived_not_request_overridable(): void
    {
        $user = $this->user(7, User::ROLE_STAFF, 'Staff Name');
        $_GET['role'] = 'owner';
        $_POST['role'] = 'owner';
        $payload = SocialAutomationServiceHealth::publicUser($user);
        unset($_GET['role'], $_POST['role']);
        self::assertSame('staff', $payload['role']);

        $src = (string) file_get_contents($this->root.'/src/Support/SocialAutomationServiceHealth.php');
        self::assertStringContainsString("'role' => (string) (\$user->role ?? '')", $src);
        self::assertStringNotContainsString("input('role'", $src);
        self::assertStringNotContainsString('request()->', $src);

        $ctrl = (string) file_get_contents($this->root.'/src/Http/Controllers/SocialAutomationBootstrapController.php');
        self::assertStringNotContainsString("input('role'", $ctrl);
    }

    public function test_openapi_documents_bootstrap_user_role(): void
    {
        $openapi = (string) file_get_contents($this->root.'/openapi/openapi.yaml');
        self::assertStringContainsString('/api/social-automation/bootstrap:', $openapi);
        self::assertStringContainsString('enum: [owner, admin, staff]', $openapi);
        self::assertStringContainsString('Authenticated User.role from the server session', $openapi);
        self::assertStringContainsString('required: [id, name, role]', $openapi);
    }
}
