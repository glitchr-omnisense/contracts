# Set your limits (for the company that runs an API)

If your API has an omnisense provider package, every site that uses it reads your usage policy
from that package's `policy.json`, and every call goes through a guard that holds the site to
it. You can set the policy yourself.

## Where it lives

`policy.json` at the root of the provider package (`omnitravel/transitous`,
`omnitravel/navitia`...), valid against
[`usage-policy.schema.json`](../resources/usage-policy.schema.json). Open a pull request on the
package's repository changing it, or take over the package.

## What you can say

- **Rate limits**: as many as you need, each with a maximum, a window (`second`, `minute`,
  `hour`, `day`, `month`, or several of them: `"every": 5`), a scope (`application`, `key`,
  `client-ip`, `user`) and the operations it counts. A limit may count `units`, `points`,
  `characters` or `content-bytes` instead of requests, with what each operation costs
  (`"cost": {"search": 100}`). Every one is enforced before the call leaves the site.
- **Concurrency**: the most requests you take in flight at once.
- **Rights**: commercial use (`allowed`, `contract`, `forbidden`), open source required,
  caching (allowed up to `maxTtl` seconds, or `forbidden`), storage and redistribution of your
  data, the attribution (its text, its link, where it must show: `display`, `map`, `credits`,
  `source`), the data's licence.
- **Requirements**: a key, a User-Agent matching a pattern (with a contact).
- **Provenance**: set `declaredBy` to `provider`, `verifiedAt` to the day of your change, your
  terms' and policy's URLs, and a contact.

## What sites can and cannot do

A site may only **tighten** your policy - a lower limit, a shorter cache, a right it gives up.
It cannot raise a limit, lengthen a cache, drop or rewrite the attribution, nor lift a
requirement: the configuration is refused with a message naming the field. A site that says it
is commercial, where you forbid commercial use, is refused when it configures your provider.

## What your API can tell clients at run time

The guard also reads your responses:

- `Retry-After` (seconds or an HTTP-date) on a 429 or a 503: no call goes out until it is over;
- `RateLimit` and `RateLimit-Policy` as the IETF draft writes them
  (`RateLimit: "default";r=0;t=30`): with nothing left, the next calls wait for the window's end.

Sending them lets you change a limit without a new release of the package.

## Seeing every declared policy

`vendor/bin/omnisense-policies` prints them all as JSON; the omnisense website lists them.
