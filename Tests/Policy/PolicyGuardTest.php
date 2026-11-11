<?php

namespace Omnisense\Contracts\Tests\Policy;

use Omnisense\Contracts\Exception\ProviderException;
use Omnisense\Contracts\Http\JsonClient;
use Omnisense\Contracts\Policy\Caching;
use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\PolicyGuard;
use Omnisense\Contracts\Policy\RateLimit;
use Omnisense\Contracts\Policy\RateLimitHeaders;
use Omnisense\Contracts\Policy\Retention;
use Omnisense\Contracts\Policy\Scope;
use Omnisense\Contracts\Policy\UsageLimitReached;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnisense\Contracts\Policy\Window;
use Omnisense\Contracts\Testing\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

final class PolicyGuardTest extends TestCase
{
    private float $now = 1_000_000.0;

    private function limiter(): InMemoryRateLimiter
    {
        return new InMemoryRateLimiter(fn (): float => $this->now);
    }

    public function testALimitReachedRefusesTheNextCallUntilItsWindowEnds(): void
    {
        $guard = new PolicyGuard(UsagePolicy::builder('transit')->limit(RateLimit::perDay(2, 'journeys'))->build(), $this->limiter());
        $calls = 0;
        $call = static function () use (&$calls): int {
            return ++$calls;
        };

        self::assertSame(1, $guard->call('journeys', $call));
        self::assertSame(1, $guard->remaining('journeys'));
        self::assertSame(2, $guard->call('journeys', $call));
        self::assertNull($guard->remaining('departures'), 'no limit counts departures');
        $guard->call('departures', $call);
        $this->now += 3600;
        try {
            $guard->call('journeys', $call);
            self::fail('The third call of the day went through.');
        } catch (UsageLimitReached $e) {
            self::assertSame(82800, $e->retryAfter);
            self::assertSame('transit', $e->provider);
            self::assertSame('2/day/application/journeys', $e->limit?->id());
            self::assertSame('transit\'s usage policy allows 2 a day for journeys: reached, try again in 82800 s.', $e->getMessage());
        }
        self::assertSame(3, $calls, 'the refused call was not made');
        $this->now += 82800;
        self::assertSame(4, $guard->call('journeys', $call), 'a new window, a new allowance');
    }

    public function testAFailedCallCountsToo(): void
    {
        $guard = new PolicyGuard(UsagePolicy::builder('x')->limit(RateLimit::perMinute(1))->build(), $this->limiter());
        try {
            $guard->call('any', static fn () => throw new \RuntimeException('down'));
        } catch (\RuntimeException) {
        }

        $this->expectException(UsageLimitReached::class);
        $guard->check('any');
    }

    public function testALimitPerClientCountsEachClientApart(): void
    {
        $guard = new PolicyGuard(UsagePolicy::builder('x')->limit(new RateLimit(1, Window::Minute, Scope::ClientIp))->build(), $this->limiter());

        $guard->call('search', static fn () => null, ['ip' => '192.0.2.1']);
        $guard->call('search', static fn () => null, ['ip' => '192.0.2.2']);
        $guard->call('search', static fn () => null);
        $this->expectException(UsageLimitReached::class);
        $guard->call('search', static fn () => null, ['ip' => '192.0.2.1']);
    }

    public function testA429WithRetryAfterIsHonouredBeforeTheNextCall(): void
    {
        $limiter = $this->limiter();
        $guard = new PolicyGuard(UsagePolicy::unknown('transit'), $limiter);
        $http = RecordedHttpClient::routes(['~/plan~' => [429, '{"error":"slow down"}', ['retry-after' => ['120']]]]);
        $json = new JsonClient($http, 'Transit', backoff: $guard->backoff());

        try {
            $guard->call('journeys', static fn () => $json->get('https://transit.example/api/plan', ['key' => 'secret-key-123']));
            self::fail('A 429 went through.');
        } catch (UsageLimitReached $e) {
            self::assertSame(120, $e->retryAfter);
            self::assertSame('transit answered 429 Too Many Requests: try again in 120 s.', $e->getMessage());
            self::assertStringNotContainsString('secret-key-123', print_r($e->getTrace(), true));
        }
        try {
            $guard->call('departures', static fn () => $json->get('https://transit.example/api/stoptimes'));
            self::fail('A call went through while the provider asked to wait.');
        } catch (UsageLimitReached $e) {
            self::assertSame(120, $e->retryAfter);
        }
        self::assertCount(1, $http->requests, 'the wait held the second request back');
        $this->now += 121;
        self::assertNull($json->get('https://transit.example/api/stoptimes'), 'the wait over, the provider is asked again (404: not recorded)');
        self::assertCount(2, $http->requests);
    }

    public function testARateLimitFieldWithNothingLeftHoldsTheNextCalls(): void
    {
        $limiter = $this->limiter();
        $guard = new PolicyGuard(UsagePolicy::unknown('x'), $limiter);
        $http = RecordedHttpClient::routes(['~~' => [200, '{"ok":true}', ['ratelimit' => ['"default";r=0;t=30'], 'ratelimit-policy' => ['"default";q=100;w=60']]]]);
        $json = new JsonClient($http, 'X', backoff: $guard->backoff());

        self::assertSame(true, $json->get('https://x.example/a')?->bool('ok'));
        self::assertSame(30, $guard->backoff()->left());
        $this->expectException(UsageLimitReached::class);
        $json->get('https://x.example/b');
    }

    public function testWithoutABackoffA429StaysTheProvidersError(): void
    {
        $json = new JsonClient(RecordedHttpClient::routes(['~~' => [429, 'slow down', ['retry-after' => ['5']]]]), 'X');

        $this->expectException(ProviderException::class);
        $json->get('https://x.example/a');
    }

    public function testTheHeadersAreReadAsTheDraftWritesThem(): void
    {
        self::assertSame([['policy' => 'burst', 'q' => 100, 'w' => 60], ['policy' => 'daily', 'q' => 1000, 'w' => 86400]], RateLimitHeaders::rateLimitPolicy('"burst";q=100;w=60,"daily";q=1000;w=86400'));
        self::assertSame([['policy' => 'peruser', 'q' => 100, 'w' => 60, 'pk' => ':cHsdsRa894==:']], RateLimitHeaders::rateLimitPolicy('"peruser";q=100;w=60;pk=:cHsdsRa894==:'));
        self::assertSame([['policy' => 'default', 'r' => 50, 't' => 30]], RateLimitHeaders::rateLimit('"default";r=50;t=30'));
        self::assertSame([['policy' => 'a,b', 'r' => 0]], RateLimitHeaders::rateLimit('"a,b";r=0'));
        self::assertNull(RateLimitHeaders::wait(200, ['RateLimit' => '"default";r=50;t=30']));
        self::assertSame(40, RateLimitHeaders::wait(200, ['RateLimit' => '"a";r=0;t=40, "b";r=3;t=5']));
        self::assertSame(20, RateLimitHeaders::wait(429, ['Retry-After' => '20', 'RateLimit' => '"dynamic";r=15;t=40']), 'Retry-After prevails');
        self::assertNull(RateLimitHeaders::wait(301, ['Retry-After' => '20']), 'a redirect\'s Retry-After is no wait');
        self::assertSame(90, RateLimitHeaders::retryAfter('Wed, 21 Oct 2015 07:29:30 GMT', (int) strtotime('2015-10-21 07:28:00 UTC')));
        self::assertNull(RateLimitHeaders::retryAfter('soon'));
    }

    public function testTheCacheTimeToLiveIsTheShorterOfTheWantedAndTheAllowed(): void
    {
        self::assertSame(60, (new PolicyGuard(UsagePolicy::builder('x')->caching(Caching::upTo(3600))->build()))->cacheTtl(60));
        self::assertSame(3600, (new PolicyGuard(UsagePolicy::builder('x')->caching(Caching::upTo(3600))->build()))->cacheTtl(86400));
        self::assertSame(3600, (new PolicyGuard(UsagePolicy::builder('x')->caching(Caching::upTo(3600))->build()))->cacheTtl());
        $forbidden = new PolicyGuard(UsagePolicy::builder('x')->caching(Caching::forbidden())->build());
        self::assertSame(0, $forbidden->cacheTtl(60));
        self::assertFalse($forbidden->mayCache());
        self::assertSame(60, (new PolicyGuard(UsagePolicy::unknown('x')))->cacheTtl(60), 'unknown terms do not shorten it');
    }

    public function testNoCachedAnswerOutlivesTheRetention(): void
    {
        $kept = new PolicyGuard(UsagePolicy::builder('x')->caching(new Caching())->retention(new Retention(7689600, 'a tenant\'s file'))->build());

        self::assertSame(7689600, $kept->cacheTtl(), 'no maximum of its own: the retention\'s');
        self::assertSame(7689600, $kept->cacheTtl(86400 * 365));
        self::assertSame(60, $kept->cacheTtl(60));
        self::assertSame(3600, (new PolicyGuard(UsagePolicy::builder('x')->caching(Caching::upTo(3600))->retention(new Retention(7689600))->build()))->cacheTtl());
        self::assertSame(0, (new PolicyGuard(UsagePolicy::builder('x')->caching(Caching::forbidden())->retention(new Retention(60))->build()))->cacheTtl());
        self::assertFalse((new PolicyGuard(UsagePolicy::builder('x')->retention(new Retention(0))->build()))->mayCache(), 'kept not at all, cached not at all');
    }

    public function testAFamilyThrowsItsOwnSubclass(): void
    {
        $guard = new PolicyGuard(UsagePolicy::builder('x')->limit(RateLimit::perDay(0))->build(), exception: FamilyLimitReached::class);

        $this->expectException(FamilyLimitReached::class);
        $guard->check('any');
    }
}

final class FamilyLimitReached extends UsageLimitReached
{
}
