<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Filament\Pages;

use Filament\Pages\Page;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\SocialAutomationPage;

/**
 * Thin API-first Filament entry — points operators to secured Swagger UI.
 * Legacy Filament business pages are not registered in api-first mode.
 */
final class SocialAutomationApiDocsPage extends Page
{
    use SocialAutomationPage;

    protected static ?string $navigationIcon = 'heroicon-o-code-bracket-square';

    protected static ?string $navigationLabel = 'API Docs';

    protected static ?string $title = 'Social Automation API';

    protected static ?string $slug = 'api-docs';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'social-automation::filament.pages.api-docs';

    public function getDocsUrl(): string
    {
        return url('/api/v1/social-automation/docs');
    }

    public function getOpenApiYamlUrl(): string
    {
        return url('/api/v1/social-automation/openapi.yaml');
    }

    public function getOpenApiJsonUrl(): string
    {
        return url('/api/v1/social-automation/openapi.json');
    }
}
