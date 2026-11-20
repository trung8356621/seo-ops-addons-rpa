<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Tiny Keep Alive Socket.IO envelopes (advisory data plane only).
 */
final class KeepAliveSocketEnvelope
{
    public const VERSION = 2;

    public const TYPE_NOTIFY = 'ka.notify';

    public const TYPE_ASSIGNMENT_CHANGED = 'ka.assignment_changed';

    public const TYPE_ROUTE_INVALIDATED = 'ka.route_invalidated';

    public const TYPE_RECOVER_HINT = 'ka.recover_hint';

    public const MAX_PAYLOAD_BYTES = 2048;

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_NOTIFY,
        self::TYPE_ASSIGNMENT_CHANGED,
        self::TYPE_ROUTE_INVALIDATED,
        self::TYPE_RECOVER_HINT,
    ];

    public static function isValidType(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public static function build(string $type, array $fields): array
    {
        if (! self::isValidType($type)) {
            throw new \InvalidArgumentException('envelope_type_invalid');
        }

        return array_merge([
            'v' => self::VERSION,
            't' => $type,
            'ts' => gmdate('c'),
        ], $fields);
    }
}
