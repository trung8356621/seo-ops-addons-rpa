<?php
declare(strict_types=1);
namespace Omnichannel\Addons\SocialAutomation\Domain;
final class AccountExecutionPolicy
{
    public function canUseDevice(?int $activeLeaseDeviceId, ?int $affinityDeviceId, bool $affinityActive, int $candidateDeviceId): bool
    {
        if ($activeLeaseDeviceId !== null) return $activeLeaseDeviceId === $candidateDeviceId;
        return ! $affinityActive || $affinityDeviceId === null || $affinityDeviceId === $candidateDeviceId;
    }
}
