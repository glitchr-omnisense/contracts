# omnisense/contracts

The contracts every omnisense provider family builds on, under any PHP host - plain PHP,
Symfony, Laravel. A family (omnitravel, omnishow, omnistay...) is a core that holds its own
contracts and models, plus one package per provider. The scaffolding they all share lives
here, once:

| Namespace | What | Kind |
|---|---|---|
| `Omnisense\Contracts\Provider\` | `ProviderInterface`, `CapabilitiesInterface`, `ProviderFactoryInterface`; `Config`, `ProviderFactory`, `Registry` | interfaces first, small bases |
| `Omnisense\Contracts\Http\` | `JsonClient` (JSON, binary and raw calls, 404 read as null, an empty body as an empty Data, credentials kept out of every error), `Answer`, `Data`, `Redaction` | small implementations |
| `Omnisense\Contracts\Policy\` | `UsagePolicy` and its parts, `PolicyBuilder`, the JSON Schema; `PolicyGuard`, `Backoff`, `RateLimiterInterface`, `InMemoryRateLimiter`, `UsageLimitReached`; `RateLimitHeaders`; `PolicyExport` | value objects, one guard |
| `Omnisense\Contracts\Testing\` | `RecordedHttpClient` (streams, routes by body), `RecordedResponse`, `MockHttp` (over Symfony's MockHttpClient), `BareTestCase` | test support |
| `Omnisense\Contracts\Exception\` | `ExceptionInterface` (a family's exception interface extends it) and the generic exceptions | |

It requires PHP 8.2 and `symfony/http-client-contracts` (interfaces only), nothing else.
Framework glue lives in the bridges (omnibridge/symfony, omnibridge/laravel).

```
composer require omnisense/contracts
```

## Usage policies

Every provider declares how it may be used, as data, and every call is held to it:

- **rate limits**: a maximum per window (second, minute, hour, day, month), per scope
  (application, key, client IP, user), for some operations or all of them;
- **rights**: commercial use (allowed, by contract, forbidden), open source required, caching
  (allowed up to a time to live, or not), storage and redistribution of the data, the
  attribution (text, link, where it is shown), the data's licence;
- **request requirements**: a key, a User-Agent (with a contact, by a pattern);
- **provenance**: the terms and the policy it was read from, the day it was checked, who
  declared it (the package's maintainer, the provider itself, or the site for per-source data),
  whom to ask.

A provider package ships `policy.json` beside its code, valid against
[`resources/usage-policy.schema.json`](resources/usage-policy.schema.json), and names it in its
`composer.json` (`"extra": {"omnisense": {"policy": "policy.json"}}`). A site's configuration
may **tighten** it - a lower limit, a shorter time to live, a right taken away - and never
loosen it: `UsagePolicy::tighten()` throws on any looser value. The family's registry wraps
each call in a `PolicyGuard`: checked before, counted after, refused with `UsageLimitReached`
(and its `retryAfter`) when a limit is reached or while the provider asks to wait (a 429, its
`Retry-After`, a `RateLimit` field with nothing left).

```php
use Omnisense\Contracts\Policy\PolicyGuard;
use Omnisense\Contracts\Policy\UsagePolicy;

$policy = UsagePolicy::fromFile('vendor/omnitravel/transitous/policy.json')
    ->tighten(['rateLimits' => [['max' => 600, 'window' => 'day', 'operations' => ['journeys']]]]);
$guard = new PolicyGuard($policy);              // an in-memory limiter by default
$journeys = $guard->call('journeys', fn () => $planner->journeys($query));
$guard->cacheTtl(3600);                         // the time to live the terms allow
```

`vendor/bin/omnisense-policies` prints every declared policy of an application (or of package
folders) as one JSON document.

## Documentation

- [docs/index.md](docs/index.md): the kernel, plain PHP first
- [docs/policies.md](docs/policies.md): the policy schema, the merge and enforcement rules, the headers read
- [docs/declare-your-policy.md](docs/declare-your-policy.md): for a provider package's maintainer
- [docs/set-your-limits.md](docs/set-your-limits.md): for the company that runs an API
- [docs/families.md](docs/families.md): building a family on the kernel

## Licence

MIT
