<?php

namespace Omnisense\Contracts\Policy;

/** The window a rate limit counts over. A month is thirty days, counted from the window's first call as the others are. */
enum Window: string
{
    case Second = 'second';
    case Minute = 'minute';
    case Hour = 'hour';
    case Day = 'day';
    case Month = 'month';

    public function seconds(): int
    {
        return match ($this) {
            self::Second => 1,
            self::Minute => 60,
            self::Hour => 3600,
            self::Day => 86400,
            self::Month => 2592000,
        };
    }
}
