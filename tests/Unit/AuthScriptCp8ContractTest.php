<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackagePublishValidator;
use PHPUnit\Framework\TestCase;

/**
 * Source + validator contracts for CP8 Auth Script (no production DB).
 */
final class AuthScriptCp8ContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_migration_adds_auth_columns_and_empty_pivot(): void
    {
        $path = $this->root.'/database/migrations/2026_10_28_140000_auth_script_pin_and_account_tag_entitlement.php';
        self::assertFileExists($path);
        $sql = (string) file_get_contents($path);

        self::assertStringContainsString('is_authentication', $sql);
        self::assertStringContainsString('auth_script_id', $sql);
        self::assertStringContainsString('auth_release_id', $sql);
        self::assertStringContainsString('sa_account_action_tags', $sql);
        self::assertStringContainsString('omi_social_automation', $sql);
        // No automatic broad grants
        self::assertStringNotContainsString('INSERT INTO', $sql);
        self::assertStringNotContainsString('->insert(', $sql);
    }

    public function test_auth_pin_not_via_action_tag_scripts_in_service(): void
    {
        $svc = (string) file_get_contents($this->root.'/src/Services/AuthScriptService.php');
        self::assertStringContainsString('auth_script_id', $svc);
        self::assertStringContainsString('auth_release_id', $svc);
        self::assertStringNotContainsString('SaActionTagScript', $svc);
        self::assertStringContainsString('sa_account_action_tags', $svc);
    }

    public function test_publish_persists_is_authentication_without_upgrading_pins(): void
    {
        $registry = (string) file_get_contents($this->root.'/src/PackageRegistry/PackageRegistryService.php');
        self::assertStringContainsString('is_authentication', $registry);
        self::assertStringContainsString('never silently rewrite Action Tag auth_release_id', $registry);

        $validator = new PackagePublishValidator;
        $base = [
            'package_key' => 'facebook.login.auth',
            'kind' => 'script',
            'name' => 'FB Auth',
            'version' => '1',
            'schema_version' => 1,
            'is_authentication' => true,
            'manifest' => [
                'script_id' => 'facebook-login-auth',
                'variables' => [],
                'steps' => [['id' => 's1', 'action' => 'CLICK_TARGET']],
                'video_demo' => [
                    'artifact_ref' => 'demo.webm',
                    'test_run_id' => 'run-1',
                    'reviewed' => true,
                    'audio' => false,
                    'desktop_capture' => false,
                ],
            ],
            'dependencies' => [],
        ];
        $ok = $validator->validate($base);
        self::assertTrue($ok['ok'], implode(',', $ok['errors'] ?? []));
        self::assertTrue($ok['data']['is_authentication']);

        $widgetAuth = $validator->validate(array_merge($base, [
            'kind' => 'widget',
            'version' => '1.0.0',
            'manifest' => [
                'script_package_key' => 'facebook.login.auth',
                'script_version' => '1',
                'script_content_digest' => str_repeat('a', 64),
                'form' => ['fields' => []],
            ],
        ]));
        self::assertFalse($widgetAuth['ok']);
        self::assertContains('is_authentication_requires_script_kind', $widgetAuth['errors']);
    }

    public function test_staff_and_client_routes_wired(): void
    {
        $provider = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');
        self::assertStringContainsString('updateAuthPin', $provider);
        self::assertStringContainsString('authenticationScriptsCatalog', $provider);
        self::assertStringContainsString('grantTagAccount', $provider);
        self::assertStringContainsString('revokeTagAccount', $provider);
        self::assertStringContainsString('EnsureClientConnectionLease', $provider);
        self::assertStringContainsString('resolveAuthScript', $provider);
        self::assertStringContainsString('pullAuthScript', $provider);

        $routes = (string) file_get_contents($this->root.'/routes/api-services.php');
        self::assertStringContainsString('client/auth/resolve', $routes);
        self::assertStringContainsString('client/auth/pull', $routes);
    }

    public function test_browser_open_without_tag_not_rejected(): void
    {
        $sync = (string) file_get_contents($this->root.'/src/Registry/SessionSyncService.php');
        self::assertStringNotContainsString('action_tag_required', $sync);
        self::assertStringNotContainsString('action_tag_id_required', $sync);
        // Ordinary browser sync still centers on account_binding / browser_id
        self::assertStringContainsString('account_binding', $sync);
        self::assertStringContainsString('account_id_required', $sync);
    }

    public function test_client_auth_response_strips_secrets(): void
    {
        $ctrl = (string) file_get_contents($this->root.'/src/Http/Controllers/RpaClientApiController.php');
        self::assertStringContainsString('unset($release[\'encrypted_password\']', $ctrl);
        self::assertStringContainsString('resolveAuthScript', $ctrl);
        self::assertStringContainsString('pullAuthScript', $ctrl);
    }

    public function test_is_enabled_not_confused_with_is_authentication_in_migration(): void
    {
        $mig = (string) file_get_contents(
            $this->root.'/database/migrations/2026_10_28_140000_auth_script_pin_and_account_tag_entitlement.php'
        );
        self::assertStringContainsString("hasColumn('sa_packages', 'is_authentication')", $mig);
        self::assertStringContainsString("->after('is_enabled')", $mig);
    }

    public function test_openapi_documents_auth_endpoints(): void
    {
        $yaml = (string) file_get_contents($this->root.'/openapi/openapi.yaml');
        self::assertStringContainsString('/api/v1/social-automation/client/auth/resolve', $yaml);
        self::assertStringContainsString('/api/v1/social-automation/client/auth/pull', $yaml);
        self::assertStringContainsString('authentication-scripts', $yaml);
        self::assertStringContainsString('auth-pin', $yaml);
        self::assertStringContainsString('is_authentication', $yaml);
    }
}
