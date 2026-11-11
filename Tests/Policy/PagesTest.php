<?php

namespace Omnisense\Contracts\Tests\Policy;

use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\PolicyGuard;
use Omnisense\Contracts\Policy\RateLimit;
use Omnisense\Contracts\Policy\UsageLimitReached;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;

/** PolicyGuard::pages(): each further page of a listing checked before it is fetched, counted after. */
final class PagesTest extends TestCase
{
    public function testEachPageAfterTheFirstIsCountedAndTheLastAnswerIsNot(): void
    {
        $guard = new PolicyGuard(UsagePolicy::builder('dns')->limit(RateLimit::perMinute(10, 'records'))->build(), new InMemoryRateLimiter());
        $fetched = [];
        $page = static function (int $n) use (&$fetched): array {
            $fetched[] = $n;

            return ['page' => $n, 'pages' => 3, 'items' => [$n * 10, $n * 10 + 1]];
        };

        $pages = $guard->pages('records', static fn () => $page(1), static fn (array $p) => $p['page'] < $p['pages'] ? static fn () => $page($p['page'] + 1) : null);
        self::assertSame([], $fetched, 'nothing fetched before it is read');
        $items = [];
        foreach ($pages as $one) {
            $items = [...$items, ...$one['items']];
        }

        self::assertSame([10, 11, 20, 21, 30, 31], $items);
        self::assertSame([1, 2, 3], $fetched);
        self::assertSame(8, $guard->remaining('records'), 'pages 2 and 3 counted; the first is the registry\'s call');
    }

    public function testAPageOverTheLimitIsNeverFetched(): void
    {
        $guard = new PolicyGuard(UsagePolicy::builder('dns')->limit(RateLimit::perMinute(1, 'records'))->build(), new InMemoryRateLimiter());
        $fetched = 0;
        $fetch = static function () use (&$fetched): int {
            return ++$fetched;
        };

        $read = [];
        try {
            foreach ($guard->pages('records', $fetch, static fn () => $fetch) as $one) {
                $read[] = $one;
            }
            self::fail('The listing went past its limit.');
        } catch (UsageLimitReached) {
        }
        self::assertSame([1, 2], $read);
        self::assertSame(2, $fetched, 'the third page was refused before it was fetched');
    }
}
