<?php

namespace Omnisense\Contracts\Provider;

use Omnisense\Contracts\Exception\InvalidConfigException;
use Omnisense\Contracts\Exception\NotSupportedException;
use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\PolicyGuard;
use Omnisense\Contracts\Policy\RateLimiterInterface;
use Omnisense\Contracts\Policy\UsageLimitReached;
use Omnisense\Contracts\Policy\UsagePolicy;

/**
 * The application's providers by name, each built once from its factory and
 * options, each with its usage policy's guard:
 *
 *   $registry = new Registry([new TransitousFactory($http)], [
 *       'planner' => [
 *           'factory' => 'transitous',
 *           'options' => ['user_agent' => 'MySite/1.0 (https://example.org; travel@example.org)'],
 *           'policy' => ['rateLimits' => [['max' => 600, 'window' => 'day', 'operations' => ['journeys']]]],
 *           'usage' => ['commercial' => false, 'open_source' => true],
 *       ],
 *   ], $limiter);
 *
 * "plan" picks the plan the site subscribed to, where the provider declares
 * plans (UsagePolicy::forPlan()); "policy" tightens the provider's usage
 * policy (UsagePolicy::tighten(): never looser), "usage" says what the site is, so that a use the terms
 * forbid fails at once; both may sit in "options" too. Every provider counts
 * in the limiter given (an in-memory one by default): an application served
 * by many processes gives a shared one.
 *
 * A family extends it with its typed accessors, each a contract(), and its
 * PREFIX, the one of its Config.
 */
class Registry
{
    /** @var string */
    public const PREFIX = Config::PREFIX;

    /** @var array<string, ProviderFactoryInterface> */
    protected array $factories = [];

    /** @var array<string, ProviderInterface> */
    protected array $providers = [];

    /** @var array<string, PolicyGuard> */
    private array $guards = [];

    private readonly RateLimiterInterface $limiter;

    /**
     * @param iterable<ProviderFactoryInterface>                                                                                         $factories
     * @param array<string, array{factory: string, options?: array<string, mixed>, policy?: array<string, mixed>, usage?: array<string, mixed>}> $config
     */
    public function __construct(iterable $factories, protected readonly array $config, ?RateLimiterInterface $limiter = null)
    {
        foreach ($factories as $factory) {
            $this->factories[$factory->getName()] = $factory;
        }
        $this->limiter = $limiter ?? new InMemoryRateLimiter();
    }

    public function get(string $name): ProviderInterface
    {
        return $this->providers[$name] ??= $this->factory($name)->create([static::PREFIX.'.name' => $name, static::PREFIX.'.limiter' => $this->limiter] + $this->options($name));
    }

    public function has(string $name): bool
    {
        return isset($this->config[$name]);
    }

    /**
     * The guard of a provider: its policy as the site runs it, the limiter's
     * counts. A family's typed accessors wrap their calls in it.
     */
    public function guard(string $name): PolicyGuard
    {
        if (isset($this->guards[$name])) {
            return $this->guards[$name];
        }
        $provider = $this->get($name);
        $factory = $this->factory($name);
        $options = $this->options($name);
        $policy = \is_array($options['policy'] ?? null) ? $options['policy'] : [];
        $usage = \is_array($options['usage'] ?? null) ? $options['usage'] : [];
        $plan = \is_string($options['plan'] ?? null) && '' !== $options['plan'] ? $options['plan'] : null;

        return $this->guards[$name] = ($factory instanceof ProviderFactory ? $factory->guard($provider) : null)
            ?? new PolicyGuard(ProviderFactory::effectivePolicy($provider, self::map($policy), self::map($usage), $plan), $this->limiter, null, $this->limitException());
    }

    /** A provider's usage policy as the site runs it: the provider's, tightened by the site's configuration. */
    public function policy(string $name): UsagePolicy
    {
        return $this->guard($name)->policy();
    }

    /**
     * Every configured provider's policy, by name: what a site's "terms of
     * the data we show" page lists.
     *
     * @return array<string, UsagePolicy>
     */
    public function policies(): array
    {
        $policies = [];
        foreach ($this->names() as $name) {
            $policies[$name] = $this->policy($name);
        }

        return $policies;
    }

    /**
     * The configured providers keeping a contract, in the configuration's
     * order; every one when null.
     *
     * @return list<string>
     */
    public function names(?string $contract = null): array
    {
        $names = array_keys($this->config);

        return null === $contract ? $names : array_values(array_filter($names, fn (string $name) => \in_array($contract, $this->get($name)->capabilities()->contracts(), true)));
    }

    /** @return array<string, mixed> the options a provider is configured with ("policy" and "usage" among them when given beside) */
    public function options(string $name): array
    {
        $entry = $this->config[$name] ?? [];
        $options = $entry['options'] ?? [];
        foreach (['policy', 'usage'] as $key) {
            if (isset($entry[$key]) && !isset($options[$key])) {
                $options[$key] = $entry[$key];
            }
        }

        return $options;
    }

    /** @return list<string> the factories installed: what `factory:` may name */
    public function factories(): array
    {
        return array_keys($this->factories);
    }

    /**
     * A provider that keeps a contract, or the family's NotSupportedException.
     *
     * @template T of object
     *
     * @param class-string<T> $contract
     *
     * @return T
     */
    protected function contract(string $name, string $contract, string $capability): object
    {
        $provider = $this->get($name);

        return $provider instanceof $contract ? $provider : throw $this->notSupported($provider, $name, $capability);
    }

    protected function factory(string $name): ProviderFactoryInterface
    {
        $provider = $this->config[$name] ?? throw $this->invalid(\sprintf('No "%s" provider; configured: %s.', $name, implode(', ', array_keys($this->config)) ?: 'none'));

        return $this->factories[$provider['factory']] ?? throw $this->invalid(\sprintf('No "%s" factory for the "%s" provider; installed: %s.', $provider['factory'], $name, implode(', ', array_keys($this->factories)) ?: 'none'));
    }

    /**
     * The family's UsageLimitReached subclass, for the guard of a provider
     * whose factory is not the kernel's (its own factories' guards throw
     * theirs already).
     *
     * @return class-string<UsageLimitReached>
     */
    protected function limitException(): string
    {
        return UsageLimitReached::class;
    }

    /** The family's InvalidConfigException. */
    protected function invalid(string $message): \Throwable
    {
        return new InvalidConfigException($message);
    }

    /** The family's NotSupportedException. */
    protected function notSupported(ProviderInterface $provider, string $name, string $capability): \Throwable
    {
        return NotSupportedException::capability($provider, $name, $capability);
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function map(array $value): array
    {
        $map = [];
        foreach ($value as $key => $one) {
            $map[(string) $key] = $one;
        }

        return $map;
    }
}
