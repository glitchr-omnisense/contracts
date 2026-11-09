<?php

namespace Omnisense\Contracts\Policy;

/**
 * Where a guard counts calls and keeps the waits a provider asked for. The
 * in-memory one counts within a PHP process; an application that serves
 * many requests gives one over a shared store (omnibridge/symfony's, over
 * symfony/rate-limiter and a cache pool).
 *
 * Windows are fixed and start at their first call, as symfony/rate-limiter's
 * fixed_window: a limit of 720 a day lets 720 calls through in the 24 hours
 * after the first one.
 */
interface RateLimiterInterface
{
    /** The calls left in the current window of $key under $limit, and the seconds until it resets. */
    public function allowance(string $key, RateLimit $limit): Allowance;

    /** One call counted. */
    public function hit(string $key, RateLimit $limit): void;

    /** No call for $seconds (a provider's Retry-After); 0 lifts it. */
    public function block(string $key, int $seconds): void;

    /** The seconds left of a block; 0 when there is none. */
    public function blocked(string $key): int;
}
