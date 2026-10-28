<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Temporary connection lease for single-active-connection-per-API-key.
 * Not a Device identity — only distinguishes the current connection session.
 */
final class ClientConnectionLease
{
    /** Slightly above OnlineStateCalculator TTL so reconnect after brief network blips works once lease expires. */
    public const DEFAULT_TTL_SECONDS = 120;

    public const ERROR_KEY_IN_USE = 'CLIENT_KEY_IN_USE';

    public function __construct(
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {}

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    public function expiresAt(?\DateTimeInterface $now = null): \DateTimeImmutable
    {
        $base = $now instanceof \DateTimeImmutable
            ? $now
            : new \DateTimeImmutable($now?->format('c') ?? 'now');

        return $base->modify('+'.$this->ttlSeconds.' seconds');
    }

    public function isActive(?string $token, ?\DateTimeInterface $expiresAt, ?\DateTimeInterface $now = null): bool
    {
        if ($token === null || $token === '' || $expiresAt === null) {
            return false;
        }
        $nowTs = ($now ?? new \DateTimeImmutable('now'))->getTimestamp();

        return $expiresAt->getTimestamp() > $nowTs;
    }

    /**
     * Accept when no active lease, same connection token, or lease expired.
     * Reject when another connection holds a valid lease.
     */
    public function allows(
        ?string $existingToken,
        ?\DateTimeInterface $expiresAt,
        ?string $incomingToken,
        ?\DateTimeInterface $now = null,
    ): bool {
        if (! $this->isActive($existingToken, $expiresAt, $now)) {
            return true;
        }
        if ($incomingToken !== null && $incomingToken !== '' && hash_equals((string) $existingToken, $incomingToken)) {
            return true;
        }

        return false;
    }

    public function issueToken(): string
    {
        return bin2hex(random_bytes(24));
    }
}
