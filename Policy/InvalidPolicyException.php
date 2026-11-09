<?php

namespace Omnisense\Contracts\Policy;

use Omnisense\Contracts\Exception\InvalidConfigException;

/**
 * A policy that cannot be read (a field of the wrong type, a value outside
 * the schema), or a site's configuration that would loosen the provider's.
 */
final class InvalidPolicyException extends InvalidConfigException
{
    public static function field(string $path, string $expected, mixed $given): self
    {
        return new self(\sprintf('The usage policy\'s "%s" must be %s, %s given.', $path, $expected, get_debug_type($given)));
    }

    public static function loosened(string $provider, string $field, string $declared, string $given): self
    {
        return new self(\sprintf('A site may only tighten %s\'s usage policy: its %s is %s, the configuration asks %s.', $provider, $field, $declared, $given));
    }
}
