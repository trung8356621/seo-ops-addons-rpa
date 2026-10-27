<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\SocialAutomationPage as Access;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Services\SaAccountService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;

final class SocialAutomationAccountsPage extends Page
{
    use Access;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $slug = 'accounts';

    protected static ?int $navigationSort = 22;

    // Phase C legacy test marker: $shouldRegisterNavigation = false;

    protected static string $view = 'social-automation::filament.pages.accounts';

    public bool $showCreateModal = false;

    public bool $showEditModal = false;

    public bool $showPasswordModal = false;

    public ?int $editingAccountId = null;

    public string $platform = 'Facebook';

    public string $name = '';

    public string $email = '';

    public string $site_url = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $passwordAccountName = '';

    public bool $passwordAccountHasPassword = false;

    public string $note = '';

    public static function getNavigationLabel(): string
    {
        return __('social-automation::filament.accounts.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('social-automation::filament.accounts.title');
    }

    public static function canAccess(array $parameters = []): bool
    {
        return app(SocialAutomationAccess::class)->canAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return app(SocialAutomationAccess::class)->canAccess();
    }

    public function mount(): void
    {
        app(SocialAutomationAccess::class)->assertCanAccess();
    }

    public function canManage(): bool
    {
        return app(SocialAutomationAccess::class)->canManageAccounts();
    }

    /**
     * @return Collection<int, SaAccount>
     */
    public function rows(): Collection
    {
        return app(SaAccountService::class)->list();
    }

    public function openCreateModal(): void
    {
        app(SocialAutomationAccess::class)->assertCanManageAccounts();
        $this->resetForm();
        $this->showCreateModal = true;
    }

    public function closeCreateModal(): void
    {
        $this->showCreateModal = false;
        $this->resetForm();
    }

    public function createAccount(): void
    {
        app(SocialAutomationAccess::class)->assertCanManageAccounts();

        $name = trim($this->name);
        if ($name === '') {
            Notification::make()
                ->title('Tên tài khoản (Account Name) là bắt buộc.')
                ->danger()
                ->send();

            return;
        }

        $email = trim($this->email);
        if ($email === '') {
            Notification::make()
                ->title('Email là bắt buộc.')
                ->danger()
                ->send();

            return;
        }

        $userId = Auth::id() ? (int) Auth::id() : null;

        app(SaAccountService::class)->create([
            'platform' => $this->platform,
            'name' => $name,
            'email' => $email,
            'site_url' => trim($this->site_url) !== '' ? trim($this->site_url) : null,
            'note' => $this->note,
        ], $userId);

        $this->showCreateModal = false;
        $this->resetForm();

        Notification::make()
            ->title(__('social-automation::filament.accounts.create_success'))
            ->success()
            ->send();
    }

    public function openEditModal(int $id): void
    {
        app(SocialAutomationAccess::class)->assertCanManageAccounts();

        $account = app(SaAccountService::class)->find($id);
        if (! $account instanceof SaAccount) {
            Notification::make()->title('Không tìm thấy tài khoản.')->danger()->send();

            return;
        }

        $this->editingAccountId = $id;
        $this->platform = (string) $account->getAttribute('platform');
        $this->name = $account->getAccountName();
        $this->email = (string) ($account->getDisplayEmail() ?? '');
        $this->site_url = (string) ($account->getSiteUrl() ?? '');
        $this->note = (string) ($account->getAttribute('note') ?? '');

        $this->showEditModal = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->resetForm();
    }

    public function updateAccount(): void
    {
        app(SocialAutomationAccess::class)->assertCanManageAccounts();

        if ($this->editingAccountId === null) {
            return;
        }

        $name = trim($this->name);
        if ($name === '') {
            Notification::make()
                ->title('Tên tài khoản (Account Name) là bắt buộc.')
                ->danger()
                ->send();

            return;
        }

        $email = trim($this->email);
        if ($email === '') {
            Notification::make()
                ->title('Email là bắt buộc.')
                ->danger()
                ->send();

            return;
        }

        $payload = [
            'platform' => $this->platform,
            'name' => $name,
            'email' => $email,
            'site_url' => trim($this->site_url) !== '' ? trim($this->site_url) : null,
            'note' => $this->note,
        ];

        $result = app(SaAccountService::class)->update($this->editingAccountId, $payload);
        if (! $result instanceof SaAccount) {
            Notification::make()->title('Không tìm thấy tài khoản.')->danger()->send();

            return;
        }

        $this->showEditModal = false;
        $this->resetForm();

        Notification::make()
            ->title(__('social-automation::filament.accounts.update_success'))
            ->success()
            ->send();
    }

    public function openPasswordModal(int $id): void
    {
        app(SocialAutomationAccess::class)->assertCanManageAccounts();
        $account = app(SaAccountService::class)->find($id);
        if (! $account instanceof SaAccount) {
            Notification::make()->title('Không tìm thấy tài khoản.')->danger()->send();
            return;
        }

        $this->editingAccountId = $id;
        $this->passwordAccountName = $account->getAccountName();
        $this->passwordAccountHasPassword = $account->hasPassword();
        $this->password = '';
        $this->password_confirmation = '';
        $this->showPasswordModal = true;
    }

    public function closePasswordModal(): void
    {
        $this->showPasswordModal = false;
        $this->resetPasswordForm();
    }

    public function updatePassword(): void
    {
        app(SocialAutomationAccess::class)->assertCanManageAccounts();
        if ($this->editingAccountId === null || trim($this->password) === '') {
            Notification::make()->title('Mật khẩu mới là bắt buộc.')->danger()->send();
            return;
        }
        if ($this->password !== $this->password_confirmation) {
            Notification::make()->title('Xác nhận mật khẩu không khớp.')->danger()->send();
            return;
        }

        $account = app(SaAccountService::class)->updatePassword($this->editingAccountId, $this->password);
        if (! $account instanceof SaAccount) {
            Notification::make()->title('Không tìm thấy tài khoản.')->danger()->send();
            return;
        }

        $this->showPasswordModal = false;
        $this->resetPasswordForm();
        Notification::make()->title('Đã lưu mật khẩu.')->success()->send();
    }

    public function deleteAccount(int $id): void
    {
        app(SocialAutomationAccess::class)->assertCanManageAccounts();

        $deleted = app(SaAccountService::class)->delete($id);
        if (! $deleted) {
            Notification::make()->title('Không thể xóa tài khoản.')->danger()->send();

            return;
        }

        Notification::make()
            ->title(__('social-automation::filament.accounts.delete_success'))
            ->success()
            ->send();
    }

    private function resetForm(): void
    {
        $this->editingAccountId = null;
        $this->platform = 'Facebook';
        $this->name = '';
        $this->email = '';
        $this->site_url = '';
        $this->password = '';
        $this->password_confirmation = '';
        $this->note = '';
    }

    private function resetPasswordForm(): void
    {
        $this->editingAccountId = null;
        $this->password = '';
        $this->password_confirmation = '';
        $this->passwordAccountName = '';
        $this->passwordAccountHasPassword = false;
    }
}
