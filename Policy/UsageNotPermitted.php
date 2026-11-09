<?php

namespace Omnisense\Contracts\Policy;

use Omnisense\Contracts\Exception\InvalidConfigException;

/** The site's use is one the provider's terms do not allow: a commercial site on a non-commercial API, closed code where open source is required. */
final class UsageNotPermitted extends InvalidConfigException
{
    /** @param list<string> $violations */
    public static function of(string $provider, array $violations): self
    {
        return new self(\sprintf('%s\'s usage policy does not allow this site: %s.', $provider, implode('; ', $violations)));
    }
}
