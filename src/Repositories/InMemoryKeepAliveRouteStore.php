<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Repositories;

use Omnichannel\Addons\SocialAutomation\Contracts\KeepAliveRouteStoreContract;

final class InMemoryKeepAliveRouteStore implements KeepAliveRouteStoreContract
{
    private InMemoryKeepAliveStore $nodes;

    private InMemoryKeepAliveFeedStore $feed;

    /** @var array<string, array{route_generation: int, revoked_at: string|null}> */
    private array $routes = [];

    /** @var array<string, array{installation_id: string, client_api_key_id: int|null, role: string, expires_at: int, consumed_at: int|null}> */
    private array $jtis = [];

    private bool $ready = true;

    public function __construct(
        ?InMemoryKeepAliveStore $nodes = null,
        ?InMemoryKeepAliveFeedStore $feed = null,
    ) {
        $this->nodes = $nodes ?? new InMemoryKeepAliveStore;
        $this->feed = $feed ?? new InMemoryKeepAliveFeedStore($this->nodes);
    }

    public function nodes(): InMemoryKeepAliveStore
    {
        return $this->nodes;
    }

    public function feed(): InMemoryKeepAliveFeedStore
    {
        return $this->feed;
    }

    public function setReady(bool $ready): void
    {
        $this->ready = $ready;
    }

    public function isReady(): bool
    {
        return $this->ready && $this->nodes->isReady();
    }

    public function getRouteGeneration(string $installationId, string $subjectType, string $subjectKey): int
    {
        $row = $this->routes[$this->key($installationId, $subjectType, $subjectKey)] ?? null;

        return $row ? (int) $row['route_generation'] : 0;
    }

    public function bumpRouteGeneration(string $installationId, string $subjectType, string $subjectKey): int
    {
        $k = $this->key($installationId, $subjectType, $subjectKey);
        $current = $this->routes[$k]['route_generation'] ?? 0;
        $next = (int) $current + 1;
        $this->routes[$k] = [
            'route_generation' => $next,
            'revoked_at' => $this->routes[$k]['revoked_at'] ?? null,
        ];

        return $next;
    }

    public function revokeRoute(string $installationId, string $subjectType, string $subjectKey): void
    {
        $k = $this->key($installationId, $subjectType, $subjectKey);
        $gen = ($this->routes[$k]['route_generation'] ?? 0) + 1;
        $this->routes[$k] = [
            'route_generation' => $gen,
            'revoked_at' => gmdate('c'),
        ];
    }

    public function isRouteRevoked(string $installationId, string $subjectType, string $subjectKey): bool
    {
        $row = $this->routes[$this->key($installationId, $subjectType, $subjectKey)] ?? null;

        return $row !== null && ($row['revoked_at'] ?? null) !== null && (string) $row['revoked_at'] !== '';
    }

    public function listActiveAssignmentsForCampaigns(string $installationId, array $campaignUuids): array
    {
        $wanted = array_fill_keys($campaignUuids, true);
        $out = [];
        foreach ($this->nodes->rawAssignments() as $row) {
            if ((string) $row['installation_id'] !== $installationId) {
                continue;
            }
            if (($row['revoked_at'] ?? null) !== null && (string) $row['revoked_at'] !== '') {
                continue;
            }
            $cu = (string) $row['campaign_uuid'];
            if (! isset($wanted[$cu])) {
                continue;
            }
            $node = $this->nodes->findNode($installationId, (string) $row['node_id']);
            if ($node === null || (($node['revoked_at'] ?? null) !== null && (string) $node['revoked_at'] !== '')) {
                continue;
            }
            $out[] = [
                'node_id' => (string) $row['node_id'],
                'campaign_uuid' => $cu,
                'assignment_generation' => (int) ($node['assignment_generation'] ?? 0),
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
        if (isset($this->jtis[$jti])) {
            return false;
        }
        $this->jtis[$jti] = [
            'installation_id' => $installationId,
            'client_api_key_id' => $clientApiKeyId,
            'role' => $role,
            'expires_at' => $expiresAtUnix,
            'consumed_at' => null,
        ];

        return true;
    }

    public function consumeTicketJti(string $jti, ?int $nowUnix = null): bool
    {
        $now = $nowUnix ?? time();
        $row = $this->jtis[$jti] ?? null;
        if ($row === null) {
            return false;
        }
        if ((int) $row['expires_at'] < $now) {
            return false;
        }
        if ($row['consumed_at'] !== null) {
            return false;
        }
        $this->jtis[$jti]['consumed_at'] = $now;

        return true;
    }

    public function jobClaimInvocations(): int
    {
        return $this->nodes->jobClaimInvocations();
    }

    private function key(string $installationId, string $subjectType, string $subjectKey): string
    {
        return $installationId.'|'.$subjectType.'|'.$subjectKey;
    }
}
