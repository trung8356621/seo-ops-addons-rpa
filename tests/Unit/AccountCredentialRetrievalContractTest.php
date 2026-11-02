<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Domain\AccountCredentialGate;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Repositories\InMemorySaAccountRepository;
use Omnichannel\Addons\SocialAutomation\Services\SaAccountService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use PHPUnit\Framework\TestCase;

/**
 * CP18C — On-demand credential retrieval authorization + storage contracts.
 */
final class AccountCredentialRetrievalContractTest extends TestCase
{
    private string $root;

    private AccountCredentialGate $gate;

    private SaAccountService $accounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
        $this->gate = new AccountCredentialGate;
        $installation = new SocialAutomationInstallationResolver;
        $installation->setForcedInstallationId('ins_cp18c');
        $this->accounts = new SaAccountService(new InMemorySaAccountRepository, $installation);
    }

    private function key(int $id = 1, string $installation = 'ins_cp18c'): SaClientApiKey
    {
        $key = new SaClientApiKey;
        $key->setRawAttributes([
            'id' => $id,
            'installation_id' => $installation,
            'name' => 'k',
            'key_prefix' => 'rpa_client_abcd1234',
            'key_hash' => 'h',
            'encrypted_key' => null,
            'created_by' => null,
            'revoked_at' => null,
        ], true);

        return $key;
    }

    private function agent(int $id, int $keyId): SaAgent
    {
        $agent = new SaAgent;
        $agent->setRawAttributes([
            'id' => $id,
            'client_api_key_id' => $keyId,
            'agent_uuid' => 'agent-'.$id,
            'agent_kind' => 'client',
        ], true);

        return $agent;
    }

    private function session(int $agentId, ?int $accountId, string $uuid = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'): SaBrowserSession
    {
        $session = new SaBrowserSession;
        $session->setRawAttributes([
            'id' => 9,
            'session_uuid' => $uuid,
            'agent_id' => $agentId,
            'account_id' => $accountId,
        ], true);

        return $session;
    }

    private function account(int $id, string $installation = 'ins_cp18c'): SaAccount
    {
        $account = new SaAccount;
        $account->setRawAttributes([
            'id' => $id,
            'account_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'installation_id' => $installation,
            'platform' => 'facebook',
            'name' => 'Acc',
            'encrypted_password' => 'enc:'.base64_encode('SecretPass#1'),
        ], true);

        return $account;
    }

    public function test_a_create_encrypts_password_at_rest(): void
    {
        $raw = 'SyntheticCreatePass!99';
        $account = $this->accounts->create([
            'name' => 'Encrypted Create',
            'email' => 'enc@example.com',
            'platform' => 'facebook',
            'password' => $raw,
        ]);
        self::assertTrue($account->hasPassword());
        $cipher = (string) $account->getAttribute('encrypted_password');
        self::assertNotSame($raw, $cipher);
        self::assertStringNotContainsString($raw, $cipher);
        self::assertSame($raw, $account->getDecryptedPassword());
    }

    public function test_authorized_bound_browser_passes_gate(): void
    {
        $key = $this->key();
        $agent = $this->agent(5, 1);
        $account = $this->account(77);
        $session = $this->session(5, 77);
        self::assertNull($this->gate->denyReason($key, $agent, $session, $account, (string) $session->session_uuid));
    }

    public function test_e_unauthorized_missing_browser_denied(): void
    {
        $deny = $this->gate->denyReason($this->key(), $this->agent(5, 1), null, $this->account(77), '');
        self::assertSame(AccountCredentialGate::ERROR_BROWSER_ID_REQUIRED, $deny);
    }

    public function test_f_wrong_installation_denied(): void
    {
        $deny = $this->gate->denyReason(
            $this->key(1, 'ins_cp18c'),
            $this->agent(5, 1),
            $this->session(5, 77),
            $this->account(77, 'ins_other'),
            'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'
        );
        self::assertSame(AccountCredentialGate::ERROR_INSTALLATION_FORBIDDEN, $deny);
    }

    public function test_g_wrong_agent_browser_ownership_denied(): void
    {
        $deny = $this->gate->denyReason(
            $this->key(),
            $this->agent(5, 1),
            $this->session(99, 77), // other agent
            $this->account(77),
            'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'
        );
        self::assertSame(AccountCredentialGate::ERROR_BROWSER_FORBIDDEN, $deny);
    }

    public function test_h_unbound_account_denied(): void
    {
        $deny = $this->gate->denyReason(
            $this->key(),
            $this->agent(5, 1),
            $this->session(5, null),
            $this->account(77),
            'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'
        );
        self::assertSame(AccountCredentialGate::ERROR_ACCOUNT_NOT_BOUND, $deny);

        $denyOther = $this->gate->denyReason(
            $this->key(),
            $this->agent(5, 1),
            $this->session(5, 88),
            $this->account(77),
            'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'
        );
        self::assertSame(AccountCredentialGate::ERROR_ACCOUNT_NOT_BOUND, $denyOther);
    }

    public function test_j_revoked_key_middleware_contract(): void
    {
        $mw = (string) file_get_contents($this->root.'/src/Http/Middleware/EnsureRpaClientApiKey.php');
        self::assertStringContainsString('ERROR_KEY_REVOKED', $mw);
        $provider = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');
        self::assertStringContainsString('EnsureRpaClientApiKey', $provider);
        self::assertStringContainsString('getAccountPassword', $provider);
    }

    public function test_k_staff_web_session_has_no_password_retrieval_route(): void
    {
        $provider = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');
        // Staff accounts API has no /password route; only Client API Key group does.
        self::assertStringNotContainsString("api/accounts/{id}/password", $provider);
        self::assertStringNotContainsString("api/accounts/{accountId}/password", $provider);
        $mgmt = (string) file_get_contents($this->root.'/src/Http/Controllers/SocialAutomationAccountManagementController.php');
        self::assertStringNotContainsString('getDecryptedPassword', $mgmt);
        self::assertStringNotContainsString("'password'", $mgmt);
        self::assertStringContainsString('has_password', $mgmt);
    }

    public function test_l_errors_and_logs_do_not_embed_plaintext_password(): void
    {
        $ctrl = (string) file_get_contents($this->root.'/src/Http/Controllers/RpaClientApiController.php');
        self::assertStringNotContainsString('password_decryption_failed:', $ctrl);
        self::assertDoesNotMatchRegularExpression('/error.*\$password/', $ctrl);
        self::assertStringContainsString("'error' => 'password_decryption_failed'", $ctrl);
        self::assertStringContainsString("'error' => \$deny", $ctrl);
    }

    public function test_m_openapi_distinguishes_inventory_from_secrets(): void
    {
        $openapi = (string) file_get_contents($this->root.'/openapi/openapi.yaml');
        self::assertStringContainsString('StaffAccountPublic:', $openapi);
        self::assertStringContainsString('Never returns password or encrypted_password', $openapi);
        self::assertStringContainsString('AccountPasswordSuccess:', $openapi);
        self::assertStringContainsString('browser_id', $openapi);
        self::assertStringContainsString('account_not_bound_to_browser', $openapi);
        self::assertStringContainsString('Cache-Control: no-store', $openapi);
        self::assertStringContainsString('Electron: request immediately before Auth Script login', $openapi);
    }

    public function test_site_registry_platform_compatibility_documented(): void
    {
        // Accounts.platform is free-form string; Action Tags sync site_key <-> platform.
        $tag = (string) file_get_contents($this->root.'/src/Models/SaActionTag.php');
        self::assertStringContainsString('site_key <-> platform', $tag);
        $openapi = (string) file_get_contents($this->root.'/openapi/openapi.yaml');
        self::assertStringContainsString('Site Registry site_key', $openapi);
        // No destructive migration for platform mapping.
        self::assertFileDoesNotExist($this->root.'/database/migrations/2026_11_02_cp18c_platform_remap.php');
    }
}
