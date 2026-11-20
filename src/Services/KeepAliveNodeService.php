<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Omnichannel\Addons\SocialAutomation\Contracts\KeepAliveStoreContract;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveProtocol;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveRegistrationPolicy;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;

/**
 * Keep Alive Node registration + explicit Campaign assignment reads.
 * Does not authorize Campaign access on register. Never claims Jobs or touches Browser Profiles.
 */
final class KeepAliveNodeService
{
    public function __construct(
        private readonly KeepAliveStoreContract $store,
        private readonly KeepAliveRegistrationPolicy $policy = new KeepAliveRegistrationPolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, http?: int, error?: string, errors?: list<string>, data?: array<string, mixed>}
     */
    public function register(SaClientApiKey $clientKey, array $payload): array
    {
        if (! $this->store->isReady()) {
            return ['ok' => false, 'http' => 503, 'error' => 'schema_unavailable', 'errors' => ['schema_unavailable']];
        }

        $validated = $this->policy->validateRegisterPayload($payload);
        if (! ($validated['ok'] ?? false)) {
            $errors = $validated['errors'] ?? [KeepAliveRegistrationPolicy::ERROR_VALIDATION_FAILED];

            return [
                'ok' => false,
                'http' => 422,
                'error' => $errors[0],
                'errors' => $errors,
            ];
        }

        $tenant = $this->policy->resolveTenantId(
            (string) $clientKey->installation_id,
            $payload['tenant_id'] ?? null,
        );
        if (! ($tenant['ok'] ?? false)) {
            $errors = $tenant['errors'] ?? [KeepAliveRegistrationPolicy::ERROR_TENANT_MISMATCH];

            return [
                'ok' => false,
                'http' => 403,
                'error' => $errors[0],
                'errors' => $errors,
            ];
        }
        $tenantId = (string) $tenant['tenant_id'];

        $agentUuid = (string) $validated['agent_uuid'];
        $agent = $this->store->findAgentByUuid($agentUuid);
        $identity = $this->policy->assertAgentIdentity(
            $agent,
            $agentUuid,
            $tenantId,
            (int) $clientKey->id,
        );
        if (! ($identity['ok'] ?? false)) {
            $errors = $identity['errors'] ?? [KeepAliveRegistrationPolicy::ERROR_AGENT_MISMATCH];
            $http = in_array(KeepAliveRegistrationPolicy::ERROR_AGENT_NOT_FOUND, $errors, true) ? 404 : 403;

            return [
                'ok' => false,
                'http' => $http,
                'error' => $errors[0],
                'errors' => $errors,
            ];
        }

        $node = $this->store->upsertNode([
            'node_id' => (string) $validated['node_id'],
            'installation_id' => $tenantId,
            'agent_id' => $agent['id'] ?? null,
            'agent_uuid' => $agentUuid,
            'client_api_key_id' => (int) $clientKey->id,
            'protocol_version' => KeepAliveProtocol::PROTOCOL_VERSION,
            'schema_version' => KeepAliveProtocol::SCHEMA_VERSION,
            'capabilities' => $validated['capabilities'],
        ]);

        return [
            'ok' => true,
            'http' => 200,
            'data' => [
                'protocol_version' => KeepAliveProtocol::PROTOCOL_VERSION,
                'schema_version' => KeepAliveProtocol::SCHEMA_VERSION,
                'campaign_access_authorized' => false,
                'node' => [
                    'node_id' => $node['node_id'],
                    'agent_uuid' => $node['agent_uuid'],
                    'tenant_id' => $tenantId,
                    'capabilities' => $node['capabilities'],
                    'assignment_generation' => $node['assignment_generation'],
                    'registered_at' => $node['registered_at'],
                    'last_seen_at' => $node['last_seen_at'],
                ],
            ],
        ];
    }

    /**
     * @return array{ok: bool, http?: int, error?: string, errors?: list<string>, data?: array<string, mixed>}
     */
    public function listAssignments(SaClientApiKey $clientKey, string $nodeId, mixed $clientTenantId = null): array
    {
        if (! $this->store->isReady()) {
            return ['ok' => false, 'http' => 503, 'error' => 'schema_unavailable', 'errors' => ['schema_unavailable']];
        }

        $nodeId = trim($nodeId);
        if ($nodeId === '') {
            return [
                'ok' => false,
                'http' => 422,
                'error' => KeepAliveRegistrationPolicy::ERROR_NODE_ID_REQUIRED,
                'errors' => [KeepAliveRegistrationPolicy::ERROR_NODE_ID_REQUIRED],
            ];
        }

        $tenant = $this->policy->resolveTenantId(
            (string) $clientKey->installation_id,
            $clientTenantId,
        );
        if (! ($tenant['ok'] ?? false)) {
            $errors = $tenant['errors'] ?? [KeepAliveRegistrationPolicy::ERROR_TENANT_MISMATCH];

            return [
                'ok' => false,
                'http' => 403,
                'error' => $errors[0],
                'errors' => $errors,
            ];
        }
        $tenantId = (string) $tenant['tenant_id'];

        $node = $this->store->findNode($tenantId, $nodeId);
        if ($node === null) {
            return [
                'ok' => false,
                'http' => 404,
                'error' => KeepAliveRegistrationPolicy::ERROR_NODE_NOT_REGISTERED,
                'errors' => [KeepAliveRegistrationPolicy::ERROR_NODE_NOT_REGISTERED],
            ];
        }
        if ((int) $node['client_api_key_id'] !== (int) $clientKey->id) {
            return [
                'ok' => false,
                'http' => 403,
                'error' => KeepAliveRegistrationPolicy::ERROR_AGENT_MISMATCH,
                'errors' => [KeepAliveRegistrationPolicy::ERROR_AGENT_MISMATCH],
            ];
        }

        $rows = $this->store->listAssignmentsForNode($tenantId, $nodeId);
        $assignments = [];
        foreach ($rows as $row) {
            $rowVersion = (int) ($row['row_version'] ?? $row['version'] ?? 0);
            $assignments[] = [
                'campaign_id' => $row['campaign_uuid'],
                'campaign_uuid' => $row['campaign_uuid'],
                'name' => $row['campaign_name'],
                'status' => $row['campaign_status'],
                'platform' => $row['campaign_platform'],
                'row_version' => $rowVersion,
                'version' => $rowVersion, // 3B.1 alias
                'assigned_at' => $row['assigned_at'],
            ];
        }

        return [
            'ok' => true,
            'http' => 200,
            'data' => [
                'protocol_version' => KeepAliveProtocol::PROTOCOL_VERSION,
                'schema_version' => KeepAliveProtocol::SCHEMA_VERSION,
                'node_id' => $nodeId,
                'tenant_id' => $tenantId,
                'assignment_generation' => (int) $node['assignment_generation'],
                'assignments' => $assignments,
            ],
        ];
    }

    /**
     * Explicit staff/test assignment helper — never invoked by register.
     *
     * @return array{ok: bool, errors?: list<string>, assignment?: array<string, mixed>, assignment_generation?: int}
     */
    public function assignCampaignExplicitly(
        SaClientApiKey $clientKey,
        string $nodeId,
        string $campaignUuid,
    ): array {
        if (! $this->store->isReady()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }

        $tenantId = (string) $clientKey->installation_id;
        $node = $this->store->findNode($tenantId, $nodeId);
        if ($node === null) {
            return ['ok' => false, 'errors' => [KeepAliveRegistrationPolicy::ERROR_NODE_NOT_REGISTERED]];
        }

        $campaign = $this->store->findCampaignByUuid($campaignUuid, $tenantId);
        if ($campaign === null) {
            return ['ok' => false, 'errors' => ['campaign_not_found']];
        }

        $assignment = $this->store->assignCampaignToNode(
            $tenantId,
            $nodeId,
            (int) $campaign['id'],
            (string) $campaign['campaign_uuid'],
            (string) $campaign['name'],
            (string) $campaign['status'],
            (string) $campaign['platform'],
        );
        $fresh = $this->store->findNode($tenantId, $nodeId);

        return [
            'ok' => true,
            'assignment' => $assignment,
            'assignment_generation' => (int) ($fresh['assignment_generation'] ?? 0),
        ];
    }
}
