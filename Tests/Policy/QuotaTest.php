<?php

namespace Omnisense\Contracts\Tests\Policy;

use Omnisense\Contracts\Policy\Allowance;
use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\PolicyExport;
use Omnisense\Contracts\Policy\PolicyGuard;
use Omnisense\Contracts\Policy\RateLimit;
use Omnisense\Contracts\Policy\RateLimiterInterface;
use Omnisense\Contracts\Policy\Unit;
use Omnisense\Contracts\Policy\UsageLimitReached;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnisense\Contracts\Policy\Window;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Limits in units other than requests, costs per operation, windows of several units, concurrency. */
final class QuotaTest extends TestCase
{
    private float $now = 1_000_000.0;

    private function limiter(): InMemoryRateLimiter
    {
        return new InMemoryRateLimiter(fn (): float => $this->now);
    }

    private static function declared(): UsagePolicy
    {
        return UsagePolicy::fromFile(__DIR__.'/../Fixtures/quota/policy.json');
    }

    public function testUnitsCostsAndWindowsAreReadAndWrittenBack(): void
    {
        $policy = self::declared();
        [$units, $burst] = $policy->rateLimits ?? [];

        self::assertSame(Unit::Units, $units->unit);
        self::assertSame(3, $units->cost('channelVideos'));
        self::assertSame(1, $units->cost('video'), 'an operation not named costs 1');
        self::assertSame('10000/day/application/*/units/channelVideos=3,live=3,schedule=3,searchVideos=100', $units->id());
        self::assertSame('10000 units a day (channelVideos costs 3, live costs 3, schedule costs 3, searchVideos costs 100)', $units->describe());
        self::assertSame(300, $burst->seconds());
        self::assertSame('500/5minute/application/*', $burst->id());
        self::assertSame('500 every 5 minutes', $burst->describe());
        self::assertSame(8, $policy->concurrency);
        self::assertEquals($policy, UsagePolicy::fromJson($policy->toJson()));
        self::assertSame(['max' => 500, 'window' => 'minute', 'every' => 5, 'scope' => 'application', 'operations' => [], 'unit' => 'requests', 'cost' => null, 'note' => '500 every 5 minutes.'], $burst->toArray());
        self::assertTrue($units->sameCountAs(RateLimit::perDay(10000)->counting(Unit::Units, ['searchVideos' => 100, 'live' => 3, 'schedule' => 3, 'channelVideos' => 3])), 'the builder writes the same limit');
        self::assertEquals(new RateLimit(500, Window::Minute, every: 5), RateLimit::perMinute(500)->every(5));
        self::assertSame(8, UsagePolicy::builder('x')->concurrency(8)->build()->concurrency);
        self::assertSame('720/day/application/journeys', RateLimit::perDay(720, 'journeys')->id(), 'a limit that uses none of it keeps its name in the store');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function outside(): iterable
    {
        yield 'no window of 0' => [['max' => 1, 'window' => 'minute', 'every' => 0], '"rateLimits[0].every" must be an integer of 1 or more'];
        yield 'an unknown unit' => [['max' => 1, 'window' => 'day', 'unit' => 'tokens'], '"rateLimits[0].unit" must be one of "requests", "content-bytes", "characters", "units", "points"'];
        yield 'a negative cost' => [['max' => 1, 'window' => 'day', 'cost' => ['live' => -1]], '"rateLimits[0].cost.live" must be an integer of 0 or more'];
        yield 'a cost list' => [['max' => 1, 'window' => 'day', 'cost' => [3]], '"rateLimits[0].cost" must be an object'];
    }

    /** @param array<string, mixed> $limit */
    #[DataProvider('outside')]
    public function testALimitOutsideTheSchemaIsRefused(array $limit, string $message): void
    {
        $this->expectException(InvalidPolicyException::class);
        $this->expectExceptionMessage($message);

        UsagePolicy::fromArray(['provider' => 'x', 'rateLimits' => [$limit]]);
    }

    public function testTheGuardConsumesEachOperationsCost(): void
    {
        $guard = new PolicyGuard(UsagePolicy::fromArray(['provider' => 'videos', 'rateLimits' => [['max' => 10, 'window' => 'day', 'unit' => 'units', 'cost' => ['channelVideos' => 3, 'free' => 0]]]]), $this->limiter());

        self::assertSame(3, $guard->remaining('channelVideos'), '10 units: three calls of 3');
        $guard->call('channelVideos', static fn () => null);
        $guard->call('channelVideos', static fn () => null);
        $guard->call('video', static fn () => null);
        self::assertSame(3, $guard->remaining('video'));
        self::assertSame(1, $guard->remaining('channelVideos'));
        self::assertNull($guard->remaining('free'), 'a free operation is held by no limit');
        $guard->call('free', static fn () => null);
        $calls = 0;
        $guard->call('channelVideos', static function () use (&$calls): void {
            ++$calls;
        });
        self::assertSame(1, $calls, '3 units left are enough for a call of 3');
        self::assertSame(0, $guard->remaining('video'));
        $this->expectException(UsageLimitReached::class);
        $guard->call('video', static fn () => null);
    }

    public function testACharacterQuotaCountsWhatTheCallReports(): void
    {
        $guard = new PolicyGuard(UsagePolicy::fromArray(['provider' => 'translator', 'rateLimits' => [
            ['max' => 1000, 'window' => 'month', 'unit' => 'characters'],
            ['max' => 3, 'window' => 'second'],
        ]]), $this->limiter());

        $guard->call('translate', static fn () => 'Hallo', amounts: ['characters' => 600]);
        self::assertSame(2, $guard->remaining('translate'), 'the request limit is counted as ever');
        $this->now += 2;
        $guard->call('translate', static fn () => ['billed' => 250], amounts: static fn (array $result): array => ['characters' => $result['billed']]);
        $made = false;
        try {
            $guard->call('translate', static function () use (&$made): void {
                $made = true;
            }, amounts: ['characters' => 151]);
        } catch (UsageLimitReached $e) {
            self::assertFalse($made, '151 characters with 150 left: the call is not made');
            self::assertSame('1000/month/application/*/characters', $e->limit?->id());
        }
        $guard->call('translate', static fn () => null, amounts: ['characters' => 150]);
        $this->expectException(UsageLimitReached::class);
        $guard->check('translate');
    }

    public function testAWindowOfFiveMinutesLastsFiveMinutes(): void
    {
        $guard = new PolicyGuard(UsagePolicy::builder('trakt')->limit(RateLimit::perMinute(2)->every(5))->build(), $this->limiter());
        $guard->call('any', static fn () => null);
        $guard->call('any', static fn () => null);
        $this->now += 61;
        try {
            $guard->call('any', static fn () => null);
            self::fail('A minute later, the 5-minute window let a third call through.');
        } catch (UsageLimitReached $e) {
            self::assertSame(239, $e->retryAfter);
        }
        $this->now += 240;
        self::assertSame(3, $guard->call('any', static fn () => 3));
    }

    public function testALimiterWithoutConsumeIsHitOncePerUnit(): void
    {
        $limiter = new class implements RateLimiterInterface {
            public int $hits = 0;

            public function allowance(string $key, RateLimit $limit): Allowance
            {
                return new Allowance($limit->max - $this->hits, 60);
            }

            public function hit(string $key, RateLimit $limit): void
            {
                ++$this->hits;
            }

            public function block(string $key, int $seconds): void
            {
            }

            public function blocked(string $key): int
            {
                return 0;
            }
        };
        $guard = new PolicyGuard(UsagePolicy::builder('x')->limit(RateLimit::perDay(10)->counting(Unit::Points, ['heavy' => 4]))->build(), $limiter);

        $guard->call('heavy', static fn () => null);
        self::assertSame(4, $limiter->hits);
    }

    public function testConcurrencyOnlyGoesLower(): void
    {
        self::assertSame(4, self::declared()->tighten(['concurrency' => 4])->concurrency);
        self::assertSame(8, self::declared()->tighten([])->concurrency);
        self::assertSame(2, UsagePolicy::unknown('x')->tighten(['concurrency' => 2])->concurrency);
        self::assertNull(UsagePolicy::unknown('x')->concurrency);

        $this->expectExceptionMessage('its concurrency is 8 in flight, the configuration asks 16 in flight');
        self::declared()->tighten(['concurrency' => 16]);
    }

    public function testASiteTightensUnitsAndWindowsLikeAnyLimit(): void
    {
        $site = self::declared()->tighten(['rateLimits' => [['max' => 400, 'window' => 'minute', 'every' => 5], ['max' => 100, 'window' => 'minute']]]);
        self::assertCount(4, $site->rateLimits ?? []);

        $this->expectExceptionMessage('its rate limit is 500 every 5 minutes, the configuration asks 600 every 5 minutes');
        self::declared()->tighten(['rateLimits' => [['max' => 600, 'window' => 'minute', 'every' => 5]]]);
    }

    public function testTheExportCarriesUnitsCostsWindowsAndConcurrency(): void
    {
        $entries = (new PolicyExport())->folders(__DIR__.'/../Fixtures/quota')->entries();
        $policy = $entries[0]['policy'] ?? [];

        self::assertSame(8, $policy['concurrency'] ?? null);
        $json = json_encode($policy, \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"unit":"units","cost":{"channelVideos":3,"live":3,"schedule":3,"searchVideos":100}', $json);
        self::assertStringContainsString('"max":500,"window":"minute","every":5', $json);
    }
}
