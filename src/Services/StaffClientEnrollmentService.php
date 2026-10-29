<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Illuminate\Support\Facades\Crypt;
use Omnichannel\Addons\SocialAutomation\Contracts\RpaClientApiKeyRepositoryContract;
use Omnichannel\Addons\SocialAutomation\Domain\RpaClientApiKeyGenerator;
use Omnichannel\Addons\SocialAutomation\Domain\StaffClientEnrollmentBootstrap;
use Omnichannel\Addons\SocialAutomation\Domain\StaffClientEnrollmentSlotLock;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Registry\AgentRegistryService;
use Omnichannel\Addons\SocialAutomation\Repositories\EloquentRpaClientApiKeyRepository;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;

/**
 * Staff ↔ Client enrollment on existing Client API Key + connection lease contracts.
 * Unused bootstrap keys are never persisted. Successful registration creates sa_client_api_keys
 * with created_by = staff user id (1 active key per staff per installation).
 */
final class StaffClientEnrollmentService
{
    public const LOCALSTORAGE_KEY = 'sa_staff_client_bootstrap_v1';

    public const ERROR_ALREADY_CONNECTED = 'staff_already_connected';

    public const ERROR_NOT_CONNECTED = 'staff_not_connected';

    public const ERROR_STAFF_SLOT_TAKEN = 'staff_client_slot_taken';

    private RpaClientApiKeyRepositoryContract $repository;

    public function __construct(
        private readonly StaffClientEnrollmentBootstrap $bootstrap,
        private readonly RpaClientApiKeyGenerator $generator,
        private readonly SocialAutomationInstallationResolver $installationResolver,
        ?RpaClientApiKeyRepositoryContract $repository = null,
        private readonly ?AgentRegistryService $agents = null,
        private readonly StaffClientEnrollmentSlotLock $slotLock = new StaffClientEnrollmentSlotLock,
    ) {
        $this->repository = $repository ?? new EloquentRpaClientApiKeyRepository;
    }

    /**
     * @return array{
     *     connected: bool,
     *     status: string,
     *     bootstrap: array<string, mixed>|null,
     *     connection: array<string, mixed>|null,
     *     localstorage_key: string
     * }
     */
    public function connectionState(int $staffUserId, ?string $installationId = null): array
    {
        $installationId = $installationId ?? $this->installationResolver->resolveInstallationId();
        $active = $this->repository->findActiveByCreator($installationId, $staffUserId);

        if ($active instanceof SaClientApiKey) {
            $agent = $this->findAgentForKey((int) $active->id);

            return [
                'connected' => true,
                'status' => 'connected',
                'bootstrap' => null,
                'connection' => $this->serializeConnection($active, $agent),
                'localstorage_key' => self::LOCALSTORAGE_KEY,
            ];
        }

        $issued = $this->bootstrap->issue($staffUserId, $installationId);

        return [
            'connected' => false,
            'status' => 'not_connected',
            'bootstrap' => [
                'raw_key' => $issued['raw_key'],
                'key_prefix' => $issued['key_prefix'],
                'expires_at' => $issued['expires_at'],
                'expires_at_iso' => $issued['expires_at_iso'],
                'installation_id' => $issued['installation_id'],
                'purpose' => StaffClientEnrollmentBootstrap::PURPOSE,
                'tutorial_video' => [
                    'available' => false,
                    'placeholder' => true,
                    'message' => 'Video tutorial placeholder',
                ],
            ],
            'connection' => null,
            'localstorage_key' => self::LOCALSTORAGE_KEY,
        ];
    }

    /**
     * Issue (or re-issue) a temporary bootstrap key. Zero DB writes.
     * Contract: refresh replaces the previous unused bootstrap; server does not track unused keys.
     *
     * @return array{ok: bool, bootstrap?: array<string, mixed>, error?: string, message?: string, localstorage_key: string}
     */
    public function issueBootstrapKey(int $staffUserId, ?string $installationId = null): array
    {
        $installationId = $installationId ?? $this->installationResolver->resolveInstallationId();
        $active = $this->repository->findActiveByCreator($installationId, $staffUserId);
        if ($active instanceof SaClientApiKey) {
            return [
                'ok' => false,
                'error' => self::ERROR_ALREADY_CONNECTED,
                'message' => 'Staff already has a connected Client. Disconnect before issuing a new Key.',
                'localstorage_key' => self::LOCALSTORAGE_KEY,
            ];
        }

        $issued = $this->bootstrap->issue($staffUserId, $installationId);

        return [
            'ok' => true,
            'bootstrap' => [
                'raw_key' => $issued['raw_key'],
                'key_prefix' => $issued['key_prefix'],
                'expires_at' => $issued['expires_at'],
                'expires_at_iso' => $issued['expires_at_iso'],
                'installation_id' => $issued['installation_id'],
                'purpose' => StaffClientEnrollmentBootstrap::PURPOSE,
            ],
            'localstorage_key' => self::LOCALSTORAGE_KEY,
        ];
    }

    /**
     * Materialize bootstrap into a persisted Client API Key (successful enrollment step).
     * Enforces one active Client key per staff. Concurrent callers lose via lock/check.
     *
     * @param  array{staff_user_id: int, installation_id: string, expires_at: int, key_prefix: string, nonce: string}  $parsed
     * @return array{ok: bool, key?: SaClientApiKey, error?: string, message?: string}
     */
    public function materializeFromBootstrap(array $parsed, string $rawKey, ?string $expectedInstallationId = null): array
    {
        $installationId = $expectedInstallationId ?? $this->installationResolver->resolveInstallationId();
        $usableError = $this->bootstrap->assertUsable($parsed, $installationId);
        if ($usableError !== null) {
            return [
                'ok' => false,
                'error' => $usableError,
                'message' => 'Enrollment bootstrap Key is invalid or expired.',
            ];
        }

        $staffUserId = (int) $parsed['staff_user_id'];
        $prefix = $this->generator->extractPrefix($rawKey);
        if ($prefix === null || ! hash_equals($prefix, (string) $parsed['key_prefix'])) {
            return [
                'ok' => false,
                'error' => StaffClientEnrollmentBootstrap::ERROR_INVALID,
                'message' => 'Enrollment bootstrap Key format is invalid.',
            ];
        }

        $create = function () use ($installationId, $staffUserId, $prefix, $rawKey): array {
            $existing = $this->repository->findActiveByCreator($installationId, $staffUserId);
            if ($existing instanceof SaClientApiKey) {
                return [
                    'ok' => false,
                    'error' => self::ERROR_STAFF_SLOT_TAKEN,
                    'message' => 'Staff already has an active Client association.',
                ];
            }

            $key = $this->repository->create([
                'installation_id' => $installationId,
                'name' => 'Staff Client '.$staffUserId,
                'key_prefix' => $prefix,
                'key_hash' => $this->generator->hash($rawKey),
                'encrypted_key' => $this->encryptRawKey($rawKey),
                'created_by' => $staffUserId,
                'revoked_at' => null,
                'last_used_at' => null,
            ]);

            return ['ok' => true, 'key' => $key];
        };

        if ($this->repository instanceof EloquentRpaClientApiKeyRepository && $this->dbAvailable()) {
            try {
                // Empty-row FOR UPDATE is not enough for first registration — SlotLock
                // holds users.id (or GET_LOCK) before check+create in one critical section.
                return $this->slotLock->synchronize($staffUserId, $installationId, $create);
            } catch (\RuntimeException $e) {
                if ($e->getMessage() === StaffClientEnrollmentSlotLock::ERROR_LOCK_TIMEOUT) {
                    return [
                        'ok' => false,
                        'error' => self::ERROR_STAFF_SLOT_TAKEN,
                        'message' => 'Staff already has an active Client association.',
                    ];
                }
                if ($e->getMessage() === StaffClientEnrollmentSlotLock::ERROR_STAFF_NOT_FOUND) {
                    return [
                        'ok' => false,
                        'error' => StaffClientEnrollmentSlotLock::ERROR_STAFF_NOT_FOUND,
                        'message' => 'Staff user not found for enrollment lock.',
                    ];
                }
                report($e);

                return [
                    'ok' => false,
                    'error' => 'enrollment_persist_failed',
                    'message' => 'Failed to persist Staff Client enrollment.',
                ];
            } catch (\Throwable $e) {
                report($e);

                return [
                    'ok' => false,
                    'error' => 'enrollment_persist_failed',
                    'message' => 'Failed to persist Staff Client enrollment.',
                ];
            }
        }

        return $create();
    }

    /**
     * Staff-side disconnect: revoke credential and force-release lease without Electron cooperation.
     *
     * @return array{ok: bool, error?: string, message?: string, revoked_key_id?: int}
     */
    public function disconnect(int $staffUserId, ?string $installationId = null): array
    {
        $installationId = $installationId ?? $this->installationResolver->resolveInstallationId();
        $active = $this->repository->findActiveByCreator($installationId, $staffUserId);
        if (! $active instanceof SaClientApiKey) {
            return [
                'ok' => false,
                'error' => self::ERROR_NOT_CONNECTED,
                'message' => 'No active Client connection for this Staff.',
            ];
        }

        $keyId = (int) $active->id;
        $this->repository->revoke($keyId, $installationId);
        $this->forceReleaseLease($keyId);

        return [
            'ok' => true,
            'revoked_key_id' => $keyId,
            'message' => 'Client disconnected. Credential revoked; connection history preserved.',
        ];
    }

    /**
     * Owner inventory of successful connections (keys that have/had a client agent).
     * Unused bootstrap keys never appear (they are not stored).
     *
     * @return list<array<string, mixed>>
     */
    public function ownerSuccessfulConnections(?string $installationId = null): array
    {
        $installationId = $installationId ?? $this->installationResolver->resolveInstallationId();
        if (! $this->dbAvailable()) {
            return [];
        }

        $keys = SaClientApiKey::query()
            ->where('installation_id', $installationId)
            ->with('creator')
            ->orderByDesc('id')
            ->get();

        $rows = [];
        foreach ($keys as $key) {
            $agent = SaAgent::query()
                ->where('client_api_key_id', (int) $key->id)
                ->where('agent_kind', 'client')
                ->first();
            if (! $agent instanceof SaAgent) {
                // Never registered = not a successful connection (and unused bootstraps have no row).
                continue;
            }
            $rows[] = $this->serializeConnection($key, $agent, includeHistory: true);
        }

        return $rows;
    }

    public function parseBootstrap(string $rawKey): ?array
    {
        return $this->bootstrap->parse($rawKey);
    }

    /**
     * @param  SaAgent|null  $agent
     * @return array<string, mixed>
     */
    public function serializeConnection(SaClientApiKey $key, $agent = null, bool $includeHistory = false): array
    {
        $connected = $key->isActive() && $agent instanceof SaAgent;
        $row = [
            'client_api_key_id' => (int) $key->id,
            'key_prefix' => (string) $key->key_prefix,
            'key_name' => (string) $key->name,
            'staff_user_id' => $key->created_by ? (int) $key->created_by : null,
            'staff_name' => $this->safeStaffName($key),
            'machine_name' => $agent instanceof SaAgent ? ($agent->display_name ?: $agent->device_id) : null,
            'agent_uuid' => $agent instanceof SaAgent ? (string) $agent->agent_uuid : null,
            'connection_status' => $key->isActive()
                ? ($agent instanceof SaAgent ? 'connected' : 'key_active_unregistered')
                : 'disconnected',
            'is_active' => $key->isActive(),
            'connected_at' => $agent instanceof SaAgent
                ? ($this->isoOrRaw($agent, 'created_at') ?? $this->isoOrRaw($key, 'created_at'))
                : $this->isoOrRaw($key, 'created_at'),
            'disconnected_at' => $key->isActive() ? null : $this->isoOrRaw($key, 'revoked_at'),
            'last_heartbeat_at' => $agent instanceof SaAgent ? $this->isoOrRaw($agent, 'last_heartbeat_at') : null,
            'installation_id' => (string) $key->installation_id,
        ];

        if ($includeHistory) {
            $row['connected'] = $connected;
        }

        return $row;
    }

    private function findAgentForKey(int $clientApiKeyId): ?SaAgent
    {
        if (! $this->agents instanceof AgentRegistryService) {
            return null;
        }
        try {
            return $this->agents->findClientByApiKeyId($clientApiKeyId);
        } catch (\Throwable) {
            return null;
        }
    }

    private function forceReleaseLease(int $clientApiKeyId): void
    {
        if (! $this->agents instanceof AgentRegistryService) {
            return;
        }
        try {
            $this->agents->forceReleaseConnection($clientApiKeyId);
        } catch (\Throwable) {
        }
    }

    private function safeStaffName(SaClientApiKey $key): ?string
    {
        if (! $key->created_by) {
            return null;
        }
        try {
            $name = $key->creator?->name;
            if (is_string($name) && $name !== '') {
                return $name;
            }
        } catch (\Throwable) {
        }

        return 'User #'.$key->created_by;
    }

    private function isoOrRaw(object $model, string $attribute): ?string
    {
        try {
            $value = $model->{$attribute} ?? null;
            if ($value instanceof \DateTimeInterface) {
                return $value->format(\DateTimeInterface::ATOM);
            }
            if (is_string($value) && $value !== '') {
                return $value;
            }
        } catch (\Throwable) {
        }
        $raw = method_exists($model, 'getAttributes') ? ($model->getAttributes()[$attribute] ?? null) : null;

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    private function encryptRawKey(string $rawKey): string
    {
        if (function_exists('app') && app()->bound('encrypter')) {
            try {
                return Crypt::encryptString($rawKey);
            } catch (\Throwable) {
            }
        }

        return 'enc:'.base64_encode($rawKey);
    }

    private function dbAvailable(): bool
    {
        try {
            if (function_exists('app') && app()->bound('db')) {
                return true;
            }
        } catch (\Throwable) {
        }

        return false;
    }
}
