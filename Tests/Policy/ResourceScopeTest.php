<?php

namespace Omnisense\Contracts\Tests\Policy;

use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\PolicyGuard;
use Omnisense\Contracts\Policy\RateLimit;
use Omnisense\Contracts\Policy\ResourceNotNamed;
use Omnisense\Contracts\Policy\Scope;
use Omnisense\Contracts\Policy\UsageLimitReached;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnisense\Contracts\Policy\Window;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A limit counted per resource: Mondial Relay's parcel tracked 6 times a day, DocuSign's envelope polled once per 15 minutes. */
final class ResourceScopeTest extends TestCase
{
    private float $now = 1_000_000.0;

    private function guard(RateLimit ...$limits): PolicyGuard
    {
        return new PolicyGuard(UsagePolicy::builder('mondial_relay')->limit(...$limits)->build(), new InMemoryRateLimiter(fn (): float => $this->now));
    }

    public function testEachResourceCountsApart(): void
    {
        $guard = $this->guard(RateLimit::perDay(2, 'trackParcel')->perResource('parcel'));
        $calls = 0;
        $track = static function () use (&$calls): int {
            return ++$calls;
        };

        $guard->call('trackParcel', $track, ['resource' => 'A1']);
        $guard->call('trackParcel', $track, ['resource' => 'A1']);
        $guard->call('trackParcel', $track, ['resource' => 'B2']);
        self::assertSame(0, $guard->remaining('trackParcel', ['resource' => 'A1']));
        self::assertSame(1, $guard->remaining('trackParcel', ['resource' => 'B2']));
        self::assertNull($guard->remaining('trackParcel'), 'no resource named: the limit per parcel is not read');
        try {
            $guard->call('trackParcel', $track, ['resource' => 'A1']);
            self::fail('A1 was tracked a third time today.');
        } catch (UsageLimitReached $e) {
            self::assertSame('mondial_relay\'s usage policy allows 2 a day for trackParcel per parcel: reached, try again in 86400 s.', $e->getMessage());
            self::assertSame('2/day/resource:parcel/trackParcel', $e->limit?->id());
        }
        self::assertSame(3, $calls, 'the refused call was not made');
        $guard->call('anotherOperation', $track);
        self::assertSame(4, $calls, 'an operation the limit does not count names no resource');
    }

    public function testACallThatNamesNoResourceIsRefusedBeforeItIsMade(): void
    {
        $guard = $this->guard(RateLimit::perMinute(1, 'pollEnvelope')->every(15)->perResource('envelope'));
        $calls = 0;
        try {
            $guard->call('pollEnvelope', static function () use (&$calls): void {
                ++$calls;
            });
            self::fail('A poll naming no envelope went through.');
        } catch (ResourceNotNamed $e) {
            self::assertSame('pollEnvelope', $e->operation);
            self::assertSame('mondial_relay\'s usage policy allows 1 every 15 minutes for pollEnvelope per envelope: the "pollEnvelope" call names no envelope (the guard\'s context "resource").', $e->getMessage());
        }
        self::assertSame(0, $calls);
        $this->expectException(ResourceNotNamed::class);
        $guard->call('pollEnvelope', static fn () => null, ['resource' => '']);
    }

    public function testAMapNamesEachKindOfResource(): void
    {
        $guard = $this->guard(RateLimit::perMinute(1, 'records')->perResource('zone'), RateLimit::perMinute(2, 'records')->perResource('account'));

        $guard->call('records', static fn () => null, ['resource' => ['zone' => 'z1', 'account' => 'a']]);
        $guard->call('records', static fn () => null, ['resource' => ['zone' => 'z2', 'account' => 'a']]);
        self::assertSame(0, $guard->remaining('records', ['resource' => ['zone' => 'z3', 'account' => 'a']]), 'the account counts both zones');
        $this->expectException(ResourceNotNamed::class);
        $guard->call('records', static fn () => null, ['resource' => ['zone' => 'z3']]);
    }

    public function testTheProvidersGuardOverThePerResourceLimitsAloneCountsInTheSameLimiter(): void
    {
        $limiter = new InMemoryRateLimiter(fn (): float => $this->now);
        $policy = UsagePolicy::builder('docusign')->limit(new RateLimit(3, Window::Hour, Scope::Key), RateLimit::perMinute(1, 'pollEnvelope')->every(15)->perResource('envelope'))->build();
        $gateway = new PolicyGuard($policy, $limiter);
        $polls = $gateway->perResource();

        self::assertCount(1, $polls->policy()->rateLimits ?? []);
        self::assertSame($policy->rights, $polls->policy()->rights);
        $gateway->call('fetch', static fn () => $polls->call('pollEnvelope', static fn () => null, ['resource' => 'e1']));
        self::assertSame(2, $gateway->remaining('fetch'), 'the request counted once under the hourly limit');
        self::assertSame(0, $polls->remaining('pollEnvelope', ['resource' => 'e1']));
        self::assertSame(1, (new PolicyGuard($policy, $limiter))->perResource()->remaining('pollEnvelope', ['resource' => 'e2']));
        self::assertNull((new PolicyGuard(UsagePolicy::unknown('x')))->perResource()->policy()->rateLimits, 'unknown limits stay unknown');
    }

    public function testThePolicyReadsAndWritesIt(): void
    {
        $data = ['max' => 6, 'window' => 'day', 'scope' => 'resource', 'per' => 'parcel', 'operations' => ['trackParcel'], 'note' => '4 to 6 times a day'];
        $limit = RateLimit::fromArray($data);

        self::assertSame(Scope::Resource, $limit->scope);
        self::assertSame('parcel', $limit->per);
        self::assertSame('6 a day for trackParcel per parcel', $limit->describe());
        self::assertSame(['max' => 6, 'window' => 'day', 'every' => 1, 'scope' => 'resource', 'per' => 'parcel', 'operations' => ['trackParcel'], 'unit' => 'requests', 'cost' => null, 'note' => '4 to 6 times a day'], $limit->toArray());
        self::assertArrayNotHasKey('per', RateLimit::perDay(6)->toArray(), 'a limit of another scope writes what it always did');
        self::assertFalse($limit->sameCountAs(RateLimit::perDay(6, 'trackParcel')->perResource('envelope')));
        self::assertTrue($limit->sameCountAs(RateLimit::perDay(4, 'trackParcel')->perResource('parcel')));
        $policy = UsagePolicy::fromArray(['provider' => 'mondial_relay', 'rateLimits' => [$data]]);
        self::assertEquals($policy, UsagePolicy::fromArray($policy->toArray()));
    }

    public function testASiteMayTightenItNotLoosenIt(): void
    {
        $policy = UsagePolicy::builder('mondial_relay')->limit(RateLimit::perDay(6, 'trackParcel')->perResource('parcel'))->build();

        $tightened = $policy->tighten(['rateLimits' => [['max' => 4, 'window' => 'day', 'scope' => 'resource', 'per' => 'parcel', 'operations' => ['trackParcel']]]]);
        self::assertCount(2, $tightened->rateLimits ?? []);
        $this->expectException(InvalidPolicyException::class);
        $policy->tighten(['rateLimits' => [['max' => 10, 'window' => 'day', 'scope' => 'resource', 'per' => 'parcel', 'operations' => ['trackParcel']]]]);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'no kind' => [['max' => 1, 'window' => 'day', 'scope' => 'resource', 'operations' => ['x']], 'rateLimits[].per'];
        yield 'a kind with a slash' => [['max' => 1, 'window' => 'day', 'scope' => 'resource', 'per' => 'a/b', 'operations' => ['x']], 'rateLimits[].per'];
        yield 'a kind for another scope' => [['max' => 1, 'window' => 'day', 'scope' => 'key', 'per' => 'parcel'], 'rateLimits[].per'];
        yield 'every operation' => [['max' => 1, 'window' => 'day', 'scope' => 'resource', 'per' => 'parcel'], 'rateLimits[].operations'];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('invalid')]
    public function testAnIncompleteLimitPerResourceIsRefused(array $data, string $path): void
    {
        $this->expectException(InvalidPolicyException::class);
        $this->expectExceptionMessage('"'.$path.'"');
        RateLimit::fromArray($data);
    }
}
