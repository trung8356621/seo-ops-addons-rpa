<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use App\Models\User;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\SocialAutomationAccountManagementController;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Repositories\InMemorySaAccountRepository;
use Omnichannel\Addons\SocialAutomation\Services\SaAccountService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceResolver;
use PHPUnit\Framework\TestCase;

final class SocialAutomationAccountInventoryTest extends TestCase
{
    private InMemorySaAccountRepository $repository;
    private SocialAutomationInstallationResolver $installationResolver;
    private SaAccountService $accountService;
    private SocialAutomationAccess $access;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new InMemorySaAccountRepository();
        $this->installationResolver = new SocialAutomationInstallationResolver();
        $this->installationResolver->setForcedInstallationId('ins_alpha');

        $this->accountService = new SaAccountService(
            $this->repository,
            $this->installationResolver
        );

        $serviceResolver = new SocialAutomationServiceResolver();
        $this->access = new SocialAutomationAccess($serviceResolver);
        $this->access->setForcedActiveForTesting(true);
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\Auth::clearResolvedInstances();
        parent::tearDown();
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

    /**
     * Requirement A: Tạo Account không có password -> PASS (Lưu NULL, không lỗi).
     */
    public function test_a_create_account_without_password_succeeds_and_stores_null(): void
    {
        $account = $this->accountService->create([
            'platform' => 'Facebook',
            'account_name' => 'Brand Page 1',
            'username' => 'brandpage1',
            'email' => 'admin@brand.com',
            'password' => '', // Empty password
            'note' => 'Main marketing page',
        ], 10);

        self::assertInstanceOf(SaAccount::class, $account);
        self::assertSame('Brand Page 1', $account->getAccountName());
        self::assertSame('Facebook', $account->platform);
        self::assertSame('brandpage1', $account->getDisplayUsername());
        self::assertSame('admin@brand.com', $account->getDisplayEmail());
        self::assertNull($account->getAttribute('encrypted_password'));
        self::assertFalse($account->hasPassword());
        self::assertSame('ins_alpha', $account->getAttribute('installation_id'));
        self::assertSame(10, $account->created_by);
    }

    /**
     * Requirement B: Tạo Account có password -> encrypted at rest, never plaintext.
     */
    public function test_b_create_account_with_password_encrypts_at_rest(): void
    {
        $rawPassword = 'SuperSecretPassword@123!';
        $account = $this->accountService->create([
            'platform' => 'X',
            'account_name' => 'Twitter Main',
            'username' => 'twitter_main',
            'email' => 'twitter@example.com',
            'password' => $rawPassword,
        ], 10);

        self::assertInstanceOf(SaAccount::class, $account);
        self::assertTrue($account->hasPassword());

        $encrypted = (string) $account->getAttribute('encrypted_password');
        self::assertNotEmpty($encrypted);
        self::assertNotSame($rawPassword, $encrypted, 'Password must not be stored in plaintext');
        self::assertStringNotContainsString($rawPassword, $encrypted);

        // Recoverable decryption
        self::assertSame($rawPassword, $account->getDecryptedPassword());
    }

    /**
     * Requirement C: List API không lộ password (kể cả plaintext lẫn ciphertext).
     */
    public function test_c_list_api_does_not_leak_password(): void
    {
        $this->accountService->create([
            'platform' => 'Threads',
            'account_name' => 'Threads Channel',
            'username' => 'channel_threads',
            'email' => 'threads@channel.com',
            'password' => 'ConfidentialPassword999',
        ], 10);

        $admin = $this->makeUser(1, User::ROLE_ADMIN);
        \Illuminate\Support\Facades\Auth::shouldReceive('user')->andReturn($admin);

        $controller = new SocialAutomationAccountManagementController(
            $this->accountService,
            $this->access
        );

        $response = $controller->index(Request::create('/social-automation/api/accounts', 'GET'));
        $data = $response->getData(true);

        self::assertTrue($data['ok']);
        self::assertCount(1, $data['accounts']);

        $item = $data['accounts'][0];
        self::assertSame('Threads Channel', $item['account_name']);
        self::assertTrue($item['has_password']);
        self::assertArrayNotHasKey('password', $item);
        self::assertArrayNotHasKey('encrypted_password', $item);

        // SaAccount toArray / toJson serialization test
        $account = $this->repository->list('ins_alpha')->first();
        self::assertArrayNotHasKey('encrypted_password', $account->toArray());
        self::assertStringNotContainsString('ConfidentialPassword999', (string) $account->toJson());
        self::assertStringNotContainsString('encrypted_password', (string) $account->toJson());
    }

    /**
     * Requirement D: Sửa Account nhưng để trống password -> giữ nguyên password cũ nếu đang có.
     */
    public function test_d_update_account_with_empty_password_preserves_existing_password(): void
    {
        $rawPassword = 'OriginalSecretPassword123';
        $account = $this->accountService->create([
            'platform' => 'Facebook',
            'account_name' => 'Old Fanpage',
            'username' => 'old_page',
            'email' => 'old_fanpage@facebook.com',
            'password' => $rawPassword,
        ], 10);

        $originalEncrypted = $account->getAttribute('encrypted_password');
        self::assertNotEmpty($originalEncrypted);

        // Update name and username, but password is empty string
        $updated = $this->accountService->update($account->id, [
            'name' => 'Updated Fanpage Name',
            'username' => 'new_username',
            'password' => '', // User leaves blank
        ]);

        self::assertInstanceOf(SaAccount::class, $updated);
        self::assertSame('Updated Fanpage Name', $updated->getAccountName());
        self::assertSame('new_username', $updated->getDisplayUsername());

        // Password MUST be preserved
        self::assertSame($originalEncrypted, $updated->getAttribute('encrypted_password'));
        self::assertSame($rawPassword, $updated->getDecryptedPassword());

        // Now update WITH a new password
        $newRaw = 'BrandNewPassword456!';
        $updatedAgain = $this->accountService->update($account->id, [
            'password' => $newRaw,
        ]);

        self::assertNotSame($originalEncrypted, $updatedAgain->getAttribute('encrypted_password'));
        self::assertSame($newRaw, $updatedAgain->getDecryptedPassword());
    }

    /**
     * Requirement E: Xóa Account -> thành công.
     */
    public function test_e_delete_account_succeeds(): void
    {
        $account = $this->accountService->create([
            'platform' => 'Instagram',
            'account_name' => 'To Be Deleted',
            'email' => 'tobe_deleted@instagram.com',
        ], 10);

        self::assertCount(1, $this->accountService->list());

        $deleted = $this->accountService->delete($account->id);
        self::assertTrue($deleted);
        self::assertCount(0, $this->accountService->list());
    }

    /**
     * Requirement F: Không truy cập chéo Installation (Strict scoping).
     */
    public function test_f_installation_scoping_prevents_cross_access(): void
    {
        // Create in ins_alpha
        $accAlpha = $this->accountService->create([
            'platform' => 'Facebook',
            'account_name' => 'Alpha Account',
            'email' => 'alpha@facebook.com',
        ]);

        // Switch to ins_beta
        $this->installationResolver->setForcedInstallationId('ins_beta');

        // Cannot see account from ins_alpha
        self::assertCount(0, $this->accountService->list());
        self::assertNull($this->accountService->find($accAlpha->id));

        // Cannot update or delete across installation
        $updateResult = $this->accountService->update($accAlpha->id, ['name' => 'Hacked Name']);
        self::assertNull($updateResult);

        $deleteResult = $this->accountService->delete($accAlpha->id);
        self::assertFalse($deleteResult);

        // Switch back to ins_alpha -> account is intact
        $this->installationResolver->setForcedInstallationId('ins_alpha');
        $recovered = $this->accountService->find($accAlpha->id);
        self::assertNotNull($recovered);
        self::assertSame('Alpha Account', $recovered->getAccountName());
    }

    /**
     * Requirement G: Staff/Owner tuân thủ quyền hiện có.
     * Owner/Admin: quản lý (view, add, edit, delete).
     * Staff: xem / copy, không thể mutate.
     */
    public function test_g_authorization_roles(): void
    {
        $owner = $this->makeUser(1, User::ROLE_OWNER);
        $admin = $this->makeUser(2, User::ROLE_ADMIN);
        $staff = $this->makeUser(3, User::ROLE_STAFF);
        $blocked = $this->makeUser(4, User::ROLE_STAFF, User::STATUS_BLOCK);

        // Access checks
        self::assertTrue($this->access->canAccess($owner));
        self::assertTrue($this->access->canAccess($admin));
        self::assertTrue($this->access->canAccess($staff));
        self::assertFalse($this->access->canAccess($blocked));

        // Account management permissions
        self::assertTrue($this->access->canManageAccounts($owner));
        self::assertTrue($this->access->canManageAccounts($admin));
        self::assertFalse($this->access->canManageAccounts($staff));
        self::assertFalse($this->access->canManageAccounts($blocked));

        // Staff attempting to mutate throws 403
        \Illuminate\Support\Facades\Auth::shouldReceive('user')->andReturn($staff);

        $controller = new SocialAutomationAccountManagementController(
            $this->accountService,
            $this->access
        );

        // Staff can list
        $listResponse = $controller->index(Request::create('/social-automation/api/accounts', 'GET'));
        self::assertSame(200, $listResponse->getStatusCode());

        // Staff cannot store -> 403
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $controller->store(Request::create('/social-automation/api/accounts', 'POST', [
            'name' => 'Forbidden Staff Account',
        ]));
    }

    /**
     * Requirement: last_interaction_at is nullable, read-only in UI, not touched on create/edit.
     */
    public function test_last_interaction_at_remains_null_and_is_not_touched_by_create_or_update(): void
    {
        $account = $this->accountService->create([
            'platform' => 'Facebook',
            'name' => 'Interaction Test Account',
            'email' => 'test@interaction.com',
        ], 1);

        self::assertNull($account->last_interaction_at);

        // Update account
        $updated = $this->accountService->update($account->id, [
            'name' => 'Updated Account Name',
            'note' => 'Some note',
        ]);

        self::assertNull($updated->last_interaction_at);

        // Simulate RPA Client setting an interaction timestamp
        $interactionTime = '2026-10-26 12:00:00';
        $accountInDb = $this->repository->find($account->id, 'ins_alpha');
        $rawAttrs = $accountInDb->getAttributes();
        $rawAttrs['last_interaction_at'] = $interactionTime;
        $accountInDb->setRawAttributes($rawAttrs, true);

        // Further user edits must NOT overwrite or clear last_interaction_at
        $updatedAgain = $this->accountService->update($account->id, [
            'note' => 'Another note update',
        ]);

        self::assertSame($interactionTime, (string) $updatedAgain->getAttribute('last_interaction_at'));
    }

    /**
     * Requirement: Username removed from Accounts Page form and list, replaced by last_interaction.
     */
    public function test_page_and_view_username_removed_and_last_interaction_shown(): void
    {
        $pageSrc = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Filament/Pages/SocialAutomationAccountsPage.php'
        );
        self::assertStringNotContainsString('public string $username', $pageSrc);

        $bladeSrc = (string) file_get_contents(
            dirname(__DIR__, 2) . '/resources/views/filament/pages/accounts.blade.php'
        );
        self::assertStringNotContainsString('col_username', $bladeSrc);
        self::assertStringContainsString('col_last_interaction', $bladeSrc);
        self::assertStringContainsString('interaction_none', $bladeSrc);
    }

    public function test_email_is_strictly_required_on_create(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Email is required.');

        $this->accountService->create([
            'platform' => 'Facebook',
            'name' => 'No Email Account',
            'email' => '', // Empty email rejected
        ]);
    }

    public function test_wordpress_account_supports_custom_site_url_with_subdirectory(): void
    {
        $account = $this->accountService->create([
            'platform' => 'WordPress',
            'name' => 'Tech Blog Vietnam',
            'email' => 'author@techblog.vn',
            'site_url' => 'https://example.com/tech-blog',
            'password' => 'WpSecretPass#2026',
        ]);

        self::assertSame('https://example.com/tech-blog', $account->getSiteUrl());
        self::assertSame('author@techblog.vn', $account->getDisplayEmail());
        self::assertTrue($account->hasPassword());
    }

    public function test_list_bindable_accounts_includes_email_site_url_has_password_and_omits_password(): void
    {
        $this->accountService->create([
            'platform' => 'facebook',
            'name' => 'FB Seeding Account',
            'email' => 'seeder@fb.com',
            'password' => 'ConfidentialPassword',
        ]);

        $this->accountService->create([
            'platform' => 'wordpress',
            'name' => 'WP Subdir Site',
            'email' => 'admin@subdir.com',
            'site_url' => 'https://portal.domain.com/cms',
            'password' => '', // Empty password
        ]);

        // Manually inject a legacy account missing email (must NOT be deleted or mutated)
        $legacy = $this->repository->create([
            'account_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'installation_id' => 'ins_alpha',
            'platform' => 'facebook',
            'name' => 'Legacy Account No Email',
            'label' => 'Legacy Account No Email',
            'email' => null,
            'declared_email' => null,
            'identity_source' => 'legacy',
            'verified' => false,
        ]);

        $key = new \Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey();
        $key->id = 1;
        $key->setAttribute('installation_id', 'ins_alpha');
        $key->setAttribute('name', 'Test Key');

        $controller = new \Omnichannel\Addons\SocialAutomation\Http\Controllers\RpaClientApiController(
            new \Omnichannel\Addons\SocialAutomation\Registry\AgentRegistryService(),
            new \Omnichannel\Addons\SocialAutomation\Registry\SessionSyncService(new \Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator()),
            $this->repository
        );

        $request = \Illuminate\Http\Request::create('/api/v1/social-automation/client/accounts', 'GET');
        $request->attributes->set(\Omnichannel\Addons\SocialAutomation\Http\Middleware\EnsureRpaClientApiKey::REQUEST_CLIENT_KEY, $key);

        $response = $controller->listBindableAccounts($request);
        $data = $response->getData(true);

        self::assertTrue($data['ok']);
        self::assertCount(3, $data['accounts']);

        $accountsMap = collect($data['accounts'])->keyBy('display_name');

        // Check FB account
        $fb = $accountsMap['FB Seeding Account'];
        self::assertSame('seeder@fb.com', $fb['email']);
        self::assertTrue($fb['has_password']);
        self::assertArrayNotHasKey('password', $fb);
        self::assertArrayNotHasKey('encrypted_password', $fb);

        // Check WP account
        $wp = $accountsMap['WP Subdir Site'];
        self::assertSame('admin@subdir.com', $wp['email']);
        self::assertSame('https://portal.domain.com/cms', $wp['site_url']);
        self::assertFalse($wp['has_password']);

        // Check Legacy account
        $leg = $accountsMap['Legacy Account No Email'];
        self::assertNull($leg['email'], 'Legacy account email is null');
    }
}
