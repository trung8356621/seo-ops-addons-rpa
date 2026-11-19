<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Dispatch eligibility by agent kind.
 * Runner + Client may execute when heartbeat-online; Admin never counts.
 */
final class AgentKindPolicy
{
    public const KIND_RUNNER = 'runner';

    public const KIND_ADMIN = 'admin';

    public const KIND_CLIENT = 'client';

    public function isRunner(string $kind): bool
    {
        return strtolower(trim($kind)) === self::KIND_RUNNER;
    }

    public function isClient(string $kind): bool
    {
        return $this->normalize($kind) === self::KIND_CLIENT;
    }

    public function countsAsOnlineForDispatch(string $kind, bool $heartbeatOnline): bool
    {
        if (! $heartbeatOnline) {
            return false;
        }

        $normalized = $this->normalize($kind);

        return $normalized === self::KIND_RUNNER || $normalized === self::KIND_CLIENT;
    }

    public function normalize(?string $kind): string
    {
        $k = strtolower(trim((string) $kind));
        if ($k === self::KIND_ADMIN) {
            return self::KIND_ADMIN;
        }

        if ($k === self::KIND_CLIENT) {
            return self::KIND_CLIENT;
        }

        return self::KIND_RUNNER;
    }
}
