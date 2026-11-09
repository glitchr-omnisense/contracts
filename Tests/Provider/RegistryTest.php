<?php

namespace Omnisense\Contracts\Tests\Provider;

use Omnisense\Contracts\Exception\InvalidConfigException;
use Omnisense\Contracts\Exception\NotSupportedException;
use Omnisense\Contracts\Policy\Backoff;
use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\Permission;
use Omnisense\Contracts\Policy\RateLimit;
use Omnisense\Contracts\Policy\UsageLimitReached;
use Omnisense\Contracts\Policy\UsageNotPermitted;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnisense\Contracts\Provider\CapabilitiesInterface;
use Omnisense\Contracts\Provider\Config;
use Omnisense\Contracts\Provider\ProviderFactory;
use Omnisense\Contracts\Provider\ProviderInterface;
use Omnisense\Contracts\Provider\Registry;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
    public function testAProviderIsBuiltOnceWithItsNameItsLimiterAndItsBackoff(): void
    {
        $factory = new EchoFactory();
        $registry = new EchoRegistry([$factory], ['echo' => ['factory' => 'echo', 'options' => ['word' => 'hi']]]);

        $provider = $registry->get('echo');
        self::assertSame($provider, $registry->get('echo'));
        self::assertInstanceOf(EchoProvider::class, $provider);
        self::assertSame('hi', $provider->config->string('word'));
        self::assertSame('echo', $provider->config->string('echo.name'));
        self::assertInstanceOf(InMemoryRateLimiter::class, $provider->config->get('echo.limiter'));
        self::assertInstanceOf(Backoff::class, $provider->config->backoff());
        self::assertSame(['echo'], $registry->names('echoes'));
        self::assertSame([], $registry->names('other'));
        self::assertSame(['echo'], $registry->factories());
    }

    public function testCallsThroughTheGuardCountUnderThePolicyTheSiteTightened(): void
    {
        $registry = new EchoRegistry([new EchoFactory()], ['echo' => ['factory' => 'echo', 'policy' => ['rateLimits' => [['max' => 2, 'window' => 'day', 'operations' => ['say']]]]]]);

        self::assertSame(['10/day/application/say', '2/day/application/say'], array_map(static fn (RateLimit $l) => $l->id(), $registry->policy('echo')->rateLimits ?? []));
        self::assertSame('a', $registry->say('echo', 'a'));
        self::assertSame('b', $registry->say('echo', 'b'));
        try {
            $registry->say('echo', 'c');
            self::fail('The site\'s limit let a third call through.');
        } catch (UsageLimitReached $e) {
            self::assertSame('2/day/application/say', $e->limit?->id());
        }
        self::assertSame(['echo' => $registry->policy('echo')], $registry->policies());
    }

    public function testASiteCannotLoosenTheProvidersLimit(): void
    {
        $registry = new EchoRegistry([new EchoFactory()], ['echo' => ['factory' => 'echo', 'options' => ['policy' => ['rateLimits' => [['max' => 50, 'window' => 'day', 'operations' => ['say']]]]]]]);

        $this->expectException(InvalidPolicyException::class);
        $this->expectExceptionMessage('A site may only tighten echo\'s usage policy: its rate limit is 10 a day for say, the configuration asks 50 a day for say.');
        $registry->say('echo', 'x');
    }

    public function testAUseTheTermsForbidFailsAtOnce(): void
    {
        $registry = new EchoRegistry([new EchoFactory()], ['echo' => ['factory' => 'echo', 'usage' => ['commercial' => true]]]);

        $this->expectException(UsageNotPermitted::class);
        $this->expectExceptionMessage('echo\'s usage policy does not allow this site: commercial use is forbidden.');
        $registry->policy('echo');
    }

    public function testAContractNotKeptAndAnUnknownNameAreTheFamilysErrors(): void
    {
        $registry = new EchoRegistry([new EchoFactory()], ['echo' => ['factory' => 'echo'], 'lost' => ['factory' => 'nowhere']]);

        try {
            $registry->shout('echo');
            self::fail('A contract the provider does not keep was given.');
        } catch (NotSupportedException $e) {
            self::assertStringContainsString('does not shout', $e->getMessage());
        }
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('No "nowhere" factory for the "lost" provider; installed: echo.');
        $registry->get('lost');
    }

    public function testARequiredOptionLeftEmptyIsNamed(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('The "echo" provider needs: word.');
        (new EchoFactory(['word']))->create();
    }
}

interface ShoutInterface extends ProviderInterface
{
}

final class EchoRegistry extends Registry
{
    public const PREFIX = 'echo';

    public function say(string $name, string $word): string
    {
        $provider = $this->contract($name, EchoProvider::class, 'say');

        return $this->guard($name)->call('say', static fn () => $provider->say($word));
    }

    public function shout(string $name): ShoutInterface
    {
        return $this->contract($name, ShoutInterface::class, 'shout');
    }
}

final class EchoConfig extends Config
{
    public const PREFIX = 'echo';
}

final class EchoFactory extends ProviderFactory
{
    /** @param list<string> $required */
    public function __construct(private readonly array $required = [])
    {
    }

    protected function newConfig(array $options): Config
    {
        return new EchoConfig($options);
    }

    protected function configure(Config $c): void
    {
        $c->defaults(['echo.factory_name' => 'echo', 'echo.factory_title' => 'Echo', 'echo.required_options' => $this->required]);
    }

    protected function make(Config $c): ProviderInterface
    {
        return new EchoProvider($c);
    }
}

final class EchoProvider implements ProviderInterface
{
    public function __construct(public readonly Config $config)
    {
    }

    public function getName(): string
    {
        return 'echo';
    }

    public function getTitle(): string
    {
        return 'Echo';
    }

    public function say(string $word): string
    {
        return $word;
    }

    public function capabilities(): CapabilitiesInterface
    {
        return new EchoCapabilities();
    }
}

final readonly class EchoCapabilities implements CapabilitiesInterface
{
    public function contracts(): array
    {
        return ['echoes'];
    }

    public function policy(): UsagePolicy
    {
        return UsagePolicy::builder('echo')->limit(RateLimit::perDay(10, 'say'))->commercial(Permission::Forbidden)->build();
    }

    public function toArray(): array
    {
        return ['contracts' => $this->contracts()];
    }
}
