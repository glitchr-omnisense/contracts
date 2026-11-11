<?php

namespace Omnisense\Contracts\Policy;

use Omnisense\Contracts\Exception\ExceptionInterface;

/**
 * A call checked against a limit counted per resource (a parcel, an
 * envelope) without the resource it is about: PolicyGuard::call()'s
 * $context['resource'] is missing. A mistake in the code that calls the
 * guard, said before anything is sent: counting every resource in one
 * bucket would refuse calls the terms allow, not counting at all would let
 * through calls they forbid.
 */
final class ResourceNotNamed extends \LogicException implements ExceptionInterface
{
    public function __construct(
        public readonly string $provider,
        public readonly RateLimit $limit,
        public readonly string $operation,
    ) {
        parent::__construct(\sprintf('%s\'s usage policy allows %s: the "%s" call names no %s (the guard\'s context "resource").', $provider, $limit->describe(), $operation, $limit->per ?? 'resource'));
    }
}
