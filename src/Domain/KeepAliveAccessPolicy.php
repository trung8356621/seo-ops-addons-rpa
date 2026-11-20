<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Authorization for Keep Alive snapshot/event reads.
 */
final class KeepAliveAccessPolicy
{
    public const ERROR_NODE_ID_REQUIRED = 'node_id_required';

    public const ERROR_ASSIGNMENT_GENERATION_REQUIRED = 'assignment_generation_required';

    public const ERROR_ASSIGNMENT_GENERATION_STALE = 'assignment_generation_stale';

    public const ERROR_ASSIGNMENT_REQUIRED = 'assignment_required';

    public const ERROR_ASSIGNMENT_REVOKED = 'assignment_revoked';

    public const ERROR_CAMPAIGN_NOT_FOUND = 'campaign_not_found';

    public const ERROR_CROSS_TENANT = 'cross_tenant_forbidden';

    public const ERROR_NODE_REVOKED = 'node_revoked';

    /**
     * @param  array<string, mixed>|null  $node
     * @param  array<string, mixed>|null  $assignment
     * @param  array<string, mixed>|null  $campaign
     * @return array{ok: bool, errors?: list<string>, http?: int}
     */
    public function assertSnapshotAccess(
        ?array $node,
        ?array $assignment,
        ?array $campaign,
        int $clientApiKeyId,
        string $tenantId,
        ?int $presentedGeneration,
    ): array {
        if ($node === null) {
            return ['ok' => false, 'http' => 404, 'errors' => [KeepAliveRegistrationPolicy::ERROR_NODE_NOT_REGISTERED]];
        }
        if ((int) ($node['client_api_key_id'] ?? 0) !== $clientApiKeyId) {
            return ['ok' => false, 'http' => 403, 'errors' => [self::ERROR_NODE_REVOKED]];
        }
        if (($node['revoked_at'] ?? null) !== null && (string) $node['revoked_at'] !== '') {
            return ['ok' => false, 'http' => 403, 'errors' => [self::ERROR_NODE_REVOKED]];
        }
        if ((string) ($node['installation_id'] ?? '') !== $tenantId) {
            return ['ok' => false, 'http' => 403, 'errors' => [self::ERROR_CROSS_TENANT]];
        }
        if ($presentedGeneration === null) {
            return ['ok' => false, 'http' => 422, 'errors' => [self::ERROR_ASSIGNMENT_GENERATION_REQUIRED]];
        }
        if ((int) ($node['assignment_generation'] ?? -1) !== $presentedGeneration) {
            return ['ok' => false, 'http' => 409, 'errors' => [self::ERROR_ASSIGNMENT_GENERATION_STALE]];
        }
        if ($assignment === null) {
            return ['ok' => false, 'http' => 403, 'errors' => [self::ERROR_ASSIGNMENT_REQUIRED]];
        }
        if (($assignment['revoked_at'] ?? null) !== null && (string) $assignment['revoked_at'] !== '') {
            return ['ok' => false, 'http' => 403, 'errors' => [self::ERROR_ASSIGNMENT_REVOKED]];
        }
        if ($campaign === null) {
            return ['ok' => false, 'http' => 404, 'errors' => [self::ERROR_CAMPAIGN_NOT_FOUND]];
        }
        if ((string) ($campaign['installation_id'] ?? '') !== $tenantId) {
            return ['ok' => false, 'http' => 403, 'errors' => [self::ERROR_CROSS_TENANT]];
        }

        return ['ok' => true];
    }
}
