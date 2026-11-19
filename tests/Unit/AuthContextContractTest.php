<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Source-contract checks for Client auth/context (0/1/N).
 */
final class AuthContextContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_service_exposes_find_eligible_auth_context(): void
    {
        $svc = (string) file_get_contents($this->root.'/src/Services/AuthScriptService.php');
        self::assertStringContainsString('findEligibleAuthContextForAccount', $svc);
        self::assertStringContainsString("'match' => 'none'", $svc);
        self::assertStringContainsString("'match' => 'unique'", $svc);
        self::assertStringContainsString("'match' => 'ambiguous'", $svc);
        self::assertStringContainsString('packageRowForAuthPin', $svc);
        self::assertStringContainsString('tagPathIsAuthentication', $svc);
        // Must not invent platform.login from account name
        self::assertStringNotContainsString("platform.'.login'", $svc);
        self::assertStringNotContainsString('platform.\'.login', $svc);
        self::assertStringNotContainsString('{$account->platform}.login', $svc);
    }

    public function test_resolve_shares_site_auth_eligibility(): void
    {
        $svc = (string) file_get_contents($this->root.'/src/Services/AuthScriptService.php');
        self::assertStringContainsString('function resolveForClient', $svc);
        self::assertStringContainsString('assertAuthTagEligibleForAccount', $svc);
        self::assertStringContainsString('candidateAuthTagsForSite', $svc);
        self::assertStringContainsString('platformsMatch', $svc);
        // context + resolve share the same eligibility helper
        self::assertMatchesRegularExpression(
            '/function findEligibleAuthContextForAccount[\s\S]*assertAuthTagEligibleForAccount/',
            $svc
        );
        self::assertMatchesRegularExpression(
            '/function resolveForClient[\s\S]*assertAuthTagEligibleForAccount/',
            $svc
        );
    }

    public function test_auth_context_is_site_scoped_not_account_grant(): void
    {
        $svc = (string) file_get_contents($this->root.'/src/Services/AuthScriptService.php');
        // Auth context/resolve must not gate on sa_account_action_tags bindings
        self::assertDoesNotMatchRegularExpression(
            '/function findEligibleAuthContextForAccount[\s\S]*SaAccountActionTag::query/',
            $svc
        );
        self::assertDoesNotMatchRegularExpression(
            '/function resolveForClient[\s\S]*isEntitled\(/',
            $svc
        );
        self::assertDoesNotMatchRegularExpression(
            '/function assertAuthTagEligibleForAccount[\s\S]*SaAccountActionTag::query/',
            $svc
        );
        // Ordinary Action Script entitlement API remains available
        self::assertStringContainsString('function isEntitled', $svc);
        self::assertStringContainsString('function grant', $svc);
        self::assertStringContainsString('function revoke', $svc);
    }

    public function test_controller_and_route_wire_auth_context(): void
    {
        $ctrl = (string) file_get_contents($this->root.'/src/Http/Controllers/RpaClientApiController.php');
        self::assertStringContainsString('function authContext', $ctrl);
        self::assertStringContainsString('findEligibleAuthContextForAccount', $ctrl);

        $routes = (string) file_get_contents($this->root.'/routes/api-services.php');
        self::assertStringContainsString('client/auth/context', $routes);
        self::assertStringContainsString('EnsureClientConnectionLease', $routes);

        $provider = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');
        self::assertStringContainsString('/auth/context', $provider);
        self::assertStringContainsString('authContext', $provider);
        self::assertStringContainsString('EnsureClientConnectionLease::class', $provider);
    }

    public function test_openapi_documents_auth_context(): void
    {
        $yaml = (string) file_get_contents($this->root.'/openapi/openapi.yaml');
        self::assertStringContainsString('/client/auth/context', $yaml);
        self::assertStringContainsString('clientAuthContext', $yaml);
        self::assertStringContainsString('ClientAuthContextSuccess', $yaml);
        self::assertStringContainsString('none, unique, ambiguous', $yaml);
    }

    public function test_auth_context_response_has_no_credential_fields(): void
    {
        $ctrl = (string) file_get_contents($this->root.'/src/Http/Controllers/RpaClientApiController.php');
        // authContext merges service result only — no password/cookie keys in method body
        self::assertMatchesRegularExpression(
            '/function authContext[\s\S]*findEligibleAuthContextForAccount[\s\S]*array_merge\(\[\'ok\' => true\], \$result\)/',
            $ctrl
        );
        $svc = (string) file_get_contents($this->root.'/src/Services/AuthScriptService.php');
        self::assertDoesNotMatchRegularExpression(
            '/function findEligibleAuthContextForAccount[\s\S]*?(encrypted_password|cookies|credentials)/',
            $svc
        );
    }
}
