<?php

namespace Omnisense\Contracts\Exception;

/** A provider asked for with options it cannot work with, or not configured at all. */
class InvalidConfigException extends \InvalidArgumentException implements ExceptionInterface
{
}
