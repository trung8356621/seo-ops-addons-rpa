<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Registry;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Domain\AgentKindPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\ClientConnectionLease;
use Omnichannel\Addons\SocialAutomation\Domain\DeviceOsMetadata;
use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;
use Omnichannel\Addons\SocialAutomation\Enums\AgentAvailability;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use Throwable;

final class AgentRegistryService
{
    public function __construct(
        private readonly OnlineStateCalculator $online = new OnlineStateCalculator,
        private readonly AgentKindPolicy $agentKinds = new AgentKindPolicy,
        private readonly ClientConnectionLease $connectionLease = new ClientConnectionLease,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, agent?: array<string, mixed>, connection_token?: string, connection_lease_expires_at?: string, errors?: list<string>, error?: string}
     */
    public function register(array $payload, ?int $credentialId = null, ?int $clientApiKeyId = null): array
    {
        if (! SocialAutomationSchema::agentsReady()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }

        if ($clientApiKeyId !== null) {
            return $this->registerClientByApiKey($payload, $clientApiKeyId);
        }

        return $this->registerRunnerByDeviceId($payload, $credentialId);
    }

    /**
     * Client identity: 1 Client API Key ID → 1 sa_agents row.
     * Electron must NOT send a Device UUID; stable device_id is derived as cak_{keyId} for DB compatibility.
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, agent?: array<string, mixed>, connection_token?: string, connection_lease_expires_at?: string, errors?: list<string>, error?: string}
     */
    private function registerClientByApiKey(array $payload, int $clientApiKeyId): array
    {
        $installationId = trim((string) ($payload['installation_id'] ?? ''));
        $incomingToken = trim((string) ($payload['connection_token'] ?? ''));
        if ($incomingToken === '') {
            $incomingToken = null;
        }
        $stableDeviceId = 'cak_'.$clientApiKeyId;

        try {
            $result = DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($payload, $clientApiKeyId, $installationId, $incomingToken, $stableDeviceId): array {
                $existing = SaAgent::query()
                    ->where('client_api_key_id', $clientApiKeyId)
                    ->lockForUpdate()
                    ->first();

                if (! $existing instanceof SaAgent) {
                    // Legacy fallback: agent linked by installation + prior device bind without key uniqueness.
                    $legacyDeviceId = trim((string) ($payload['device_id'] ?? ''));
                    if ($legacyDeviceId !== '' && $installationId !== '') {
                        $existing = SaAgent::query()
                            ->where('device_id', $legacyDeviceId)
                            ->where('installation_id', $installationId)
                            ->where(function ($q) use ($clientApiKeyId): void {
                                $q->whereNull('client_api_key_id')->orWhere('client_api_key_id', $clientApiKeyId);
                            })
                            ->lockForUpdate()
                            ->first();
                    }
                }

                if ($existing instanceof SaAgent) {
                    if (! $this->connectionLease->allows(
                        $existing->connection_token,
                        $existing->connection_lease_expires_at,
                        $incomingToken,
                    )) {
                        throw new \DomainException(ClientConnectionLease::ERROR_KEY_IN_USE);
                    }

                    $token = ($incomingToken !== null && $existing->connection_token !== null
                        && hash_equals((string) $existing->connection_token, $incomingToken))
                        ? (string) $existing->connection_token
                        : $this->connectionLease->issueToken();
                    $leaseExpires = $this->connectionLease->expiresAt();

                    $update = DeviceOsMetadata::applyToFill([
                        'agent_kind' => AgentKindPolicy::KIND_CLIENT,
                        'installation_id' => $installationId !== '' ? $installationId : $existing->installation_id,
                        'device_id' => $stableDeviceId,
                        'display_name' => $payload['display_name'] ?? $existing->display_name,
                        'app_version' => $payload['app_version'] ?? $existing->app_version,
                        'runtime_version' => $payload['runtime_version'] ?? $existing->runtime_version,
                        'capabilities' => is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : $existing->capabilities,
                        'max_concurrency' => max(1, (int) ($payload['max_concurrency'] ?? $existing->max_concurrency ?? 1)),
                        'client_api_key_id' => $clientApiKeyId,
                        'connection_token' => $token,
                        'connection_lease_expires_at' => $leaseExpires,
                        'last_heartbeat_at' => now(),
                        'availability' => AgentAvailability::Available->value,
                    ], $payload, $existing->os_family, $existing->os_label);
                    $existing->fill($update)->save();
                    $agent = $existing->fresh() ?? $existing;

                    return ['agent' => $agent, 'connection_token' => $token, 'lease_expires' => $leaseExpires];
                }

                $token = $this->connectionLease->issueToken();
                $leaseExpires = $this->connectionLease->expiresAt();
                $create = DeviceOsMetadata::applyToFill([
                    'agent_uuid' => (string) Str::uuid(),
                    'device_id' => $stableDeviceId,
                    'installation_id' => $installationId !== '' ? $installationId : null,
                    'agent_kind' => AgentKindPolicy::KIND_CLIENT,
                    'display_name' => $payload['display_name'] ?? null,
                    'app_version' => $payload['app_version'] ?? null,
                    'runtime_version' => $payload['runtime_version'] ?? null,
                    'capabilities' => is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : [],
                    'availability' => AgentAvailability::Available->value,
                    'max_concurrency' => max(1, (int) ($payload['max_concurrency'] ?? 1)),
                    'active_jobs' => 0,
                    'last_heartbeat_at' => now(),
                    'credential_id' => null,
                    'client_api_key_id' => $clientApiKeyId,
                    'connection_token' => $token,
                    'connection_lease_expires_at' => $leaseExpires,
                ], $payload);

                $agent = SaAgent::query()->create($create);

                return ['agent' => $agent, 'connection_token' => $token, 'lease_expires' => $leaseExpires];
            });
        } catch (\DomainException $e) {
            if ($e->getMessage() === ClientConnectionLease::ERROR_KEY_IN_USE) {
                return [
                    'ok' => false,
                    'error' => ClientConnectionLease::ERROR_KEY_IN_USE,
                    'errors' => [ClientConnectionLease::ERROR_KEY_IN_USE],
                    'message' => 'Client API Key is already in use by another active connection.',
                ];
            }

            return ['ok' => false, 'errors' => ['register_failed'], 'error' => 'register_failed', 'diagnostic' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);

            return [
                'ok' => false,
                'errors' => ['register_failed'],
                'error' => 'register_failed',
                'diagnostic' => class_basename($e).':constraint_or_db',
            ];
        }

        /** @var SaAgent $agent */
        $agent = $result['agent'];
        $leaseExpires = $result['lease_expires'];

        return [
            'ok' => true,
            'agent' => $this->toArray($agent),
            'connection_token' => $result['connection_token'],
            'connection_lease_expires_at' => $leaseExpires instanceof \DateTimeInterface
                ? $leaseExpires->format(\DateTimeInterface::ATOM)
                : (string) $leaseExpires,
        ];
    }

    /**
     * Runner path: still identified by device_id (unchanged).
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, agent?: array<string, mixed>, errors?: list<string>}
     */
    private function registerRunnerByDeviceId(array $payload, ?int $credentialId): array
    {
        $deviceId = trim((string) ($payload['device_id'] ?? ''));
        if ($deviceId === '') {
            return ['ok' => false, 'errors' => ['device_id_required']];
        }

        $kind = $this->agentKinds->normalize(
            isset($payload['agent_kind']) ? (string) $payload['agent_kind'] : AgentKindPolicy::KIND_RUNNER
        );

        try {
            $agent = DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($payload, $deviceId, $credentialId, $kind): SaAgent {
                $installationId = trim((string) ($payload['installation_id'] ?? ''));
                $existing = SaAgent::query()->where('device_id', $deviceId)
                    ->when($installationId !== '', fn ($q) => $q->where('installation_id', $installationId))
                    ->first();
                if ($existing instanceof SaAgent) {
                    if ($credentialId !== null && $existing->credential_id !== null
                        && (int) $existing->credential_id !== $credentialId) {
                        throw new \RuntimeException('credential_conflict');
                    }
                    $update = DeviceOsMetadata::applyToFill([
                        'agent_kind' => $kind,
                        'installation_id' => $installationId !== '' ? $installationId : $existing->installation_id,
                        'display_name' => $payload['display_name'] ?? $existing->display_name,
                        'app_version' => $payload['app_version'] ?? $existing->app_version,
                        'runtime_version' => $payload['runtime_version'] ?? $existing->runtime_version,
                        'capabilities' => is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : $existing->capabilities,
                        'max_concurrency' => max(1, (int) ($payload['max_concurrency'] ?? $existing->max_concurrency ?? 1)),
                        'credential_id' => $credentialId ?? $existing->credential_id,
                        'last_heartbeat_at' => now(),
                    ], $payload, $existing->os_family, $existing->os_label);
                    $existing->fill($update)->save();

                    return $existing->fresh() ?? $existing;
                }

                $create = DeviceOsMetadata::applyToFill([
                    'agent_uuid' => (string) Str::uuid(),
                    'device_id' => $deviceId,
                    'installation_id' => $installationId !== '' ? $installationId : null,
                    'agent_kind' => $kind,
                    'display_name' => $payload['display_name'] ?? null,
                    'app_version' => $payload['app_version'] ?? null,
                    'runtime_version' => $payload['runtime_version'] ?? null,
                    'capabilities' => is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : [],
                    'availability' => AgentAvailability::Available->value,
                    'max_concurrency' => max(1, (int) ($payload['max_concurrency'] ?? 1)),
                    'active_jobs' => 0,
                    'last_heartbeat_at' => now(),
                    'credential_id' => $credentialId,
                    'client_api_key_id' => null,
                ], $payload);

                return SaAgent::query()->create($create);
            });
        } catch (Throwable $e) {
            $code = $e->getMessage() === 'credential_conflict' ? 'credential_conflict' : 'register_failed';

            return ['ok' => false, 'errors' => [$code]];
        }

        return ['ok' => true, 'agent' => $this->toArray($agent)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, agent?: array<string, mixed>, errors?: list<string>, error?: string}
     */
    public function heartbeat(string $agentUuid, array $payload, ?int $credentialId = null, ?int $clientApiKeyId = null): array
    {
        if (! SocialAutomationSchema::agentsReady()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }

        $agent = SaAgent::query()->where('agent_uuid', $agentUuid)->orWhere('device_id', $agentUuid)->first();
        if (! $agent instanceof SaAgent) {
            return ['ok' => false, 'errors' => ['agent_not_found']];
        }
        if ($credentialId !== null && $agent->credential_id !== null
            && (int) $agent->credential_id !== $credentialId) {
            return ['ok' => false, 'errors' => ['credential_mismatch']];
        }
        if ($clientApiKeyId !== null && $agent->client_api_key_id !== null
            && (int) $agent->client_api_key_id !== $clientApiKeyId) {
            return ['ok' => false, 'errors' => ['client_api_key_mismatch']];
        }

        if ($clientApiKeyId !== null) {
            $incomingToken = trim((string) ($payload['connection_token'] ?? ''));
            if ($incomingToken === '') {
                return ['ok' => false, 'error' => 'connection_token_required', 'errors' => ['connection_token_required']];
            }
            if (! $this->connectionLease->allows(
                $agent->connection_token,
                $agent->connection_lease_expires_at,
                $incomingToken,
            )) {
                return [
                    'ok' => false,
                    'error' => ClientConnectionLease::ERROR_KEY_IN_USE,
                    'errors' => [ClientConnectionLease::ERROR_KEY_IN_USE],
                ];
            }
            if ($agent->connection_token === null || ! hash_equals((string) $agent->connection_token, $incomingToken)) {
                return [
                    'ok' => false,
                    'error' => ClientConnectionLease::ERROR_KEY_IN_USE,
                    'errors' => [ClientConnectionLease::ERROR_KEY_IN_USE],
                ];
            }
        }

        $availability = trim((string) ($payload['availability'] ?? $agent->availability));
        if (! in_array($availability, array_column(AgentAvailability::cases(), 'value'), true)) {
            $availability = $agent->availability;
        }

        $heartbeatFill = DeviceOsMetadata::applyToFill([
            'last_heartbeat_at' => now(),
            'availability' => $availability,
            'app_version' => $payload['app_version'] ?? $agent->app_version,
            'runtime_version' => $payload['runtime_version'] ?? $agent->runtime_version,
            'capabilities' => is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : $agent->capabilities,
            'last_error_code' => $payload['last_error_code'] ?? null,
            'last_error_message' => isset($payload['last_error_message'])
                ? substr((string) $payload['last_error_message'], 0, 2000)
                : $agent->last_error_message,
            'active_jobs' => max(0, (int) ($payload['active_jobs'] ?? $agent->active_jobs)),
        ], $payload, $agent->os_family, $agent->os_label);

        if ($clientApiKeyId !== null) {
            $heartbeatFill['connection_lease_expires_at'] = $this->connectionLease->expiresAt();
        }

        $agent->fill($heartbeatFill)->save();

        return ['ok' => true, 'agent' => $this->toArray($agent->fresh() ?? $agent)];
    }

    /**
     * Resolve the single client agent for an authenticated API key.
     */
    public function findClientByApiKeyId(int $clientApiKeyId): ?SaAgent
    {
        return SaAgent::query()
            ->where('client_api_key_id', $clientApiKeyId)
            ->where('agent_kind', AgentKindPolicy::KIND_CLIENT)
            ->first();
    }

    /**
     * Release the temporary connection lease (graceful disconnect).
     * Does not delete the Client identity or revoke the API key.
     */
    public function releaseConnection(int $clientApiKeyId, string $connectionToken): bool
    {
        $token = trim($connectionToken);
        if ($token === '') {
            return false;
        }
        $agent = $this->findClientByApiKeyId($clientApiKeyId);
        if (! $agent instanceof SaAgent) {
            return false;
        }
        if ($agent->connection_token === null || ! hash_equals((string) $agent->connection_token, $token)) {
            return false;
        }
        $agent->fill([
            'connection_token' => null,
            'connection_lease_expires_at' => null,
        ])->save();

        return true;
    }

    /**
     * Staff/server force-release: clear lease without requiring Electron connection_token.
     * Used after credential revoke so offline/broken Clients cannot keep a live lease.
     */
    public function forceReleaseConnection(int $clientApiKeyId): bool
    {
        $agent = $this->findClientByApiKeyId($clientApiKeyId);
        if (! $agent instanceof SaAgent) {
            return false;
        }
        $agent->fill([
            'connection_token' => null,
            'connection_lease_expires_at' => null,
        ])->save();

        return true;
    }

    /**
     * @return array{ok: bool, agent?: array<string, mixed>, errors?: list<string>}
     */
    public function disable(string $agentUuid, ?int $credentialId = null): array
    {
        if (! SocialAutomationSchema::agentsReady()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }

        $agent = SaAgent::query()->where('agent_uuid', $agentUuid)->first();
        if (! $agent instanceof SaAgent) {
            return ['ok' => false, 'errors' => ['agent_not_found']];
        }
        if ($credentialId !== null && $agent->credential_id !== null
            && (int) $agent->credential_id !== $credentialId) {
            return ['ok' => false, 'errors' => ['credential_mismatch']];
        }

        $agent->availability = AgentAvailability::Disabled->value;
        $agent->save();

        return ['ok' => true, 'agent' => $this->toArray($agent->fresh() ?? $agent)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUi(): array
    {
        if (! SocialAutomationSchema::agentsReady()) {
            return [];
        }

        return SaAgent::query()->orderByDesc('last_heartbeat_at')->limit(200)->get()
            ->map(fn (SaAgent $a): array => $this->toArray($a))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(SaAgent $agent): array
    {
        $hb = $agent->last_heartbeat_at;
        $kind = $this->agentKinds->normalize(
            isset($agent->agent_kind) ? (string) $agent->agent_kind : AgentKindPolicy::KIND_RUNNER
        );
        $heartbeatOnline = $this->online->isOnline($hb);

        return [
            'agent_uuid' => $agent->agent_uuid,
            'device_id' => $agent->device_id,
            'client_api_key_id' => $agent->client_api_key_id,
            'agent_kind' => $kind,
            'display_name' => $agent->display_name,
            'os_family' => $agent->os_family,
            'os_label' => $agent->os_label,
            'app_version' => $agent->app_version,
            'runtime_version' => $agent->runtime_version,
            'capabilities' => $agent->capabilities ?? [],
            'availability' => $agent->availability,
            'max_concurrency' => (int) $agent->max_concurrency,
            'active_jobs' => (int) $agent->active_jobs,
            'device_online' => $heartbeatOnline,
            'online_state' => ($kind === AgentKindPolicy::KIND_CLIENT)
                ? ($heartbeatOnline ? 'online' : 'offline')
                : ($this->agentKinds->countsAsOnlineForDispatch($kind, $heartbeatOnline) ? 'online' : 'offline'),
            'dispatch_eligible' => $this->agentKinds->countsAsOnlineForDispatch($kind, $heartbeatOnline),
            'last_heartbeat_at' => $hb?->toIso8601String(),
            'connection_lease_expires_at' => $agent->connection_lease_expires_at?->toIso8601String(),
            'last_error_code' => $agent->last_error_code,
            'last_error_message' => $agent->last_error_message,
        ];
    }
}
