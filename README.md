# omnisense/contracts

The contracts every omnisense provider family builds on, under any PHP host - plain PHP,
Symfony, Laravel. A family (omnitravel, omnishow, omnistay...) is a core that holds its own
contracts and models, plus one package per provider. The scaffolding they all share lives
here, once:

| Namespace | What | Kind |
|---|---|---|
| `Omnisense\Contracts\Provider\` | `ProviderInterface`, `CapabilitiesInterface`, `ProviderFactoryInterface`; `Config`, `ProviderFactory`, `Registry` | interfaces first, small bases |
| `Omnisense\Contracts\Http\` | `JsonClient` (JSON, binary and raw calls, 404 read as null, an empty body as an empty Data, credentials kept out of every error), `Answer`, `Data`, `Redaction`, `NoRetry` (a client kept from replaying a write) | small implementations |
| `Omnisense\Contracts\Policy\` | `UsagePolicy` and its parts, `PolicyBuilder`, the JSON Schema; `PolicyGuard`, `Backoff`, `RateLimiterInterface`, `InMemoryRateLimiter`, `UsageLimitReached`; `RateLimitHeaders`; `PolicyExport` | value objects, one guard |
| `Omnisense\Contracts\Testing\` | `RecordedHttpClient` (streams, routes by body), `RecordedResponse`, `MockHttp` (over Symfony's MockHttpClient), `BareTestCase` | test support |
| `Omnisense\Contracts\Exception\` | `ExceptionInterface` (a family's exception interface extends it) and the generic exceptions | |

It requires PHP 8.2 or later (tested on 8.2, 8.3, 8.4 and 8.5) and `symfony/http-client-contracts`
(interfaces only), nothing else. Framework glue lives in the bridges (omnibridge/symfony,
omnibridge/laravel).

```
composer require omnisense/contracts:^1.0
```

Limits are counted by a `RateLimiterInterface`. `InMemoryRateLimiter` counts within one PHP
process; a web application served by several processes gives a shared one. omnibridge/symfony's
`Omnibridge\Symfony\Policy\RateLimiter` (on its 1.x branch, over symfony/rate-limiter and a cache
pool) is one.

## Usage policies

Every provider declares how it may be used, as data, and every call is held to it:

- **rate limits**: a maximum per window (second, minute, hour, day, month, or several of them:
  500 every 5 minutes), per scope (application, key, client IP, user, or each resource of a
  kind: 6 trackings a day per parcel), for some operations or all of them, counted in requests
  or in the provider's unit (characters, units, points) with a cost per operation; the requests
  allowed at once; the figures of each plan the provider publishes;
- **rights**: commercial use (allowed, by contract, forbidden), open source required, caching
  (allowed up to a time to live, or not), storage and redistribution of the data, how long it
  may be kept at all, the attribution (text, link, where it is shown), the data's licence;
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

## Stability

From 1.0.0 the package follows [semantic versioning](https://semver.org): a 1.x minor adds,
it never breaks. `Tests/api-1.x.txt` lists the public surface that promise covers, and
`Tests/ApiSurfaceTest` fails on any change to it.

What 1.x keeps:

- **Interfaces a family implements**: `ProviderInterface`, `CapabilitiesInterface`,
  `ProviderFactoryInterface`, `DeclaresUsagePolicyInterface`, `RateLimiterInterface`,
  `ConsumingRateLimiterInterface`, `ExceptionInterface`. No method is added to them in 1.x.
  A new capability comes as a new interface that extends one of them, as
  `ConsumingRateLimiterInterface` extends `RateLimiterInterface`.
- **Classes a family extends**: `Config`, `ProviderFactory`, `Registry`, `JsonClient`, `Data`,
  `Redaction`, `UsageLimitReached`, the exceptions, `BareTestCase`, `RecordedHttpClient` and
  `RecordedResponse`. The signatures of their public and protected methods do not change, because
  a family overrides them (`get()`, `request()`, `raw()`, `bytes()`, `decode()`...). A minor
  may add a method, a constant or an optional constructor argument. As in Symfony's promise, a
  method a subclass adds under the same name may then clash with one, so name a family's own
  methods after the family. A subclass of `UsageLimitReached` keeps its
  `(message, retryAfter, provider)` constructor, because `Backoff` and `PolicyGuard` build it.
- **Classes a family calls** (the `final` and `readonly` classes, the enums): `PolicyGuard`,
  `Backoff`, `UsagePolicy` and its parts, `PolicyBuilder`, `RateLimitHeaders`, `NoRetry`,
  `MockHttp`... A minor may add methods, optional arguments and enum cases. Code that matches
  on an enum has a default arm.
- **Data**: every `policy.json` valid against 1.0's schema stays valid and reads the same in
  1.x, and the ids of its limits stay the same. A key added in a minor is optional, and its
  absence means what 1.0 did. A policy that uses the new key requires that minor.
- **Behaviour**: what docs/policies.md describes, including when a call is counted (checked
  before, recorded after it returns or throws).

What 1.x does not keep: whatever is marked `@internal` (`Policy\Read`, `Http\SentOnce`,
`Http\RetriedReads`, `Testing\RecordedChunk`, `Testing\RecordedStream`), the messages of
exceptions, the tests, and the dev tools in `require-dev`.

Upgrading from 1.0.0-rc.1: [UPGRADE-1.0.md](UPGRADE-1.0.md).

## Documentation

- [docs/index.md](docs/index.md): the kernel, plain PHP first
- [docs/policies.md](docs/policies.md): the policy schema, the merge and enforcement rules, the headers read
- [docs/declare-your-policy.md](docs/declare-your-policy.md): for a provider package's maintainer
- [docs/set-your-limits.md](docs/set-your-limits.md): for the company that runs an API
- [docs/families.md](docs/families.md): building a family on the kernel

## Licence

MIT
