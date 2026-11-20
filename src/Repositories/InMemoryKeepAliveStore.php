<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Repositories;

use Omnichannel\Addons\SocialAutomation\Contracts\KeepAliveStoreContract;

/**
 * In-memory Keep Alive store for unit tests (no LIVE / VPS DB writes).
 */
final class InMemoryKeepAliveStore implements KeepAliveStoreContract
{
    /** @var array<int, array<string, mixed>> */
    private array $agents = [];

    /** @var array<int, array<string, mixed>> */
    private array $campaigns = [];

    /** @var array<int, array<string, mixed>> */
    private array $nodes = [];

    /** @var array<int, array<string, mixed>> */
    private array $assignments = [];

    private int $nodeSeq = 1;

    private int $assignmentSeq = 1;

    private int $jobClaimInvocations = 0;

    private bool $ready = true;

    public function setReady(bool $ready): void
    {
        $this->ready = $ready;
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    /**
     * @param  array{id: int, agent_uuid: string, installation_id: string, client_api_key_id: int|null}  $agent
     */
    public function seedAgent(array $agent): void
    {
        $this->agents[(int) $agent['id']] = $agent;
    }

    /**
     * @param  array{id: int, campaign_uuid: string, installation_id: string, name: string, status: string, platform: string}  $campaign
     */
    public function seedCampaign(array $campaign): void
    {
        $this->campaigns[(int) $campaign['id']] = $campaign;
    }

    public function findAgentByUuid(string $agentUuid): ?array
    {
        foreach ($this->agents as $agent) {
            if ((string) $agent['agent_uuid'] === $agentUuid) {
                return [
                    'id' => (int) $agent['id'],
                    'agent_uuid' => (string) $agent['agent_uuid'],
                    'installation_id' => (string) $agent['installation_id'],
                    'client_api_key_id' => isset($agent['client_api_key_id']) ? (int) $agent['client_api_key_id'] : null,
                ];
            }
        }

        return null;
    }

    public function findCampaignByUuid(string $campaignUuid, string $installationId): ?array
    {
        foreach ($this->campaigns as $campaign) {
            if ((string) $campaign['campaign_uuid'] === $campaignUuid
                && (string) $campaign['installation_id'] === $installationId) {
                return [
                    'id' => (int) $campaign['id'],
                    'campaign_uuid' => (string) $campaign['campaign_uuid'],
                    'installation_id' => (string) $campaign['installation_id'],
                    'name' => (string) $campaign['name'],
                    'status' => (string) $campaign['status'],
                    'platform' => (string) $campaign['platform'],
                ];
            }
        }

        return null;
    }

    public function findNode(string $installationId, string $nodeId): ?array
    {
        foreach ($this->nodes as $node) {
            if ((string) $node['installation_id'] === $installationId
                && (string) $node['node_id'] === $nodeId) {
                return $this->normalizeNode($node);
            }
        }

        return null;
    }

    public function upsertNode(array $attributes): array
    {
        $now = gmdate('c');
        $existing = $this->findNode((string) $attributes['installation_id'], (string) $attributes['node_id']);
        if ($existing !== null) {
            $id = (int) $existing['id'];
            $this->nodes[$id] = array_merge($existing, [
                'agent_id' => $attributes['agent_id'],
                'agent_uuid' => $attributes['agent_uuid'],
                'client_api_key_id' => $attributes['client_api_key_id'],
                'protocol_version' => $attributes['protocol_version'],
                'schema_version' => $attributes['schema_version'],
                'capabilities' => $attributes['capabilities'],
                'last_seen_at' => $now,
            ]);

            return $this->normalizeNode($this->nodes[$id]);
        }

        $id = $this->nodeSeq++;
        $row = [
            'id' => $id,
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
        ];
        $this->nodes[$id] = $row;

        return $this->normalizeNode($row);
    }

    public function listAssignmentsForNode(string $installationId, string $nodeId): array
    {
        $out = [];
        foreach ($this->assignments as $row) {
            if ((string) $row['installation_id'] === $installationId
                && (string) $row['node_id'] === $nodeId) {
                $out[] = $this->normalizeAssignment($row);
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['campaign_uuid'], $b['campaign_uuid']));

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
        $node = $this->findNode($installationId, $nodeId);
        if ($node === null) {
            throw new \InvalidArgumentException('node_not_registered');
        }

        foreach ($this->assignments as $id => $row) {
            if ((int) $row['keepalive_node_id'] === (int) $node['id']
                && (int) $row['campaign_id'] === $campaignId) {
                $this->assignments[$id]['campaign_name'] = $campaignName;
                $this->assignments[$id]['campaign_status'] = $campaignStatus;
                $this->assignments[$id]['campaign_platform'] = $campaignPlatform;
                $this->assignments[$id]['version'] = (int) $row['version'] + 1;
                $this->bumpAssignmentGeneration($installationId, $nodeId);

                return $this->normalizeAssignment($this->assignments[$id]);
            }
        }

        $id = $this->assignmentSeq++;
        $row = [
            'id' => $id,
            'installation_id' => $installationId,
            'keepalive_node_id' => (int) $node['id'],
            'node_id' => $nodeId,
            'campaign_id' => $campaignId,
            'campaign_uuid' => $campaignUuid,
            'campaign_name' => $campaignName,
            'campaign_status' => $campaignStatus,
            'campaign_platform' => $campaignPlatform,
            'version' => 1,
            'assigned_at' => gmdate('c'),
        ];
        $this->assignments[$id] = $row;
        $this->bumpAssignmentGeneration($installationId, $nodeId);

        return $this->normalizeAssignment($row);
    }

    public function bumpAssignmentGeneration(string $installationId, string $nodeId): int
    {
        foreach ($this->nodes as $id => $node) {
            if ((string) $node['installation_id'] === $installationId
                && (string) $node['node_id'] === $nodeId) {
                $next = (int) $node['assignment_generation'] + 1;
                $this->nodes[$id]['assignment_generation'] = $next;

                return $next;
            }
        }

        return 0;
    }

    public function jobClaimInvocations(): int
    {
        return $this->jobClaimInvocations;
    }

    /** Test helper — prove Keep Alive paths never call claim. */
    public function recordJobClaim(): void
    {
        $this->jobClaimInvocations++;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function normalizeNode(array $node): array
    {
        return [
            'id' => (int) $node['id'],
            'node_id' => (string) $node['node_id'],
            'installation_id' => (string) $node['installation_id'],
            'agent_id' => isset($node['agent_id']) ? (int) $node['agent_id'] : null,
            'agent_uuid' => (string) $node['agent_uuid'],
            'client_api_key_id' => (int) $node['client_api_key_id'],
            'protocol_version' => (string) $node['protocol_version'],
            'schema_version' => (int) $node['schema_version'],
            'capabilities' => array_values((array) $node['capabilities']),
            'assignment_generation' => (int) $node['assignment_generation'],
            'registered_at' => (string) $node['registered_at'],
            'last_seen_at' => (string) $node['last_seen_at'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeAssignment(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'installation_id' => (string) $row['installation_id'],
            'keepalive_node_id' => (int) $row['keepalive_node_id'],
            'node_id' => (string) $row['node_id'],
            'campaign_id' => (int) $row['campaign_id'],
            'campaign_uuid' => (string) $row['campaign_uuid'],
            'campaign_name' => (string) $row['campaign_name'],
            'campaign_status' => (string) $row['campaign_status'],
            'campaign_platform' => (string) $row['campaign_platform'],
            'version' => (int) $row['version'],
            'assigned_at' => (string) $row['assigned_at'],
        ];
    }
}
