# Building a family on the kernel

A family core keeps its contracts (its `*Interface` per capability), its models, its
Capabilities fields and its public names; the scaffolding comes from here, as far as it fits the
family. Every family now requires `omnisense/contracts`; they took four shapes.

## The shapes the families took

| Shape | Families | Registry and guard | HTTP |
|---|---|---|---|
| Providers on the kernel's registry | omnitravel (the model), omnishow, omnistay, omniplay, omnicrawl, omnilang, omnical, omnidns, omniticket, omnipulse; omnimap; omnishield, omniguard; omnibill, omnisign | the kernel's `Registry` and `ProviderFactory`; the typed accessors give a `Policy\Guarded<Contract>` per contract (omnibill and omnisign: the gateway itself, the caller going through `guard($name)->call()`) | `Http\JsonClient` (a thin subclass) with `$c->backoff()`; omnishield's `Http\Answer`; omnimap's providers keep their own calls |
| Payum-style gateways | omnibus, omnitrade, omnibank, omnimeet | the kernel's `Registry`; one `Gateway` guarded as a whole (`guardedBy(PolicyGuard)`, operation names in `Gateway::OPERATIONS`), `Registry::guarded($name)` | a per-provider `Api` over the family's `Http\Throttle` (`Backoff::clear()` before each call, `observe()` after) |
| Registries of their own | omnistate (`Policy\Guards`); omnifood, omnipost, omnisong; omnilab, omnilex, omnischolar (`Source\HttpSource`, `Policy\SourceGuard`) | the family's registry, a `PolicyGuard` per provider from its declared policy, `Guarded*` wrappers | the family's client, given the provider's `Backoff` |
| Where it fits | omniauth, omnisearch, omnidrive | none: their docs say why (a sign-in is never counted against a usage limit; a self-hosted engine declares none; a drive's published figures are per prefix or per blob) | omniauth's providers and omnidrive's engines through their libraries |

Every shape keeps: one `ExceptionInterface` above the family's exceptions; a policy per provider
as data (`policy.json`, `extra.omnisense.policy`, `DeclaresUsagePolicyInterface` or the factory's
`<prefix>.policy`) wherever an API is called; the `Backoff` fed by every answer; a client that
never replays a write (`Http\NoRetry`); the bare check; the harness mounting the contracts
checkout (`OMNISENSE_CONTRACTS` in `docker/.env`).

## The classes, for a family on the kernel's registry

omnitravel is the model: its public names stay, each a thin subclass.

| Family class | On | What it adds |
|---|---|---|
| `Config` (final) | `Provider\Config` | `PREFIX`, `invalid()` → its InvalidConfigException |
| `ProviderInterface` | `Provider\ProviderInterface` | `capabilities(): Capabilities` (its own) |
| `ProviderFactoryInterface` | `Provider\ProviderFactoryInterface` | `create(): ProviderInterface` (its own) |
| `ProviderFactory` (abstract) | `Provider\ProviderFactory` | `populate()`/`build()` on its Config (the names its providers already use), `limitException()` |
| `Registry` (final) | `Provider\Registry` | `PREFIX`, its typed accessors, each a guarded `contract()`; `invalid()`, `notSupported()`, `limitException()` (the guard it builds for a factory that is not the kernel's) |
| `Capabilities` (final readonly) | `Provider\CapabilitiesInterface` | its fields; `policy()` (given, or `UsagePolicy::described()` from older fields) |
| `Http\JsonClient`, `Http\Data`, `Http\Redaction` (final) | `Http\*` | its exceptions through `answered()`/`unreachable()`, its Data through `data()` |
| `Testing\RecordedHttpClient`, `RecordedResponse` (final) | `Testing\*` | its name |
| `Http\NoRetry` (final) | `Http\NoRetry` | nothing: the family calls it, or keeps a thin alias of its former copy |
| `Http\Answer` (final constructor) | `Http\Answer`, when its raw calls need one | its name; `json(Data::class)` gives its Data |
| `Testing\BareTestCase` (abstract) | `Testing\BareTestCase` | `coreDirs()` + its core, `AUTOLOAD_ENV` |
| `Exception\<Family>Exception` | `Exception\ExceptionInterface` | `extends` it |
| `Exception\UsageLimitReachedException` (final) | `Policy\UsageLimitReached` | implements the family's interface |
| `Policy\Guarded<Contract>` | - | one per contract: each method a `PolicyGuard::call()` named after it |

The guarded wrappers are the family's own: PHP cannot implement "whatever interfaces the
provider has" at run time, so the registry's typed accessors (`timetables()`, `search()`...)
return one wrapper per contract, and `get()` the provider itself. A gateway family has one
class answering every request, so it is guarded whole.

## A provider's own requests

A provider's requests that its registry's guard does not see go through a guard its factory gives
it, `$c->guard(UsagePolicy::fromFile(self::POLICY))` (`Config::guard()`), which runs the same plan
and tightening as the registry's, counts in the same limiter and throws the family's exception:

- an OAuth token renewed in the middle of an operation: `call('token', ...)` (omnipulse,
  omniticket/helloasso);
- the further pages of a listing: `pages($operation, $first, $next)` or the loop with `call()`
  (omnidns, omniticket). An operation that reads all its pages before it returns is recorded by
  the registry's guard after them, so within that call each page is checked against the pages
  already counted, not against the call itself: one page more than the limit can go out in it;
- a request about one resource the terms limit per resource: `perResource()->call($op, ...,
  ['resource' => $key])`, under an operation of the provider's own (omnibus/mondial-relay's
  `trackParcel`, omnisign/docusign's `pollEnvelope`), so that no other limit counts it twice.

See [Requests inside an operation](policies.md#requests-inside-an-operation) and
[Limits per resource](policies.md#limits-per-resource).

Rules kept from the omni plan: the core requires `omnisense/contracts` and
`symfony/http-client-contracts`, nothing of a framework; a provider package requires what it
calls (`symfony/http-client`), accepts a client from outside, and runs its `BareTest`.

## Writes sent once

A family's Symfony bundle hands the application's `http_client` to each factory. When the
application turns on `framework.http_client.retry_failed` (or a scoped client's), that client is
a `RetryableHttpClient`: with Symfony's defaults it resends **any** request answered 423, 425, 429,
502 or 503, POST included, and an idempotent one (GET, HEAD, PUT, DELETE, OPTIONS, TRACE, QUERY)
on 500, 504, 507, 510 or a transport error (`GenericRetryStrategy::DEFAULT_RETRY_STATUS_CODES`).
A payment, a label's purchase, a publication or a transfer would go twice without the family's
code retrying anything.

`Http\NoRetry` is where the client enters the family - the factory's constructor (omnibus,
omnitrade, omnibank, omnifood, omnipost), the family's `JsonClient` (omnidns, omnical, omnistay),
the bundle (omnibill, omnisign), or the provider's own `HttpClient::create()` fallback:

```php
public function __construct(?HttpClientInterface $http = null)
{
    $this->http = null !== $http ? NoRetry::of($http) : null;
}
```

- `NoRetry::of($http)`, the default: **nothing is retried**, reads included. A 429 retried by the
  client never reaches the provider's `Backoff`, so the wait that holds every process is lost;
  each retry is a request the policy guard never counted, so a declared quota is passed unseen;
  the process sleeps the `Retry-After` inside a web request; and an API's PUT or DELETE is
  idempotent only when its provider says so. The family's code decides what to try again.
- `NoRetry::forWrites($http)`: GET, HEAD and OPTIONS keep the application's retries, every other
  method is sent once. For a family whose reads take no quota and gain from a retried 502.
- How: `withOptions(['max_retries' => 0])`, which reaches the `RetryableHttpClient` through
  Symfony's decorators (Traceable, Scoping, UriTemplate...) - no reflection. A client that refuses
  the option has no retry and is kept as given. Where the innermost transport takes any option
  (a `MockHttpClient` under decorators: the framework's test transport), the chain is not copied:
  each request is sent through it with `max_retries` 0 (`Http\SentOnce`), so the same
  `MockHttpClient` answers and counts.
- Kept as given, the same instance: a `MockHttpClient`, the kit's `RecordedHttpClient` (and
  `Testing\MockHttp`'s client), any client with no retry. `NoRetry::isTestClient()` says which.
- symfony/http-client stays optional (none of its classes is loaded); the `max_retries` option
  needs symfony/http-client 6.4 or later.

A family's test proves it with a real `RetryableHttpClient` over a `MockHttpClient`: a write
answered 503 then 200 is sent once and its 503 reaches the provider.

## Decoded answers and PHPStan

The families run PHPStan at level max with an empty baseline. A decoded JSON answer is `mixed`:
it is read through `Http\Data` (or the family's subclass), whose readers give their type or null,
never cast blindly. A provider with many reads names its own, `@internal`
(`final readonly class Data extends \Omnisense\Contracts\Http\Data`), adding the reads that keep
PHP's casts where the provider relied on them - `text()`, `number()`, `fields()`, and the static
`cast()`, `integer()`, `map()` (omnitrade/stripe; omni-provider-template's `parts/json/Data.php`).

## Migration checklist

What the four rounds of migration found, for a family not done yet or one moving on from an
older kernel:

1. The core requires `omnisense/contracts`; its classes become thin subclasses or keep their own
   shape (above); one `ExceptionInterface` above its exceptions; its `UsageLimitReachedException`
   implements `Policy\UsageLimitReached`.
2. Each provider ships `policy.json` - read at its source that day, `verifiedAt`, unknown for
   what is not published, figures seen elsewhere in `notes` only - named in
   `extra.omnisense.policy`, given to the family (`DeclaresUsagePolicyInterface` or the factory's
   policy key) and to its Capabilities; the old free-text fields stay, `@deprecated`, derived from
   the policy.
3. The figures as the terms write them: `every` for a window of several units (trakt, Microsoft
   Graph), not a stricter per-minute figure; `unit` and each operation's `cost` for a quota in
   units, points or characters (YouTube, Azure Translator); `concurrency` where the terms give
   one; `plans` with their `source` and `verifiedAt` (omnicrawl, Azure); `rights.retention` rather
   than `caching.maxTtl` for "keep it no longer than" (dossierfacile, YouTube); `"scope":
   "resource"` and `per` for a limit per parcel or envelope (Mondial Relay, DocuSign).
4. `$c->backoff()` reaches every HTTP call; a 429 is the family's refusal with its
   `Retry-After`, never retried; `Backoff::hold()` for a refusal worded otherwise (Google's 403
   rateLimitExceeded); `withLegacyHeaders()` only for a provider that sends `X-RateLimit-*` alone.
5. `Http\NoRetry` where the client comes in, and a test over a `RetryableHttpClient`.
6. Token requests, further pages and per-resource requests through `Config::guard()` (above). A
   family whose registry builds its own guards overrides `limitException()`; a family's own
   `Config::guard()` override (omniticket's) goes: the kernel's does the same, plus the plan.
7. The constraint is `"omnisense/contracts": "^1.0"`. A `@dev` or `@RC` flag only counts in the
   root project, so a family that keeps one does not install where minimum-stability is stable.
   The fallbacks written for 1568f2c and older kernels (`class_exists(NoRetry::class)`,
   `\defined(Scope::class.'::Resource')`, the plain `call()` loop instead of `pages()`) go with
   it.
8. PHPStan at max, its baseline empty; decoded answers through `Data`.
9. The harness mounts the contracts checkout (`OMNISENSE_CONTRACTS`) and runs its tests too; the
   family's `BareTest` extends the kernel's `BareTestCase` (`coreDirs()` with its core).
10. Tests per family: a limit reached with the call never made, a 429 honoured and the next call
    held, a site's tightening accepted and a loosening refused, a write sent once.
