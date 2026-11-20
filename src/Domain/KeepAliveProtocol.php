<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Keep Alive Node protocol constants (keepalive.v1 / schema 2).
 */
final class KeepAliveProtocol
{
    public const PROTOCOL_VERSION = 'keepalive.v1';

    public const SCHEMA_VERSION = 2;

    /** @var list<string> */
    public const REQUIRED_CAPABILITIES = ['observe', 'store', 'recover'];

    public static function isSupportedProtocol(string $version): bool
    {
        return $version === self::PROTOCOL_VERSION;
    }

    public static function isSupportedSchema(int $version): bool
    {
        return $version === self::SCHEMA_VERSION;
    }

    /**
     * @param  mixed  $capabilities
     * @return list<string>
     */
    public static function normalizeCapabilities(mixed $capabilities): array
    {
        if (! is_array($capabilities)) {
            return [];
        }
        $out = [];
        foreach ($capabilities as $cap) {
            if (! is_string($cap) && ! is_int($cap)) {
                continue;
            }
            $value = strtolower(trim((string) $cap));
            if ($value === '' || in_array($value, $out, true)) {
                continue;
            }
            $out[] = $value;
        }
        sort($out);

        return $out;
    }

    /**
     * @param  list<string>  $capabilities
     */
    public static function hasRequiredCapabilities(array $capabilities): bool
    {
        foreach (self::REQUIRED_CAPABILITIES as $required) {
            if (! in_array($required, $capabilities, true)) {
                return false;
            }
        }

        return true;
    }
}
