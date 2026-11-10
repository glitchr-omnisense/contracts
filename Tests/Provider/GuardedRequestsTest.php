<?php

namespace Omnisense\Contracts\Tests\Provider;

use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\PolicyGuard;
use Omnisense\Contracts\Policy\RateLimit;
use Omnisense\Contracts\Policy\UsageLimitReached;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnisense\Contracts\Provider\CapabilitiesInterface;
use Omnisense\Contracts\Provider\Config;
use Omnisense\Contracts\Provider\ProviderFactory;
use Omnisense\Contracts\Provider\ProviderFactoryInterface;
use Omnisense\Contracts\Provider\ProviderInterface;
use Omnisense\Contracts\Provider\Registry;
use PHPUnit\Framework\TestCase;

/**
 * What the registry's guard does not see, counted with it: a token request
 * and each further page of a lazily paged listing, through Config::guard();
 * the "plan" option; the family's exception from the registry's own guard.
 */
final class GuardedRequestsTest extends TestCase
{
    public function testEachPageFetchedLazilyCountsAndTheLimitStopsTheNextBeforeItIsFetched(): void
    {
        $registry = new PagesRegistry([new PagesFactory()], ['pages' => ['factory' => 'pages', 'options' => ['pages' => 5]]]);
        $provider = $registry->get('pages');
        self::assertInstanceOf(PagesProvider::class, $provider);

        $records = $registry->records('pages');
        self::assertSame([], $provider->fetched, 'nothing is fetched before the listing is read');
        $read = [];
        try {
            foreach ($records as $record) {
                $read[] = $record;
            }
            self::fail('A fourth page went past a limit of 3 requests a day.');
        } catch (FamilyLimitReached $e) {
            self::assertSame('3/day/application/records', $e->limit?->id());
        }
        self::assertSame([1, 2, 3], $provider->fetched, 'the first page counted by the registry\'s guard, the next two by the provider\'s; the fourth never sent');
        self::assertSame(['p1', 'p2', 'p3'], $read);
        self::assertSame(0, $registry->guard('pages')->remaining('records'), 'both guards count in the registry\'s limiter');
    }

    public function testATokenRequestCountsUnderItsOwnOperationAndThrowsTheFamilysException(): void
    {
        $provider = (new PagesFactory())->create(['pages' => 1]);
        self::assertInstanceOf(PagesProvider::class, $provider);

        self::assertSame('token-1', $provider->token());
        $this->expectException(FamilyLimitReached::class);
        $this->expectExceptionMessage('pages\'s usage policy allows 1 an hour for token: reached');
        $provider->token();
    }

    public function testTheProvidersGuardAndTheFactorysCountTogetherOutsideARegistry(): void
    {
        $factory = new PagesFactory();
        $provider = $factory->create(['pages' => 1]);
        self::assertInstanceOf(PagesProvider::class, $provider);
        $guard = $factory->guard($provider);
        self::assertInstanceOf(PolicyGuard::class, $guard);

        $provider->token();
        self::assertSame(0, $guard->remaining('token'), 'one limiter for the factory\'s guard and the provider\'s');
    }

    public function testThePlanOptionPicksTheLimitsTheSiteThenTightens(): void
    {
        $registry = new PagesRegistry([new PagesFactory()], [
            'free' => ['factory' => 'pages'],
            'pro' => ['factory' => 'pages', 'options' => ['plan' => 'pro']],
            'tight' => ['factory' => 'pages', 'options' => ['plan' => 'pro'], 'policy' => ['rateLimits' => [['max' => 50, 'window' => 'day', 'operations' => ['records']]]]],
            'loose' => ['factory' => 'pages', 'options' => ['plan' => 'pro'], 'policy' => ['rateLimits' => [['max' => 500, 'window' => 'day', 'operations' => ['records']]]]],
            'gold' => ['factory' => 'pages', 'options' => ['plan' => 'gold']],
        ]);

        $ids = static fn (UsagePolicy $p) => array_map(static fn (RateLimit $l) => $l->id(), $p->rateLimits ?? []);
        self::assertSame(['3/day/application/records', '1/hour/application/token'], $ids($registry->policy('free')));
        self::assertSame('free', $registry->policy('free')->plan);
        self::assertSame(['100/day/application/records', '1/hour/application/token'], $ids($registry->policy('pro')));
        self::assertSame(['100/day/application/records', '1/hour/application/token', '50/day/application/records'], $ids($registry->policy('tight')));
        $provider = $registry->get('pro');
        self::assertInstanceOf(PagesProvider::class, $provider);
        self::assertSame(100, $provider->pages->remaining('records'), 'the provider\'s guard runs the same plan');
        foreach (['loose' => 'its rate limit is 100 a day for records', 'gold' => 'No "gold" plan; declared: free, pro.'] as $name => $message) {
            try {
                $registry->policy($name);
                self::fail('The "'.$name.'" configuration was accepted.');
            } catch (InvalidPolicyException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testTheRegistrysOwnGuardThrowsTheFamilysException(): void
    {
        $registry = new PagesRegistry([new PlainFactory()], ['plain' => ['factory' => 'plain']]);

        $registry->guard('plain')->call('records', static fn () => null);
        $this->expectException(FamilyLimitReached::class);
        $registry->guard('plain')->call('records', static fn () => null);
    }
}

final class FamilyLimitReached extends UsageLimitReached
{
}

final class PagesRegistry extends Registry
{
    public const PREFIX = 'pages';

    /** @return iterable<string> */
    public function records(string $name): iterable
    {
        $provider = $this->contract($name, PagesProvider::class, 'list records');

        return $this->guard($name)->call('records', static fn () => $provider->records());
    }

    protected function limitException(): string
    {
        return FamilyLimitReached::class;
    }
}

final class PagesConfig extends Config
{
    public const PREFIX = 'pages';
}

final class PagesFactory extends ProviderFactory
{
    protected function newConfig(array $options): Config
    {
        return new PagesConfig($options);
    }

    protected function configure(Config $c): void
    {
        $c->defaults(['pages.factory_name' => 'pages', 'pages.factory_title' => 'Pages']);
    }

    protected function make(Config $c): ProviderInterface
    {
        return new PagesProvider($c->int('pages', 1), $c->guard(PagesProvider::policy()));
    }

    protected function limitException(): string
    {
        return FamilyLimitReached::class;
    }
}

final class PagesProvider implements ProviderInterface, DeclaresUsagePolicyInterface
{
    /** @var list<int> the pages fetched */
    public array $fetched = [];

    private int $tokens = 0;

    public function __construct(private readonly int $count, public readonly PolicyGuard $pages)
    {
    }

    public static function policy(): UsagePolicy
    {
        return UsagePolicy::fromArray([
            'provider' => 'pages',
            'rateLimits' => [['max' => 3, 'window' => 'day', 'operations' => ['records']], ['max' => 1, 'window' => 'hour', 'operations' => ['token']]],
            'plans' => ['default' => 'free', 'plans' => [
                'free' => ['rateLimits' => [['max' => 3, 'window' => 'day', 'operations' => ['records']], ['max' => 1, 'window' => 'hour', 'operations' => ['token']]], 'source' => 'https://pages.example.com/plans', 'verifiedAt' => '2026-11-10'],
                'pro' => ['rateLimits' => [['max' => 100, 'window' => 'day', 'operations' => ['records']], ['max' => 1, 'window' => 'hour', 'operations' => ['token']]], 'source' => 'https://pages.example.com/plans', 'verifiedAt' => '2026-11-10'],
            ]],
        ]);
    }

    public function usagePolicy(): UsagePolicy
    {
        return self::policy();
    }

    /**
     * Pages fetched as the listing is read: the first under the registry's
     * guard (its call() counted the operation once), each further one
     * reported to the provider's guard before it is sent.
     *
     * @return \Generator<int, string>
     */
    public function records(): \Generator
    {
        for ($page = 1; $page <= $this->count; ++$page) {
            $fetch = function () use ($page): string {
                $this->fetched[] = $page;

                return 'p'.$page;
            };
            yield 1 === $page ? $fetch() : $this->pages->call('records', $fetch);
        }
    }

    public function token(): string
    {
        return $this->pages->call('token', fn () => 'token-'.++$this->tokens);
    }

    public function getName(): string
    {
        return 'pages';
    }

    public function getTitle(): string
    {
        return 'Pages';
    }

    public function capabilities(): CapabilitiesInterface
    {
        return new PagesCapabilities();
    }
}

final readonly class PagesCapabilities implements CapabilitiesInterface
{
    public function contracts(): array
    {
        return ['records'];
    }

    public function policy(): UsagePolicy
    {
        return PagesProvider::policy();
    }

    public function toArray(): array
    {
        return ['contracts' => $this->contracts()];
    }
}

/** A factory that is not the kernel's: the registry builds the guard itself. */
final class PlainFactory implements ProviderFactoryInterface
{
    public function getName(): string
    {
        return 'plain';
    }

    public function create(array $options = []): ProviderInterface
    {
        return new PlainProvider();
    }
}

final class PlainProvider implements ProviderInterface, DeclaresUsagePolicyInterface
{
    public function usagePolicy(): UsagePolicy
    {
        return UsagePolicy::builder('plain')->limit(RateLimit::perDay(1, 'records'))->build();
    }

    public function getName(): string
    {
        return 'plain';
    }

    public function getTitle(): string
    {
        return 'Plain';
    }

    public function capabilities(): CapabilitiesInterface
    {
        return new PagesCapabilities();
    }
}
