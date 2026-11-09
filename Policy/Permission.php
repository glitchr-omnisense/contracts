<?php

namespace Omnisense\Contracts\Policy;

/**
 * Whether the provider's terms allow a use: commercial use, storing its
 * data, redistributing it, caching its answers. Unknown is what nobody has
 * read yet; it ranks above allowed, so a site can never turn it into
 * "allowed" by tightening (only a declaration can).
 */
enum Permission: string
{
    case Allowed = 'allowed';
    case Unknown = 'unknown';
    case Contract = 'contract';
    case Forbidden = 'forbidden';

    /** The higher, the stricter: allowed 0, unknown 1, by contract 2, forbidden 3. */
    public function strictness(): int
    {
        return match ($this) {
            self::Allowed => 0,
            self::Unknown => 1,
            self::Contract => 2,
            self::Forbidden => 3,
        };
    }

    public function isStricterThan(self $other): bool
    {
        return $this->strictness() > $other->strictness();
    }
}
