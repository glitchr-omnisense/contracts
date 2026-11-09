<?php

namespace Omnisense\Contracts\Tests\Policy;

use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\Permission;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A site's configuration may tighten a provider's policy, never loosen it. */
final class TightenTest extends TestCase
{
    private static function declared(): UsagePolicy
    {
        return UsagePolicy::fromFile(__DIR__.'/../Fixtures/transit.json');
    }

    public function testALowerLimitAShorterTimeToLiveAndStricterRightsAreKept(): void
    {
        $site = self::declared()->tighten([
            'rateLimits' => [['max' => 600, 'window' => 'day', 'operations' => ['journeys']], ['max' => 100, 'window' => 'hour']],
            'rights' => ['caching' => ['maxTtl' => 60], 'storage' => 'forbidden', 'attribution' => ['where' => ['credits']]],
            'requirements' => ['key' => true],
        ]);

        self::assertSame(['720/day/application/journeys', '5/second/application/*', '600/day/application/journeys', '100/hour/application/*'], array_map(static fn ($l) => $l->id(), $site->rateLimits ?? []));
        self::assertCount(4, $site->limitsFor('journeys'), 'every limit counts: the strictest wins');
        self::assertSame(60, $site->rights->caching->maxTtl);
        self::assertSame(Permission::Forbidden, $site->rights->storage);
        self::assertSame(['credits', 'display'], $site->rights->attribution->where);
        self::assertTrue($site->requirements->key);
        self::assertEquals(self::declared()->provenance, $site->provenance);
        self::assertEquals(self::declared(), self::declared()->tighten([]));
    }

    public function testLimitsUnknownTakeTheSitesOwn(): void
    {
        $site = UsagePolicy::unknown('x')->tighten(['rateLimits' => [['max' => 600, 'window' => 'day']]]);

        self::assertSame(['600/day/application/*'], array_map(static fn ($l) => $l->id(), $site->rateLimits ?? []));
        self::assertNull(UsagePolicy::unknown('x')->tighten(['rights' => ['storage' => 'forbidden']])->rateLimits);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function looser(): iterable
    {
        yield 'a higher maximum for the same count' => [['rateLimits' => [['max' => 1000, 'window' => 'day', 'operations' => ['journeys']]]], 'rate limit is 720 a day for journeys, the configuration asks 1000 a day for journeys'];
        yield 'a longer time to live' => [['rights' => ['caching' => ['maxTtl' => 86400]]], 'cache time to live is 3600 s'];
        yield 'no maximum time to live' => [['rights' => ['caching' => ['maxTtl' => null]]], 'cache time to live is 3600 s, the configuration asks no maximum'];
        yield 'commercial use allowed' => [['rights' => ['commercial' => 'allowed']], 'commercial use is forbidden, the configuration asks allowed'];
        yield 'redistribution unknown again' => [['rights' => ['redistribution' => 'unknown']], 'redistribution is forbidden'];
        yield 'open source no longer required' => [['rights' => ['openSourceRequired' => false]], 'open-source requirement'];
        yield 'the attribution dropped' => [['rights' => ['attribution' => ['required' => false]]], 'attribution is required'];
        yield 'the attribution rewritten' => [['rights' => ['attribution' => ['text' => 'Us']]], 'attribution text is "Transit and its sources"'];
        yield 'another licence' => [['rights' => ['licence' => 'CC0-1.0']], 'licence is "ODbL-1.0"'];
        yield 'the User-Agent no longer required' => [['requirements' => ['userAgent' => ['required' => false]]], 'User-Agent requirement'];
        yield 'another User-Agent pattern' => [['requirements' => ['userAgent' => ['pattern' => '~.~']]], 'User-Agent pattern'];
    }

    /** @param array<string, mixed> $site */
    #[DataProvider('looser')]
    public function testALooserValueIsRefused(array $site, string $message): void
    {
        $this->expectException(InvalidPolicyException::class);
        $this->expectExceptionMessage($message);

        self::declared()->tighten($site);
    }

    public function testUnknownBecomesNoAllowanceBySite(): void
    {
        $this->expectExceptionMessage('storage is unknown, the configuration asks allowed');

        self::declared()->tighten(['rights' => ['storage' => 'allowed']]);
    }

    public function testASiteCannotRewriteWhereThePolicyComesFrom(): void
    {
        $this->expectException(InvalidPolicyException::class);
        $this->expectExceptionMessage('"provenance" must be absent');

        self::declared()->tighten(['provenance' => ['declaredBy' => 'site']]);
    }
}
