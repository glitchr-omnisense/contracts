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
| `rateLimits[].scope` | `application` (default), `key`, `client-ip`, `user`, `resource` |
| `rateLimits[].per` | with `"scope": "resource"` only, and then required: the kind of resource each one counts apart (`parcel`, `envelope`, `zone`); such a limit names its `operations` |
| `rateLimits[].operations` | the family's operations it counts (its contract methods: `journeys`, `departures`...); empty: all |
| permissions (`commercial`, `storage`, `redistribution`, `caching.permission`) | `allowed`, `unknown`, `contract`, `forbidden` |
| `openSourceRequired` | `true`, `false`, `null` (unknown) |
| `caching.maxTtl` | seconds, `null` for no maximum |
| `attribution.where` | `display` (beside the data), `map`, `credits` (an about page), `source` (code, docs) |
| `requirements.userAgent.pattern` | a PCRE with its delimiters; `Requirements::USER_AGENT_WITH_CONTACT` is the usual one |
| `provenance.verifiedAt` | `YYYY-MM-DD`: the day someone read the policy against its source |
| `provenance.declaredBy` | `maintainer`, `provider`, `site` |
| `rights.retention` | `{"maxAge": seconds, "covers": "..."}`: how long the data may be kept at all; `null`/absent: not said |
| `plans` | `{"default": "free", "plans": {"<name>": {rateLimits?, concurrency?, source, verifiedAt, note?}}}`: the limits per plan; the top-level ones are the default plan's |
| `plan` | the plan a policy runs under, written by `forPlan()`; never in a declaration |

## Plans

A provider whose limits depend on the plan subscribed (Firecrawl, DeepL, Azure...) declares
them in `plans`. `fromArray()` refuses a default that is not declared and top-level
`rateLimits` or `concurrency` other than the default plan's (compared by what they count, not
by their notes): a reader that knows nothing of plans reads the free plan.

```php
$pro = $policy->forPlan('pro');   // the plan's limits and concurrency; provenance policy/verifiedAt = the plan's source/date
$pro->plan;                       // "pro"; $pro->plans is null
$policy->forPlan(null);           // the default plan
$policy->forPlan('gold');         // InvalidPolicyException: No "gold" plan; declared: free, pro.
```

A plan that names no `rateLimits` (or no `concurrency`) keeps the policy's. Rights,
requirements and terms are the provider's whatever the plan. A site names its plan with the
`plan` option (beside `policy` and `usage`); `ProviderFactory::effectivePolicy()` applies it
**before** `tighten()`, so a site only tightens the plan it runs, and `tighten()` on a
declaration with plans tightens its default plan. A `plan` for a provider without plans is
refused. The export carries the plans; during the transition, a package whose plans are still
a file of their own names it in `extra.omnisense.plans` and the export puts it in the policy.

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
- **retention only gets shorter**: a site may set one where none is declared, shorten it,
  never lift it nor change what it `covers`;
- **provenance and the provider's name** are the declaration's: a site giving them is refused;
  the plan is not a tightening but the `plan` option, applied first.

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
  bucket - the strictest reading; `resource` for limits counted per resource (below);
- `remaining($operation)`: the calls left before a limit is reached, `null` when none counts it;
- `cacheTtl(?int $wanted)`: `0` when caching is forbidden or needs a contract, the shorter of the
  wanted and the maximum otherwise; unknown terms do not shorten it.

A family passes its own subclass of `UsageLimitReached` (`exception:`), so that its exception
interface catches it too.

### Limits per resource

Some terms count per object, not per caller: Mondial Relay tracks a parcel 4 to 6 times a day
at most, DocuSign polls an envelope once per 15 minutes. `"scope": "resource"` with `per`
naming the kind counts each one apart; the provider names the one a call is about in the
context, as it would a key:

```json
{"max": 6, "window": "day", "scope": "resource", "per": "parcel", "operations": ["trackParcel"]}
```

```php
$tracking->call('trackParcel', fn () => $this->api->track($number), ['resource' => $number]);
// an operation about resources of two kinds counted so: ['resource' => ['zone' => $z, 'record' => $r]]
```

A call that names no resource for such a limit throws `ResourceNotNamed` (a `LogicException`,
an `ExceptionInterface`) before anything is sent. Not a shared bucket, as for a key, client or
user not given: six tracking calls a day for all parcels together would refuse the seventh
parcel's first call, which the terms allow. Not skipped either: the terms would be broken
without a sign. Only the code that makes the call knows the resource, so a missing one is a
mistake there, said the first time a test runs it. `remaining()` reads such a limit only when
the context names the resource. A per-resource limit must name its operations: no call of
another operation names the resource.

Where the family's registry guards its operations without a context (most families: the
typed accessors pass none), the provider counts the request itself, under an operation of its
own (`trackParcel`, `pollEnvelope`, as `token` for a token request), through a guard that
holds the per-resource limits alone, so that no other limit counts the same request twice:

```php
// the factory
new TrackingAction($c->guard(UsagePolicy::fromFile(__DIR__.'/policy.json'))->perResource());
```

`PolicyGuard::perResource()` is the same guard (limiter, wait, exception) over the policy's
limits counted per resource; it counts with the registry's guard in the same limiter. A
package that must also load with an older omnisense/contracts (before `Scope::Resource`) reads
its policy.json without its per-resource limits there (`Scope::tryFrom('resource')` is null):
an older kernel refuses the unknown scope.

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

### Requests inside an operation

The registry's guard counts an operation once, when it is called. Two kinds of requests happen
inside one, where it cannot see them:

- **a token request** (OAuth client credentials renewed in the middle of a listing): the
  provider wraps it in `call('token', ...)`, an operation of its own, which a limit names;
- **the pages of a lazily paged listing** (DNS records, events, attendees): the operation
  returns a generator, so the registry's `call()` returns before the first page is even fetched,
  and counts once. The provider reports each further page to a guard **before** it is sent:

```php
// the factory
return new ExampleProvider($client, $c->guard(UsagePolicy::fromFile(ExampleProvider::POLICY)));

// the provider
public function records(string $zone): \Generator
{
    $url = '/zones/'.$zone.'/records';
    for ($page = 1; null !== $url; ++$page) {
        $fetch = fn () => $this->client->get($url);
        $data = 1 === $page ? $fetch() : $this->guard->call('records', $fetch);   // the first is the registry's
        yield from $data->list('result');
        $url = $data->string('next');
    }
}
```

`PolicyGuard::pages()` is that loop: the first page fetched as it is, then `$next` given each
page read answers the fetch of the next one (or null: the last answer is never counted as a
request), each through `call()`:

```php
foreach ($this->guard->pages('records', fn () => $this->client->get($first), fn (Data $page) => null === ($url = $page->string('next')) ? null : fn () => $this->client->get($url)) as $page) {
    yield from $page->list('result');
}
```

A package that must load with an older omnisense/contracts writes the loop with `call()`.

`Config::guard()` runs the policy given under the site's `plan` and `policy` options, as the
registry's guard does, in the same limiter (the registry's; outside a registry, the one the
factory built, which `create()` now keeps in the Config) with the provider's Backoff, and
throws the family's exception (the Backoff's). Both guards count under the same limit
keys, so a limit of 3 requests a day lets three pages through and refuses the fourth before
it is requested - the pages already read are kept, the iteration stops with
`UsageLimitReached`.

Why the provider reports, and nothing new in the guard: the guard already checks before and
counts after (`call()`); what was missing is a guard the provider holds that shares the
registry's counts. A wrapper over the returned iterable cannot know where pages begin (items
are not requests), and `amounts: ['requests' => n]` needs n before the call or from its result,
which a lazy listing has neither. A limit scoped per key, client or user needs the provider to
pass that `context` too; the registry's typed accessors pass none today.

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
