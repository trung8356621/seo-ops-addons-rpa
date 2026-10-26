<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\SocialAutomation\Services\RpaClientApiKeyService;
use Omnichannel\Addons\SocialAutomation\Support\DashboardMetrics;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceHealth;

/**
 * Global Social Automation dashboard — metrics & compact RPA Client Connection card.
 */
final class SocialAutomationDashboardPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $slug = '/';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'social-automation::filament.pages.dashboard';

    public string $clientEndpoint = '';

    public string $clientApiKey = '';

    public bool $isKeyActivated = false;

    public string $keyStatusLabel = '';

    public ?string $candidateToken = null;

    public bool $justActivatedAndCopied = false;

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function getUrl(
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?\Illuminate\Database\Eloquent\Model $tenant = null,
    ): string {
        return parent::getUrl($parameters, $isAbsolute, $panel ?? 'social-automation', $tenant);
    }

    public static function canAccess(array $parameters = []): bool
    {
        return app(SocialAutomationAccess::class)->canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return __('social-automation::filament.dashboard.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('social-automation::filament.dashboard.title');
    }

    public function mount(): void
    {
        app(SocialAutomationAccess::class)->assertCanAccess();

        $this->clientEndpoint = url('/');
        $this->loadKeyData();
    }

    public function loadKeyData(): void
    {
        $data = app(RpaClientApiKeyService::class)->getActiveOrCandidateKey();

        $this->isKeyActivated = (bool) ($data['is_activated'] ?? false);
        $this->keyStatusLabel = (string) ($data['status_label'] ?? 'Chưa kích hoạt');
        $this->clientApiKey = (string) ($data['raw_key'] ?? '');
        $this->candidateToken = $data['candidate_token'] ?? null;
    }

    /**
     * Copy action: If candidate, activates atomically first, then copies to clipboard.
     */
    public function copyAndActivateKey(): void
    {
        app(SocialAutomationAccess::class)->assertCanMutate();

        if ($this->isKeyActivated) {
            $this->dispatch('copy-key-to-clipboard', text: $this->clientApiKey);
            Notification::make()
                ->title('Đã sao chép Client API Key vào clipboard.')
                ->success()
                ->send();

            return;
        }

        if (! $this->candidateToken) {
            $this->loadKeyData();
        }

        $userId = Auth::id() ? (int) Auth::id() : null;
        $result = app(RpaClientApiKeyService::class)->activateCandidateKey((string) $this->candidateToken, $userId);

        if (! ($result['ok'] ?? false)) {
            Notification::make()
                ->title('Không thể kích hoạt Key')
                ->body($result['message'] ?? 'Vui lòng làm mới trang.')
                ->danger()
                ->send();

            return;
        }

        $rawKey = (string) ($result['raw_key'] ?? $this->clientApiKey);
        $this->clientApiKey = $rawKey;
        $this->isKeyActivated = true;
        $this->keyStatusLabel = 'Đã kích hoạt';
        $this->candidateToken = null;
        $this->justActivatedAndCopied = true;

        $this->dispatch('copy-key-to-clipboard', text: $rawKey);

        Notification::make()
            ->title('Đã kích hoạt và sao chép')
            ->body('Key đã được kích hoạt thành công trên SEO-OPS và sao chép vào clipboard.')
            ->success()
            ->send();
    }

    /**
     * @return array<string, mixed>
     */
    public function statusPayload(): array
    {
        return app(SocialAutomationServiceHealth::class)->report();
    }

    /**
     * @return array<string, mixed>
     */
    public function metrics(): array
    {
        return app(DashboardMetrics::class)->snapshot();
    }
}
