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
        {"max": 720, "window": "day", "scope": "application", "operations": ["journeys"], "note": "..."},
        {"max": 500, "window": "minute", "every": 5}
    ],
    "concurrency": null,
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
| `rateLimits[].every` | the windows it spans, 1 by default: `{"window": "minute", "every": 5}` is 5 minutes (Trakt's 500 per 5 minutes, Graph's 10,000 per 10 minutes) |
| `rateLimits[].unit` | what `max` counts: `requests` (default), `content-bytes`, `characters`, `units`, `points` |
| `rateLimits[].cost` | what one call of an operation consumes, in the unit: `{"searchVideos": 100}`; 1 when not named, 0 for a free one; `null`: 1 each |
| `concurrency` | the most requests in flight at once (IGDB: 8); `null`: not said. Declared and exported, not enforced |
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
  counts the same calls as a declared one (same window and multiple, scope, operations, unit
  and costs) with a higher maximum is refused: it would do nothing, and it says the site
  expects more than it gets. Only the limits the site adds are compared: a policy that already
  carries two limits on the same calls (a provider's two figures, a site's earlier tightening)
  can still be tightened. Where the declared limits are unknown, the site's are the only ones;
- **concurrency only goes lower**: a site may lower it, or set it where none is declared;
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

### Units and costs

A limit counts the provider's HTTP requests by default. One in other units says so, with what
each operation costs:

```json
{"max": 10000, "window": "day", "unit": "units", "cost": {"channelVideos": 3, "live": 3, "schedule": 3}}
```

The guard checks, before the call, that each limit has at least the operation's cost left,
and consumes it after. What only the call knows is reported in `amounts`, by unit; it replaces
the cost under the limits counted in that unit:

```php
// a translation counted in characters: known before the call...
$guard->call('translate', fn () => $deepl->translate($text), amounts: ['characters' => mb_strlen($text)]);
// ...or billed by the provider, read from the result
$guard->call('translate', fn () => $deepl->translate($text), amounts: fn (Translation $t) => ['characters' => $t->billed]);
// an operation that followed three pages
$guard->call('search', fn () => $provider->search($q), amounts: fn (Page $p) => ['requests' => $p->requests]);
```

`remaining()` gives the calls of an operation left (what each limit has left divided by its
cost). The units follow RateLimit-Policy's `qu`: its default `requests` and its registry's
`content-bytes`; `characters`, `units` and `points` are the providers' own (DeepL and Google
Translation, YouTube, Twitch and GitHub GraphQL). The draft has no cost per request ("whether
a specific request actually consumes a quota unit is implementation-specific"): `cost` is the
policy's own field, not a header's.

**Operations, not HTTP requests.** The guard counts operations; a provider's terms count HTTP
requests. `cost` says how many requests an operation makes (`{"channelVideos": 3}` under a
`requests` limit), and `amounts` reports a number only the call knows (pages followed). The
kernel does not count at the HTTP layer: a limit must be checked before the operation starts
(counting each request would refuse it halfway, its first pages spent for nothing), and the
HTTP client knows neither the operation nor the key, client or user a scoped limit counts per.
The provider does.

A limiter that counts several units at once implements `ConsumingRateLimiterInterface`
(`consume()`; `InMemoryRateLimiter` does). One that implements only `RateLimiterInterface`
still counts right: the guard calls its `hit()` once per unit.

### Concurrency

`concurrency` is declared, tightened (only lower) and exported, not enforced: a PHP process
makes one request at a time, so the guard has nothing to hold. An asynchronous client, or a
semaphore shared by an application's workers (symfony/semaphore), reads
`$policy->concurrency`. The IETF draft expresses concurrency as a quota unit
(`concurrent-requests`); here it is its own field, since it has no window.

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
process sharing it waits too.

The legacy fields some APIs send - `X-RateLimit-Limit`, `-Remaining`, `-Reset`, and the
`RateLimit-Limit`, `-Remaining`, `-Reset` of the draft's early versions (Twitch) - are not read
by default: their reset is seconds left with some APIs and a Unix time with others. A provider
whose API sends them opts in, saying which:

```php
new JsonClient($http, 'Twitch', $headers, $c->backoff()?->withLegacyHeaders(LegacyReset::Epoch));
```

With nothing remaining (or on a 429), the next calls wait until the reset. `Retry-After`
still prevails on a 429 or a 503. The returned Backoff shares the guard's limiter and key.

A signal that is neither (Google's `403 rateLimitExceeded`, an error code in a 200) is the
provider's to read; `hold()` keeps the wait it decides and gives the exception to throw:

```php
throw $backoff->hold(60, 'Google answered 403 rateLimitExceeded');
```

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
