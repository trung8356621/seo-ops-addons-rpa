<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Omnichannel\Addons\SocialAutomation\Contracts\KeepAliveRouteStoreContract;
use Omnichannel\Addons\SocialAutomation\Contracts\KeepAliveStoreContract;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveAccessPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveProtocol;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveRegistrationPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\KeepAliveSocketTicket;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;

/**
 * Mint / verify Keep Alive Socket.IO route tickets.
 * Mint uses Client API Key. Gateway verify uses shared gateway secret + HMAC ticket — never Client API Keys.
 */
final class KeepAliveSocketTicketService
{
    public function __construct(
        private readonly KeepAliveStoreContract $nodes,
        private readonly KeepAliveRouteStoreContract $routes,
        private readonly KeepAliveSocketTicket $tickets = new KeepAliveSocketTicket,
        private readonly KeepAliveRegistrationPolicy $policy = new KeepAliveRegistrationPolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, http?: int, error?: string, errors?: list<string>, data?: array<string, mixed>}
     */
    public function mint(SaClientApiKey $clientKey, array $payload): array
    {
        if (! $this->nodes->isReady() || ! $this->routes->isReady()) {
            return ['ok' => false, 'http' => 503, 'error' => 'schema_unavailable', 'errors' => ['schema_unavailable']];
        }

        $tenant = $this->policy->resolveTenantId(
            (string) $clientKey->installation_id,
            $payload['tenant_id'] ?? null,
        );
        if (! ($tenant['ok'] ?? false)) {
            $errors = $tenant['errors'] ?? [KeepAliveRegistrationPolicy::ERROR_TENANT_MISMATCH];

            return ['ok' => false, 'http' => 403, 'error' => $errors[0], 'errors' => $errors];
        }
        $tenantId = (string) $tenant['tenant_id'];

        $role = strtolower(trim((string) ($payload['role'] ?? '')));
        if (! KeepAliveSocketTicket::isValidRole($role)) {
            return [
                'ok' => false,
                'http' => 422,
                'error' => KeepAliveSocketTicket::ERROR_ROLE_INVALID,
                'errors' => [KeepAliveSocketTicket::ERROR_ROLE_INVALID],
            ];
        }

        $campaignIds = $this->normalizeCampaignIds($payload['campaign_ids'] ?? $payload['campaign_id'] ?? null);
        $ttl = isset($payload['ttl_seconds']) ? (int) $payload['ttl_seconds'] : KeepAliveSocketTicket::DEFAULT_TTL_SECONDS;
        $ttl = max(1, min(KeepAliveSocketTicket::MAX_TTL_SECONDS, $ttl));

        if ($role === KeepAliveSocketTicket::ROLE_RECOVERY) {
            $built = $this->buildRecoveryClaims($clientKey, $tenantId, $payload, $campaignIds);
        } else {
            $built = $this->buildExecutorClaims($clientKey, $tenantId, $payload, $campaignIds);
        }

        if (! ($built['ok'] ?? false)) {
            return [
                'ok' => false,
                'http' => (int) ($built['http'] ?? 422),
                'error' => $built['error'] ?? ($built['errors'][0] ?? 'ticket_mint_failed'),
                'errors' => $built['errors'] ?? ['ticket_mint_failed'],
            ];
        }

        /** @var array{tenant_id: string, role: string, node_id: string|null, campaign_ids: list<string>, rooms: list<string>, assignment_generation: int, route_generation: int, client_api_key_id: int, agent_uuid?: string|null, subject_type: string, subject_key: string} $claims */
        $claims = $built['claims'];
        $issued = $this->tickets->issue($claims, $ttl);
        $remembered = $this->routes->rememberTicketJti(
            (string) $issued['jti'],
            $tenantId,
            (int) $clientKey->id,
            $role,
            (int) $issued['expires_at'],
        );
        if (! $remembered) {
            return [
                'ok' => false,
                'http' => 500,
                'error' => KeepAliveSocketTicket::ERROR_INVALID,
                'errors' => [KeepAliveSocketTicket::ERROR_INVALID],
            ];
        }

        return [
            'ok' => true,
            'http' => 200,
            'data' => [
                'protocol_version' => KeepAliveProtocol::PROTOCOL_VERSION,
                'schema_version' => KeepAliveProtocol::SCHEMA_VERSION,
                'ticket' => $issued['ticket'],
                'jti' => $issued['jti'],
                'expires_at' => $issued['expires_at_iso'],
                'expires_at_unix' => $issued['expires_at'],
                'ttl_seconds' => $ttl,
                'role' => $role,
                'tenant_id' => $tenantId,
                'node_id' => $claims['node_id'],
                'campaign_ids' => $claims['campaign_ids'],
                'rooms' => $claims['rooms'],
                'assignment_generation' => $claims['assignment_generation'],
                'route_generation' => $claims['route_generation'],
                'gateway_url' => $this->gatewayUrl(),
                'audience' => KeepAliveSocketTicket::AUDIENCE,
                // Renewal: re-mint before exp; never reuse ticket after verify consume.
                'renewal' => [
                    're_mint_path' => '/client/keepalive/socket/ticket',
                    'max_ttl_seconds' => KeepAliveSocketTicket::MAX_TTL_SECONDS,
                    'on_route_generation_stale' => 're_mint',
                    'on_reconnect' => 're_mint_then_auth_handshake',
                ],
            ],
        ];
    }

    /**
     * Gateway ticket verification. Auth: shared gateway secret header — not Client API Key.
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, http?: int, error?: string, errors?: list<string>, data?: array<string, mixed>}
     */
    public function verifyForGateway(string $gatewaySecret, array $payload, ?int $nowUnix = null): array
    {
        if (! $this->assertGatewaySecret($gatewaySecret)) {
            return [
                'ok' => false,
                'http' => 401,
                'error' => KeepAliveSocketTicket::ERROR_GATEWAY_UNAUTHORIZED,
                'errors' => [KeepAliveSocketTicket::ERROR_GATEWAY_UNAUTHORIZED],
            ];
        }

        if (! $this->routes->isReady()) {
            return ['ok' => false, 'http' => 503, 'error' => 'schema_unavailable', 'errors' => ['schema_unavailable']];
        }

        $ticket = trim((string) ($payload['ticket'] ?? ''));
        if ($ticket === '') {
            return [
                'ok' => false,
                'http' => 422,
                'error' => KeepAliveSocketTicket::ERROR_INVALID,
                'errors' => [KeepAliveSocketTicket::ERROR_INVALID],
            ];
        }

        $crypto = $this->tickets->verify($ticket, $nowUnix);
        if (! ($crypto['ok'] ?? false)) {
            $err = (string) ($crypto['error'] ?? KeepAliveSocketTicket::ERROR_INVALID);
            $http = $err === KeepAliveSocketTicket::ERROR_EXPIRED ? 401 : 401;

            return ['ok' => false, 'http' => $http, 'error' => $err, 'errors' => [$err]];
        }

        /** @var array<string, mixed> $claims */
        $claims = $crypto['claims'];
        $tenantId = (string) $claims['tid'];
        $role = (string) $claims['role'];
        $jti = (string) $claims['jti'];
        $consume = ! array_key_exists('consume', $payload) || (bool) $payload['consume'];

        $subject = $this->subjectFromClaims($claims);
        if ($this->routes->isRouteRevoked($tenantId, $subject['type'], $subject['key'])) {
            return [
                'ok' => false,
                'http' => 403,
                'error' => KeepAliveAccessPolicy::ERROR_ASSIGNMENT_REVOKED,
                'errors' => [KeepAliveAccessPolicy::ERROR_ASSIGNMENT_REVOKED],
            ];
        }

        $currentRgen = $this->routes->getRouteGeneration($tenantId, $subject['type'], $subject['key']);
        if ((int) ($claims['rgen'] ?? -1) !== $currentRgen) {
            return [
                'ok' => false,
                'http' => 409,
                'error' => KeepAliveSocketTicket::ERROR_ROUTE_GENERATION_STALE,
                'errors' => [KeepAliveSocketTicket::ERROR_ROUTE_GENERATION_STALE],
            ];
        }

        $authorized = $this->assertClaimsStillAuthorized($claims);
        if (! ($authorized['ok'] ?? false)) {
            return [
                'ok' => false,
                'http' => (int) ($authorized['http'] ?? 403),
                'error' => $authorized['error'] ?? ($authorized['errors'][0] ?? 'unauthorized'),
                'errors' => $authorized['errors'] ?? ['unauthorized'],
            ];
        }

        // Consume only after authz succeeds so stale tickets can be diagnosed without burning jti early.
        if ($consume && ! $this->routes->consumeTicketJti($jti, $nowUnix)) {
            return [
                'ok' => false,
                'http' => 401,
                'error' => KeepAliveSocketTicket::ERROR_REPLAY,
                'errors' => [KeepAliveSocketTicket::ERROR_REPLAY],
            ];
        }

        return [
            'ok' => true,
            'http' => 200,
            'data' => [
                'protocol_version' => KeepAliveProtocol::PROTOCOL_VERSION,
                'schema_version' => KeepAliveProtocol::SCHEMA_VERSION,
                'tenant_id' => $tenantId,
                'role' => $role,
                'node_id' => $claims['nid'] ?? null,
                'campaign_ids' => array_values((array) ($claims['cid'] ?? [])),
                'rooms' => array_values((array) ($claims['rooms'] ?? [])),
                'assignment_generation' => (int) ($claims['agen'] ?? 0),
                'route_generation' => (int) ($claims['rgen'] ?? 0),
                'jti' => $jti,
                'consumed' => $consume,
                // Gateway joins only these rooms — never trust client-supplied room names.
                'authorized_rooms' => array_values((array) ($claims['rooms'] ?? [])),
            ],
        ];
    }

    public function bumpNodeRoute(string $installationId, string $nodeId): int
    {
        return $this->routes->bumpRouteGeneration(
            $installationId,
            KeepAliveRouteStoreContract::SUBJECT_NODE,
            $nodeId,
        );
    }

    public function revokeNodeRoute(string $installationId, string $nodeId): void
    {
        $this->routes->revokeRoute(
            $installationId,
            KeepAliveRouteStoreContract::SUBJECT_NODE,
            $nodeId,
        );
    }

    public function bumpExecutorRoute(string $installationId, int $clientApiKeyId): int
    {
        return $this->routes->bumpRouteGeneration(
            $installationId,
            KeepAliveRouteStoreContract::SUBJECT_EXECUTOR,
            $this->executorSubjectKey($clientApiKeyId),
        );
    }

    public function assertGatewaySecret(string $presented): bool
    {
        $expected = $this->expectedGatewaySecret();
        if ($expected === '' || $presented === '') {
            return false;
        }

        return hash_equals($expected, $presented);
    }

    /**
     * Secret shared with gateway only (never Client API Key material).
     */
    public function expectedGatewaySecret(): string
    {
        try {
            if (function_exists('config')) {
                $configured = (string) config('social-automation.keepalive_gateway_secret', '');
                if ($configured !== '') {
                    return $configured;
                }
            }
        } catch (\Throwable) {
        }

        // Derive from ticket signing secret so local/tests work without extra env.
        return 'ka-gw-v1|'.$this->tickets->signingSecretForGateway();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $campaignIds
     * @return array{ok: bool, http?: int, error?: string, errors?: list<string>, claims?: array<string, mixed>}
     */
    private function buildRecoveryClaims(
        SaClientApiKey $clientKey,
        string $tenantId,
        array $payload,
        array $campaignIds,
    ): array {
        $nodeId = trim((string) ($payload['node_id'] ?? ''));
        if ($nodeId === '') {
            return [
                'ok' => false,
                'http' => 422,
                'error' => KeepAliveRegistrationPolicy::ERROR_NODE_ID_REQUIRED,
                'errors' => [KeepAliveRegistrationPolicy::ERROR_NODE_ID_REQUIRED],
            ];
        }

        $presentedGen = $payload['assignment_generation'] ?? null;
        if ($presentedGen === null || $presentedGen === '') {
            return [
                'ok' => false,
                'http' => 422,
                'error' => KeepAliveAccessPolicy::ERROR_ASSIGNMENT_GENERATION_REQUIRED,
                'errors' => [KeepAliveAccessPolicy::ERROR_ASSIGNMENT_GENERATION_REQUIRED],
            ];
        }
        $presentedGen = (int) $presentedGen;

        $node = $this->nodes->findNode($tenantId, $nodeId);
        if ($node === null) {
            return [
                'ok' => false,
                'http' => 404,
                'error' => KeepAliveRegistrationPolicy::ERROR_NODE_NOT_REGISTERED,
                'errors' => [KeepAliveRegistrationPolicy::ERROR_NODE_NOT_REGISTERED],
            ];
        }
        if ((int) ($node['client_api_key_id'] ?? 0) !== (int) $clientKey->id) {
            return [
                'ok' => false,
                'http' => 403,
                'error' => KeepAliveRegistrationPolicy::ERROR_AGENT_MISMATCH,
                'errors' => [KeepAliveRegistrationPolicy::ERROR_AGENT_MISMATCH],
            ];
        }
        if (($node['revoked_at'] ?? null) !== null && (string) $node['revoked_at'] !== '') {
            return [
                'ok' => false,
                'http' => 403,
                'error' => KeepAliveAccessPolicy::ERROR_NODE_REVOKED,
                'errors' => [KeepAliveAccessPolicy::ERROR_NODE_REVOKED],
            ];
        }
        if ((int) ($node['assignment_generation'] ?? -1) !== $presentedGen) {
            return [
                'ok' => false,
                'http' => 409,
                'error' => KeepAliveAccessPolicy::ERROR_ASSIGNMENT_GENERATION_STALE,
                'errors' => [KeepAliveAccessPolicy::ERROR_ASSIGNMENT_GENERATION_STALE],
            ];
        }

        $assigned = $this->nodes->listAssignmentsForNode($tenantId, $nodeId);
        $assignedMap = [];
        foreach ($assigned as $row) {
            $assignedMap[(string) $row['campaign_uuid']] = true;
        }
        if ($campaignIds === []) {
            $campaignIds = array_keys($assignedMap);
        }
        if ($campaignIds === []) {
            return [
                'ok' => false,
                'http' => 403,
                'error' => KeepAliveAccessPolicy::ERROR_ASSIGNMENT_REQUIRED,
                'errors' => [KeepAliveAccessPolicy::ERROR_ASSIGNMENT_REQUIRED],
            ];
        }
        foreach ($campaignIds as $cid) {
            if (! isset($assignedMap[$cid])) {
                return [
                    'ok' => false,
                    'http' => 403,
                    'error' => KeepAliveSocketTicket::ERROR_CAMPAIGN_UNAUTHORIZED,
                    'errors' => [KeepAliveSocketTicket::ERROR_CAMPAIGN_UNAUTHORIZED],
                ];
            }
        }

        if ($this->routes->isRouteRevoked($tenantId, KeepAliveRouteStoreContract::SUBJECT_NODE, $nodeId)) {
            return [
                'ok' => false,
                'http' => 403,
                'error' => KeepAliveAccessPolicy::ERROR_ASSIGNMENT_REVOKED,
                'errors' => [KeepAliveAccessPolicy::ERROR_ASSIGNMENT_REVOKED],
            ];
        }

        $rgen = $this->routes->getRouteGeneration($tenantId, KeepAliveRouteStoreContract::SUBJECT_NODE, $nodeId);
        $rooms = [];
        foreach ($campaignIds as $cid) {
            $rooms[] = KeepAliveSocketTicket::roomName($tenantId, $cid, $nodeId);
        }

        return [
            'ok' => true,
            'claims' => [
                'tenant_id' => $tenantId,
                'role' => KeepAliveSocketTicket::ROLE_RECOVERY,
                'node_id' => $nodeId,
                'campaign_ids' => array_values($campaignIds),
                'rooms' => $rooms,
                'assignment_generation' => $presentedGen,
                'route_generation' => $rgen,
                'client_api_key_id' => (int) $clientKey->id,
                'agent_uuid' => (string) ($node['agent_uuid'] ?? ''),
                'subject_type' => KeepAliveRouteStoreContract::SUBJECT_NODE,
                'subject_key' => $nodeId,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $campaignIds
     * @return array{ok: bool, http?: int, error?: string, errors?: list<string>, claims?: array<string, mixed>}
     */
    private function buildExecutorClaims(
        SaClientApiKey $clientKey,
        string $tenantId,
        array $payload,
        array $campaignIds,
    ): array {
        if ($campaignIds === []) {
            return [
                'ok' => false,
                'http' => 422,
                'error' => KeepAliveSocketTicket::ERROR_CAMPAIGNS_REQUIRED,
                'errors' => [KeepAliveSocketTicket::ERROR_CAMPAIGNS_REQUIRED],
            ];
        }

        $rows = $this->routes->listActiveAssignmentsForCampaigns($tenantId, $campaignIds);
        $byCampaign = [];
        foreach ($rows as $row) {
            $byCampaign[(string) $row['campaign_uuid']][] = $row;
        }
        foreach ($campaignIds as $cid) {
            if (! isset($byCampaign[$cid]) || $byCampaign[$cid] === []) {
                return [
                    'ok' => false,
                    'http' => 403,
                    'error' => KeepAliveSocketTicket::ERROR_CAMPAIGN_UNAUTHORIZED,
                    'errors' => [KeepAliveSocketTicket::ERROR_CAMPAIGN_UNAUTHORIZED],
                ];
            }
        }

        $subjectKey = $this->executorSubjectKey((int) $clientKey->id);
        if ($this->routes->isRouteRevoked($tenantId, KeepAliveRouteStoreContract::SUBJECT_EXECUTOR, $subjectKey)) {
            return [
                'ok' => false,
                'http' => 403,
                'error' => KeepAliveAccessPolicy::ERROR_ASSIGNMENT_REVOKED,
                'errors' => [KeepAliveAccessPolicy::ERROR_ASSIGNMENT_REVOKED],
            ];
        }

        $rooms = [];
        $maxAgen = 0;
        foreach ($campaignIds as $cid) {
            foreach ($byCampaign[$cid] as $row) {
                $rooms[] = KeepAliveSocketTicket::roomName($tenantId, $cid, (string) $row['node_id']);
                $maxAgen = max($maxAgen, (int) $row['assignment_generation']);
            }
        }
        $rooms = array_values(array_unique($rooms));
        $rgen = $this->routes->getRouteGeneration($tenantId, KeepAliveRouteStoreContract::SUBJECT_EXECUTOR, $subjectKey);
        $agentUuid = trim((string) ($payload['agent_uuid'] ?? ''));

        return [
            'ok' => true,
            'claims' => [
                'tenant_id' => $tenantId,
                'role' => KeepAliveSocketTicket::ROLE_EXECUTOR,
                'node_id' => null,
                'campaign_ids' => array_values($campaignIds),
                'rooms' => $rooms,
                'assignment_generation' => $maxAgen,
                'route_generation' => $rgen,
                'client_api_key_id' => (int) $clientKey->id,
                'agent_uuid' => $agentUuid !== '' ? $agentUuid : null,
                'subject_type' => KeepAliveRouteStoreContract::SUBJECT_EXECUTOR,
                'subject_key' => $subjectKey,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array{ok: bool, http?: int, error?: string, errors?: list<string>}
     */
    private function assertClaimsStillAuthorized(array $claims): array
    {
        $tenantId = (string) $claims['tid'];
        $role = (string) $claims['role'];
        $campaignIds = array_values(array_map('strval', (array) ($claims['cid'] ?? [])));
        $rooms = array_values(array_map('strval', (array) ($claims['rooms'] ?? [])));

        if ($role === KeepAliveSocketTicket::ROLE_RECOVERY) {
            $nodeId = trim((string) ($claims['nid'] ?? ''));
            $node = $this->nodes->findNode($tenantId, $nodeId);
            if ($node === null || (($node['revoked_at'] ?? null) !== null && (string) $node['revoked_at'] !== '')) {
                return [
                    'ok' => false,
                    'http' => 403,
                    'error' => KeepAliveAccessPolicy::ERROR_NODE_REVOKED,
                    'errors' => [KeepAliveAccessPolicy::ERROR_NODE_REVOKED],
                ];
            }
            if ((int) ($node['assignment_generation'] ?? -1) !== (int) ($claims['agen'] ?? -2)) {
                return [
                    'ok' => false,
                    'http' => 409,
                    'error' => KeepAliveAccessPolicy::ERROR_ASSIGNMENT_GENERATION_STALE,
                    'errors' => [KeepAliveAccessPolicy::ERROR_ASSIGNMENT_GENERATION_STALE],
                ];
            }
            $assigned = $this->nodes->listAssignmentsForNode($tenantId, $nodeId);
            $map = [];
            foreach ($assigned as $row) {
                $map[(string) $row['campaign_uuid']] = true;
            }
            foreach ($campaignIds as $cid) {
                if (! isset($map[$cid])) {
                    return [
                        'ok' => false,
                        'http' => 403,
                        'error' => KeepAliveSocketTicket::ERROR_CAMPAIGN_UNAUTHORIZED,
                        'errors' => [KeepAliveSocketTicket::ERROR_CAMPAIGN_UNAUTHORIZED],
                    ];
                }
            }

            return ['ok' => true];
        }

        // Executor: every room must still map to an active assignment (multi-node safe).
        $active = $this->routes->listActiveAssignmentsForCampaigns($tenantId, $campaignIds);
        $activeRooms = [];
        foreach ($active as $row) {
            $activeRooms[KeepAliveSocketTicket::roomName(
                $tenantId,
                (string) $row['campaign_uuid'],
                (string) $row['node_id'],
            )] = true;
        }
        foreach ($rooms as $room) {
            if (! isset($activeRooms[$room])) {
                return [
                    'ok' => false,
                    'http' => 403,
                    'error' => KeepAliveSocketTicket::ERROR_CAMPAIGN_UNAUTHORIZED,
                    'errors' => [KeepAliveSocketTicket::ERROR_CAMPAIGN_UNAUTHORIZED],
                ];
            }
        }

        return ['ok' => true];
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array{type: string, key: string}
     */
    private function subjectFromClaims(array $claims): array
    {
        $role = (string) ($claims['role'] ?? '');
        if ($role === KeepAliveSocketTicket::ROLE_RECOVERY) {
            return [
                'type' => KeepAliveRouteStoreContract::SUBJECT_NODE,
                'key' => (string) ($claims['nid'] ?? ''),
            ];
        }

        return [
            'type' => KeepAliveRouteStoreContract::SUBJECT_EXECUTOR,
            'key' => $this->executorSubjectKey((int) ($claims['kid'] ?? 0)),
        ];
    }

    private function executorSubjectKey(int $clientApiKeyId): string
    {
        return 'key:'.$clientApiKeyId;
    }

    /**
     * @return list<string>
     */
    private function normalizeCampaignIds(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (! is_array($raw)) {
            $raw = [$raw];
        }
        $out = [];
        foreach ($raw as $item) {
            $id = trim((string) $item);
            if ($id !== '') {
                $out[$id] = $id;
            }
        }

        return array_values($out);
    }

    private function gatewayUrl(): string
    {
        try {
            if (function_exists('config')) {
                $url = trim((string) config('social-automation.keepalive_gateway_url', ''));
                if ($url !== '') {
                    return $url;
                }
                $appUrl = rtrim((string) config('app.url', ''), '/');
                if ($appUrl !== '') {
                    return $appUrl.'/keepalive/socket.io/';
                }
            }
        } catch (\Throwable) {
        }

        return '/keepalive/socket.io/';
    }
}
