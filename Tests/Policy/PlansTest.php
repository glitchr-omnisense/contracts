<?php

namespace Omnisense\Contracts\Tests\Policy;

use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\Plan;
use Omnisense\Contracts\Policy\Plans;
use Omnisense\Contracts\Policy\PolicyExport;
use Omnisense\Contracts\Policy\RateLimit;
use Omnisense\Contracts\Policy\Retention;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnisense\Contracts\Policy\Window;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plans (the default the policy's own limits, one picked by name, only tightened), retention, describe()'s article. */
final class PlansTest extends TestCase
{
    private static function declared(): UsagePolicy
    {
        return UsagePolicy::fromFile(__DIR__.'/../Fixtures/planned/policy.json');
    }

    /** @return list<string> */
    private static function ids(UsagePolicy $p): array
    {
        return array_map(static fn (RateLimit $l) => $l->id(), $p->rateLimits ?? []);
    }

    public function testThePlanPickedReplacesTheLimitsConcurrencyAndProvenance(): void
    {
        $policy = self::declared();

        self::assertSame(['free', 'pro', 'team'], $policy->plans?->names());
        self::assertNull($policy->plan);
        $free = $policy->forPlan(null);
        self::assertSame('free', $free->plan);
        self::assertNull($free->plans);
        self::assertSame(self::ids($policy), self::ids($free), 'the default plan is the policy\'s own limits');

        $pro = $policy->forPlan('pro');
        self::assertSame([], $pro->rateLimits, 'a plan with no cap');
        self::assertSame(10, $pro->concurrency);
        self::assertSame(['https://provider.example.com/terms', 'https://provider.example.com/plans', '2026-11-10'], [$pro->provenance->terms, $pro->provenance->policy, $pro->provenance->verifiedAt]);
        self::assertSame('Plan "pro", read at https://provider.example.com/plans on 2026-11-10. Pay as you go: no cap. Terms read.', $pro->provenance->notes);
        self::assertEquals($policy->rights, $pro->rights, 'rights stay the provider\'s');

        $team = $policy->forPlan('team');
        self::assertSame(self::ids($policy), self::ids($team), 'a plan naming no limits keeps the policy\'s');
        self::assertSame(5, $team->concurrency);
        self::assertSame('2026-11-09', $team->provenance->verifiedAt);

        self::assertSame($pro, $pro->forPlan('pro'), 'a policy run under a plan gives itself back for that plan');
        self::assertSame($pro, $pro->forPlan(null));
        self::assertEquals($pro, UsagePolicy::fromJson($pro->toJson()), 'the plan run is written back');
        self::assertEquals($policy, UsagePolicy::fromJson($policy->toJson()), 'the plans are written back');
    }

    public function testASiteTightensThePlanItRunsAndNeverLoosensIt(): void
    {
        $pro = self::declared()->forPlan('pro');

        self::assertCount(1, $pro->tighten(['rateLimits' => [['max' => 2000000, 'window' => 'month', 'unit' => 'characters', 'operations' => ['translate']]]])->rateLimits ?? []);
        self::assertSame(3, $pro->tighten(['concurrency' => 3])->concurrency);
        self::assertSame('pro', $pro->tighten(['concurrency' => 3])->plan);
        self::assertSame('free', self::declared()->tighten(['concurrency' => 1])->plan, 'tightening a declaration tightens its default plan');
        try {
            $pro->tighten(['concurrency' => 20]);
            self::fail('A site raised its plan\'s concurrency.');
        } catch (InvalidPolicyException $e) {
            self::assertStringContainsString('concurrency is 10 in flight', $e->getMessage());
        }
        $this->expectException(InvalidPolicyException::class);
        self::declared()->forPlan(null)->tighten(['rateLimits' => [['max' => 900000, 'window' => 'month', 'unit' => 'characters', 'operations' => ['translate']]]]);
    }

    public function testAnUnknownPlanIsRefused(): void
    {
        foreach ([
            [self::declared(), 'enterprise', 'No "enterprise" plan; declared: free, pro, team.'],
            [UsagePolicy::unknown('plain'), 'pro', 'plain declares no plans: no "pro" plan.'],
            [self::declared()->forPlan('pro'), 'team', 'translator already runs the "pro" plan, not "team".'],
        ] as [$policy, $plan, $message]) {
            try {
                $policy->forPlan($plan);
                self::fail('The "'.$plan.'" plan was accepted.');
            } catch (InvalidPolicyException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
        self::assertSame($policy = UsagePolicy::unknown('plain'), $policy->forPlan(null), 'a policy without plans runs as it is');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badPlans(): iterable
    {
        $limits = [['max' => 10, 'window' => 'minute']];
        $plan = ['rateLimits' => $limits, 'source' => 'https://x.example', 'verifiedAt' => '2026-11-10'];
        yield 'no source' => [['default' => 'free', 'plans' => ['free' => ['verifiedAt' => '2026-11-10']]], '"plans.plans.free.source" must be where the plan\'s limits are published'];
        yield 'no date' => [['default' => 'free', 'plans' => ['free' => ['source' => 'https://x.example']]], '"plans.plans.free.verifiedAt" must be a date'];
        yield 'a default not declared' => [['default' => 'gold', 'plans' => ['free' => $plan]], 'The default plan "gold" is not among the plans declared: free.'];
        yield 'no concurrency of 0' => [['default' => 'free', 'plans' => ['free' => ['concurrency' => 0] + $plan]], '"plans.plans.free.concurrency" must be an integer of 1 or more'];
        yield 'an unknown key' => [['default' => 'free', 'plans' => ['free' => ['price' => 9] + $plan]], 'has no "price"'];
        yield 'top-level limits other than the default\'s' => [['default' => 'free', 'plans' => ['free' => ['rateLimits' => [['max' => 20, 'window' => 'minute']]] + $plan]], 'rateLimits must be its default plan\'s ("free"): 10 a minute declared, the plan says 20 a minute.'];
        yield 'a concurrency other than the default\'s' => [['default' => 'free', 'plans' => ['free' => ['concurrency' => 4] + $plan]], 'concurrency must be its default plan\'s ("free"): 2 declared, the plan says 4.'];
    }

    /** @param array<string, mixed> $plans */
    #[DataProvider('badPlans')]
    public function testPlansOutsideTheSchemaAreRefused(array $plans, string $message): void
    {
        $this->expectException(InvalidPolicyException::class);
        $this->expectExceptionMessage($message);

        UsagePolicy::fromArray(['provider' => 'x', 'rateLimits' => [['max' => 10, 'window' => 'minute']], 'concurrency' => 2, 'plans' => $plans]);
    }

    public function testThePlansAreBuiltInPhpAndExportedFromThePolicyOrASeparateFile(): void
    {
        $built = UsagePolicy::builder('crawler')->limit(RateLimit::perMinute(10, 'fetch'))->concurrency(2)
            ->plans(new Plans('free', ['free' => new Plan('https://provider.example.com/limits', '2026-11-10', [RateLimit::perMinute(10, 'fetch')], 2), 'hobby' => new Plan('https://provider.example.com/limits', '2026-11-10', [RateLimit::perMinute(100, 'fetch')], 5)]))
            ->build();
        self::assertSame(['100/minute/application/fetch'], self::ids($built->forPlan('hobby')));

        $entries = (new PolicyExport())->folders(__DIR__.'/../Fixtures/planned', __DIR__.'/../Fixtures/planned-separate')->entries();
        self::assertSame(['example/planned', 'example/planned-separate'], array_column($entries, 'package'));
        self::assertSame(self::declared()->toArray(), $entries[0]['policy'] ?? null);
        self::assertSame($built->toArray()['plans'], $entries[1]['policy']['plans'] ?? null, 'extra.omnisense.plans puts a separate file in the policy');
        self::assertArrayNotHasKey('plans', UsagePolicy::unknown('x')->toArray(), 'a policy without plans writes what it always did');
        self::assertArrayNotHasKey('retention', UsagePolicy::unknown('x')->rights->toArray());
    }

    public function testRetentionIsReadTightenedOnlyDownwardsAndExported(): void
    {
        $policy = self::declared();
        $retention = $policy->rights->retention;

        self::assertEquals(new Retention(2592000, 'texts sent and their translations'), $retention);
        self::assertEquals(Retention::days(30, 'texts sent and their translations'), $retention);
        self::assertNotNull($retention);
        self::assertTrue($retention->exceededBy(2592001));
        self::assertSame(['maxAge' => 2592000, 'covers' => 'texts sent and their translations'], $policy->rights->toArray()['retention'] ?? null);
        $shorter = $policy->tighten(['rights' => ['retention' => ['maxAge' => 86400]]])->rights->retention;
        self::assertSame([86400, 'texts sent and their translations'], [$shorter?->maxAge, $shorter?->covers], 'shorter, what it covers kept');
        self::assertSame(3600, UsagePolicy::unknown('x')->tighten(['rights' => ['retention' => ['maxAge' => 3600]]])->rights->retention?->maxAge, 'a site may set one where none is declared');
        self::assertSame(3600, UsagePolicy::builder('x')->retention(new Retention(3600))->build()->rights->retention?->maxAge);

        foreach ([
            [['maxAge' => 7776000], 'its retention is 2592000 s, the configuration asks 7776000 s'],
            [null, 'its retention is 2592000 s, the configuration asks no maximum'],
            [['maxAge' => 60, 'covers' => 'nothing'], 'its retention scope is "texts sent and their translations", the configuration asks "nothing"'],
        ] as [$site, $message]) {
            try {
                $policy->tighten(['rights' => ['retention' => $site]]);
                self::fail('A site loosened the retention: '.json_encode($site));
            } catch (InvalidPolicyException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testDescribeWritesTheArticleEachWindowTakes(): void
    {
        self::assertSame(['1 a second', '1 a minute', '1200 an hour', '1 a day', '1 a month', '5000 every 2 hours'], [
            RateLimit::perSecond(1)->describe(), RateLimit::perMinute(1)->describe(), RateLimit::perHour(1200)->describe(),
            RateLimit::perDay(1)->describe(), RateLimit::perMonth(1)->describe(), (new RateLimit(5000, Window::Hour, every: 2))->describe(),
        ]);
    }
}
