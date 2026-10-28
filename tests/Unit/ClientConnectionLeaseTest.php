<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Domain\ClientConnectionLease;
use PHPUnit\Framework\TestCase;

final class ClientConnectionLeaseTest extends TestCase
{
    public function test_allows_when_no_active_lease(): void
    {
        $lease = new ClientConnectionLease(120);
        self::assertTrue($lease->allows(null, null, null));
        self::assertTrue($lease->allows('tok', null, 'other'));
    }

    public function test_allows_same_token_while_active(): void
    {
        $lease = new ClientConnectionLease(120);
        $now = new \DateTimeImmutable('2026-10-28 10:00:00');
        $expires = $now->modify('+60 seconds');
        self::assertTrue($lease->allows('tok-a', $expires, 'tok-a', $now));
    }

    public function test_rejects_different_token_while_active(): void
    {
        $lease = new ClientConnectionLease(120);
        $now = new \DateTimeImmutable('2026-10-28 10:00:00');
        $expires = $now->modify('+60 seconds');
        self::assertFalse($lease->allows('tok-a', $expires, 'tok-b', $now));
        self::assertFalse($lease->allows('tok-a', $expires, null, $now));
    }

    public function test_allows_after_lease_expires(): void
    {
        $lease = new ClientConnectionLease(120);
        $now = new \DateTimeImmutable('2026-10-28 10:00:00');
        $expires = $now->modify('-1 second');
        self::assertTrue($lease->allows('tok-a', $expires, 'tok-b', $now));
    }

    public function test_issue_token_is_opaque_hex(): void
    {
        $lease = new ClientConnectionLease();
        $token = $lease->issueToken();
        self::assertMatchesRegularExpression('/^[a-f0-9]{48}$/', $token);
    }
}
