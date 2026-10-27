<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Filament\Pages;

use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\SocialAutomationPage as Access;
use Omnichannel\Addons\SocialAutomation\Registry\SessionSyncService;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Services\ClientNotificationService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;

final class SocialAutomationSessionsPage extends Page
{
    use Access;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $slug = 'clients';

    protected static ?int $navigationSort = 30;

    protected static string $view = 'social-automation::filament.pages.sessions';

    public static function getNavigationLabel(): string
    {
        return 'Clients';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Clients';
    }

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        return app(SessionSyncService::class)->listForUi();
    }

    public function dashboard(): array { return app(SessionSyncService::class)->clientDashboard(); }
    public function toggleDispatch(int $sessionId, bool $enabled): void { app(SessionSyncService::class)->setDispatch($sessionId, $enabled); }
    public function requireLogin(int $sessionId): void
    {
        $installationId = app(SocialAutomationInstallationResolver::class)->resolveInstallationId();
        $session = SaBrowserSession::query()->whereKey($sessionId)
            ->whereHas('agent', fn ($query) => $query->where('installation_id', $installationId))
            ->firstOrFail();
        $accountName = $session->account_id
            ? \Omnichannel\Addons\SocialAutomation\Models\SaAccount::query()->find($session->account_id)?->getAccountName()
            : null;
        app(ClientNotificationService::class)->requestLogin(
            $session,
            $installationId,
            (int) auth()->id(),
            'Vui lòng mở Browser cho '.($accountName ?: 'Account').' và đăng nhập lại, sau đó xác nhận đã xử lý.'
        );
    }
}
