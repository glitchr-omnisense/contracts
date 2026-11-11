# Changelog

## [Unreleased]

What the family migrations still lacked (round 4). Every policy.json written so far reads as
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
- `bin/load-all.php` (the bare check's child process) reports the files and classes loading the
  package brought in, not those Composer's autoloader had loaded when it was required: in a
  family harness where another package autoloads files eagerly (google/apiclient in
  omnidrive's), `BareTest` counted them as outside the package. `eager` still lists Composer's
  files. Tests/Testing/LoadAllTest.

Writes sent once (backward compatible: nothing calls it until a family does).

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

What thirteen families' migrations asked for next (round 3). Every policy.json written so far
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

## 1.x (first state)

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
