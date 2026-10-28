<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class OpenApiAndApiFirstContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_addon_manifest_declares_api_first(): void
    {
        $meta = json_decode((string) file_get_contents($this->root.'/addon.json'), true);
        self::assertIsArray($meta);
        self::assertTrue((bool) ($meta['api_first'] ?? false));
        self::assertSame('0.2.0', $meta['version'] ?? null);
        self::assertContains('social-automation.openapi', $meta['provides'] ?? []);
    }

    public function test_openapi_spec_file_exists_and_is_v3(): void
    {
        $path = $this->root.'/openapi/openapi.yaml';
        self::assertFileExists($path);
        $raw = (string) file_get_contents($path);
        self::assertStringContainsString('openapi: 3.0.3', $raw);
        self::assertStringContainsString('/api/v1/social-automation/rpa-admin/login', $raw);
        self::assertStringContainsString('/api/v1/social-automation/client/register', $raw);
        self::assertStringContainsString('/api/v1/services/{service}/social-automation/packages', $raw);
        self::assertStringContainsString('RpaAdminBearer', $raw);
        self::assertStringContainsString('RpaClientApiKey', $raw);
        self::assertStringContainsString('ServiceApiBearer', $raw);
        self::assertStringNotContainsString('sk_live_', $raw);
        self::assertStringNotContainsString('password123', $raw);
    }

    public function test_provider_registers_secured_docs_routes(): void
    {
        $source = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');
        self::assertStringContainsString('EnsureSocialAutomationDocsAccess::class', $source);
        self::assertStringContainsString("prefix('api/v1/social-automation')", $source);
        self::assertStringContainsString("'/docs'", $source);
        self::assertStringContainsString("'/openapi.yaml'", $source);
        self::assertStringContainsString("'/openapi.json'", $source);
        self::assertStringContainsString('OpenApiController::class', $source);
    }

    public function test_panel_registers_api_docs_only(): void
    {
        $source = (string) file_get_contents($this->root.'/src/Providers/SocialAutomationPanelProvider.php');
        self::assertStringContainsString('SocialAutomationApiDocsPage::class', $source);
        self::assertStringNotContainsString('SocialAutomationDashboardPage::class', $source);
        self::assertStringNotContainsString('SocialAutomationScriptsPage::class', $source);
        self::assertStringNotContainsString('SocialAutomationAgentsPage::class', $source);
        self::assertStringNotContainsString('SocialAutomationClientKeysPage::class', $source);
    }

    public function test_docs_middleware_reuses_seo_ops_identity(): void
    {
        $source = (string) file_get_contents($this->root.'/src/Http/Middleware/EnsureSocialAutomationDocsAccess.php');
        self::assertStringContainsString('canAccessAdminPages', $source);
        self::assertStringContainsString('RpaAdminSessionService', $source);
        self::assertStringNotContainsString('jwt', strtolower($source));
        self::assertStringNotContainsString('new User', $source);
    }
}
