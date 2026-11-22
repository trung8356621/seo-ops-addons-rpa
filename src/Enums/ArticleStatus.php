<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Enums;

enum ArticleStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Archived = 'archived';

    public static function tryFromInput(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }

    public function isAssignable(): bool
    {
        return $this !== self::Archived;
    }
}
