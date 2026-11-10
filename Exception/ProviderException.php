<?php

namespace Omnisense\Contracts\Exception;

/**
 * The provider answered, and not with what was asked: an error status, a
 * body that is not JSON. The HTTP status is its code (status()); 0 for a
 * body that could not be read.
 *
 * @phpstan-consistent-constructor
 */
class ProviderException extends \RuntimeException implements ExceptionInterface
{
    public static function answered(string $provider, string $what, int|string $status, string $detail = ''): static
    {
        return new static(rtrim(\sprintf('%s answered %s for %s. %s', $provider, (string) $status, $what, mb_substr(trim(strip_tags($detail)), 0, 300))), \is_int($status) && 200 !== $status ? $status : 0);
    }

    /** The HTTP status the provider answered; 0 when it answered a body that could not be read. */
    public function status(): int
    {
        return $this->getCode();
    }
}
