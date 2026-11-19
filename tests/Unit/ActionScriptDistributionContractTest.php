<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Support\ScriptReleaseAssetReader;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for Client Action Script pull/asset + Claim pin fields.
 * Source/OpenAPI/policy presence — no MOCK E2E / no live Claim.
 */
final class ActionScriptDistributionContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_routes_and_controller_wire_action_script_endpoints(): void
    {
        $provider = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');
        self::assertStringContainsString("/scripts/pull", $provider);
        self::assertStringContainsString("/scripts/asset", $provider);
        self::assertStringContainsString('pullActionScript', $provider);
        self::assertStringContainsString('downloadActionAsset', $provider);
        self::assertStringContainsString('EnsureClientConnectionLease::class', $provider);

        $routes = (string) file_get_contents($this->root.'/routes/api-services.php');
        self::assertStringContainsString('client/scripts/pull', $routes);
        self::assertStringContainsString('client/scripts/asset', $routes);

        $ctrl = (string) file_get_contents($this->root.'/src/Http/Controllers/RpaClientApiController.php');
        self::assertStringContainsString('function pullActionScript', $ctrl);
        self::assertStringContainsString('function downloadActionAsset', $ctrl);
        self::assertStringContainsString('ActionScriptDistributionService', $ctrl);
    }

    public function test_auth_endpoints_remain_separate_and_present(): void
    {
        $provider = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');
        self::assertStringContainsString('/auth/pull', $provider);
        self::assertStringContainsString('/auth/asset', $provider);
        self::assertStringContainsString('/scripts/pull', $provider);
        // Must not reuse Auth methods for Action Scripts.
        self::assertStringContainsString('pullActionScript', $provider);
        self::assertStringContainsString('downloadActionAsset', $provider);
        self::assertStringNotContainsString("'/scripts/pull', [RpaClientApiController::class, 'pullAuthScript']", $provider);
    }

    public function test_service_enforces_job_pin_and_published_only(): void
    {
        $svc = (string) file_get_contents($this->root.'/src/Services/ActionScriptDistributionService.php');
        self::assertStringContainsString('release_not_pinned_to_job', $svc);
        self::assertStringContainsString('release_unpublished', $svc);
        self::assertStringContainsString('account_binding_mismatch', $svc);
        self::assertStringContainsString('installation_forbidden', $svc);
        self::assertStringContainsString('stale_lease_token', $svc);
        self::assertStringContainsString('stale_attempt', $svc);
        self::assertStringContainsString('lease_expired', $svc);
        self::assertStringContainsString('content_digest_mismatch', $svc);
        self::assertStringContainsString("status !== 'published'", $svc);
        self::assertStringContainsString('ScriptReleaseAssetReader', $svc);
        self::assertStringContainsString('execution_digest', $svc);
        self::assertStringContainsString('content_digest', $svc);
    }

    public function test_shared_asset_reader_path_and_sha_fail_closed(): void
    {
        $reader = new ScriptReleaseAssetReader;
        self::assertFalse($reader->isSafeAssetPath('../etc/passwd'));
        self::assertFalse($reader->isSafeAssetPath('/abs/path.png'));
        self::assertFalse($reader->isSafeAssetPath('C:\\windows\\x.png'));
        self::assertTrue($reader->isSafeAssetPath('18690ccb-86bf-4f5d-898a-565d1c23de3b.png'));

        $bad = $reader->loadVerified(0, 'x.png', 'not-a-sha');
        self::assertFalse($bad['ok']);
        self::assertNotEmpty($bad['errors'] ?? []);

        $src = (string) file_get_contents($this->root.'/src/Support/ScriptReleaseAssetReader.php');
        self::assertStringContainsString('asset_not_found', $src);
        self::assertStringContainsString('asset_sha256_mismatch', $src);
        self::assertStringContainsString('asset_schema_unavailable', $src);
    }

    public function test_claim_payload_includes_exact_script_pin_fields(): void
    {
        $orc = (string) file_get_contents($this->root.'/src/Orchestration/CampaignOrchestrator.php');
        self::assertStringContainsString('script_pin', $orc);
        self::assertStringContainsString('resolvePublishedPin', $orc);
        self::assertStringContainsString("'package_key'", $orc);
        self::assertStringContainsString("'release_id'", $orc);
        self::assertStringContainsString("'content_digest'", $orc);
        self::assertStringContainsString("'execution_digest'", $orc);
        self::assertStringContainsString('jobScriptPin', $orc);
        self::assertMatchesRegularExpression('/private function claimPayload\(/', $orc);
    }

    public function test_openapi_documents_action_script_and_claim_pin(): void
    {
        $yaml = (string) file_get_contents($this->root.'/openapi/openapi.yaml');
        self::assertStringContainsString('/api/v1/social-automation/client/scripts/pull', $yaml);
        self::assertStringContainsString('/api/v1/social-automation/client/scripts/asset', $yaml);
        self::assertStringContainsString('clientActionScriptsPull', $yaml);
        self::assertStringContainsString('clientActionScriptsAsset', $yaml);
        self::assertStringContainsString('ClientActionScriptPullRequest', $yaml);
        self::assertStringContainsString('ClientActionScriptRelease', $yaml);
        self::assertStringContainsString('do NOT treat it as `execution_digest`', $yaml);
        self::assertStringContainsString('release_id:', $yaml);
        self::assertStringContainsString('content_digest:', $yaml);
        self::assertStringContainsString('execution_digest:', $yaml);
        // Auth endpoints still documented
        self::assertStringContainsString('/api/v1/social-automation/client/auth/pull', $yaml);
        self::assertStringContainsString('/api/v1/social-automation/client/auth/asset', $yaml);
    }

    public function test_digest_semantics_are_not_equated_in_service_payload(): void
    {
        $svc = (string) file_get_contents($this->root.'/src/Services/ActionScriptDistributionService.php');
        self::assertStringContainsString('content_digest = package content integrity', $svc);
        self::assertMatchesRegularExpression(
            "/'content_digest'\\s*=>\\s*\\(string\\)\\s*\\\$release->content_digest/",
            $svc
        );
        self::assertMatchesRegularExpression(
            "/'execution_digest'\\s*=>\\s*\\\$release->execution_digest/",
            $svc
        );
    }
}
