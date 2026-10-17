# Step 08 — Create candy-async <!-- @rename-keep historical provenance -->

**Branch:** `ai/candy-async-new` <!-- @rename-keep historical provenance -->
**Depends on:** step-00 ✅
**Blocks:** step-23 (sugar-forms / sugar-forms / candy-core migrate to candy-async) <!-- @rename-keep historical provenance -->

## Goal

Create the `candy-async` foundation package — unifies the fragmented ReactPHP usage across candy-core (`exec()` that blocks), sugar-forms / sugar-forms (async suggestions but no cancellation), and others. Provides a `Cancellable` token, `Subscription` lifecycle, and small `AsyncOps` helpers around ReactPHP's event loop. Consumers in step-23 swap their ad-hoc usage for these. <!-- @rename-keep historical provenance -->

Reference: `docs/repo_map_update.md` §369.6 (consolidation of async patterns), §387.5 (ReactPHP reinvention).

## Files expected to be created

- `candy-async/composer.json` <!-- @rename-keep historical provenance -->
- `candy-async/phpunit.xml` <!-- @rename-keep historical provenance -->
- `candy-async/README.md` <!-- @rename-keep historical provenance -->
- `candy-async/CALIBER_LEARNINGS.md` <!-- @rename-keep historical provenance -->
- `candy-async/src/Cancellable.php` — interface (`isCancelled(): bool`, `cancel(): void`, `onCancel(callable): void`). <!-- @rename-keep historical provenance -->
- `candy-async/src/CancellationToken.php` — concrete; immutable token + a Source that flips it. <!-- @rename-keep historical provenance -->
- `candy-async/src/CancellationSource.php` — owner side; `cancel()` flips the token. <!-- @rename-keep historical provenance -->
- `candy-async/src/Subscription.php` — interface (`unsubscribe(): void`, `isActive(): bool`). <!-- @rename-keep historical provenance -->
- `candy-async/src/Subscriptions.php` — Manager for multiple subscriptions (`compose()`, `disposeAll()`). <!-- @rename-keep historical provenance -->
- `candy-async/src/AsyncOps.php` — static helpers: `withTimeout(LoopInterface $loop, PromiseInterface $p, float $secs): PromiseInterface`, `retry(callable, int $attempts, float $backoff)`, `debounce`, `throttle`. <!-- @rename-keep historical provenance -->
- `candy-async/src/Suspended.php` — value-object wrapping a paused operation (for resumable Cmds in the TEA model). <!-- @rename-keep historical provenance -->
- `candy-async/tests/CancellationTokenTest.php`, `SubscriptionsTest.php`, `AsyncOpsTest.php`. <!-- @rename-keep historical provenance -->

## Files expected to be modified

- Root composer.json, MATCHUPS, PROJECT_NAMES, README, docs/index.html, docs/lib/candy-async.html, codecov.yml. <!-- @rename-keep historical provenance -->

## Acceptance criteria

- [ ] `CancellationSource::new(): self` + `CancellationSource::token(): CancellationToken`.
- [ ] `CancellationToken` is read-only; only its `Source` can cancel it.
- [ ] `Cancellable::onCancel(callable)` callbacks fire exactly once, even if cancel() is called multiple times.
- [ ] `Subscriptions::compose(Subscription ...$subs)` returns a single Subscription whose `unsubscribe()` disposes all.
- [ ] `AsyncOps::withTimeout` cancels the inner promise on timeout (uses CancellationToken plumbing).
- [ ] `AsyncOps::retry` honors cancellation between attempts.
- [ ] `AsyncOps::debounce(callable, float $secs, LoopInterface)` returns a wrapped callable.
- [ ] **Depends on `react/event-loop` ^1.5** (or whatever the project already pins) — leaf within `sugarcraft/*`.
- [ ] ≥95 % coverage.
- [ ] `git status` clean on master.

## Coder brief

1. **Audit existing usage first** (delegate to an Explore subagent):
   - "Find every `use React\` import across the SugarCraft monorepo. Group by what ReactPHP concept it uses (Loop, Promise, Stream, EventEmitter, ChildProcess). Report which files would benefit from candy-async helpers vs. which use ReactPHP directly in a way candy-async shouldn't interfere with." <!-- @rename-keep historical provenance -->
   - Block on the report. Append findings to `docs/repo_map_updates.md` so step-23 has a roadmap.
2. **Invoke `scaffold-library`** with slug `candy-async`, namespace `SugarCraft\Async\`, role "ReactPHP utilities: cancellation, subscriptions, retries (shared)". <!-- @rename-keep historical provenance -->
3. **Require `react/event-loop`** + `react/promise` in composer.json (match versions already used elsewhere in the monorepo).
4. **Implement Cancellation pair**: Source holds mutable flag, Token holds read-only reference. `onCancel` callbacks fire from `Source::cancel()` in registration order.
5. **Implement Subscriptions**: a Subscription is the disposal handle returned by `subscribe()`-style APIs. `Subscriptions::compose(...)` lets multiple subs be disposed atomically (used in TEA's `subscriptions()` lifecycle).
6. **Implement AsyncOps helpers**: pure functions over `react/promise` + `react/event-loop`. Use `Loop::addTimer` for timeout/debounce/throttle. Tests use `React\EventLoop\Loop::run()` with bounded fixture promises.
7. Run phpunit + check-path-repos.

## Tester brief

- Cancellation: register 3 callbacks, cancel once, assert all 3 fire in order, in single call.
- Token is immutable from outside: try to mutate via reflection; should be possible (PHP) but document that callers should never. Test that `Source::cancel()` is the supported path.
- Subscriptions::compose: dispose composed → all underlying disposed; calling `unsubscribe()` twice is idempotent.
- AsyncOps::withTimeout: promise resolves before timeout → success; after → rejection with TimeoutException + inner promise's cancellation triggered.
- AsyncOps::retry: 3 attempts, fail-fail-success → 1 final success; cancellation between attempts → no further attempts.
- AsyncOps::debounce: rapid calls within window → only last fires; isolated call after window → fires immediately.
- All async tests use ReactPHP loop with bounded fixtures (no real time waits >100ms).

## Scribe brief

- README: pitch as "the shared async vocabulary". Quickstart: CancellationSource + AsyncOps::withTimeout chained example.
- CALIBER_LEARNINGS: "ReactPHP event loop is shared across consumers — don't construct multiple loops. Pass `Loop::get()` or accept a `LoopInterface` parameter."
- MATCHUPS: 🚀 (PHP-native pattern, no Charmbracelet upstream).
- PROJECT_NAMES Candy table.
- Docs/index.html + docs/lib/candy-async.html. <!-- @rename-keep historical provenance -->

## Ship brief

- **PR title**: `candy-async: shared cancellation + subscription + AsyncOps utilities` <!-- @rename-keep historical provenance -->
- **PR body**:
  ```
  ## Summary
  - New foundation lib candy-async unifies ReactPHP usage across the monorepo. <!-- @rename-keep historical provenance -->
  - Provides Cancellable tokens, Subscriptions composition, AsyncOps (withTimeout, retry, debounce, throttle).
  - Consumers (sugar-core, sugar-forms, sugar-forms) migrate in step-23.

  ## Test plan
  - [x] vendor/bin/phpunit in candy-async (≥95% coverage) <!-- @rename-keep historical provenance -->
  - [x] All async tests bounded with fixture promises; no >100ms real waits
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_08.md, docs/repo_map_update.md §369.6, §387.5
  ```
- Commit subject: `candy-async: cancellation tokens + async helpers`. <!-- @rename-keep historical provenance -->
