# omnisense/contracts

The kernel of every omnisense provider family, usable in plain PHP first.

## In plain PHP

A family's core extends the bases. The smallest family:

```php
use Omnisense\Contracts\Provider\{CapabilitiesInterface, Config, ProviderFactory, ProviderInterface, Registry};
use Omnisense\Contracts\Policy\{RateLimit, UsagePolicy};

final class EchoFactory extends ProviderFactory
{
    protected function configure(Config $c): void
    {
        $c->defaults(['provider.factory_name' => 'echo', 'provider.factory_title' => 'Echo', 'provider.required_options' => []]);
    }

    protected function make(Config $c): ProviderInterface
    {
        return new EchoProvider();   // its JsonClient would take $c->backoff()
    }
}

$registry = new Registry([new EchoFactory()], [
    'echo' => ['factory' => 'echo', 'policy' => ['rateLimits' => [['max' => 100, 'window' => 'day']]]],
]);
$registry->guard('echo')->call('say', fn () => $registry->get('echo')->say('hi'));
```

- `Provider\Config` holds the options; the keys a factory and the registry fill carry the
  family's prefix (`Config::PREFIX`, `"provider"` here, `"omnitravel"` in omnitravel):
  `<prefix>.factory_name`, `.factory_title`, `.required_options`, `.name`, `.limiter`,
  `.backoff`. Every provider also takes `policy` (the site's tightening) and `usage` (what the
  site says of itself).
- `Provider\ProviderFactory::create()` checks the required options, gives the Config the
  provider's `Backoff` (over the registry's limiter), builds it (`make()`), and keeps its
  `PolicyGuard` for `guard()`, built on first use.
- `Provider\Registry` builds each configured provider once, its guard with it; `contract()`
  gives a provider keeping a contract or the family's NotSupportedException; `policy()`,
  `policies()` give the policies as the site runs them.
- `Http\JsonClient` calls the provider; every failure leaves without its credentials
  (`Redaction`). Given a `Backoff`, it honours 429s, `Retry-After` and `RateLimit`. An empty
  body (204, 304) is an empty `Data`; `decode()` is the hook for a body read otherwise.
  `raw()` makes any other call (XML, an ETag to read, a 412 to pass) and gives an
  `Http\Answer`: status, lower-case headers, body, and the URL that answered after redirects.
  A header may carry several values (`['Prefer' => ['a', 'b']]`).
- `Testing\RecordedHttpClient::routes()` answers by pattern, headers included (a 429's
  `Retry-After`), and by request body when a route gives a fourth pattern; it streams
  (`stream()`), so it can sit under Symfony's PSR-18 bridge. `Testing\MockHttp` is the same
  over Symfony's `MockHttpClient` (symfony/http-client, suggested), every request kept as it
  went on the wire (headers, body, `json()`, `fields()`, `dump()`). `Testing\BareTestCase` loads every class of a package in a process of its own
  and checks nothing outside it, the kernel and its requirements was loaded.

## Under a framework

Nothing here knows one. omnibridge/symfony brings the Symfony glue: a `RateLimiterInterface`
over symfony/rate-limiter, so limits count across processes; the container wiring of a
family's registry.

See [policies.md](policies.md), [declare-your-policy.md](declare-your-policy.md),
[set-your-limits.md](set-your-limits.md), [families.md](families.md).
