<?php

namespace Omnisense\Contracts\Policy;

/**
 * Counts within the PHP process: a command, a worker, a test. A web
 * application served by many processes gives a shared limiter instead.
 */
final class InMemoryRateLimiter implements ConsumingRateLimiterInterface
{
    /** @var array<string, array{start: float, count: int}> */
    private array $windows = [];

    /** @var array<string, float> */
    private array $blocks = [];

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /** @param ?\Closure(): float $clock the time in seconds (microtime(true) by default) */
    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function allowance(string $key, RateLimit $limit): Allowance
    {
        $window = $this->window($key, $limit);
        if (null === $window) {
            return new Allowance($limit->max, $limit->seconds());
        }

        return new Allowance(max(0, $limit->max - $window['count']), (int) ceil($window['start'] + $limit->seconds() - ($this->clock)()));
    }

    public function hit(string $key, RateLimit $limit): void
    {
        $this->consume($key, $limit, 1);
    }

    public function consume(string $key, RateLimit $limit, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        $window = $this->window($key, $limit) ?? ['start' => ($this->clock)(), 'count' => 0];
        $window['count'] += $amount;
        $this->windows[$key."\0".$limit->id()] = $window;
    }

    public function block(string $key, int $seconds): void
    {
        if ($seconds <= 0) {
            unset($this->blocks[$key]);

            return;
        }
        $this->blocks[$key] = max($this->blocks[$key] ?? 0.0, ($this->clock)() + $seconds);
    }

    public function blocked(string $key): int
    {
        $left = ($this->blocks[$key] ?? 0.0) - ($this->clock)();

        return $left > 0 ? (int) ceil($left) : 0;
    }

    /** @return ?array{start: float, count: int} the current window; null when it has not started or has ended */
    private function window(string $key, RateLimit $limit): ?array
    {
        $window = $this->windows[$key."\0".$limit->id()] ?? null;

        return null !== $window && ($this->clock)() < $window['start'] + $limit->seconds() ? $window : null;
    }
}
