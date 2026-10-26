<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceHealth;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceResolver;

/**
 * Minimal Social Automation service status — activation, scope, DB readiness.
 */
final class SocialAutomationServiceStatusPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $slug = 'service';

    protected static ?int $navigationSort = 90;

    protected static bool $shouldRegisterNavigation = false;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected static string $view = 'social-automation::filament.pages.service-status';

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
        return app(SocialAutomationAccess::class)->canAccessAdminPages();
    }

    public static function getNavigationLabel(): string
    {
        return __('social-automation::filament.service.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('social-automation::filament.service.title');
    }

    public function mount(): void
    {
        app(SocialAutomationAccess::class)->assertCanAccessAdminPages();
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return app(SocialAutomationServiceHealth::class)->report();
    }

    public function isServiceActive(): bool
    {
        return app(SocialAutomationServiceResolver::class)->isActive();
    }
}
