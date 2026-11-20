<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Pure registration / identity rules for Keep Alive Nodes.
 * Tenant ownership is always server-side (Client API Key installation_id).
 */
final class KeepAliveRegistrationPolicy
{
    public const ERROR_UNAUTHORIZED = 'unauthorized';

    public const ERROR_VALIDATION_FAILED = 'validation_failed';

    public const ERROR_PROTOCOL_UNSUPPORTED = 'protocol_unsupported';

    public const ERROR_SCHEMA_UNSUPPORTED = 'schema_unsupported';

    public const ERROR_CAPABILITIES_INVALID = 'capabilities_invalid';

    public const ERROR_TENANT_MISMATCH = 'tenant_mismatch';

    public const ERROR_AGENT_NOT_FOUND = 'agent_not_found';

    public const ERROR_AGENT_MISMATCH = 'agent_mismatch';

    public const ERROR_NODE_ID_REQUIRED = 'node_id_required';

    public const ERROR_AGENT_UUID_REQUIRED = 'agent_uuid_required';

    public const ERROR_NODE_NOT_REGISTERED = 'node_not_registered';

    public const ERROR_CROSS_TENANT = 'cross_tenant_forbidden';

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, errors?: list<string>, node_id?: string, agent_uuid?: string, capabilities?: list<string>, protocol_version?: string, schema_version?: int}
     */
    public function validateRegisterPayload(array $payload): array
    {
        $errors = [];
        $nodeId = trim((string) ($payload['node_id'] ?? ''));
        $agentUuid = trim((string) ($payload['agent_uuid'] ?? ''));
        $protocol = trim((string) ($payload['protocol_version'] ?? ''));
        $schemaVersion = (int) ($payload['schema_version'] ?? 0);
        $capabilities = KeepAliveProtocol::normalizeCapabilities($payload['capabilities'] ?? null);

        if ($nodeId === '') {
            $errors[] = self::ERROR_NODE_ID_REQUIRED;
        }
        if ($agentUuid === '') {
            $errors[] = self::ERROR_AGENT_UUID_REQUIRED;
        }
        if (! KeepAliveProtocol::isSupportedProtocol($protocol)) {
            $errors[] = self::ERROR_PROTOCOL_UNSUPPORTED;
        }
        if (! KeepAliveProtocol::isSupportedSchema($schemaVersion)) {
            $errors[] = self::ERROR_SCHEMA_UNSUPPORTED;
        }
        if (! KeepAliveProtocol::hasRequiredCapabilities($capabilities)) {
            $errors[] = self::ERROR_CAPABILITIES_INVALID;
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        return [
            'ok' => true,
            'node_id' => $nodeId,
            'agent_uuid' => $agentUuid,
            'capabilities' => $capabilities,
            'protocol_version' => KeepAliveProtocol::PROTOCOL_VERSION,
            'schema_version' => KeepAliveProtocol::SCHEMA_VERSION,
        ];
    }

    /**
     * Never trust client-supplied tenant_id. Reject mismatch; otherwise use server tenant.
     *
     * @return array{ok: bool, tenant_id?: string, errors?: list<string>}
     */
    public function resolveTenantId(string $serverTenantId, mixed $clientTenantId): array
    {
        $server = trim($serverTenantId);
        if ($server === '') {
            return ['ok' => false, 'errors' => [self::ERROR_UNAUTHORIZED]];
        }

        if ($clientTenantId === null || $clientTenantId === '') {
            return ['ok' => true, 'tenant_id' => $server];
        }

        $client = trim((string) $clientTenantId);
        if ($client !== $server) {
            return ['ok' => false, 'errors' => [self::ERROR_TENANT_MISMATCH, self::ERROR_CROSS_TENANT]];
        }

        return ['ok' => true, 'tenant_id' => $server];
    }

    /**
     * Agent must belong to the authenticated Client API Key + tenant.
     *
     * @param  array{agent_uuid?: string|null, installation_id?: string|null, client_api_key_id?: int|null}|null  $agent
     * @return array{ok: bool, errors?: list<string>}
     */
    public function assertAgentIdentity(
        ?array $agent,
        string $expectedAgentUuid,
        string $tenantId,
        int $clientApiKeyId,
    ): array {
        if ($agent === null) {
            return ['ok' => false, 'errors' => [self::ERROR_AGENT_NOT_FOUND]];
        }

        $agentUuid = trim((string) ($agent['agent_uuid'] ?? ''));
        $installationId = trim((string) ($agent['installation_id'] ?? ''));
        $keyId = isset($agent['client_api_key_id']) ? (int) $agent['client_api_key_id'] : null;

        if ($agentUuid === '' || ! hash_equals($expectedAgentUuid, $agentUuid)) {
            return ['ok' => false, 'errors' => [self::ERROR_AGENT_MISMATCH]];
        }
        if ($installationId === '' || ! hash_equals($tenantId, $installationId)) {
            return ['ok' => false, 'errors' => [self::ERROR_CROSS_TENANT, self::ERROR_AGENT_MISMATCH]];
        }
        if ($keyId === null || $keyId !== $clientApiKeyId) {
            return ['ok' => false, 'errors' => [self::ERROR_AGENT_MISMATCH]];
        }

        return ['ok' => true];
    }
}
