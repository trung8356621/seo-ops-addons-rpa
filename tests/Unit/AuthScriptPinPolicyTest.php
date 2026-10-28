<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Domain\AccountTagEntitlementPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\AuthScriptPinPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\ClientConnectionLease;
use PHPUnit\Framework\TestCase;

final class AuthScriptPinPolicyTest extends TestCase
{
    private AuthScriptPinPolicy $pins;

    private AccountTagEntitlementPolicy $entitlement;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pins = new AuthScriptPinPolicy;
        $this->entitlement = new AccountTagEntitlementPolicy;
    }

    /** @return array{id: int, kind: string, is_authentication: bool, is_enabled: bool} */
    private function authPackage(array $over = []): array
    {
        return array_merge([
            'id' => 10,
            'kind' => 'script',
            'is_authentication' => true,
            'is_enabled' => true,
        ], $over);
    }

    /** @return array{id: int, package_id: int, version: string, status: string} */
    private function publishedRelease(array $over = []): array
    {
        return array_merge([
            'id' => 20,
            'package_id' => 10,
            'version' => '1',
            'status' => 'published',
        ], $over);
    }

    public function test_pin_accepts_authentication_published_script(): void
    {
        $r = $this->pins->assertPinTarget($this->authPackage(), $this->publishedRelease());
        self::assertTrue($r['ok']);
    }

    public function test_pin_rejects_non_authentication_package(): void
    {
        $r = $this->pins->assertPinTarget(
            $this->authPackage(['is_authentication' => false]),
            $this->publishedRelease(),
        );
        self::assertFalse($r['ok']);
        self::assertContains('auth_script_not_authentication', $r['errors']);
    }

    public function test_pin_rejects_yanked_or_pending_release(): void
    {
        $yanked = $this->pins->assertPinTarget(
            $this->authPackage(),
            $this->publishedRelease(['status' => 'yanked']),
        );
        self::assertFalse($yanked['ok']);
        self::assertContains('auth_release_not_published', $yanked['errors']);

        $pending = $this->pins->assertPinTarget(
            $this->authPackage(),
            $this->publishedRelease(['status' => 'pending_media']),
        );
        self::assertFalse($pending['ok']);
    }

    public function test_pin_rejects_disabled_package(): void
    {
        $r = $this->pins->assertPinTarget(
            $this->authPackage(['is_enabled' => false]),
            $this->publishedRelease(),
        );
        self::assertFalse($r['ok']);
        self::assertContains('auth_script_disabled', $r['errors']);
    }

    public function test_pin_rejects_package_release_mismatch(): void
    {
        $r = $this->pins->assertPinTarget(
            $this->authPackage(),
            $this->publishedRelease(['package_id' => 99]),
        );
        self::assertFalse($r['ok']);
        self::assertContains('auth_release_package_mismatch', $r['errors']);
    }

    public function test_pin_rejects_widget_kind(): void
    {
        $r = $this->pins->assertPinTarget(
            $this->authPackage(['kind' => 'widget']),
            $this->publishedRelease(),
        );
        self::assertFalse($r['ok']);
        self::assertContains('auth_script_kind_invalid', $r['errors']);
    }

    public function test_resolve_requires_matching_pin_ids(): void
    {
        $ok = $this->pins->assertPinnedAuthRelease(
            $this->authPackage(),
            $this->publishedRelease(),
            10,
            20,
        );
        self::assertTrue($ok['ok']);

        $missing = $this->pins->assertPinnedAuthRelease(
            $this->authPackage(),
            $this->publishedRelease(),
            null,
            null,
        );
        self::assertFalse($missing['ok']);
        self::assertContains('auth_pin_missing', $missing['errors']);

        $mismatch = $this->pins->assertPinnedAuthRelease(
            $this->authPackage(),
            $this->publishedRelease(),
            10,
            999,
        );
        self::assertFalse($mismatch['ok']);
        self::assertContains('auth_pin_mismatch', $mismatch['errors']);
    }

    public function test_entitlement_fail_closed_without_binding(): void
    {
        self::assertFalse($this->entitlement->isAuthorized(false));
        $deny = $this->entitlement->assertAuthorized(false);
        self::assertFalse($deny['ok']);
        self::assertContains('account_tag_unauthorized', $deny['errors']);

        self::assertTrue($this->entitlement->isAuthorized(true));
    }

    public function test_lease_valid_expired_wrong_token(): void
    {
        $lease = new ClientConnectionLease(120);
        $now = new \DateTimeImmutable('2026-10-28 12:00:00');
        $active = $now->modify('+60 seconds');
        $expired = $now->modify('-1 second');

        self::assertTrue($lease->isActive('tok', $active, $now));
        self::assertFalse($lease->isActive('tok', $expired, $now));
        self::assertFalse($lease->isActive(null, $active, $now));

        self::assertTrue($lease->allows('tok', $active, 'tok', $now));
        self::assertFalse($lease->allows('tok', $active, 'other', $now));
        self::assertFalse($lease->allows('tok', $active, null, $now));
        self::assertTrue($lease->allows('tok', $expired, 'other', $now));
    }
}
