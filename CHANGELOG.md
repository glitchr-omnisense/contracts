# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable version: rc.1's code unchanged, the public surface frozen for 1.x, and
semantic versioning from here on (README, Stability). Upgrading: UPGRADE-1.0.md.

- README: a Stability section. It says what 1.x keeps: interfaces a family implements (no
  method added), classes a family extends (signatures of overridable methods unchanged),
  classes it calls, the policy data and ids, the counting behaviour. It also says what 1.x does
  not keep (`@internal`, messages). The install line is now `^1.0`. The README names
  omnibridge/symfony's `Policy\RateLimiter` as the shared limiter and lists the PHP versions
  tested.
- `Tests/ApiSurfaceTest` and `Tests/api-1.x.txt`: the public surface (classes, constants,
  properties, method signatures, with protected members where a subclass can see them; nothing
  marked `@internal`), one line per member. A line gone or changed fails the test. The test
  writes `self` the same way on every PHP version.
- `Testing\RecordedChunk` and `Testing\RecordedStream` are marked `@internal`: they are built by
  `RecordedHttpClient::stream()` and read through Symfony's interfaces.
- CI: the package's tests on PHP 8.2 (lowest and highest dependencies), 8.3 and 8.5, beside
  the 8.4 job (PHPUnit, PHPStan max, consumer install).
- What the families still lack is left for 1.x minors, as additions that keep every signature a
  family overrides. omnihub's notes list: conditional requests, a refusal on a 403 with
  Retry-After, a clock for `Backoff`, timeouts and a size cap, every status given back,
  `BareTestCase` reading the PSR-4 root, and the User-Agent check. Earlier proposals are left
  the same way: counting at the check (a reservation), a provider's own operations under
  all-operations limits, and a request naming its resource. 1.x keeps the documented behaviour,
  and each can come as opt-in.

Since rc.1, housekeeping with no change to the package's code:

- `.gitattributes`: the release's source archive, and what Composer installs from it, leaves out
  `Tests/`, `.github/`, `docker/`, `phpunit.xml.dist`, the PHPStan configuration, `.gitignore` and
  itself; `bin/` (the bare check's child process, `omnisense-policies`), `Testing\`, `resources/`
  and `docs/` stay. Checked from an archive installed in a provider package: its `BareTest` and
  `vendor/bin/omnisense-policies` work. Tests/ArchiveTest reads the attributes as `git archive`
  does.
- docs/families.md reread against the families: the four shapes they took (providers on the
  kernel's registry, Payum-style gateways guarded whole, registries of their own, the kernel
  where it fits), where each puts `Http\NoRetry`, a provider's own requests (token, pages, a
  resource), decoded answers through `Data` for PHPStan at max, and a migration checklist drawn
  from the four rounds.
- docs/declare-your-policy.md: limits per resource (`"scope": "resource"`, `per`) and how a
  provider counts them, its token requests and its pages. docs/policies.md: an operation that reads
  every page before it returns is recorded by the registry's guard after them. README: the policy
  fields added since the first state (`every`, the resource scope, units and costs, concurrency,
  plans, retention), `Http\NoRetry`.

## [1.0.0-rc.1] - 2026-11-11

The first tagged version of the kernel every omni family builds on: everything below, from its
first state on. Backward compatible throughout: every policy.json written since the first state
reads as it did. What `1.x-dev` installed until now (1568f2c in the sites' vendors) is a subset.

### Round 4

What the family migrations still lacked. Every policy.json written so far reads as
before; `RateLimit::toArray()` writes `per` only for a limit counted per resource.

- Limits per resource: `Scope::Resource` (`"scope": "resource"`) and `RateLimit::$per`, the kind
  of resource each one counts apart (`"per": "parcel"`, `"envelope"`, `"zone"`; lower case),
  required with that scope and refused with any other; such a limit names its operations.
  `RateLimit::perResource($kind)`; `id()` writes the scope as `resource:<kind>`, `describe()`
  says "per parcel". The guard's context takes `resource`: the key of the resource the call is
  about, or a map of kind to key. A call that names none for such a limit throws
  `Policy\ResourceNotNamed` (a `LogicException` and an `ExceptionInterface`) before anything is
  sent, rather than one shared bucket (which would refuse the next resource's calls) or no count
  (which would let the terms be broken); `remaining()` reads such a limit only when the
  resource is named. `PolicyGuard::perResource()`: the same guard over the per-resource limits
  alone, for a provider that counts the request itself under an operation of its own while the
  registry's guard counts the family's operation. The JSON Schema: `resource`, `per`, the
  conditions between them. docs/policies.md (Limits per resource). `PolicyGuard::record()` no
  longer asks the limiter for a limit the call consumes nothing of.
- `PolicyGuard::pages($operation, $first, $next, $context)`: a listing's pages fetched as they
  are read, each after the first through `call()`; `$next` answers the next page's fetch or
  null, so the last answer is never counted as a request.
- `PolicyGuard::cacheTtl()` is never longer than `rights.retention`'s `maxAge`: a cached answer
  is a copy, kept no longer than the data may be (a policy without a retention gives what it did).
  The retention can then hold a provider's "kept three months" alone, without the same figure as
  `caching.maxTtl` (omnisign/dossierfacile, omnishow/youtube).
- `bin/load-all.php` (the bare check's child process) reports the files and classes loading the
  package brought in, not those Composer's autoloader had loaded when it was required: in a
  family harness where another package autoloads files eagerly (google/apiclient in
  omnidrive's), `BareTest` counted them as outside the package. `eager` still lists Composer's
  files. Tests/Testing/LoadAllTest.

### Writes sent once

Backward compatible: nothing calls it until a family does.

- `Http\NoRetry`: the HTTP client a factory is given, kept from replaying a request. Symfony's
  `RetryableHttpClient` (`framework.http_client`'s `retry_failed`) resends a POST answered 423,
  425, 429, 502 or 503 with its default strategy. `of()` (the default) turns every retry off,
  reads included: a 429 must reach the provider's Backoff, and a retry is a request the guard
  never counted. `forWrites()` keeps the application's retries for GET, HEAD and OPTIONS only
  (`Http\RetriedReads`). Through `withOptions(['max_retries' => 0])`, across Symfony's decorators,
  no reflection; a `MockHttpClient` or a `RecordedHttpClient` is kept as given (the same
  instance), and one under decorators is not copied (`Http\SentOnce`: `max_retries` 0 per
  request), so `getRequestsCount()` still counts. `isTestClient()`. symfony/http-client stays
  optional; the option needs 6.4 or later. docs/families.md (Writes sent once).
  Tests/Http/NoRetryTest: a POST answered 503 then 200 sent once over a real
  `RetryableHttpClient`, a GET sent once by `of()` and retried by `forWrites()`.

### Round 3

What thirteen families' migrations asked for next. Every policy.json written so far
reads as before; `toArray()` writes `plans`, `plan` and `rights.retention` only when they are
set, so a policy without them writes what it always did.

- Plans (omnicrawl's `Policy\Plans`, moved in): a `plans` member (`default`, `plans`: name →
  `{rateLimits?, concurrency?, source, verifiedAt, note?}`), `Policy\Plans` and `Policy\Plan`;
  the default plan must exist and the policy's own limits and concurrency must be its.
  `UsagePolicy::forPlan(?string)`: the plan's limits and concurrency replace the policy's, its
  source and date become the provenance's, `$plan` names it; an unknown plan, or one for a
  provider without plans, throws `InvalidPolicyException`. `withPlans()`;
  `PolicyBuilder::plans()`. `tighten()` on a declaration with plans tightens its default plan.
- `ProviderFactory::effectivePolicy()` takes the `plan` (the factory's `plan` option, read by
  `guard()` and the registry) and applies it before `tighten()`: a site only tightens its plan.
- `PolicyExport` carries the plans, and reads a separate plans file named in
  `extra.omnisense.plans` (a file, or a map of policy file to plans file) during the transition.
- `Config::guard(UsagePolicy)`, from omniticket: a guard for the requests the registry's guard
  does not see (an OAuth `token` request, each further page of a listing paged lazily), under
  the same plan and tightening, in the same limiter, with the provider's Backoff and the
  family's exception. `ProviderFactory::create()` keeps the limiter in the Config
  (`<prefix>.limiter`), so the two guards count together outside a registry too.
  `Backoff::exception()`.
- `rights.retention` (`Policy\Retention`: `maxAge` in seconds, `covers`): how long the data may
  be kept at all, beside `caching.maxTtl`; a site may only shorten it, never lift it nor change
  what it covers; exported. `PolicyBuilder::retention()`.
- `Registry::limitException()`: the family's `UsageLimitReached` subclass for the guard the
  registry builds itself (a factory that is not the kernel's); `UsageLimitReached` by default.
- Fixed `RateLimit::describe()`: "1200 an hour" (was "a hour"); the other windows were right.

### Round 2

What the first family migrations (omnishow, omnistay, omniplay, omnical) found missing. Every
policy.json written so far reads as before.

- Fixed `UsagePolicy::tighten()`: a policy carrying two limits on the same calls (a site's
  earlier tightening, a provider's two figures) read its own higher limit as a loosening and
  refused any tightening. Only the limits the site adds are compared with the declared ones.
- Rate limits: `every` (a window of several units: `{"window": "minute", "every": 5}`), `unit`
  (`requests` by default, `content-bytes`, `characters`, `units`, `points`) and `cost` (what one
  call of an operation consumes, 1 by default); `RateLimit::every()`, `counting()`, `cost()`,
  `seconds()`. `id()` and `describe()` unchanged for a limit that uses none of them.
- `PolicyGuard::call()`, `check()`, `record()` take `amounts` by unit (a closure of the result
  for `call()`): what only the call knows, such as characters translated. `remaining()` divides
  by the operation's cost. `ConsumingRateLimiterInterface::consume()`, implemented by
  `InMemoryRateLimiter`; a limiter without it is hit once per unit.
- `concurrency` in the policy (most requests in flight): read, tightened only lower, exported;
  not enforced. `PolicyBuilder::concurrency()`.
- `toArray()` and the export carry `every`, `unit`, `cost` and `concurrency` (defaults: 1,
  `requests`, null, null).
- `Backoff::withLegacyHeaders(LegacyReset)`: X-RateLimit-* and RateLimit-Remaining/-Reset read on
  an explicit opt-in, the reset as seconds or a Unix time. The default is unchanged: Retry-After
  and the IETF fields only. `Backoff::hold()`: a wait the provider decides on a signal of its own
  (Google's 403 rateLimitExceeded). `RateLimitHeaders::legacyWait()`.
- `JsonClient`: an empty body (204, 304) reads as an empty Data; `decode()` hook; `raw()` for any
  other call, giving an `Http\Answer` (status, headers, body, the URL that answered after
  redirects) with error statuses to pass; headers with several values.
- `Testing\RecordedHttpClient::stream()` works (`RecordedStream`, `RecordedChunk`), so it sits
  under Symfony's PSR-18 bridge; a route's fourth element matches the request body;
  `RecordedHttpClient::body()`. `Testing\MockHttp` over Symfony's MockHttpClient (from
  omnilang's, without its multipart reader; `@suggested symfony/http-client`).
- `ProviderException::answered()` gives the HTTP status as its code, `status()` reads it (0 for a
  body that could not be read), as omniplay's and omnical's do.
- The JSON Schema: `every`, `unit`, `cost`, `concurrency`.

### The first state

- 2026-11-10: the kernel written, from omnisense/omnitravel's copy (the newest), checked against
  omnishow's and omnistay's: `Provider\` (ProviderInterface, CapabilitiesInterface,
  ProviderFactoryInterface, Config with a family prefix, ProviderFactory, Registry with
  `contract()`, `guard()`, `policy()`, `policies()`), `Http\` (JsonClient, Data, Redaction),
  `Testing\` (RecordedHttpClient with a route's headers, RecordedResponse, BareTestCase with
  `coreDirs()`), `Exception\`. Usage policies (`Policy\`): UsagePolicy and its parts (RateLimit,
  Rights, Caching, Attribution, Requirements, Provenance), PolicyBuilder, the JSON Schema,
  `tighten()` (a site only tightens), `violations()` (what the terms forbid a site), PolicyGuard,
  Backoff (429, Retry-After, the IETF RateLimit fields), RateLimiterInterface and
  InMemoryRateLimiter, UsageLimitReached, PolicyExport and `bin/omnisense-policies`.
