<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use App\Models\User;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\SocialAutomationActionTagsPage;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\SocialAutomationAgentsPage;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\SocialAutomationClientKeysPage;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\SocialAutomationDashboardPage;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\SocialAutomationJobsPage;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\SocialAutomationPackagesPage;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\SocialAutomationSchedulesPage;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\SocialAutomationScriptsPage;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\SocialAutomationServiceStatusPage;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\SocialAutomationSessionsPage;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\SocialAutomationTasksPage;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\SocialAutomationWidgetsPage;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\SocialAutomationWorkflowsPage;
use Omnichannel\Addons\SocialAutomation\Services\RpaClientApiKeyService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceResolver;
use Omnichannel\Addons\SocialAutomation\Repositories\InMemoryRpaClientApiKeyRepository;
use Omnichannel\Addons\SocialAutomation\Domain\RpaClientApiKeyGenerator;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use PHPUnit\Framework\TestCase;

final class StaffUiAndNavigationTest extends TestCase
{
    private SocialAutomationAccess $access;
    private InMemoryRpaClientApiKeyRepository $repo;
    private RpaClientApiKeyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $resolver = new SocialAutomationServiceResolver();
        $this->access = new SocialAutomationAccess($resolver);
        $this->access->setForcedActiveForTesting(true);

        $generator = new RpaClientApiKeyGenerator();
        $installationResolver = new SocialAutomationInstallationResolver();
        $installationResolver->setForcedInstallationId('inst_test_01');
        $this->repo = new InMemoryRpaClientApiKeyRepository();
        $this->service = new RpaClientApiKeyService(
            $generator,
            $installationResolver,
            $this->repo
        );
    }

    private function makeUser(int $id, string $role, string $status = User::STATUS_NORMAL): User
    {
        $user = new User();
        $user->id = $id;
        $user->role = $role;
        $user->status = $status;
        $user->email = "user{$id}@example.com";

        return $user;
    }

    public function test_staff_role_identification(): void
    {
        $staff = $this->makeUser(10, User::ROLE_STAFF);
        $admin = $this->makeUser(11, User::ROLE_ADMIN);
        $owner = $this->makeUser(12, User::ROLE_OWNER);

        $this->assertTrue($this->access->isStaff($staff));
        $this->assertFalse($this->access->isStaff($admin));
        $this->assertFalse($this->access->isStaff($owner));
    }

    public function test_staff_can_access_social_automation_but_cannot_access_admin_pages(): void
    {
        $staff = $this->makeUser(10, User::ROLE_STAFF);

        // Staff can access Social Automation root
        $this->assertTrue($this->access->canAccess($staff));

        // Staff cannot access admin pages
        $this->assertFalse($this->access->canAccessAdminPages($staff));
    }

    public function test_owner_and_admin_can_access_admin_pages(): void
    {
        $admin = $this->makeUser(11, User::ROLE_ADMIN);
        $owner = $this->makeUser(12, User::ROLE_OWNER);

        $this->assertTrue($this->access->canAccessAdminPages($admin));
        $this->assertTrue($this->access->canAccessAdminPages($owner));
    }

    public function test_admin_pages_reject_staff_access(): void
    {
        $adminPages = [
            SocialAutomationActionTagsPage::class,
            SocialAutomationScriptsPage::class,
            SocialAutomationWidgetsPage::class,
            SocialAutomationWorkflowsPage::class,
            SocialAutomationPackagesPage::class,
            SocialAutomationAgentsPage::class,
            SocialAutomationClientKeysPage::class,
            SocialAutomationSessionsPage::class,
            SocialAutomationTasksPage::class,
            SocialAutomationSchedulesPage::class,
            SocialAutomationJobsPage::class,
            SocialAutomationServiceStatusPage::class,
        ];

        $staff = $this->makeUser(10, User::ROLE_STAFF);
        \Illuminate\Support\Facades\Auth::shouldReceive('user')->andReturn($staff);

        // Bind SocialAutomationAccess in container
        app()->instance(SocialAutomationAccess::class, $this->access);

        foreach ($adminPages as $pageClass) {
            $this->assertFalse(
                $pageClass::canAccess(),
                "Staff should NOT be able to access {$pageClass}"
            );
        }
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\Auth::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_admin_pages_allow_admin_and_owner_access(): void
    {
        \Illuminate\Support\Facades\Auth::clearResolvedInstances();
        $admin = $this->makeUser(11, User::ROLE_ADMIN);
        \Illuminate\Support\Facades\Auth::shouldReceive('user')->andReturn($admin);

        app()->instance(SocialAutomationAccess::class, $this->access);

        $this->assertTrue(SocialAutomationScriptsPage::canAccess());
        $this->assertTrue(SocialAutomationClientKeysPage::canAccess());
        $this->assertTrue(SocialAutomationActionTagsPage::canAccess());
        $this->assertTrue(SocialAutomationAgentsPage::canAccess());
    }

    public function test_staff_dashboard_navigation_is_suppressed(): void
    {
        $staff = $this->makeUser(10, User::ROLE_STAFF);
        \Illuminate\Support\Facades\Auth::shouldReceive('user')->andReturn($staff);
        app()->instance(SocialAutomationAccess::class, $this->access);

        $this->assertFalse(SocialAutomationDashboardPage::shouldRegisterNavigation());

        $admin = $this->makeUser(11, User::ROLE_ADMIN);
        \Illuminate\Support\Facades\Auth::clearResolvedInstances();
        \Illuminate\Support\Facades\Auth::shouldReceive('user')->andReturn($admin);

        $this->assertTrue(SocialAutomationDashboardPage::shouldRegisterNavigation());
    }

    public function test_staff_lazy_activation_flow(): void
    {
        // 1. First load: Candidate key generated, not in DB
        $load1 = $this->service->getActiveOrCandidateKey();
        $this->assertFalse($load1['is_activated']);
        $this->assertNotEmpty($load1['raw_key']);
        $this->assertNotEmpty($load1['candidate_token']);
        $this->assertCount(0, $this->repo->list('inst_test_01'));

        // 2. Staff copies and activates
        $activation = $this->service->activateCandidateKey($load1['candidate_token'], 10);
        $this->assertTrue($activation['ok']);
        $this->assertSame($load1['raw_key'], $activation['raw_key']);
        $this->assertCount(1, $this->repo->list('inst_test_01'));

        // 3. Reload does not create a new key
        $load2 = $this->service->getActiveOrCandidateKey();
        $this->assertTrue($load2['is_activated']);
        $this->assertSame($load1['raw_key'], $load2['raw_key']);
        $this->assertCount(1, $this->repo->list('inst_test_01'));
    }
}
