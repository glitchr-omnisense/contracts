# Usage policies

`Omnisense\Contracts\Policy\UsagePolicy` is a provider's usage policy as a value object. It is
read from JSON (`fromFile()`, `fromJson()`, `fromArray()`) or written in PHP
(`UsagePolicy::builder()`), and written back by `toArray()`/`toJson()` in the same shape.

## The schema

[`resources/usage-policy.schema.json`](../resources/usage-policy.schema.json) (JSON Schema
2020-12). `fromArray()` checks the same rules by hand, without a validator library, and names
the path of any field it refuses (`"rateLimits[0].window" must be one of ...`). A key it does
not know is refused too, so a typo does not pass silently.

```json
{
    "provider": "transitous",
    "rateLimits": [
        {"max": 720, "window": "day", "scope": "application", "operations": ["journeys"], "note": "..."}
    ],
    "rights": {
        "commercial": "forbidden",
        "openSourceRequired": true,
        "caching": {"permission": "unknown", "maxTtl": null},
        "storage": "unknown",
        "redistribution": "unknown",
        "attribution": {"required": true, "text": "...", "url": "https://transitous.org/sources/", "where": ["display", "map"]},
        "licence": "ODbL-1.0"
    },
    "requirements": {"key": false, "userAgent": {"required": true, "pattern": "~/.*[(;@]|[(;@].*/~"}},
    "provenance": {
        "terms": "https://transitous.org/api/", "policy": "https://transitous.org/api/",
        "verifiedAt": "2026-11-10", "declaredBy": "maintainer",
        "contact": "https://matrix.to/#/%23transitous:matrix.spline.de", "notes": "..."
    }
}
```

| Field | Values |
|---|---|
| `rateLimits` | `null`: unknown; `[]`: the terms set none; else a list of limits, **each** counted |
| `rateLimits[].window` | `second`, `minute`, `hour`, `day`, `month` (30 days); fixed windows from their first call |
| `rateLimits[].scope` | `application` (default), `key`, `client-ip`, `user` |
| `rateLimits[].operations` | the family's operations it counts (its contract methods: `journeys`, `departures`...); empty: all |
| permissions (`commercial`, `storage`, `redistribution`, `caching.permission`) | `allowed`, `unknown`, `contract`, `forbidden` |
| `openSourceRequired` | `true`, `false`, `null` (unknown) |
| `caching.maxTtl` | seconds, `null` for no maximum |
| `attribution.where` | `display` (beside the data), `map`, `credits` (an about page), `source` (code, docs) |
| `requirements.userAgent.pattern` | a PCRE with its delimiters; `Requirements::USER_AGENT_WITH_CONTACT` is the usual one |
| `provenance.verifiedAt` | `YYYY-MM-DD`: the day someone read the policy against its source |
| `provenance.declaredBy` | `maintainer`, `provider`, `site` |

## The merge rule: a site only tightens

`$declared->tighten(array $site)` takes `rateLimits`, `rights` and `requirements` in the
schema's shape, any part of them:

- **rate limits are added**: every limit counts, so the strictest wins. A site's limit that
  counts the same calls as a declared one (same window, scope and operations) with a higher
  maximum is refused: it would do nothing, and it says the site expects more than it gets.
  Where the declared limits are unknown, the site's are the only ones;
- **permissions only go stricter**, in the order `allowed` < `unknown` < `contract` <
  `forbidden`. Unknown ranks above allowed: a site cannot turn an unread term into "allowed";
  only a declaration can;
- **the time to live only gets shorter**; caching allowed where it was not is refused;
- **open source, the attribution, a key, a User-Agent**: once required, they stay required;
  the attribution's text and link, the licence and the User-Agent pattern cannot be rewritten
  once declared; places to credit are added;
- **provenance and the provider's name** are the declaration's: a site giving them is refused.

Anything looser throws `InvalidPolicyException` (an `InvalidConfigException`), naming the field,
the declared value and the one asked.

What a site says of itself goes in `usage` (`commercial`, `open_source`, `contract`):
`$policy->violations(new Usage(commercial: true))` lists what the terms forbid, and a family's
registry refuses such a configuration at once (`UsageNotPermitted`).

## Enforcement: `PolicyGuard`

```php
$guard = new PolicyGuard($policy, $limiter);   // RateLimiterInterface; InMemoryRateLimiter by default
$guard->call('journeys', fn () => $provider->journeys($query));
```

- `check($operation, $context)` before: the provider's wait first (`Backoff`), then every
  limit that counts the operation; `UsageLimitReached` when one is exhausted, with
  `retryAfter` (seconds until its window resets), `provider` and `limit`;
- `record($operation, $context)` after, whether the call succeeded or failed (the provider
  counted it too);
- `$context`: `key`, `ip`, `user` for limits scoped so; one not given counts in a shared
  bucket - the strictest reading;
- `remaining($operation)`: the calls left before a limit is reached, `null` when none counts it;
- `cacheTtl(?int $wanted)`: `0` when caching is forbidden or needs a contract, the shorter of the
  wanted and the maximum otherwise; unknown terms do not shorten it.

A family passes its own subclass of `UsageLimitReached` (`exception:`), so that its exception
interface catches it too.

## The provider's own signals: `Backoff`

`JsonClient` given the provider's `Backoff` calls `clear()` before each request and
`observe($status, $headers)` after each response. It reads, as they are specified:

- `Retry-After` ([RFC 9110 §10.2.3](https://www.rfc-editor.org/rfc/rfc9110#section-10.2.3)):
  delay-seconds or an HTTP-date, honoured on a 429 or a 503;
- `RateLimit` and `RateLimit-Policy`
  ([draft-ietf-httpapi-ratelimit-headers-11](https://datatracker.ietf.org/doc/draft-ietf-httpapi-ratelimit-headers/),
  2026-05-23), Structured Field lists: `RateLimit: "default";r=0;t=30` (nothing left, the
  window ends in 30 s) holds the next calls for 30 s, before the provider has to refuse one.
  `Retry-After` prevails over `RateLimit` (the draft's section 7).

A 429 throws `UsageLimitReached` with the wait asked. The wait is kept in the limiter, so every
process sharing it waits too. The `X-RateLimit-*` fields some APIs send are not read: their
meaning differs from one API to the next.

## Limiters

`RateLimiterInterface` (`allowance()`, `hit()`, `block()`, `blocked()`) is where calls are
counted. `InMemoryRateLimiter` counts within a PHP process (a command, a worker, a test). A web
application served by many processes gives a shared one: omnibridge/symfony's, over
symfony/rate-limiter and a cache pool.

## Exporting every policy

```
vendor/bin/omnisense-policies                         # the application's packages
vendor/bin/omnisense-policies --installed=path/to/vendor/composer/installed.json
vendor/bin/omnisense-policies ~/Sites/omnitravel/*    # package folders
```

`PolicyExport` reads each package's `extra.omnisense.policy` (or `policies`, a list) and prints
`{"schema": ..., "policies": [{"package", "version", "file", "policy" | "error"}]}`, sorted by
package. It exits 1 when a declared policy cannot be read. This is the input of a policies table
on the omnisense website.
