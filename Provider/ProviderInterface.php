<?php

namespace Omnisense\Contracts\Provider;

/**
 * One source, configured. A family's provider interface extends it, its
 * contracts (TimetablesInterface, SearchInterface...) extend that one.
 */
interface ProviderInterface
{
    /** The factory's name: "gtfs", "transitous", "tmdb"... */
    public function getName(): string;

    public function getTitle(): string;

    public function capabilities(): CapabilitiesInterface;
}
