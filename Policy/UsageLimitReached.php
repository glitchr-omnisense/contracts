<?php

namespace Omnisense\Contracts\Policy;

use Omnisense\Contracts\Exception\ExceptionInterface;

/**
 * A call refused before it was made: a rate limit of the provider's policy
 * is reached, or the provider asked to wait (a 429, a Retry-After, a
 * RateLimit field with nothing left). $retryAfter says in how many seconds
 * to try again, when it is known. A family extends it so that its own
 * exception interface catches it too (Omnitravel\Exception\UsageLimitReachedException).
 *
 * @phpstan-consistent-constructor
 */
class UsageLimitReached extends \RuntimeException implements ExceptionInterface
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfter = null,
        public readonly ?string $provider = null,
        public readonly ?RateLimit $limit = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 429, $previous);
    }
}
