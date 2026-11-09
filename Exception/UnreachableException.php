<?php

namespace Omnisense\Contracts\Exception;

/** The provider could not be reached: a network error, a timeout. */
class UnreachableException extends \RuntimeException implements ExceptionInterface
{
}
