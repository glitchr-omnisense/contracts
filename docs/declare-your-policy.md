# Declare your policy (for a provider package's maintainer)

Your package wraps someone else's API. Its terms say how it may be used: write that down once,
as data, so every site that installs your package is held to it.

1. **Read the terms and the usage policy at their source**, on the day you write the file. Do
   not copy a figure from a blog post, a search result or memory. If a page cannot be read (a
   403, a login wall), leave the field unknown and say so in `provenance.notes`.
2. **Write `policy.json` at your package's root**, against the schema:

   ```json
   {
       "$schema": "https://github.com/glitchr-omnisense/contracts/blob/1.x/resources/usage-policy.schema.json",
       "provider": "transitous",
       "rateLimits": [{"max": 720, "window": "day", "operations": ["journeys"], "note": "More is heavy use: ask first."}],
       "rights": {"commercial": "forbidden", "openSourceRequired": true, "attribution": {"required": true, "text": "...", "url": "...", "where": ["display"]}},
       "requirements": {"userAgent": {"required": true, "pattern": "~/.*[(;@]|[(;@].*/~"}},
       "provenance": {"terms": "https://...", "policy": "https://...", "verifiedAt": "2026-11-10", "declaredBy": "maintainer", "contact": "..."}
   }
   ```

   - `rateLimits`: `null` when you do not know them, `[]` when the terms set none. A threshold
     the terms call "heavy use, ask first" is a limit too: write it with a `note`.
   - `operations`: the family's operation names, which are its contract methods (omnitravel:
     `departures`, `journeys`, `vehicles`, `stop`, `nearby`, `searchStops`, `lines`, `line`,
     `lineStops`, `stopLines`, `operators`). Map the API's endpoints onto them: Transitous'
     `/plan` is `journeys`.
   - A window of several units is `"every"`: 500 every 5 minutes is
     `{"max": 500, "window": "minute", "every": 5}` - not 100 a minute, which is stricter than
     the terms and lets no burst through.
   - A quota counted in something else than requests says its `unit` (`units`, `points`,
     `characters`, `content-bytes`) and each operation's `cost`; an operation that makes
     several HTTP requests under a `requests` limit has a cost too. A cost only the call knows
     (characters translated, pages followed) is reported by the provider in the guard's
     `amounts`.
   - `concurrency`: the requests the terms allow in flight at once, when they say.
   - Unknown is a value: `"unknown"` for a permission, `null` for `openSourceRequired`. A site
     cannot turn it into "allowed".
   - `provenance.verifiedAt` is the day you read it. Update it each time you check again.
3. **Name it in `composer.json`**, so the export finds it:

   ```json
   "extra": {"omnisense": {"policy": "policy.json"}}
   ```
4. **Give it to the family**: have your provider implement `DeclaresUsagePolicyInterface`
   (`usagePolicy(): UsagePolicy`, read once from the file), and pass it to its Capabilities
   (`policy:`). Pass the factory's `Config::backoff()` to your `JsonClient`, so that 429s and
   `Retry-After` are honoured.
5. **Test it**: the declared figures, a limit reached through the registry (no request goes out),
   a 429 with its `Retry-After` honoured, a looser site configuration refused. omnitravel/transitous'
   `Tests/PolicyTest.php` is the model.

When the data's terms are per source (a GTFS feed's licence), they are the site's to give in
its configuration, and your `policy.json` says so in `provenance.notes`, its rights unknown.
