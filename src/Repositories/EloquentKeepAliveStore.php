<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Repositories;

use Omnichannel\Addons\SocialAutomation\Contracts\KeepAliveStoreContract;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaKeepAliveCampaignAssignment;
use Omnichannel\Addons\SocialAutomation\Models\SaKeepAliveNode;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;

final class EloquentKeepAliveStore implements KeepAliveStoreContract
{
    public function isReady(): bool
    {
        return SocialAutomationSchema::keepaliveReady();
    }

    public function findAgentByUuid(string $agentUuid): ?array
    {
        $agent = SaAgent::query()->where('agent_uuid', $agentUuid)->first();
        if (! $agent instanceof SaAgent) {
            return null;
        }

        return [
            'id' => (int) $agent->id,
            'agent_uuid' => (string) $agent->agent_uuid,
            'installation_id' => (string) $agent->installation_id,
            'client_api_key_id' => $agent->client_api_key_id !== null ? (int) $agent->client_api_key_id : null,
        ];
    }

    public function findCampaignByUuid(string $campaignUuid, string $installationId): ?array
    {
        $campaign = SaCampaign::query()
            ->where('campaign_uuid', $campaignUuid)
            ->where('installation_id', $installationId)
            ->first();
        if (! $campaign instanceof SaCampaign) {
            return null;
        }

        return [
            'id' => (int) $campaign->id,
            'campaign_uuid' => (string) $campaign->campaign_uuid,
            'installation_id' => (string) $campaign->installation_id,
            'name' => (string) $campaign->name,
            'status' => (string) $campaign->status,
            'platform' => (string) $campaign->platform,
        ];
    }

    public function findNode(string $installationId, string $nodeId): ?array
    {
        $node = SaKeepAliveNode::query()
            ->where('installation_id', $installationId)
            ->where('node_id', $nodeId)
            ->first();

        return $node instanceof SaKeepAliveNode ? $this->serializeNode($node) : null;
    }

    public function upsertNode(array $attributes): array
    {
        $now = now();
        $node = SaKeepAliveNode::query()
            ->where('installation_id', $attributes['installation_id'])
            ->where('node_id', $attributes['node_id'])
            ->first();

        if ($node instanceof SaKeepAliveNode) {
            $node->fill([
                'agent_id' => $attributes['agent_id'],
                'agent_uuid' => $attributes['agent_uuid'],
                'client_api_key_id' => $attributes['client_api_key_id'],
                'protocol_version' => $attributes['protocol_version'],
                'schema_version' => $attributes['schema_version'],
                'capabilities' => $attributes['capabilities'],
                'last_seen_at' => $now,
            ])->save();

            return $this->serializeNode($node->fresh() ?? $node);
        }

        $node = SaKeepAliveNode::query()->create([
            'node_id' => $attributes['node_id'],
            'installation_id' => $attributes['installation_id'],
            'agent_id' => $attributes['agent_id'],
            'agent_uuid' => $attributes['agent_uuid'],
            'client_api_key_id' => $attributes['client_api_key_id'],
            'protocol_version' => $attributes['protocol_version'],
            'schema_version' => $attributes['schema_version'],
            'capabilities' => $attributes['capabilities'],
            'assignment_generation' => 0,
            'registered_at' => $now,
            'last_seen_at' => $now,
        ]);

        return $this->serializeNode($node);
    }

    public function listAssignmentsForNode(string $installationId, string $nodeId): array
    {
        $rows = SaKeepAliveCampaignAssignment::query()
            ->where('installation_id', $installationId)
            ->where('node_id', $nodeId)
            ->orderBy('campaign_uuid')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            if (! $row instanceof SaKeepAliveCampaignAssignment) {
                continue;
            }
            $campaign = SaCampaign::query()->find($row->campaign_id);
            $out[] = [
                'id' => (int) $row->id,
                'installation_id' => (string) $row->installation_id,
                'keepalive_node_id' => (int) $row->keepalive_node_id,
                'node_id' => (string) $row->node_id,
                'campaign_id' => (int) $row->campaign_id,
                'campaign_uuid' => (string) $row->campaign_uuid,
                'campaign_name' => $campaign instanceof SaCampaign ? (string) $campaign->name : '',
                'campaign_status' => $campaign instanceof SaCampaign ? (string) $campaign->status : '',
                'campaign_platform' => $campaign instanceof SaCampaign ? (string) $campaign->platform : '',
                'version' => (int) $row->version,
                'assigned_at' => $row->assigned_at?->toIso8601String() ?? '',
            ];
        }

        return $out;
    }

    public function assignCampaignToNode(
        string $installationId,
        string $nodeId,
        int $campaignId,
        string $campaignUuid,
        string $campaignName,
        string $campaignStatus,
        string $campaignPlatform,
    ): array {
        $node = SaKeepAliveNode::query()
            ->where('installation_id', $installationId)
            ->where('node_id', $nodeId)
            ->first();
        if (! $node instanceof SaKeepAliveNode) {
            throw new \InvalidArgumentException('node_not_registered');
        }

        $existing = SaKeepAliveCampaignAssignment::query()
            ->where('keepalive_node_id', $node->id)
            ->where('campaign_id', $campaignId)
            ->first();

        if ($existing instanceof SaKeepAliveCampaignAssignment) {
            $existing->version = (int) $existing->version + 1;
            $existing->save();
            $this->bumpAssignmentGeneration($installationId, $nodeId);

            return [
                'id' => (int) $existing->id,
                'installation_id' => $installationId,
                'keepalive_node_id' => (int) $node->id,
                'node_id' => $nodeId,
                'campaign_id' => $campaignId,
                'campaign_uuid' => $campaignUuid,
                'campaign_name' => $campaignName,
                'campaign_status' => $campaignStatus,
                'campaign_platform' => $campaignPlatform,
                'version' => (int) $existing->version,
                'assigned_at' => $existing->assigned_at?->toIso8601String() ?? '',
            ];
        }

        $row = SaKeepAliveCampaignAssignment::query()->create([
            'installation_id' => $installationId,
            'keepalive_node_id' => $node->id,
            'node_id' => $nodeId,
            'campaign_id' => $campaignId,
            'campaign_uuid' => $campaignUuid,
            'version' => 1,
            'assigned_at' => now(),
        ]);
        $this->bumpAssignmentGeneration($installationId, $nodeId);

        return [
            'id' => (int) $row->id,
            'installation_id' => $installationId,
            'keepalive_node_id' => (int) $node->id,
            'node_id' => $nodeId,
            'campaign_id' => $campaignId,
            'campaign_uuid' => $campaignUuid,
            'campaign_name' => $campaignName,
            'campaign_status' => $campaignStatus,
            'campaign_platform' => $campaignPlatform,
            'version' => 1,
            'assigned_at' => $row->assigned_at?->toIso8601String() ?? '',
        ];
    }

    public function bumpAssignmentGeneration(string $installationId, string $nodeId): int
    {
        $node = SaKeepAliveNode::query()
            ->where('installation_id', $installationId)
            ->where('node_id', $nodeId)
            ->first();
        if (! $node instanceof SaKeepAliveNode) {
            return 0;
        }
        $node->assignment_generation = (int) $node->assignment_generation + 1;
        $node->save();

        return (int) $node->assignment_generation;
    }

    public function jobClaimInvocations(): int
    {
        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeNode(SaKeepAliveNode $node): array
    {
        $caps = $node->capabilities;
        if (! is_array($caps)) {
            $caps = [];
        }

        return [
            'id' => (int) $node->id,
            'node_id' => (string) $node->node_id,
            'installation_id' => (string) $node->installation_id,
            'agent_id' => $node->agent_id !== null ? (int) $node->agent_id : null,
            'agent_uuid' => (string) $node->agent_uuid,
            'client_api_key_id' => (int) $node->client_api_key_id,
            'protocol_version' => (string) $node->protocol_version,
            'schema_version' => (int) $node->schema_version,
            'capabilities' => array_values($caps),
            'assignment_generation' => (int) $node->assignment_generation,
            'registered_at' => $node->registered_at?->toIso8601String() ?? '',
            'last_seen_at' => $node->last_seen_at?->toIso8601String() ?? '',
        ];
    }
}
