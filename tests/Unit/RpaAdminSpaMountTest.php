<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Http\Controllers\RpaAdmin\RpaAdminSpaController;
use PHPUnit\Framework\TestCase;

/**
 * CP9B — React RPA Admin same-origin SPA mount contracts.
 */
final class RpaAdminSpaMountTest extends TestCase
{
    private string $root;

    /** @var list<string> */
    private const SPA_PATHS = [
        '/action-tags',
        '/scripts',
        '/accounts',
        '/clients',
        '/client-api-keys',
    ];

    /** @var list<string> */
    private const SPA_ROUTE_NAMES = [
        'social-automation.spa.action-tags',
        'social-automation.spa.scripts',
        'social-automation.spa.accounts',
        'social-automation.spa.clients',
        'social-automation.spa.client-api-keys',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_five_spa_routes_registered_exactly(): void
    {
        $source = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');

        foreach (self::SPA_PATHS as $path) {
            self::assertStringContainsString("Route::get('{$path}', RpaAdminSpaController::class)", $source);
        }

        foreach (self::SPA_ROUTE_NAMES as $name) {
            self::assertStringContainsString("->name('{$name}')", $source);
        }

        // No broad SPA catch-all.
        self::assertStringNotContainsString("Route::get('/{any}", $source);
        self::assertStringNotContainsString("where('any'", $source);
        self::assertStringNotContainsString("fallback(", $source);
        self::assertSame(5, substr_count($source, 'RpaAdminSpaController::class'));
    }

    public function test_spa_routes_use_staff_session_authenticate_middleware(): void
    {
        $source = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');

        // Same Staff Session stack as existing /social-automation/api/* routes.
        self::assertStringContainsString('Authenticate::class', $source);
        self::assertStringContainsString('StartSession::class', $source);
        self::assertStringContainsString('VerifyCsrfToken::class', $source);

        $spaBlockStart = strpos($source, "Route::get('/action-tags', RpaAdminSpaController::class)");
        self::assertNotFalse($spaBlockStart);

        $prefixBefore = strrpos(substr($source, 0, $spaBlockStart), "->prefix('social-automation')");
        self::assertNotFalse($prefixBefore);

        $middlewareBefore = strrpos(substr($source, 0, $spaBlockStart), 'Route::middleware($middleware)');
        self::assertNotFalse($middlewareBefore);
        self::assertLessThan($spaBlockStart, $middlewareBefore);

        // Anonymous guests are blocked by Authenticate (redirect/401) — not public.
        self::assertStringContainsString('\\Illuminate\\Auth\\Middleware\\Authenticate::class', $source);
    }

    public function test_missing_build_returns_controlled_unavailable(): void
    {
        $missing = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rpa-spa-missing-'.uniqid('', true).'.html';
        self::assertFileDoesNotExist($missing);

        $response = RpaAdminSpaController::responseForIndex($missing);

        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('temporarily unavailable', (string) $response->getContent());
        self::assertStringNotContainsString($missing, (string) $response->getContent());
        self::assertStringNotContainsString('stack', strtolower((string) $response->getContent()));
        self::assertStringNotContainsString('Exception', (string) $response->getContent());
        self::assertStringNotContainsString('build-rpa-web', (string) $response->getContent());
    }

    public function test_existing_build_serves_spa_html(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rpa-spa-'.uniqid('', true);
        self::assertTrue(mkdir($dir));
        $index = $dir.DIRECTORY_SEPARATOR.'index.html';
        $shell = "<!DOCTYPE html><html><head><title>RPA Admin</title></head><body><div id=\"root\"></div><script type=\"module\" src=\"/build-rpa-web/assets/index.js\"></script></body></html>";
        self::assertNotFalse(file_put_contents($index, $shell));

        try {
            $response = RpaAdminSpaController::responseForIndex($index);

            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            self::assertSame($shell, $response->getContent());
            self::assertStringContainsString('id="root"', (string) $response->getContent());
        } finally {
            @unlink($index);
            @rmdir($dir);
        }
    }

    public function test_empty_build_file_handled_as_unavailable(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rpa-spa-empty-'.uniqid('', true);
        self::assertTrue(mkdir($dir));
        $index = $dir.DIRECTORY_SEPARATOR.'index.html';
        self::assertNotFalse(file_put_contents($index, ''));

        try {
            $response = RpaAdminSpaController::responseForIndex($index);
            self::assertSame(503, $response->getStatusCode());
            self::assertStringContainsString('temporarily unavailable', (string) $response->getContent());
        } finally {
            @unlink($index);
            @rmdir($dir);
        }
    }

    public function test_api_and_media_routes_unaffected(): void
    {
        $source = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');

        self::assertStringContainsString("prefix('api/action-registry')", $source);
        self::assertStringContainsString("prefix('api/client-keys')", $source);
        self::assertStringContainsString("prefix('api/accounts')", $source);
        self::assertStringContainsString("'/script-media/{mediaId}'", $source);
        self::assertStringContainsString('social-automation.script-media.show', $source);
        self::assertStringContainsString("prefix('api/v1/social-automation/client')", $source);
        self::assertStringContainsString("prefix('api/v1/social-automation/rpa-admin')", $source);

        // SPA shells are siblings of api/*, not replacements.
        self::assertStringContainsString("Route::get('/action-tags', RpaAdminSpaController::class)", $source);
        self::assertStringContainsString("Route::get('/action-tags', [RpaAdminScriptSyncController::class, 'actionTags'])", $source);
    }

    public function test_legacy_filament_action_tags_preserved(): void
    {
        self::assertFileExists($this->root.'/src/Filament/Pages/SocialAutomationActionTagsPage.php');
        self::assertFileExists($this->root.'/resources/views/filament/pages/action-tags.blade.php');

        $page = (string) file_get_contents($this->root.'/src/Filament/Pages/SocialAutomationActionTagsPage.php');
        self::assertStringContainsString("slug = 'action-tags'", $page);
        self::assertStringContainsString('social-automation::filament.pages.action-tags', $page);

        $panel = (string) file_get_contents($this->root.'/src/Providers/SocialAutomationPanelProvider.php');
        self::assertStringContainsString("->path('social-automation-panel')", $panel);
        self::assertStringContainsString('->pages([])', $panel);
        self::assertStringNotContainsString('SocialAutomationActionTagsPage', $panel);
    }

    public function test_preserved_host_paths_not_captured_by_spa(): void
    {
        $source = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');

        // Panel / docs / rpa-admin API remain distinct.
        self::assertStringContainsString("path('social-automation-panel')", (string) file_get_contents(
            $this->root.'/src/Providers/SocialAutomationPanelProvider.php'
        ));
        self::assertStringContainsString('/social-automation/api-docs', $source);
        self::assertStringNotContainsString("Route::get('/login'", $source);
        self::assertStringNotContainsString("Route::get('/seo'", $source);
        self::assertStringNotContainsString("Route::get('/admin'", $source);

        // Asset prefix constant for React build.
        self::assertSame('build-rpa-web/index.html', RpaAdminSpaController::INDEX_RELATIVE);
    }

    public function test_controller_does_not_alter_staff_api_csrf_or_auth(): void
    {
        $controller = (string) file_get_contents(
            $this->root.'/src/Http/Controllers/RpaAdmin/RpaAdminSpaController.php'
        );

        self::assertStringNotContainsString('VerifyCsrfToken', $controller);
        self::assertStringNotContainsString('EnsureRpaAdminUserApi', $controller);
        self::assertStringNotContainsString('EnsureRpaClientApiKey', $controller);
        self::assertStringContainsString('build-rpa-web/index.html', $controller);
    }
}
