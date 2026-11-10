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
 *
 * A call consumes, under each limit, the limit's cost for the operation (1
 * unless the policy says otherwise: 3 quota units, 2 HTTP requests). What
 * only the call knows is reported in $amounts, by unit: the characters of a
 * text to translate before the call, or those the provider billed after it
 * (a closure of the result):
 *
 *   $guard->call('translate', fn () => $deepl->translate($text), amounts: ['characters' => mb_strlen($text)]);
 *   $guard->call('translate', fn () => $deepl->translate($text), amounts: fn (Translation $t) => ['characters' => $t->billed]);
 *
 * A failed call consumes its cost, or the amounts given before it.
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
     * @param callable(): T                                        $call
     * @param array{key?: string, ip?: string, user?: string}       $context
     * @param array<string, int>|\Closure(T): array<string, int>   $amounts by unit ("characters", "units", "requests"): what the call consumes in place of the cost; a closure reads it from the result
     *
     * @return T
     */
    public function call(string $operation, callable $call, array $context = [], array|\Closure $amounts = []): mixed
    {
        $before = \is_array($amounts) ? $amounts : [];
        $this->check($operation, $context, $before);
        $after = $before;
        try {
            $result = $call();
            if ($amounts instanceof \Closure) {
                $after = $amounts($result);
            }

            return $result;
        } finally {
            $this->record($operation, $context, $after);
        }
    }

    /**
     * Throws UsageLimitReached when the operation may not be called now: a
     * limit has less left than the call consumes.
     *
     * @param array{key?: string, ip?: string, user?: string} $context
     * @param array<string, int>                              $amounts by unit, in place of the cost
     */
    public function check(string $operation, array $context = [], array $amounts = []): void
    {
        $this->backoff->clear();
        foreach ($this->policy->limitsFor($operation) as $limit) {
            $need = self::consumed($limit, $operation, $amounts);
            if (0 === $need) {
                continue;
            }
            $allowance = $this->limiter->allowance($this->key($limit, $context), $limit);
            if ($allowance->remaining < $need) {
                throw new ($this->exception)(\sprintf('%s\'s usage policy allows %s: reached, try again in %d s.', $this->policy->provider, $limit->describe(), $allowance->resetsIn), $allowance->resetsIn, $this->policy->provider, $limit);
            }
        }
    }

    /**
     * One call of the operation counted: under each limit, its cost or the
     * amount given in its unit.
     *
     * @param array{key?: string, ip?: string, user?: string} $context
     * @param array<string, int>                              $amounts by unit, in place of the cost
     */
    public function record(string $operation, array $context = [], array $amounts = []): void
    {
        foreach ($this->policy->limitsFor($operation) as $limit) {
            $amount = self::consumed($limit, $operation, $amounts);
            if ($this->limiter instanceof ConsumingRateLimiterInterface) {
                $this->limiter->consume($this->key($limit, $context), $limit, $amount);
                continue;
            }
            for ($i = 0; $i < $amount; ++$i) {
                $this->limiter->hit($this->key($limit, $context), $limit);
            }
        }
    }

    /**
     * The calls of an operation left before a limit is reached (what is
     * left of each limit divided by the operation's cost); null when no
     * limit counts it.
     *
     * @param array{key?: string, ip?: string, user?: string} $context
     */
    public function remaining(string $operation, array $context = []): ?int
    {
        $remaining = null;
        foreach ($this->policy->limitsFor($operation) as $limit) {
            $cost = $limit->cost($operation);
            if (0 === $cost) {
                continue;
            }
            $left = intdiv(max(0, $this->limiter->allowance($this->key($limit, $context), $limit)->remaining), $cost);
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

    /** @param array<string, int> $amounts */
    private static function consumed(RateLimit $limit, string $operation, array $amounts): int
    {
        return max(0, $amounts[$limit->unit->value] ?? $limit->cost($operation));
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
