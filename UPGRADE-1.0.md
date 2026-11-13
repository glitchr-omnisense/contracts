# Upgrading to 1.0

## From 1.0.0-rc.1

Nothing in the package's code changed between 1.0.0-rc.1 and 1.0.0. A family or site that works
on rc.1 works on 1.0.0 unchanged.

- **Constraint**: require `omnisense/contracts: ^1.0`. Drop `@dev` and `@RC`: Composer reads a
  stability flag only in the root project's composer.json, so a family that requires
  `^1.0@dev` does not install in a project whose minimum-stability is stable. `^1.0` does.
- **Internal**: `Testing\RecordedChunk` and `Testing\RecordedStream` are now marked `@internal`.
  `RecordedHttpClient::stream()` builds them. Read them through Symfony's `ChunkInterface` and
  `ResponseStreamInterface`, as before.
- **Release archive**: Composer installs the package without `Tests/`, `.github/` and the
  analysis configuration. `bin/`, `Testing\`, `resources/` and `docs/` are still in it.
- **Fallbacks for older heads**: a family that still guards against the pre-rc heads (1568f2c
  and older), such as `class_exists(NoRetry::class)`, `\defined(Scope::class.'::Resource')` or a
  plain `call()` loop instead of `PolicyGuard::pages()`, can drop that code once it requires
  `^1.0`, because every 1.x has them.

## From 1.x-dev before rc.1

Everything up to rc.1 was backward compatible (CHANGELOG, `1.0.0-rc.1`). Every `policy.json`
written since the first state reads as it did.
