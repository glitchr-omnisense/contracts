<?php

namespace Omnisense\Contracts\Policy;

/**
 * The waits a provider asks for, kept in the limiter so that every process
 * sharing it waits too: an HTTP client calls clear() before each request
 * and observe() on each response. A 429 throws UsageLimitReached with the
 * wait read from Retry-After (or RateLimit's t); a RateLimit field with
 * nothing left blocks the next calls until its window ends, before the
 * provider has to refuse one.
 */
final class Backoff
{
    /** @param class-string<UsageLimitReached> $exception the family's own subclass, thrown in its place */
    public function __construct(
        private readonly RateLimiterInterface $limiter,
        private readonly string $provider,
        private readonly string $exception = UsageLimitReached::class,
    ) {
    }

    /** Throws while the provider's wait lasts. */
    public function clear(): void
    {
        if (0 < $left = $this->limiter->blocked($this->key())) {
            throw new ($this->exception)(\sprintf('%s asked to wait: try again in %d s.', $this->provider, $left), $left, $this->provider);
        }
    }

    /** The seconds left of the provider's wait; 0 when there is none. */
    public function left(): int
    {
        return $this->limiter->blocked($this->key());
    }

    /**
     * Reads a response's Retry-After and RateLimit fields; throws on a 429.
     *
     * @param array<string, string|list<string>> $headers
     */
    public function observe(int $status, array $headers, ?int $now = null): void
    {
        $wait = RateLimitHeaders::wait($status, $headers, $now);
        if (null !== $wait && $wait > 0) {
            $this->limiter->block($this->key(), $wait);
        }
        if (429 === $status) {
            throw new ($this->exception)(\sprintf('%s answered 429 Too Many Requests%s.', $this->provider, null === $wait ? '' : \sprintf(': try again in %d s', $wait)), $wait, $this->provider);
        }
    }

    private function key(): string
    {
        return 'backoff/'.$this->provider;
    }
}
