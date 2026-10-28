<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Normalizes client-reported OS metadata for sa_agents.
 * Additive only — empty payload does not clear existing values.
 */
final class DeviceOsMetadata
{
    private const FAMILIES = ['windows', 'linux', 'macos', 'other'];

    /**
     * @param  array<string, mixed>  $payload
     * @return array{os_family: ?string, os_label: ?string}
     */
    public static function fromPayload(array $payload): array
    {
        $familyRaw = strtolower(trim((string) ($payload['os_family'] ?? '')));
        $family = in_array($familyRaw, self::FAMILIES, true) ? $familyRaw : null;

        $label = trim((string) ($payload['os_label'] ?? ''));
        if ($label === '') {
            $label = null;
        } else {
            $label = mb_substr($label, 0, 64);
        }

        // Legacy clients may only send platform / os_version.
        if ($family === null) {
            $platform = strtolower(trim((string) ($payload['platform'] ?? '')));
            $family = match ($platform) {
                'windows', 'win32' => 'windows',
                'linux' => 'linux',
                'darwin', 'macos' => 'macos',
                default => null,
            };
        }

        if ($label === null && $family !== null) {
            $label = match ($family) {
                'windows' => 'Windows',
                'linux' => 'Linux',
                'macos' => 'macOS',
                default => null,
            };
        }

        return [
            'os_family' => $family,
            'os_label' => $label,
        ];
    }

    /**
     * Merge into fill attributes without wiping prior values when client omits OS fields.
     *
     * @param  array<string, mixed>  $fill
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function applyToFill(array $fill, array $payload, ?string $existingFamily = null, ?string $existingLabel = null): array
    {
        $parsed = self::fromPayload($payload);
        if ($parsed['os_family'] !== null) {
            $fill['os_family'] = $parsed['os_family'];
        } elseif ($existingFamily !== null) {
            $fill['os_family'] = $existingFamily;
        }
        if ($parsed['os_label'] !== null) {
            $fill['os_label'] = $parsed['os_label'];
        } elseif ($existingLabel !== null) {
            $fill['os_label'] = $existingLabel;
        }

        return $fill;
    }
}
