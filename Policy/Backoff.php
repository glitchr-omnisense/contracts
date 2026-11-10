<?php

namespace Omnisense\Contracts\Policy;

/**
 * The waits a provider asks for, kept in the limiter so that every process
 * sharing it waits too: an HTTP client calls clear() before each request
 * and observe() on each response. A 429 throws UsageLimitReached with the
 * wait read from Retry-After (or RateLimit's t); a RateLimit field with
 * nothing left blocks the next calls until its window ends, before the
 * provider has to refuse one.
 *
 * The legacy fields some APIs send (X-RateLimit-Remaining and -Reset,
 * RateLimit-Remaining and -Reset) are read only when the provider's code
 * asks for them, saying how its reset counts: withLegacyHeaders(). A signal
 * that is no status nor field (Google's 403 rateLimitExceeded) is the
 * provider's to read: hold() keeps the wait it decides.
 */
final class Backoff
{
    /** @param class-string<UsageLimitReached> $exception the family's own subclass, thrown in its place */
    public function __construct(
        private readonly RateLimiterInterface $limiter,
        private readonly string $provider,
        private readonly string $exception = UsageLimitReached::class,
        private readonly ?LegacyReset $legacy = null,
    ) {
    }

    /**
     * The same wait (the same limiter, the same provider) reading the legacy
     * fields too, their reset counted as $reset says; for the provider's
     * HTTP client: new JsonClient($http, 'Twitch', backoff: $c->backoff()?->withLegacyHeaders(LegacyReset::Epoch)).
     */
    public function withLegacyHeaders(LegacyReset $reset): self
    {
        return new self($this->limiter, $this->provider, $this->exception, $reset);
    }

    /**
     * No call for $seconds, on a signal the provider read itself (a 403
     * rateLimitExceeded, an error code in a 200): the wait kept as a
     * Retry-After's is, and the exception to throw returned.
     *
     *   throw $backoff->hold(60, 'Google answered 403 rateLimitExceeded');
     */
    public function hold(int $seconds, string $reason): UsageLimitReached
    {
        if ($seconds > 0) {
            $this->limiter->block($this->key(), $seconds);
        }

        return new ($this->exception)(\sprintf('%s: try again in %d s.', rtrim($reason, '.'), max(0, $seconds)), max(0, $seconds), $this->provider);
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
        $wait = RateLimitHeaders::wait($status, $headers, $now, $this->legacy);
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
