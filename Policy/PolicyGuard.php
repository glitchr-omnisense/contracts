<?php

namespace Omnisense\Contracts\Policy;

/**
 * A provider's policy enforced around its calls: a family core wraps each
 * call to a provider in call() - the limits that count the operation
 * checked before (UsageLimitReached when one is reached, or while the
 * provider's own wait lasts), the call counted after, failed or not (the
 * provider counted it too). cacheTtl() gives the time to live the terms
 * allow an answer.
 *
 *   $guard = new PolicyGuard($policy, $limiter);
 *   $journeys = $guard->call('journeys', fn () => $provider->journeys($query));
 *
 * A limit scoped per key counts per $context['key'], per client address per
 * $context['ip'], per user per $context['user']; one not given counts in
 * a shared bucket.
 */
final class PolicyGuard
{
    private readonly Backoff $backoff;

    /** @param class-string<UsageLimitReached> $exception the family's own subclass, thrown in its place */
    public function __construct(
        private readonly UsagePolicy $policy,
        private readonly RateLimiterInterface $limiter = new InMemoryRateLimiter(),
        ?Backoff $backoff = null,
        private readonly string $exception = UsageLimitReached::class,
    ) {
        $this->backoff = $backoff ?? new Backoff($limiter, $policy->provider, $exception);
    }

    public function policy(): UsagePolicy
    {
        return $this->policy;
    }

    /** What an HTTP client of the provider gives each response to (Backoff::observe()). */
    public function backoff(): Backoff
    {
        return $this->backoff;
    }

    /**
     * @template T
     *
     * @param callable(): T                                  $call
     * @param array{key?: string, ip?: string, user?: string} $context
     *
     * @return T
     */
    public function call(string $operation, callable $call, array $context = []): mixed
    {
        $this->check($operation, $context);
        try {
            return $call();
        } finally {
            $this->record($operation, $context);
        }
    }

    /**
     * Throws UsageLimitReached when the operation may not be called now.
     *
     * @param array{key?: string, ip?: string, user?: string} $context
     */
    public function check(string $operation, array $context = []): void
    {
        $this->backoff->clear();
        foreach ($this->policy->limitsFor($operation) as $limit) {
            $allowance = $this->limiter->allowance($this->key($limit, $context), $limit);
            if ($allowance->exhausted()) {
                throw new ($this->exception)(\sprintf('%s\'s usage policy allows %s: reached, try again in %d s.', $this->policy->provider, $limit->describe(), $allowance->resetsIn), $allowance->resetsIn, $this->policy->provider, $limit);
            }
        }
    }

    /**
     * One call of the operation counted.
     *
     * @param array{key?: string, ip?: string, user?: string} $context
     */
    public function record(string $operation, array $context = []): void
    {
        foreach ($this->policy->limitsFor($operation) as $limit) {
            $this->limiter->hit($this->key($limit, $context), $limit);
        }
    }

    /**
     * The calls of an operation left before a limit is reached; null when no
     * limit counts it.
     *
     * @param array{key?: string, ip?: string, user?: string} $context
     */
    public function remaining(string $operation, array $context = []): ?int
    {
        $remaining = null;
        foreach ($this->policy->limitsFor($operation) as $limit) {
            $left = $this->limiter->allowance($this->key($limit, $context), $limit)->remaining;
            $remaining = null === $remaining ? $left : min($remaining, $left);
        }

        return $remaining;
    }

    /**
     * The time to live the terms allow an answer: 0 when it may not be
     * cached, the shorter of the one wanted and the policy's maximum
     * otherwise (Caching::ttl()).
     */
    public function cacheTtl(?int $wanted = null): ?int
    {
        return $this->policy->rights->caching->ttl($wanted);
    }

    public function mayCache(): bool
    {
        return 0 !== $this->cacheTtl();
    }

    /** @param array{key?: string, ip?: string, user?: string} $context */
    private function key(RateLimit $limit, array $context): string
    {
        $bucket = match ($limit->scope) {
            Scope::Application => '',
            Scope::Key => isset($context['key']) ? hash('xxh128', $context['key']) : '*',
            Scope::ClientIp => $context['ip'] ?? '*',
            Scope::User => $context['user'] ?? '*',
        };

        return $this->policy->provider.'/'.$bucket;
    }
}
