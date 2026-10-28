<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SocialAutomation\Domain\AgentKindPolicy;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Models\SaClientNotification;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Garbage-collect client agents that are offline >= 72h AND have zero browser profiles.
 * Revokes the Client API Key and removes dependent client rows. Never deletes Accounts/Installations.
 */
final class ClientCleanupService
{
    public const STALE_HOURS = 72;

    /**
     * @return array{cleaned: int, revoked_keys: int, skipped_online: int, skipped_with_browsers: int}
     */
    public function cleanupStaleClients(?\DateTimeInterface $now = null): array
    {
        $cleaned = 0;
        $revoked = 0;
        $skippedOnline = 0;
        $skippedBrowsers = 0;

        if (! SocialAutomationSchema::agentsReady()) {
            return [
                'cleaned' => 0,
                'revoked_keys' => 0,
                'skipped_online' => 0,
                'skipped_with_browsers' => 0,
            ];
        }

        $threshold = \DateTimeImmutable::createFromInterface($now ?? new \DateTimeImmutable('now'))
            ->modify('-'.self::STALE_HOURS.' hours');

        $candidates = SaAgent::query()
            ->where('agent_kind', AgentKindPolicy::KIND_CLIENT)
            ->where(function ($q) use ($threshold): void {
                $q->whereNull('last_heartbeat_at')
                    ->orWhere('last_heartbeat_at', '<=', $threshold->format('Y-m-d H:i:s'));
            })
            ->orderBy('id')
            ->limit(200)
            ->get();

        foreach ($candidates as $agent) {
            if (! $agent instanceof SaAgent) {
                continue;
            }

            // Online / lease still active → keep
            $leaseActive = $agent->connection_lease_expires_at !== null
                && $agent->connection_lease_expires_at->getTimestamp() > ($now ?? new \DateTimeImmutable('now'))->getTimestamp();
            if ($leaseActive) {
                $skippedOnline++;
                continue;
            }

            $browserCount = (int) $agent->browserSessions()->count();
            if ($browserCount > 0) {
                $skippedBrowsers++;
                continue;
            }

            DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($agent, &$cleaned, &$revoked): void {
                SaClientNotification::query()->where('agent_id', $agent->id)->delete();

                $keyId = $agent->client_api_key_id !== null ? (int) $agent->client_api_key_id : null;
                $agent->delete();

                if ($keyId !== null) {
                    $key = SaClientApiKey::query()->whereKey($keyId)->first();
                    if ($key instanceof SaClientApiKey && $key->isActive()) {
                        $key->revoke();
                        $revoked++;
                    }
                }
                $cleaned++;
            });
        }

        return [
            'cleaned' => $cleaned,
            'revoked_keys' => $revoked,
            'skipped_online' => $skippedOnline,
            'skipped_with_browsers' => $skippedBrowsers,
        ];
    }
}
