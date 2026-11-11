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
 * a shared bucket. A limit counted per resource (a parcel, an envelope)
 * counts per $context['resource']: the key of the resource the call is
 * about, or a map of kind to key when the operation touches resources of
 * several kinds (['zone' => $zoneId, 'record' => $recordId]). One not given
 * throws ResourceNotNamed before anything is sent: a shared bucket would
 * refuse the second parcel's calls, skipping the limit would let through
 * what the terms forbid, and only the provider knows the resource.
 *
 *   $tracking->call('trackParcel', fn () => $api->track($number), ['resource' => $number]);
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
     * @param array{key?: string, ip?: string, user?: string, resource?: string|array<string, string>}       $context
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
     * @param array{key?: string, ip?: string, user?: string, resource?: string|array<string, string>} $context
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
            $allowance = $this->limiter->allowance($this->key($limit, $context, $operation), $limit);
            if ($allowance->remaining < $need) {
                throw new ($this->exception)(\sprintf('%s\'s usage policy allows %s: reached, try again in %d s.', $this->policy->provider, $limit->describe(), $allowance->resetsIn), $allowance->resetsIn, $this->policy->provider, $limit);
            }
        }
    }

    /**
     * One call of the operation counted: under each limit, its cost or the
     * amount given in its unit.
     *
     * @param array{key?: string, ip?: string, user?: string, resource?: string|array<string, string>} $context
     * @param array<string, int>                              $amounts by unit, in place of the cost
     */
    public function record(string $operation, array $context = [], array $amounts = []): void
    {
        foreach ($this->policy->limitsFor($operation) as $limit) {
            $amount = self::consumed($limit, $operation, $amounts);
            if (0 === $amount) {
                continue;
            }
            $key = $this->key($limit, $context, $operation);
            if ($this->limiter instanceof ConsumingRateLimiterInterface) {
                $this->limiter->consume($key, $limit, $amount);
                continue;
            }
            for ($i = 0; $i < $amount; ++$i) {
                $this->limiter->hit($key, $limit);
            }
        }
    }

    /**
     * The calls of an operation left before a limit is reached (what is
     * left of each limit divided by the operation's cost); null when no
     * limit counts it. A limit counted per resource is read only when the
     * context names the resource.
     *
     * @param array{key?: string, ip?: string, user?: string, resource?: string|array<string, string>} $context
     */
    public function remaining(string $operation, array $context = []): ?int
    {
        $remaining = null;
        foreach ($this->policy->limitsFor($operation) as $limit) {
            $cost = $limit->cost($operation);
            if (0 === $cost || (Scope::Resource === $limit->scope && null === self::resource($limit, $context))) {
                continue;
            }
            $left = intdiv(max(0, $this->limiter->allowance($this->key($limit, $context, $operation), $limit)->remaining), $cost);
            $remaining = null === $remaining ? $left : min($remaining, $left);
        }

        return $remaining;
    }

    /**
     * The same guard (its limiter, its wait, its exception) over the
     * policy's limits counted per resource alone: what a provider holds for
     * the requests it makes about one resource (a parcel tracked, an
     * envelope polled), under an operation of its own, while the registry's
     * guard counts the family's operation - so that no other limit counts
     * the same request twice.
     *
     *   $tracking = $c->guard(UsagePolicy::fromFile(...))->perResource();
     */
    public function perResource(): self
    {
        $policy = $this->policy;
        $limits = null === $policy->rateLimits ? null : array_values(array_filter($policy->rateLimits, static fn (RateLimit $limit) => Scope::Resource === $limit->scope));

        return new self(new UsagePolicy($policy->provider, $limits, $policy->rights, $policy->requirements, $policy->provenance, $policy->concurrency, null, $policy->plan), $this->limiter, $this->backoff, $this->exception);
    }

    /**
     * The pages of a listing, fetched as they are read, each after the
     * first through call(): checked before it is sent, counted after. The
     * first is fetched as it is (the registry's guard counts the operation
     * that asks for the listing); $next is given each page read and answers
     * the fetch of the next one, or null when there is none - so the last
     * answer is never counted as a request it did not make.
     *
     *   yield from $guard->pages('records', fn () => $this->page($zone, 1), fn (array $page) => $page['page'] < $page['pages'] ? fn () => $this->page($zone, $page['page'] + 1) : null);
     *
     * @template P
     *
     * @param callable(): P                  $first
     * @param callable(P): ((callable(): P)|null) $next
     * @param array{key?: string, ip?: string, user?: string, resource?: string|array<string, string>} $context
     *
     * @return \Generator<int, P>
     */
    public function pages(string $operation, callable $first, callable $next, array $context = []): \Generator
    {
        $page = $first();
        while (true) {
            yield $page;
            $fetch = $next($page);
            if (null === $fetch) {
                return;
            }
            $page = $this->call($operation, $fetch, $context);
        }
    }

    /**
     * The time to live the terms allow an answer: 0 when it may not be
     * cached, the shorter of the one wanted and the policy's maximum
     * otherwise (Caching::ttl()) - and never longer than the data may be
     * kept at all (rights.retention's maxAge): a cached answer is a copy.
     */
    public function cacheTtl(?int $wanted = null): ?int
    {
        $ttl = $this->policy->rights->caching->ttl($wanted);
        $kept = $this->policy->rights->retention?->maxAge;

        return null === $kept ? $ttl : min($ttl ?? $kept, $kept);
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

    /** @param array{key?: string, ip?: string, user?: string, resource?: string|array<string, string>} $context */
    private function key(RateLimit $limit, array $context, string $operation): string
    {
        $bucket = match ($limit->scope) {
            Scope::Application => '',
            Scope::Key => isset($context['key']) ? hash('xxh128', $context['key']) : '*',
            Scope::ClientIp => $context['ip'] ?? '*',
            Scope::User => $context['user'] ?? '*',
            Scope::Resource => $limit->per.':'.hash('xxh128', self::resource($limit, $context) ?? throw new ResourceNotNamed($this->policy->provider, $limit, $operation)),
        };

        return $this->policy->provider.'/'.$bucket;
    }

    /**
     * The key of the resource a limit counts per, as the context names it.
     *
     * @param array{key?: string, ip?: string, user?: string, resource?: string|array<string, string>} $context
     */
    private static function resource(RateLimit $limit, array $context): ?string
    {
        $resource = $context['resource'] ?? null;
        $resource = \is_array($resource) ? ($resource[(string) $limit->per] ?? null) : $resource;

        return null === $resource || '' === $resource ? null : $resource;
    }
}
