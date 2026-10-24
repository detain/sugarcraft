# ADR-001: AsyncOps Consolidation Decision

**Status:** Accepted
**Date:** 2026-06-30
**Deciders:** SugarCraft Audit Team

> **Naming note (2026-10 rename):** this record predates the rename. `candy-async` is the package today published as `sugar-async` (its `AsyncOps` now lives at `sugar-async/src/AsyncOps.php`); `candy-files` kept its name. Historical paths are preserved verbatim below.

---

## Context

During the repeated_logic audit (Finding #5), we identified that both `candy-async/src/AsyncOps.php` and `candy-files/src/AsyncOps.php` implement similar async utility patterns using `Deferred` + `futureTick` from ReactPHP. This raised the question of whether these should be consolidated into a single shared `AsyncOps` class. <!-- @rename-keep historical provenance -->

## Decision

**Keep `candy-async/AsyncOps` and `candy-files/AsyncOps` as separate classes at different abstraction levels.** Do not merge them. <!-- @rename-keep historical provenance -->

## Rationale

### candy-async/AsyncOps (212 lines) — Generic async patterns <!-- @rename-keep historical provenance -->
- `withTimeout(LoopInterface, PromiseInterface, float)` — wraps promise with timeout
- `retry(callable, int, float, ?CancellationToken)` — retry with exponential backoff
- `debounce(callable, float, ?LoopInterface)` — returns debounced closure
- `throttle(callable, float, ?LoopInterface)` — returns throttled closure

These are **generic, reusable async utilities** that apply to any Promise-based operation regardless of domain.

### candy-files/AsyncOps (207 lines) — File-specific I/O operations
- `copyAsync(string $src, string $dst): PromiseInterface<bool>`
- `moveAsync(string $src, string $dst): PromiseInterface<bool>`
- `renameAsync(string $src, string $newName): PromiseInterface<bool>`
- `copyManyAsync(array $map): PromiseInterface<array<string, bool>>`
- `moveManyAsync(array $map): PromiseInterface<array<string, bool>>`

These are **file-operation-specific** commands that happen to use async patterns.

### Key insight

While both use `Deferred` + `futureTick` as the underlying implementation technique, they operate at fundamentally different abstraction levels:

1. **candy-async** provides **combinators** (timeout, retry, debounce, throttle) that transform any Promise <!-- @rename-keep historical provenance -->
2. **candy-files** provides **operations** that produce Promises for specific domain actions

Consolidating them would either:
- Force file operations to depend on generic async utilities they don't need
- Create a kitchen-sink utility class that mixes concerns

## Consequences

### Positive
- Clear separation of concerns between generic async utilities and domain-specific operations
- Libraries can use candy-async without pulling in candy-files dependencies <!-- @rename-keep historical provenance -->
- Each class can evolve independently based on its domain needs

### Negative
- Some code duplication in the `Deferred` + `futureTick` pattern (acceptable trade-off)

## Alternatives Considered

### 1. Extract shared `Deferred` pattern to `sugar-core`
- **Rejected:** The `Deferred` class from ReactPHP is already the shared primitive. Extracting a wrapper would add indirection without benefit.

### 2. Have candy-files use candy-async utilities internally <!-- @rename-keep historical provenance -->
- **Rejected for v1:** While `candy-files/AsyncOps` could theoretically use `candy-async::retry()` for copy operations with automatic retry, this creates a hard dependency from candy-files to candy-async. At this stage, keeping them independent preserves flexibility. <!-- @rename-keep historical provenance -->

## Notes

- Future refactoring (post-v1.0) may revisit whether `candy-files` operations should use `candy-async::retry()` for automatic retry on I/O failures <!-- @rename-keep historical provenance -->
- Both classes should document their use of ReactPHP's `Deferred` + `futureTick` pattern for discoverability
