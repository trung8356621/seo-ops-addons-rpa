<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Domain\AccountSiteUrlResolver;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\RpaClientApiController;
use Omnichannel\Addons\SocialAutomation\Http\Middleware\EnsureRpaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Registry\AgentRegistryService;
use Omnichannel\Addons\SocialAutomation\Registry\SessionSyncService;
use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;
use Omnichannel\Addons\SocialAutomation\Repositories\InMemorySaAccountRepository;
use Omnichannel\Addons\SocialAutomation\Services\SaAccountService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class AccountSiteUrlResolverTest extends TestCase
{
    private InMemorySaAccountRepository $repository;

    private SaAccountService $accountService;

    private AccountSiteUrlResolver $resolver;

    private RpaClientApiController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new InMemorySaAccountRepository;
        $installation = new class extends SocialAutomationInstallationResolver
        {
            public function resolveInstallationId(?object $user = null): string
            {
                return 'ins_alpha';
            }
        };
        $this->accountService = new SaAccountService($this->repository, $installation);

        // Preloaded Site Registry map — mirrors sa_sites.base_url (unified canonical URL).
        $this->resolver = new AccountSiteUrlResolver([
            'facebook' => 'https://www.facebook.com',
            'mayhopphat' => 'https://mayhopphat.com', // after Owner promotes base_url
            'x' => 'https://twitter.com',
        ]);

        $this->controller = new RpaClientApiController(
            new AgentRegistryService,
            new SessionSyncService(new OnlineStateCalculator),
            $this->repository,
            null,
            $this->resolver,
        );
    }

    public function test_validate_http_url_rejects_invalid_and_non_http(): void
    {
        self::assertNull($this->resolver->validateHttpUrl(null));
        self::assertNull($this->resolver->validateHttpUrl(''));
        self::assertNull($this->resolver->validateHttpUrl('mayhopphat'));
        self::assertNull($this->resolver->validateHttpUrl('ftp://example.com'));
        self::assertNull($this->resolver->validateHttpUrl('javascript:alert(1)'));
        self::assertSame('https://mayhopphat.com', $this->resolver->validateHttpUrl('https://mayhopphat.com/'));
        self::assertSame('http://example.com', $this->resolver->validateHttpUrl('http://example.com'));
    }

    public function test_dynamic_site_key_resolves_registry_base_url(): void
    {
        $account = $this->accountService->create([
            'platform' => 'Facebook',
            'name' => 'FB A',
            'username' => 'user_a',
        ]);

        self::assertSame('https://www.facebook.com', $this->resolver->resolveForAccount($account));
    }

    public function test_multiple_accounts_sharing_site_receive_same_url(): void
    {
        $a = $this->accountService->create([
            'platform' => 'facebook',
            'name' => 'FB One',
            'username' => 'one',
        ]);
        $b = $this->accountService->create([
            'platform' => 'facebook',
            'name' => 'FB Two',
            'username' => 'two',
        ]);

        self::assertSame(
            $this->resolver->resolveForAccount($a),
            $this->resolver->resolveForAccount($b)
        );
        self::assertSame('https://www.facebook.com', $this->resolver->resolveForAccount($a));
    }

    public function test_changing_site_url_in_registry_updates_resolution(): void
    {
        $account = $this->accountService->create([
            'platform' => 'x',
            'name' => 'X Acc',
            'username' => 'xuser',
        ]);
        self::assertSame('https://twitter.com', $this->resolver->resolveForAccount($account));

        $updated = new AccountSiteUrlResolver([
            'x' => 'https://x.com',
        ]);
        self::assertSame('https://x.com', $updated->resolveForAccount($account));
    }

    public function test_unknown_site_key_does_not_fabricate_url(): void
    {
        $account = $this->accountService->create([
            'platform' => 'unknown_platform_xyz',
            'name' => 'Unknown',
            'username' => 'u1',
        ]);
        self::assertNull($this->resolver->resolveForAccount($account));
    }

    public function test_dynamic_site_with_unified_base_url_resolves_for_electron(): void
    {
        $account = $this->accountService->create([
            'platform' => 'mayhopphat',
            'name' => 'Mayhopphat Acc',
            'username' => 'mayhopphat',
        ]);
        self::assertSame('https://mayhopphat.com', $this->resolver->resolveForAccount($account));
        self::assertNull($account->getSiteUrl(), 'must not invent/write Account.site_url');
    }

    public function test_missing_site_base_url_returns_null_without_using_test_url(): void
    {
        $resolver = new AccountSiteUrlResolver([
            'orphan_dynamic' => null,
        ]);
        $account = $this->accountService->create([
            'platform' => 'orphan_dynamic',
            'name' => 'Orphan Dynamic',
            'username' => 'orphan',
        ]);
        // Registry entry exists with null base_url — must not invent from legacy test_base_url
        self::assertNull($resolver->resolveForAccount($account));
    }

    public function test_legacy_account_site_url_fallback_when_registry_missing(): void
    {
        $account = $this->accountService->create([
            'platform' => 'wordpress',
            'name' => 'WP Legacy',
            'username' => 'admin',
            'site_url' => 'https://portal.domain.com/cms',
        ]);
        self::assertSame('https://portal.domain.com/cms', $this->resolver->resolveForAccount($account));
        // Persisted value unchanged
        self::assertSame('https://portal.domain.com/cms', $account->getSiteUrl());
    }

    public function test_registry_url_preferred_over_legacy_account_site_url(): void
    {
        $account = $this->accountService->create([
            'platform' => 'facebook',
            'name' => 'FB Legacy Url',
            'username' => 'fb',
            'site_url' => 'https://old.example.com/fb',
        ]);
        self::assertSame('https://www.facebook.com', $this->resolver->resolveForAccount($account));
        self::assertSame('https://old.example.com/fb', $account->getSiteUrl(), 'must not mutate Account.site_url');
    }

    public function test_client_inventory_and_detail_use_resolved_url_without_password(): void
    {
        $this->accountService->create([
            'platform' => 'facebook',
            'name' => 'FB Seeding',
            'username' => 'seeder',
            'password' => 'SecretPass!',
        ]);
        $this->accountService->create([
            'platform' => 'mayhopphat',
            'name' => 'Mayhopphat Acc',
            'username' => 'mayhopphat',
            'password' => 'SecretPass!',
        ]);
        $wp = $this->accountService->create([
            'platform' => 'wordpress',
            'name' => 'WP Subdir',
            'username' => 'wpadmin',
            'site_url' => 'https://portal.domain.com/cms',
        ]);

        $key = new SaClientApiKey;
        $key->id = 1;
        $key->setAttribute('installation_id', 'ins_alpha');

        $request = Request::create('/api/v1/social-automation/client/accounts', 'GET');
        $request->attributes->set(EnsureRpaClientApiKey::REQUEST_CLIENT_KEY, $key);

        $list = $this->controller->listBindableAccounts($request)->getData(true);
        self::assertTrue($list['ok']);
        self::assertCount(3, $list['accounts']);

        $map = collect($list['accounts'])->keyBy('display_name');
        self::assertSame('https://www.facebook.com', $map['FB Seeding']['site_url']);
        self::assertSame('https://mayhopphat.com', $map['Mayhopphat Acc']['site_url']);
        self::assertSame('https://portal.domain.com/cms', $map['WP Subdir']['site_url']);
        self::assertArrayNotHasKey('password', $map['FB Seeding']);
        self::assertArrayNotHasKey('encrypted_password', $map['FB Seeding']);
        self::assertTrue($map['FB Seeding']['has_password']);

        // Detail serializer matches list username/site_url; never exposes secrets.
        $detail = $this->controller->serializeClientAccountDetail($wp);
        self::assertSame($map['WP Subdir']['username'], $detail['username']);
        self::assertSame($map['WP Subdir']['site_url'], $detail['site_url']);
        self::assertSame($map['WP Subdir']['has_password'], $detail['has_password']);
        self::assertArrayNotHasKey('password', $detail);
        self::assertArrayNotHasKey('encrypted_password', $detail);
        self::assertSame('not_set', $detail['password_status']);
    }

    public function test_list_and_detail_return_same_username_for_mayhopphat(): void
    {
        $account = $this->accountService->create([
            'platform' => 'mayhopphat',
            'name' => 'mayhopphat',
            'username' => 'mayhopphat',
            'password' => 'SecretPass!',
        ]);

        $key = new SaClientApiKey;
        $key->id = 1;
        $key->setAttribute('installation_id', 'ins_alpha');
        $request = Request::create('/api/v1/social-automation/client/accounts', 'GET');
        $request->attributes->set(EnsureRpaClientApiKey::REQUEST_CLIENT_KEY, $key);

        $list = $this->controller->listBindableAccounts($request)->getData(true);
        $listItem = collect($list['accounts'])->firstWhere('account_id', (string) $account->account_uuid);
        self::assertIsArray($listItem);
        self::assertSame('mayhopphat', $listItem['username']);

        $detail = $this->controller->serializeClientAccountDetail($account);
        self::assertSame($listItem['username'], $detail['username']);
        self::assertSame($listItem['display_name'], $detail['display_name']);
        self::assertSame($listItem['platform'], $detail['platform']);
        self::assertSame($listItem['has_password'], $detail['has_password']);
        self::assertTrue($detail['has_password']);
        self::assertSame('available', $detail['password_status']);
        self::assertNull($detail['email']);
        self::assertArrayNotHasKey('password', $detail);
        self::assertArrayNotHasKey('encrypted_password', $detail);
    }

    public function test_wrong_installation_cannot_list_foreign_accounts(): void
    {
        $this->accountService->create([
            'platform' => 'facebook',
            'name' => 'Alpha Only',
            'username' => 'alpha',
        ]);

        $foreignKey = new SaClientApiKey;
        $foreignKey->id = 2;
        $foreignKey->setAttribute('installation_id', 'ins_foreign');

        $request = Request::create('/api/v1/social-automation/client/accounts', 'GET');
        $request->attributes->set(EnsureRpaClientApiKey::REQUEST_CLIENT_KEY, $foreignKey);

        $list = $this->controller->listBindableAccounts($request)->getData(true);
        self::assertTrue($list['ok']);
        self::assertCount(0, $list['accounts']);
    }

    public function test_warm_from_sites_is_single_map_no_n_plus_one(): void
    {
        $resolver = new AccountSiteUrlResolver;
        $resolver->warmFromSites([
            ['key' => 'facebook', 'base_url' => 'https://www.facebook.com'],
            ['key' => 'mayhopphat', 'base_url' => null],
            ['key' => 'Reddit', 'base_url' => 'https://www.reddit.com/'],
        ]);

        $a = new SaAccount;
        $a->platform = 'facebook';
        $b = new SaAccount;
        $b->platform = 'reddit';
        $c = new SaAccount;
        $c->platform = 'mayhopphat';

        self::assertSame('https://www.facebook.com', $resolver->resolveForAccount($a));
        self::assertSame('https://www.reddit.com', $resolver->resolveForAccount($b));
        self::assertNull($resolver->resolveForAccount($c));
    }
}
