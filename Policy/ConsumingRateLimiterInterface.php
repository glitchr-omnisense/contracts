<?php

namespace Omnisense\Contracts\Policy;

/**
 * A limiter that counts several units at once: a call costing 3 quota
 * units, a translation of 1,200 characters. PolicyGuard uses consume() when
 * the limiter has it, and hit() as many times otherwise (a limiter written
 * before costs existed still counts right, one unit at a time).
 */
interface ConsumingRateLimiterInterface extends RateLimiterInterface
{
    /** $amount units counted at once (0: nothing). */
    public function consume(string $key, RateLimit $limit, int $amount): void;
}
