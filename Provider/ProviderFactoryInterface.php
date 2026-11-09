<?php

namespace Omnisense\Contracts\Provider;

/** Builds a provider from its options (a key, a token, a User-Agent...). */
interface ProviderFactoryInterface
{
    /** The name providers are configured with: "gtfs", "transitous", "tmdb"... */
    public function getName(): string;

    /** @param array<string, mixed> $options */
    public function create(array $options = []): ProviderInterface;
}
