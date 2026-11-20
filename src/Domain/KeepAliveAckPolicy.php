<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Contiguous durable-event ACK rules for Keep Alive Recovery Nodes.
 * These ACKs are delivery/persistence confirmations — not Job execution ACKs.
 */
final class KeepAliveAckPolicy
{
    public const ERROR_EVENT_IDS_REQUIRED = 'event_ids_required';

    public const ERROR_EVENT_IDS_INVALID = 'event_ids_invalid';

    public const ERROR_EVENT_CAMPAIGN_MISMATCH = 'event_campaign_mismatch';

    public const ERROR_ACK_NON_CONTIGUOUS = 'ack_non_contiguous';

    public const ERROR_ACK_BEYOND_HEAD = 'ack_beyond_head';

    public const ERROR_ACK_SEQUENCE_MISMATCH = 'ack_sequence_mismatch';

    public const ERROR_LAST_CONTIGUOUS_REQUIRED = 'last_contiguous_sequence_required';

    public const ERROR_ARTIFACT_NOT_READY = 'artifact_not_ready';

    public const ERROR_ARTIFACT_UNSUPPORTED = 'artifact_unsupported';

    public const ERROR_ARTIFACT_CHECKSUM_MISMATCH = 'artifact_checksum_mismatch';

    public const ERROR_ARTIFACT_STORAGE_KEY_MISMATCH = 'artifact_storage_key_mismatch';

    public const ERROR_ARTIFACT_JOB_MISMATCH = 'artifact_job_mismatch';

    public const ERROR_JOB_ID_REQUIRED = 'job_id_required';

    public const ERROR_STORAGE_KEY_REQUIRED = 'storage_key_required';

    public const ERROR_SHA256_REQUIRED = 'sha256_required';

    /**
     * Validate contiguous ACK from current cursor to last_contiguous_sequence.
     *
     * @param  list<array{event_id: string, event_sequence: int, campaign_id: string}>  $resolvedEvents
     * @param  list<string>  $requestedEventIds
     * @return array{ok: bool, errors?: list<string>, acked_through?: int, acked_count?: int, idempotent?: bool}
     */
    public function validateContiguousEventAck(
        int $currentCursor,
        int $lastContiguousSequence,
        int $eventHead,
        array $resolvedEvents,
        array $requestedEventIds,
        string $campaignUuid,
    ): array {
        if ($lastContiguousSequence < 0) {
            return ['ok' => false, 'errors' => [self::ERROR_LAST_CONTIGUOUS_REQUIRED]];
        }

        // Idempotent: already acked through this point (or further).
        if ($lastContiguousSequence <= $currentCursor) {
            if ($requestedEventIds !== [] && $resolvedEvents === []) {
                return ['ok' => false, 'errors' => [self::ERROR_EVENT_IDS_INVALID]];
            }

            return [
                'ok' => true,
                'acked_through' => $currentCursor,
                'acked_count' => 0,
                'idempotent' => true,
            ];
        }

        if ($lastContiguousSequence > $eventHead) {
            return ['ok' => false, 'errors' => [self::ERROR_ACK_BEYOND_HEAD]];
        }

        if ($requestedEventIds === []) {
            return ['ok' => false, 'errors' => [self::ERROR_EVENT_IDS_REQUIRED]];
        }

        if (count($resolvedEvents) !== count(array_unique($requestedEventIds))) {
            return ['ok' => false, 'errors' => [self::ERROR_EVENT_IDS_INVALID]];
        }

        $bySeq = [];
        foreach ($resolvedEvents as $ev) {
            if ((string) ($ev['campaign_id'] ?? '') !== $campaignUuid) {
                return ['ok' => false, 'errors' => [self::ERROR_EVENT_CAMPAIGN_MISMATCH]];
            }
            $seq = (int) $ev['event_sequence'];
            if (isset($bySeq[$seq])) {
                return ['ok' => false, 'errors' => [self::ERROR_ACK_NON_CONTIGUOUS]];
            }
            $bySeq[$seq] = $ev;
        }

        $expectedCount = $lastContiguousSequence - $currentCursor;
        if (count($bySeq) !== $expectedCount) {
            return ['ok' => false, 'errors' => [self::ERROR_ACK_NON_CONTIGUOUS]];
        }

        for ($seq = $currentCursor + 1; $seq <= $lastContiguousSequence; $seq++) {
            if (! isset($bySeq[$seq])) {
                return ['ok' => false, 'errors' => [self::ERROR_ACK_NON_CONTIGUOUS]];
            }
        }

        $maxSeq = max(array_keys($bySeq));
        if ($maxSeq !== $lastContiguousSequence) {
            return ['ok' => false, 'errors' => [self::ERROR_ACK_SEQUENCE_MISMATCH]];
        }

        return [
            'ok' => true,
            'acked_through' => $lastContiguousSequence,
            'acked_count' => $expectedCount,
            'idempotent' => false,
        ];
    }

    /**
     * Verify client storage_key against authoritative server refs (path or artifact UUID).
     * Basename-only matches are rejected.
     */
    public function storageKeyMatches(string $presentedKey, string $storagePath, string $artifactUuid): bool
    {
        $key = trim($presentedKey);
        if ($key === '') {
            return false;
        }
        if ($artifactUuid !== '' && hash_equals($artifactUuid, $key)) {
            return true;
        }
        if ($storagePath !== '' && hash_equals($storagePath, $key)) {
            return true;
        }

        // Reject bare filename equality even if basename matches path.
        return false;
    }

    public function checksumMatches(string $presentedSha, ?string $serverSha): bool
    {
        $presented = strtolower(trim($presentedSha));
        $server = strtolower(trim((string) $serverSha));
        if ($presented === '' || $server === '' || ! preg_match('/^[a-f0-9]{64}$/', $presented)) {
            return false;
        }

        return hash_equals($server, $presented);
    }
}
