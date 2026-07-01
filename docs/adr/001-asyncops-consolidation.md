# ADR-001: AsyncOps Consolidation Decision

**Status:** Accepted
**Date:** 2026-06-30
**Deciders:** SugarCraft Audit Team

---

## Context

During the repeated_logic audit (Finding #5), we identified that both `candy-async/src/AsyncOps.php` and `candy-files/src/AsyncOps.php` implement similar async utility patterns using `Deferred` + `futureTick` from ReactPHP. This raised the question of whether these should be consolidated into a single shared `AsyncOps` class.

## Decision

**Keep `candy-async/AsyncOps` and `candy-files/AsyncOps` as separate classes at different abstraction levels.** Do not merge them.

## Rationale

### candy-async/AsyncOps (212 lines) — Generic async patterns
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

1. **candy-async** provides **combinators** (timeout, retry, debounce, throttle) that transform any Promise
2. **candy-files** provides **operations** that produce Promises for specific domain actions

Consolidating them would either:
- Force file operations to depend on generic async utilities they don't need
- Create a kitchen-sink utility class that mixes concerns

## Consequences

### Positive
- Clear separation of concerns between generic async utilities and domain-specific operations
- Libraries can use candy-async without pulling in candy-files dependencies
- Each class can evolve independently based on its domain needs

### Negative
- Some code duplication in the `Deferred` + `futureTick` pattern (acceptable trade-off)

## Alternatives Considered

### 1. Extract shared `Deferred` pattern to `candy-core`
- **Rejected:** The `Deferred` class from ReactPHP is already the shared primitive. Extracting a wrapper would add indirection without benefit.

### 2. Have candy-files use candy-async utilities internally
- **Rejected for v1:** While `candy-files/AsyncOps` could theoretically use `candy-async::retry()` for copy operations with automatic retry, this creates a hard dependency from candy-files to candy-async. At this stage, keeping them independent preserves flexibility.

## Notes

- Future refactoring (post-v1.0) may revisit whether `candy-files` operations should use `candy-async::retry()` for automatic retry on I/O failures
- Both classes should document their use of ReactPHP's `Deferred` + `futureTick` pattern for discoverability
