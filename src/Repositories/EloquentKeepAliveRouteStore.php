<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Repositories;

use Omnichannel\Addons\SocialAutomation\Contracts\KeepAliveRouteStoreContract;
use Omnichannel\Addons\SocialAutomation\Models\SaKeepAliveCampaignAssignment;
use Omnichannel\Addons\SocialAutomation\Models\SaKeepAliveNode;
use Omnichannel\Addons\SocialAutomation\Models\SaKeepAliveRouteState;
use Omnichannel\Addons\SocialAutomation\Models\SaKeepAliveSocketTicketJti;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;

final class EloquentKeepAliveRouteStore implements KeepAliveRouteStoreContract
{
    public function isReady(): bool
    {
        return SocialAutomationSchema::keepaliveSocketReady();
    }

    public function getRouteGeneration(string $installationId, string $subjectType, string $subjectKey): int
    {
        $row = SaKeepAliveRouteState::query()
            ->where('installation_id', $installationId)
            ->where('subject_type', $subjectType)
            ->where('subject_key', $subjectKey)
            ->first();

        return $row instanceof SaKeepAliveRouteState ? (int) $row->route_generation : 0;
    }

    public function bumpRouteGeneration(string $installationId, string $subjectType, string $subjectKey): int
    {
        $row = SaKeepAliveRouteState::query()
            ->where('installation_id', $installationId)
            ->where('subject_type', $subjectType)
            ->where('subject_key', $subjectKey)
            ->first();
        if (! $row instanceof SaKeepAliveRouteState) {
            $row = SaKeepAliveRouteState::query()->create([
                'installation_id' => $installationId,
                'subject_type' => $subjectType,
                'subject_key' => $subjectKey,
                'route_generation' => 1,
                'revoked_at' => null,
            ]);

            return 1;
        }
        $row->route_generation = (int) $row->route_generation + 1;
        $row->revoked_at = null;
        $row->save();

        return (int) $row->route_generation;
    }

    public function revokeRoute(string $installationId, string $subjectType, string $subjectKey): void
    {
        $row = SaKeepAliveRouteState::query()
            ->where('installation_id', $installationId)
            ->where('subject_type', $subjectType)
            ->where('subject_key', $subjectKey)
            ->first();
        if (! $row instanceof SaKeepAliveRouteState) {
            SaKeepAliveRouteState::query()->create([
                'installation_id' => $installationId,
                'subject_type' => $subjectType,
                'subject_key' => $subjectKey,
                'route_generation' => 1,
                'revoked_at' => now(),
            ]);

            return;
        }
        $row->route_generation = (int) $row->route_generation + 1;
        $row->revoked_at = now();
        $row->save();
    }

    public function isRouteRevoked(string $installationId, string $subjectType, string $subjectKey): bool
    {
        $row = SaKeepAliveRouteState::query()
            ->where('installation_id', $installationId)
            ->where('subject_type', $subjectType)
            ->where('subject_key', $subjectKey)
            ->first();

        return $row instanceof SaKeepAliveRouteState && $row->revoked_at !== null;
    }

    public function listActiveAssignmentsForCampaigns(string $installationId, array $campaignUuids): array
    {
        if ($campaignUuids === []) {
            return [];
        }
        $rows = SaKeepAliveCampaignAssignment::query()
            ->where('installation_id', $installationId)
            ->whereIn('campaign_uuid', $campaignUuids)
            ->whereNull('revoked_at')
            ->get();
        $out = [];
        foreach ($rows as $row) {
            if (! $row instanceof SaKeepAliveCampaignAssignment) {
                continue;
            }
            $node = SaKeepAliveNode::query()
                ->where('installation_id', $installationId)
                ->where('node_id', $row->node_id)
                ->whereNull('revoked_at')
                ->first();
            if (! $node instanceof SaKeepAliveNode) {
                continue;
            }
            $out[] = [
                'node_id' => (string) $row->node_id,
                'campaign_uuid' => (string) $row->campaign_uuid,
                'assignment_generation' => (int) $node->assignment_generation,
                'revoked' => false,
            ];
        }

        return $out;
    }

    public function rememberTicketJti(
        string $jti,
        string $installationId,
        ?int $clientApiKeyId,
        string $role,
        int $expiresAtUnix,
    ): bool {
        if (SaKeepAliveSocketTicketJti::query()->where('jti', $jti)->exists()) {
            return false;
        }
        SaKeepAliveSocketTicketJti::query()->create([
            'jti' => $jti,
            'installation_id' => $installationId,
            'client_api_key_id' => $clientApiKeyId,
            'role' => $role,
            'expires_at' => date('Y-m-d H:i:s', $expiresAtUnix),
            'consumed_at' => null,
        ]);

        return true;
    }

    public function consumeTicketJti(string $jti, ?int $nowUnix = null): bool
    {
        $row = SaKeepAliveSocketTicketJti::query()->where('jti', $jti)->first();
        if (! $row instanceof SaKeepAliveSocketTicketJti) {
            return false;
        }
        $now = $nowUnix ?? time();
        if ($row->expires_at !== null && $row->expires_at->getTimestamp() < $now) {
            return false;
        }
        if ($row->consumed_at !== null) {
            return false;
        }
        $row->consumed_at = date('Y-m-d H:i:s', $now);
        $row->save();

        return true;
    }

    public function jobClaimInvocations(): int
    {
        return 0;
    }
}
