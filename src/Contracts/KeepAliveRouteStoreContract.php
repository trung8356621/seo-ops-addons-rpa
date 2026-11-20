<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Contracts;

/**
 * Route generation + socket ticket jti persistence for Keep Alive Socket tickets.
 */
interface KeepAliveRouteStoreContract
{
    public const SUBJECT_NODE = 'node';

    public const SUBJECT_EXECUTOR = 'executor';

    public function isReady(): bool;

    public function getRouteGeneration(string $installationId, string $subjectType, string $subjectKey): int;

    public function bumpRouteGeneration(string $installationId, string $subjectType, string $subjectKey): int;

    public function revokeRoute(string $installationId, string $subjectType, string $subjectKey): void;

    public function isRouteRevoked(string $installationId, string $subjectType, string $subjectKey): bool;

    /**
     * Active assignments across nodes for the given campaign UUIDs (multi-node safe).
     *
     * @param  list<string>  $campaignUuids
     * @return list<array{node_id: string, campaign_uuid: string, assignment_generation: int, revoked: bool}>
     */
    public function listActiveAssignmentsForCampaigns(string $installationId, array $campaignUuids): array;

    /**
     * Record issued jti for replay detection. Returns false if jti already exists.
     */
    public function rememberTicketJti(
        string $jti,
        string $installationId,
        ?int $clientApiKeyId,
        string $role,
        int $expiresAtUnix,
    ): bool;

    /**
     * Mark jti consumed (single-use verify). Returns false if missing/expired/already consumed.
     */
    public function consumeTicketJti(string $jti, ?int $nowUnix = null): bool;

    public function jobClaimInvocations(): int;
}
