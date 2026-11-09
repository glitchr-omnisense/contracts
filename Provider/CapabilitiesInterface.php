<?php

namespace Omnisense\Contracts\Provider;

use Omnisense\Contracts\Policy\UsagePolicy;

/**
 * What a provider is, said before it is asked anything: the family's
 * contracts it keeps, and its usage policy. Each family's Capabilities adds
 * what its answers carry.
 */
interface CapabilitiesInterface
{
    /** @return list<string> the family's contracts it keeps ("timetables", "itineraries"...) */
    public function contracts(): array;

    /** Its usage policy as the provider declares it (a site's tightening is the registry's: Registry::policy()). */
    public function policy(): UsagePolicy;

    /** @return array<string, mixed> */
    public function toArray(): array;
}
