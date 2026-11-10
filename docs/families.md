# Building a family on the kernel

A family core keeps its contracts (its `*Interface` per capability), its models, its
Capabilities fields; the scaffolding comes from here. omnitravel is the model: its public names
stay, each a thin subclass.

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

A provider's requests that its registry's guard does not see - an OAuth token renewed in the
middle of a listing, the further pages of a listing it pages lazily - go through a guard its
factory gives it: `$c->guard(UsagePolicy::fromFile(self::POLICY))` (`Config::guard()`), which
runs the same plan and tightening as the registry's, counts in the same limiter and throws the
family's exception. See [Requests inside an operation](policies.md#requests-inside-an-operation).

The guarded wrappers are the family's own: PHP cannot implement "whatever interfaces the
provider has" at run time, so the registry's typed accessors (`timetables()`, `search()`...)
return one wrapper per contract, and `get()` the provider itself.

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

`Http\NoRetry` is where the client enters the family - the factory's constructor, or the
provider's own `HttpClient::create()` fallback:

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
