<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Source-contract checks for Client auth/asset download.
 */
final class AuthAssetContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_service_exposes_download_asset_for_client(): void
    {
        $svc = (string) file_get_contents($this->root.'/src/Services/AuthScriptService.php');
        self::assertStringContainsString('function downloadAssetForClient', $svc);
        self::assertStringContainsString('findEligiblePinnedAuthRelease', $svc);
        self::assertStringContainsString('isSafeAssetPath', $svc);
        self::assertStringContainsString('ScriptReleaseAssetReader', $svc);
        self::assertStringContainsString('loadVerified', $svc);
        $reader = (string) file_get_contents($this->root.'/src/Support/ScriptReleaseAssetReader.php');
        self::assertStringContainsString('asset_sha256_mismatch', $reader);
        // Shared eligibility with context/resolve/pull
        self::assertMatchesRegularExpression(
            '/function downloadAssetForClient[\s\S]*findEligiblePinnedAuthRelease/',
            $svc
        );
        self::assertStringContainsString("Storage::disk('local')", $reader);
        self::assertStringContainsString('SaScriptReleaseAsset', $reader);
        self::assertMatchesRegularExpression(
            '/function findEligiblePinnedAuthRelease[\s\S]*assertAuthTagEligibleForAccount/',
            $svc
        );
        // Must not gate Auth asset on sa_account_action_tags
        self::assertDoesNotMatchRegularExpression(
            '/function downloadAssetForClient[\s\S]*?function findEligiblePinnedAuthRelease[\s\S]*?SaAccountActionTag::query/',
            $svc
        );
        // Never leak storage_path in success payload keys
        self::assertDoesNotMatchRegularExpression(
            "/return \[\s*'ok' => true,\s*'asset_path'[\s\S]*'storage_path'/",
            $svc
        );
    }

    public function test_path_traversal_rejected(): void
    {
        $svc = (string) file_get_contents($this->root.'/src/Services/AuthScriptService.php');
        self::assertStringContainsString('isSafeAssetPath', $svc);
        $reader = (string) file_get_contents($this->root.'/src/Support/ScriptReleaseAssetReader.php');
        self::assertStringContainsString("str_contains(\$assetPath, '..')", $reader);
        self::assertStringContainsString("str_starts_with(\$assetPath, '/')", $reader);
        self::assertStringContainsString('isSafeAssetPath', $reader);
    }

    public function test_controller_and_routes_wire_auth_asset(): void
    {
        $ctrl = (string) file_get_contents($this->root.'/src/Http/Controllers/RpaClientApiController.php');
        self::assertStringContainsString('function downloadAuthAsset', $ctrl);
        self::assertStringContainsString('downloadAssetForClient', $ctrl);
        self::assertStringContainsString('asset_base64', $ctrl);
        self::assertStringContainsString("format === 'binary'", $ctrl);
        self::assertStringContainsString('X-Checksum-Sha256', $ctrl);
        self::assertStringContainsString('installation_forbidden', $ctrl);
        // Bound to this method only — no credential / filesystem leak in success payload
        self::assertMatchesRegularExpression(
            '/function downloadAuthAsset\([^)]*\):[^{]*\{[\s\S]*?asset_base64[\s\S]*?\}/',
            $ctrl
        );
        if (preg_match('/function downloadAuthAsset\([^)]*\):[^{]*\{([\s\S]*?)\n    (?:private |public )?function /', $ctrl, $m) !== 1) {
            self::fail('downloadAuthAsset method body not isolated');
        }
        $body = $m[1];
        self::assertStringNotContainsString('encrypted_password', $body);
        self::assertStringNotContainsString('storage_path', $body);
        self::assertStringNotContainsString('cookies', $body);

        $routes = (string) file_get_contents($this->root.'/routes/api-services.php');
        self::assertStringContainsString('client/auth/asset', $routes);
        self::assertStringContainsString('downloadAuthAsset', $routes);

        $provider = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');
        self::assertStringContainsString('/auth/asset', $provider);
        self::assertStringContainsString('downloadAuthAsset', $provider);
        self::assertStringContainsString('EnsureClientConnectionLease::class', $provider);
    }

    public function test_openapi_documents_auth_asset(): void
    {
        $yaml = (string) file_get_contents($this->root.'/openapi/openapi.yaml');
        self::assertStringContainsString('/api/v1/social-automation/client/auth/asset', $yaml);
        self::assertStringContainsString('clientAuthAsset', $yaml);
        self::assertStringContainsString('ClientAuthAssetRequest', $yaml);
        self::assertStringContainsString('ClientAuthAssetSuccess', $yaml);
        self::assertStringContainsString('expected_sha256', $yaml);
        self::assertStringContainsString('asset_base64', $yaml);
    }

    public function test_existing_auth_contracts_still_present(): void
    {
        $routes = (string) file_get_contents($this->root.'/routes/api-services.php');
        self::assertStringContainsString('client/auth/context', $routes);
        self::assertStringContainsString('client/auth/resolve', $routes);
        self::assertStringContainsString('client/auth/pull', $routes);
        self::assertStringContainsString('client/auth/asset', $routes);

        $ctrl = (string) file_get_contents($this->root.'/src/Http/Controllers/RpaClientApiController.php');
        self::assertStringContainsString('function authContext', $ctrl);
        self::assertStringContainsString('function resolveAuthScript', $ctrl);
        self::assertStringContainsString('function pullAuthScript', $ctrl);
        self::assertStringContainsString('function downloadAuthAsset', $ctrl);
    }
}
