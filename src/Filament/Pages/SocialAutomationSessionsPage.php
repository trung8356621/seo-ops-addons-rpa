<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Filament\Pages;

use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\SocialAutomationPage as Access;
use Omnichannel\Addons\SocialAutomation\Registry\SessionSyncService;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Services\ClientNotificationService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use Illuminate\Support\Str;

final class SocialAutomationSessionsPage extends Page
{
    use Access;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $slug = 'clients';

    protected static ?int $navigationSort = 30;

    protected static string $view = 'social-automation::filament.pages.sessions';

    public bool $showNotificationModal = false;
    public ?int $notificationAgentId = null;
    public ?int $notificationSessionId = null;
    public string $notificationTemplate = '';
    public string $notificationMessage = '';

    public static function getNavigationLabel(): string
    {
        return 'Máy Client';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Máy Client';
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

    public function clearSessionSignal(int $sessionId): void
    {
        $installationId = app(SocialAutomationInstallationResolver::class)->resolveInstallationId();
        SaBrowserSession::query()->whereKey($sessionId)
            ->whereHas('agent', fn ($query) => $query->where('installation_id', $installationId))
            ->firstOrFail();
        app(SessionSyncService::class)->setSessionSignalPresent($sessionId, false);
    }

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

    /** @return list<array{id:int,label:string}> */
    public function notificationBrowserOptions(): array
    {
        if ($this->notificationAgentId === null) return [];
        return collect(app(SessionSyncService::class)->clientDashboard()['devices'])
            ->firstWhere('id', $this->notificationAgentId)['browsers'] ?? [];
    }

    public function openNotificationModal(int $agentId, ?int $sessionId = null): void
    {
        $installationId = app(SocialAutomationInstallationResolver::class)->resolveInstallationId();
        SaAgent::query()->whereKey($agentId)->where('installation_id', $installationId)->firstOrFail();
        $this->notificationAgentId = $agentId;
        $this->notificationSessionId = $sessionId;
        $this->notificationTemplate = '';
        $this->notificationMessage = '';
        $this->showNotificationModal = true;
    }

    public function applyNotificationTemplate(): void
    {
        $this->notificationMessage = match ($this->notificationTemplate) {
            'check_browser' => 'Vui lòng mở Browser và kiểm tra trạng thái hiển thị hiện tại.',
            'manual_modal' => 'Website đang hiển thị cửa sổ xác nhận. Vui lòng mở Browser và xử lý thủ công.',
            'check_account' => 'Vui lòng kiểm tra lại Account và phản hồi khi đã xử lý.',
            default => $this->notificationMessage,
        };
    }

    public function sendNotification(): void
    {
        $this->validate([
            'notificationAgentId'=>'required|integer',
            'notificationSessionId'=>'nullable|integer',
            'notificationMessage'=>'required|string|max:2000',
        ]);
        $installationId = app(SocialAutomationInstallationResolver::class)->resolveInstallationId();
        $agent = SaAgent::query()->whereKey($this->notificationAgentId)
            ->where('installation_id', $installationId)->firstOrFail();
        $session = $this->notificationSessionId
            ? SaBrowserSession::query()->whereKey($this->notificationSessionId)->where('agent_id', $agent->id)->firstOrFail()
            : null;
        app(ClientNotificationService::class)->create([
            'installation_id'=>$installationId,
            'agent_id'=>$agent->id,
            'browser_session_id'=>$session?->id,
            'account_id'=>$session?->account_id,
            'job_id'=>null,
            'type'=>'display_message',
            'message'=>$this->notificationMessage,
            'idempotency_key'=>'display-message:'.Str::uuid(),
        ], (int) auth()->id());
        $this->showNotificationModal = false;
    }

    public function closeNotificationModal(): void
    {
        $this->showNotificationModal = false;
    }
}
