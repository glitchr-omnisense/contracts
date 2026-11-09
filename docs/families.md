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
| `Registry` (final) | `Provider\Registry` | `PREFIX`, its typed accessors, each a guarded `contract()`; `invalid()`, `notSupported()` |
| `Capabilities` (final readonly) | `Provider\CapabilitiesInterface` | its fields; `policy()` (given, or `UsagePolicy::described()` from older fields) |
| `Http\JsonClient`, `Http\Data`, `Http\Redaction` (final) | `Http\*` | its exceptions through `answered()`/`unreachable()`, its Data through `data()` |
| `Testing\RecordedHttpClient`, `RecordedResponse` (final) | `Testing\*` | its name |
| `Testing\BareTestCase` (abstract) | `Testing\BareTestCase` | `coreDirs()` + its core, `AUTOLOAD_ENV` |
| `Exception\<Family>Exception` | `Exception\ExceptionInterface` | `extends` it |
| `Exception\UsageLimitReachedException` (final) | `Policy\UsageLimitReached` | implements the family's interface |
| `Policy\Guarded<Contract>` | - | one per contract: each method a `PolicyGuard::call()` named after it |

The guarded wrappers are the family's own: PHP cannot implement "whatever interfaces the
provider has" at run time, so the registry's typed accessors (`timetables()`, `search()`...)
return one wrapper per contract, and `get()` the provider itself.

Rules kept from the omni plan: the core requires `omnisense/contracts` and
`symfony/http-client-contracts`, nothing of a framework; a provider package requires what it
calls (`symfony/http-client`), accepts a client from outside, and runs its `BareTest`.
