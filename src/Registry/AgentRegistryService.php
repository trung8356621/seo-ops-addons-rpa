<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Registry;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Domain\AgentKindPolicy;
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
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, agent?: array<string, mixed>, errors?: list<string>}
     */
    public function register(array $payload, ?int $credentialId = null, ?int $clientApiKeyId = null): array
    {
        if (! SocialAutomationSchema::agentsReady()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }

        $deviceId = trim((string) ($payload['device_id'] ?? ''));
        if ($deviceId === '') {
            return ['ok' => false, 'errors' => ['device_id_required']];
        }

        $defaultKind = $clientApiKeyId !== null ? AgentKindPolicy::KIND_CLIENT : AgentKindPolicy::KIND_RUNNER;
        $kind = $this->agentKinds->normalize(
            isset($payload['agent_kind']) ? (string) $payload['agent_kind'] : $defaultKind
        );

        try {
            $agent = DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($payload, $deviceId, $credentialId, $clientApiKeyId, $kind): SaAgent {
                $installationId = trim((string) ($payload['installation_id'] ?? ''));
                $existing = SaAgent::query()->where('device_id', $deviceId)
                    ->when($installationId !== '', fn ($q) => $q->where(function ($scope) use ($installationId, $clientApiKeyId): void {
                        $scope->where('installation_id', $installationId);
                        if ($clientApiKeyId !== null) {
                            $scope->orWhere(fn ($legacy) => $legacy->whereNull('installation_id')->where('client_api_key_id', $clientApiKeyId));
                        }
                    }))
                    ->first();
                if ($existing instanceof SaAgent) {
                    if ($credentialId !== null && $existing->credential_id !== null
                        && (int) $existing->credential_id !== $credentialId) {
                        throw new \RuntimeException('credential_conflict');
                    }
                    $existing->fill([
                        'agent_kind' => $kind,
                        'installation_id' => $installationId !== '' ? $installationId : $existing->installation_id,
                        'display_name' => $payload['display_name'] ?? $existing->display_name,
                        'app_version' => $payload['app_version'] ?? $existing->app_version,
                        'runtime_version' => $payload['runtime_version'] ?? $existing->runtime_version,
                        'capabilities' => is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : $existing->capabilities,
                        'max_concurrency' => max(1, (int) ($payload['max_concurrency'] ?? $existing->max_concurrency ?? 1)),
                        'credential_id' => $credentialId ?? $existing->credential_id,
                        'client_api_key_id' => $clientApiKeyId ?? $existing->client_api_key_id,
                        'last_heartbeat_at' => now(),
                    ])->save();

                    return $existing->fresh() ?? $existing;
                }

                return SaAgent::query()->create([
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
                    'client_api_key_id' => $clientApiKeyId,
                ]);
            });
        } catch (Throwable $e) {
            $code = $e->getMessage() === 'credential_conflict' ? 'credential_conflict' : 'register_failed';

            return ['ok' => false, 'errors' => [$code]];
        }

        return ['ok' => true, 'agent' => $this->toArray($agent)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, agent?: array<string, mixed>, errors?: list<string>}
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

        $availability = trim((string) ($payload['availability'] ?? $agent->availability));
        if (! in_array($availability, array_column(AgentAvailability::cases(), 'value'), true)) {
            $availability = $agent->availability;
        }

        $agent->fill([
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
        ])->save();

        return ['ok' => true, 'agent' => $this->toArray($agent->fresh() ?? $agent)];
    }

    /**
     * Admin/ops disable (revocation of dispatch eligibility). Does not delete history.
     *
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
            'agent_kind' => $kind,
            'display_name' => $agent->display_name,
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
            'last_error_code' => $agent->last_error_code,
            'last_error_message' => $agent->last_error_message,
            'client_api_key_id' => $agent->client_api_key_id,
        ];
    }
}
