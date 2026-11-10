# Changelog

## [Unreleased]

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
