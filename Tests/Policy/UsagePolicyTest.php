<?php

namespace Omnisense\Contracts\Tests\Policy;

use Omnisense\Contracts\Policy\Caching;
use Omnisense\Contracts\Policy\DeclaredBy;
use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\Permission;
use Omnisense\Contracts\Policy\PolicyExport;
use Omnisense\Contracts\Policy\RateLimit;
use Omnisense\Contracts\Policy\Retention;
use Omnisense\Contracts\Policy\Rights;
use Omnisense\Contracts\Policy\Requirements;
use Omnisense\Contracts\Policy\Scope;
use Omnisense\Contracts\Policy\Usage;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnisense\Contracts\Policy\Window;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UsagePolicyTest extends TestCase
{
    public function testAPolicyIsReadFromItsJsonAndWrittenBackTheSame(): void
    {
        $policy = UsagePolicy::fromFile(__DIR__.'/../Fixtures/transit.json');

        self::assertSame('transit', $policy->provider);
        self::assertTrue($policy->knowsLimits());
        self::assertCount(2, $policy->limitsFor('journeys'));
        self::assertCount(1, $policy->limitsFor('departures'));
        self::assertSame('720/day/application/journeys', $policy->limitsFor('journeys')[0]->id());
        self::assertSame(Permission::Forbidden, $policy->rights->commercial);
        self::assertTrue($policy->rights->openSourceRequired);
        self::assertSame(3600, $policy->rights->caching->maxTtl);
        self::assertSame(['display'], $policy->rights->attribution->where);
        self::assertTrue($policy->requirements->acceptsUserAgent('MySite/1.0 (https://example.org)'));
        self::assertFalse($policy->requirements->acceptsUserAgent('MySite'));
        self::assertFalse($policy->requirements->acceptsUserAgent(null));
        self::assertSame(DeclaredBy::Maintainer, $policy->provenance->declaredBy);
        self::assertEquals($policy, UsagePolicy::fromJson($policy->toJson()));
    }

    public function testTheBuilderWritesTheSamePolicy(): void
    {
        $built = UsagePolicy::builder('transit')
            ->limit(new RateLimit(720, Window::Day, Scope::Application, ['journeys'], 'More is heavy use: ask first.'), RateLimit::perSecond(5))
            ->commercial(Permission::Forbidden)->openSourceRequired()
            ->caching(Caching::upTo(3600))->redistribution(Permission::Forbidden)
            ->attribution('Transit and its sources', 'https://transit.example/sources/')
            ->licence('ODbL-1.0')
            ->requireUserAgent(Requirements::USER_AGENT_WITH_CONTACT)
            ->provenance('https://transit.example/api/', 'https://transit.example/api/#policy', '2026-11-10', DeclaredBy::Maintainer, 'api@transit.example')
            ->build();

        self::assertEquals(UsagePolicy::fromFile(__DIR__.'/../Fixtures/transit.json'), $built);
        self::assertNull(UsagePolicy::builder('x')->build()->rateLimits, 'no limit() call: the limits are unknown');
        self::assertSame([], UsagePolicy::builder('x')->noLimits()->build()->rateLimits, 'noLimits(): the terms set none');
    }

    public function testAnOlderCapabilitiesTextBecomesAPolicyWithUnknownLimits(): void
    {
        $policy = UsagePolicy::described('navitia', 'Navitia (https://navitia.io)', 'ODbL-1.0', ['https://doc.navitia.io/']);

        self::assertNull($policy->rateLimits);
        self::assertTrue($policy->rights->attribution->required);
        self::assertSame('Navitia (https://navitia.io)', $policy->rights->attribution->text);
        self::assertTrue($policy->requirements->key);
        self::assertSame('https://doc.navitia.io/', $policy->provenance->terms);
        self::assertFalse(UsagePolicy::described('gtfs', keyless: true)->requirements->key);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'no provider' => [[], '"provider" must be a name'];
        yield 'a typo' => [['provider' => 'x', 'rateLimit' => []], 'has no "rateLimit"'];
        yield 'a window unknown' => [['provider' => 'x', 'rateLimits' => [['max' => 1, 'window' => 'week']]], '"rateLimits[0].window" must be one of'];
        yield 'no window' => [['provider' => 'x', 'rateLimits' => [['max' => 1]]], '"rateLimits[0].window" must be a window'];
        yield 'a negative maximum' => [['provider' => 'x', 'rateLimits' => [['max' => -1, 'window' => 'day']]], 'an integer of 0 or more'];
        yield 'a permission unknown' => [['provider' => 'x', 'rights' => ['commercial' => 'maybe']], '"rights.commercial" must be one of'];
        yield 'a place unknown' => [['provider' => 'x', 'rights' => ['attribution' => ['where' => ['footer']]]], '"rights.attribution.where" must be one of'];
        yield 'a date that is not one' => [['provider' => 'x', 'provenance' => ['verifiedAt' => '10/11/2026']], 'YYYY-MM-DD'];
        yield 'a pattern that is not one' => [['provider' => 'x', 'requirements' => ['userAgent' => ['pattern' => '(']]], 'a regular expression'];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('invalid')]
    public function testAPolicyOutsideTheSchemaIsRefusedByItsPath(array $data, string $message): void
    {
        $this->expectException(InvalidPolicyException::class);
        $this->expectExceptionMessage($message);

        UsagePolicy::fromArray($data);
    }

    public function testTheSchemaNamesWhatThePolicyReads(): void
    {
        $schema = json_decode((string) file_get_contents(UsagePolicy::SCHEMA), true);

        self::assertSame(array_map(static fn (Permission $p) => $p->value, Permission::cases()), self::at($schema, '$defs', 'permission', 'enum'));
        self::assertSame(array_map(static fn (Window $w) => $w->value, Window::cases()), self::at($schema, '$defs', 'rateLimit', 'properties', 'window', 'enum'));
        self::assertSame(array_map(static fn (Scope $s) => $s->value, Scope::cases()), self::at($schema, '$defs', 'rateLimit', 'properties', 'scope', 'enum'));
        self::assertSame(array_map(static fn (DeclaredBy $d) => $d->value, DeclaredBy::cases()), self::at($schema, 'properties', 'provenance', 'properties', 'declaredBy', 'enum'));
        $written = static function (array $keys): array {
            sort($keys);

            return $keys;
        };
        self::assertSame($written([...array_keys(UsagePolicy::unknown('x')->toArray()), 'plans', 'plan']), $written(array_values(array_diff(array_keys(self::at($schema, 'properties')), ['$schema']))), 'plans and plan are written only when set');
        self::assertSame(array_keys((new Rights(retention: new Retention(1)))->toArray()), array_keys(self::at($schema, 'properties', 'rights', 'properties')));
        self::assertSame(array_keys(UsagePolicy::unknown('x')->provenance->toArray()), array_keys(self::at($schema, 'properties', 'provenance', 'properties')));
        self::assertSame(array_keys(RateLimit::perDay(1)->toArray()), array_keys(self::at($schema, '$defs', 'rateLimit', 'properties')));
    }

    /** @return array<mixed> */
    private static function at(mixed $value, string ...$keys): array
    {
        foreach ($keys as $key) {
            self::assertIsArray($value);
            $value = $value[$key] ?? null;
        }
        self::assertIsArray($value);

        return $value;
    }

    public function testASiteThatSaysWhatItIsLearnsWhatTheTermsForbid(): void
    {
        $policy = UsagePolicy::fromFile(__DIR__.'/../Fixtures/transit.json');

        self::assertSame(['commercial use is forbidden', 'the application must be open source'], $policy->violations(new Usage(commercial: true, openSource: false)));
        self::assertSame([], $policy->violations(new Usage(commercial: false, openSource: true)));
        self::assertSame([], $policy->violations(new Usage()), 'unsaid, nothing is checked');
        $contract = UsagePolicy::builder('x')->commercial(Permission::Contract)->build();
        self::assertSame(['commercial use needs a contract with the provider'], $contract->violations(new Usage(commercial: true)));
        self::assertSame([], $contract->violations(new Usage(commercial: true, contract: true)));
    }

    public function testEveryDeclaredPolicyIsExportedWithItsPackage(): void
    {
        $export = (new PolicyExport())->folders(__DIR__.'/../Fixtures/provider-a', __DIR__.'/../Fixtures/provider-b', __DIR__.'/../Fixtures/none');
        $entries = $export->entries();

        self::assertSame(['family/provider-a', 'family/provider-b'], array_column($entries, 'package'));
        self::assertSame('transit', $entries[0]['policy']['provider'] ?? null);
        self::assertStringContainsString('rateLimits[0].window', $entries[1]['error'] ?? '');
        $json = json_decode($export->toJson(), true);
        self::assertIsArray($json);
        self::assertCount(2, self::at($json, 'policies'));

        $installed = sys_get_temp_dir().'/omnisense-installed-'.getmypid();
        @mkdir($installed.'/composer', 0777, true);
        file_put_contents($installed.'/composer/installed.json', json_encode(['packages' => [['name' => 'family/provider-a', 'version' => '1.2.0', 'install-path' => (string) realpath(__DIR__.'/../Fixtures/provider-a'), 'extra' => ['omnisense' => ['policy' => '../transit.json']]]]]));
        $fromVendor = (new PolicyExport())->installed($installed.'/composer/installed.json')->entries();
        self::assertSame('1.2.0', $fromVendor[0]['version'] ?? null);
        self::assertSame('transit', $fromVendor[0]['policy']['provider'] ?? null);
    }
}
