<?php

namespace Omnisense\Contracts\Exception;

/**
 * A provider asked for what it does not do.
 *
 * @phpstan-consistent-constructor
 */
class NotSupportedException extends \LogicException implements ExceptionInterface
{
    public static function capability(object $provider, string $name, string $capability): static
    {
        return new static(\sprintf('The "%s" provider (%s) does not %s.', $name, $provider::class, $capability));
    }
}
