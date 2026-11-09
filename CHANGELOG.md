# Changelog

## 1.x (unreleased)

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
