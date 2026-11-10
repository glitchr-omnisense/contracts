<?php

namespace Omnisense\Contracts\Policy;

/**
 * How a provider's legacy reset field counts, once it is read
 * (Backoff::withLegacyHeaders()): X-RateLimit-Reset and RateLimit-Reset are
 * seconds left with some APIs, a Unix time with others (GitHub, Twitch).
 * Nothing guesses which: the provider's code says it.
 */
enum LegacyReset: string
{
    /** The seconds until the window resets ("X-RateLimit-Reset: 42"). */
    case Seconds = 'seconds';

    /** The Unix time it resets at, in seconds, decimals allowed ("X-RateLimit-Reset: 1762790400"). */
    case Epoch = 'epoch';
}
