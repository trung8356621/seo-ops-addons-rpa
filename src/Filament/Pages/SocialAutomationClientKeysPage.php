<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\SocialAutomationPage as Access;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Services\RpaClientApiKeyService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;

final class SocialAutomationClientKeysPage extends Page
{
    use Access;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $slug = 'client-keys';

    protected static ?int $navigationSort = 21;

    protected static string $view = 'social-automation::filament.pages.client-keys';

    public string $newKeyName = '';

    public ?string $recentlyCreatedRawKey = null;

    public ?string $recentlyCreatedKeyName = null;

    public bool $showCreateModal = false;

    public static function getNavigationLabel(): string
    {
        return __('social-automation::filament.client_keys.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('social-automation::filament.client_keys.title');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, SaClientApiKey>
     */
    public function rows()
    {
        return app(RpaClientApiKeyService::class)->list();
    }

    public function openCreateModal(): void
    {
        app(SocialAutomationAccess::class)->assertCanMutate();
        $this->newKeyName = '';
        $this->showCreateModal = true;
    }

    public function closeCreateModal(): void
    {
        $this->showCreateModal = false;
        $this->newKeyName = '';
    }

    public function createKey(): void
    {
        app(SocialAutomationAccess::class)->assertCanMutate();

        $name = trim($this->newKeyName);
        if ($name === '') {
            $name = 'Client API Key ' . date('Y-m-d H:i');
        }

        $userId = Auth::id() ? (int) Auth::id() : null;
        $result = app(RpaClientApiKeyService::class)->create($name, $userId);

        $this->recentlyCreatedRawKey = $result['raw_key'];
        $this->recentlyCreatedKeyName = $result['key']->name;
        $this->showCreateModal = false;
        $this->newKeyName = '';

        Notification::make()
            ->title(__('social-automation::filament.client_keys.create_success'))
            ->success()
            ->send();
    }

    public function dismissRawKeyBanner(): void
    {
        $this->recentlyCreatedRawKey = null;
        $this->recentlyCreatedKeyName = null;
    }

    public function revokeKey(int $id): void
    {
        app(SocialAutomationAccess::class)->assertCanMutate();

        $revoked = app(RpaClientApiKeyService::class)->revoke($id);
        if ($revoked) {
            Notification::make()
                ->title(__('social-automation::filament.client_keys.revoke_success'))
                ->warning()
                ->send();
        } else {
            Notification::make()
                ->title(__('social-automation::filament.client_keys.revoke_failed'))
                ->danger()
                ->send();
        }
    }
}
