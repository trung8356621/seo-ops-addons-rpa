<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Session Signal is a two-way boolean (present) with last-writer-wins by checked_at.
 * Independent of session_state / task verification / notification ACK.
 */
final class SessionSignalState
{
    /**
     * @param  array<string, mixed>|null  $raw
     * @return array{present: bool, status: string, checked_at: ?string, method: ?string}
     */
    public static function normalize(?array $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        if (array_key_exists('present', $raw) && $raw['present'] !== null) {
            $present = (bool) $raw['present'];
        } else {
            $present = strtolower(trim((string) ($raw['status'] ?? ''))) === 'present';
        }

        $method = $raw['method'] ?? null;
        if ($method !== null) {
            $method = is_string($method) ? $method : null;
        }

        $checkedAt = $raw['checked_at'] ?? null;
        if ($checkedAt !== null) {
            $checkedAt = is_string($checkedAt) && trim($checkedAt) !== '' ? trim($checkedAt) : null;
        }

        return [
            'present' => $present,
            'status' => $present ? 'present' : 'absent',
            'checked_at' => $checkedAt,
            'method' => $method,
        ];
    }

    /**
     * Last writer wins by checked_at. Equal timestamps prefer the incoming write.
     * Missing timestamps are treated as older than any explicit timestamp.
     *
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>|null  $incoming
     * @return array{present: bool, status: string, checked_at: ?string, method: ?string}
     */
    public static function mergeLastWriteWins(?array $current, ?array $incoming): array
    {
        $currentNorm = self::normalize($current);
        $incomingNorm = self::normalize($incoming);

        $currentTs = self::timestamp($currentNorm['checked_at']);
        $incomingTs = self::timestamp($incomingNorm['checked_at']);

        if ($incomingTs === null && $currentTs === null) {
            return $incomingNorm;
        }
        if ($incomingTs === null) {
            return $currentNorm;
        }
        if ($currentTs === null) {
            return $incomingNorm;
        }
        if ($incomingTs >= $currentTs) {
            return $incomingNorm;
        }

        return $currentNorm;
    }

    /**
     * @param  array<string, mixed>|null  $raw
     */
    public static function isPresent(?array $raw): bool
    {
        return self::normalize($raw)['present'];
    }

    private static function timestamp(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = strtotime($value);

        return $ts === false ? null : $ts;
    }
}
