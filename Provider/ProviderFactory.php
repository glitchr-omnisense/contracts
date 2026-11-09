<?php

namespace Omnisense\Contracts\Provider;

use Omnisense\Contracts\Policy\Backoff;
use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\PolicyGuard;
use Omnisense\Contracts\Policy\RateLimiterInterface;
use Omnisense\Contracts\Policy\Usage;
use Omnisense\Contracts\Policy\UsageLimitReached;
use Omnisense\Contracts\Policy\UsageNotPermitted;
use Omnisense\Contracts\Policy\UsagePolicy;

/**
 * A provider's factory fills a Config - its name ("<prefix>.factory_name"),
 * its title ("<prefix>.factory_title"), the options it needs
 * ("<prefix>.required_options") and the defaults of the others - then builds
 * the provider from it (configure(), make()).
 *
 * Before make(), the Config holds the provider's Backoff ("<prefix>.backoff",
 * Config::backoff()), over the registry's limiter ("<prefix>.limiter") or an
 * in-memory one: the factory gives it to the provider's JsonClient. After,
 * guard() gives the provider's PolicyGuard: its declared policy tightened by
 * the "policy" option, checked against the "usage" option.
 *
 * Nothing here calls anything: a factory whose provider is reached over
 * HTTP takes its client in its own constructor - the application's, a
 * MockHttpClient in a test.
 */
abstract class ProviderFactory implements ProviderFactoryInterface
{
    /** @var \WeakMap<ProviderInterface, array{0: Config, 1: RateLimiterInterface, 2: Backoff, 3?: PolicyGuard}> */
    private \WeakMap $built;

    public function getName(): string
    {
        $config = $this->createConfig();

        return $config->string($config->key('factory_name')) ?? static::class;
    }

    public function create(array $options = []): ProviderInterface
    {
        $config = $this->createConfig($options);
        $required = $config->get($config->key('required_options'), []);
        $config->validateNotEmpty(\is_array($required) ? array_values(array_filter($required, 'is_string')) : []);
        $limiter = $config->get($config->key('limiter'));
        $limiter = $limiter instanceof RateLimiterInterface ? $limiter : new InMemoryRateLimiter();
        $backoff = new Backoff($limiter, $config->string($config->key('factory_name')) ?? static::class, $this->limitException());
        $config[$config->key('backoff')] = $backoff;
        $provider = $this->make($config);
        $this->built ??= new \WeakMap();
        $this->built[$provider] = [$config, $limiter, $backoff];

        return $provider;
    }

    /** @param array<string, mixed> $options */
    public function createConfig(array $options = []): Config
    {
        $config = $this->newConfig($options);
        $this->configure($config);

        return $config;
    }

    /**
     * The guard of a provider this factory built: its policy (as the
     * provider declares it, tightened by the "policy" option), the limiter
     * it was built with, the wait its HTTP client observes. Built on the
     * first call, so that a provider whose Capabilities read a store is not
     * asked at once. Null for a provider it did not build.
     *
     * @throws UsageNotPermitted when the "usage" option says a use the policy forbids
     */
    public function guard(ProviderInterface $provider): ?PolicyGuard
    {
        $built = ($this->built ?? null)?->offsetExists($provider) ? $this->built[$provider] : null;
        if (null === $built) {
            return null;
        }
        if (!isset($built[3])) {
            [$config, $limiter, $backoff] = $built;
            $built[3] = new PolicyGuard(self::effectivePolicy($provider, $config->map('policy'), $config->map('usage')), $limiter, $backoff, $this->limitException());
            $this->built[$provider] = $built;
        }

        return $built[3];
    }

    /**
     * A provider's policy as a site runs it: declared by the provider
     * (DeclaresUsagePolicyInterface, or its Capabilities), tightened by the
     * site's "policy" option, checked against its "usage" option.
     *
     * @param array<string, mixed> $policy the site's tightening
     * @param array<string, mixed> $usage  what the site says of itself
     */
    public static function effectivePolicy(ProviderInterface $provider, array $policy = [], array $usage = []): UsagePolicy
    {
        $declared = $provider instanceof DeclaresUsagePolicyInterface ? $provider->usagePolicy() : $provider->capabilities()->policy();
        if (UsagePolicy::UNNAMED === $declared->provider) {
            $declared = $declared->named($provider->getName());
        }
        $effective = $declared->tighten($policy);
        if ([] !== $usage && [] !== $violations = $effective->violations(Usage::fromArray($usage))) {
            throw UsageNotPermitted::of($effective->provider, $violations);
        }

        return $effective;
    }

    /**
     * The family's Config, with its prefix and exceptions.
     *
     * @param array<string, mixed> $options
     */
    protected function newConfig(array $options): Config
    {
        return new Config($options);
    }

    /** @return class-string<UsageLimitReached> the family's own, its exception interface implemented */
    protected function limitException(): string
    {
        return UsageLimitReached::class;
    }

    /** The factory's name and title, its required options, the defaults of the others. */
    abstract protected function configure(Config $c): void;

    /** The provider, from a Config that holds everything it needs. */
    abstract protected function make(Config $c): ProviderInterface;
}
