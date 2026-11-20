<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Contracts;

/**
 * Minimal persistence for Keep Alive node registration + explicit campaign assignments.
 *
 * @phpstan-type KeepAliveNodeRow array{
 *   id: int,
 *   node_id: string,
 *   installation_id: string,
 *   agent_id: int|null,
 *   agent_uuid: string,
 *   client_api_key_id: int,
 *   protocol_version: string,
 *   schema_version: int,
 *   capabilities: list<string>,
 *   assignment_generation: int,
 *   registered_at: string,
 *   last_seen_at: string
 * }
 * @phpstan-type KeepAliveAssignmentRow array{
 *   id: int,
 *   installation_id: string,
 *   keepalive_node_id: int,
 *   node_id: string,
 *   campaign_id: int,
 *   campaign_uuid: string,
 *   campaign_name: string,
 *   campaign_status: string,
 *   campaign_platform: string,
 *   version: int,
 *   assigned_at: string
 * }
 * @phpstan-type AgentIdentity array{
 *   id: int,
 *   agent_uuid: string,
 *   installation_id: string,
 *   client_api_key_id: int|null
 * }
 * @phpstan-type CampaignIdentity array{
 *   id: int,
 *   campaign_uuid: string,
 *   installation_id: string,
 *   name: string,
 *   status: string,
 *   platform: string
 * }
 */
interface KeepAliveStoreContract
{
    public function isReady(): bool;

    /**
     * @return AgentIdentity|null
     */
    public function findAgentByUuid(string $agentUuid): ?array;

    /**
     * @return CampaignIdentity|null
     */
    public function findCampaignByUuid(string $campaignUuid, string $installationId): ?array;

    /**
     * @return KeepAliveNodeRow|null
     */
    public function findNode(string $installationId, string $nodeId): ?array;

    /**
     * Idempotent upsert by (installation_id, node_id).
     *
     * @param  array{
     *   node_id: string,
     *   installation_id: string,
     *   agent_id: int|null,
     *   agent_uuid: string,
     *   client_api_key_id: int,
     *   protocol_version: string,
     *   schema_version: int,
     *   capabilities: list<string>
     * }  $attributes
     * @return KeepAliveNodeRow
     */
    public function upsertNode(array $attributes): array;

    /**
     * @return list<KeepAliveAssignmentRow>
     */
    public function listAssignmentsForNode(string $installationId, string $nodeId): array;

    /**
     * Explicit assignment only — never called by register.
     *
     * @return KeepAliveAssignmentRow
     */
    public function assignCampaignToNode(
        string $installationId,
        string $nodeId,
        int $campaignId,
        string $campaignUuid,
        string $campaignName,
        string $campaignStatus,
        string $campaignPlatform,
    ): array;

    public function bumpAssignmentGeneration(string $installationId, string $nodeId): int;

    /**
     * Job-claim side-effect probe for tests (must remain unused by Keep Alive paths).
     */
    public function jobClaimInvocations(): int;
}
