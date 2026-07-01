---
status: not-started
phase: 1
updated: 2026-06-30
---

# Implementation Plan: sugar-dash Code Review Fixes

## Goal

Fix all identified critical, high, medium, and low severity issues in the sugar-dash TUI dashboard library, covering syntax errors, import errors, immutability violations, design issues, and missing features across Layout, Events, Module, Plot, Plugin, and State components.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Fix C-1 first (Stack.php parse error) | Blocking issue — entire class fails to autoload | `findings/sugar-dash.md:C-1` |
| C-2 requires namespace resolution | `SugarCraft\Buffer\*` must be resolved against actual candy-buffer package namespace | `findings/sugar-dash.md:C-2` |
| EventDispatcher must use immutable dispatch pattern | All other methods use clone-based immutability; dispatch() is inconsistent | `findings/sugar-dash.md:C-3` |
| RingBuffer oldest() bug confirmed for wrapped partial buffer | toArray() and oldest() disagree after buffer wraps but is not yet full | `findings/sugar-dash.md:H-4` investigation |
| ClockModule H-2 is a false positive | `$time` is NOT declared `readonly` — `withTime()` clone pattern works correctly | investigation at `src/Modules/Clock/ClockModule.php:19` |
| Sizer/Drawable interfaces exist | Found at `src/Foundation/Sizer.php` and `src/Foundation/Drawable.php` — L-5 is invalid | investigation |
| Phased approach: Critical → High → Medium → Low | Critical issues block the library from running at all | standard severity ordering |

## Phase 1: Critical Issues [PENDING]

### 1.1 C-1: Fix Stack.php Parse Error (Missing Space Before `&&`)

**File:** `sugar-dash/src/Layout/Stack.php:78`
**Severity:** Critical
**Category:** Syntax Error

**What is expected:** Add a space between `Sizer` and `&&` on line 78.

**Why the change should be done:** The current code `Sizer&& $useWidth` is a parse error. PHP interprets `Sizer&&` as an attempt to use `&&` as a constant name, causing a fatal parse error that prevents the class from being autoloaded entirely.

**Conditions for success:**
- `php -l src/Layout/Stack.php` returns no errors
- `Stack::render()` can be called without parse failure

**Related code locations:**
- `sugar-dash/src/Layout/Stack.php:78`

**Investigation notes:**
Confirmed at line 78:
```php
if ($item instanceof \SugarCraft\Dash\Foundation\Sizer&& $useWidth > 0) {
```
The fix is simply inserting a space: `Sizer &&`

---

### 1.2 C-2: Fix Non-Existent Package Imports in Chart.php

**File:** `sugar-dash/src/Plot/Chart/Chart.php:7-9`
**Severity:** Critical
**Category:** Compatibility Issues / Wrong Imports

**What is expected:** Replace the three `SugarCraft\Buffer\*` imports with the correct namespace that corresponds to actual monorepo classes. The `composer.json` shows `sugarcraft/candy-buffer` is a dependency with a path repository entry. The actual namespace exposed by candy-buffer needs to be determined and used, OR the imports should reference classes that exist within sugar-dash itself (e.g., `SugarCraft\Dash\Foundation\Buffer` if those classes exist there).

**Why the change should be done:** Any code that instantiates or renders a `Chart` object will fatal with `Class "SugarCraft\Buffer\Buffer" not found`. This is a blocking issue — Chart is completely unusable.

**Conditions for success:**
- Chart class can be instantiated without fatal error
- `php -l src/Plot/Chart/Chart.php` passes
- Unit tests for Chart pass

**Related code locations:**
- `sugar-dash/src/Plot/Chart/Chart.php:7-9` (the wrong imports)
- `sugar-dash/composer.json:38,102-106` (candy-buffer dependency and path repo)
- `sugar-dash/src/Foundation/Buffer.php` (if it exists — needs investigation)
- `sugar-dash/src/Foundation/Cell.php` (if it exists — needs investigation)

**Investigation notes:**
The composer.json at lines 38 and 102-106 shows `sugarcraft/candy-buffer` is available via path repository. The imports in Chart.php use `SugarCraft\Buffer\*` which does not match the `SugarCraft\Dash\*` namespace prefix used throughout sugar-dash. The actual Buffer/Cell classes may be in:
1. `candy-buffer` package with namespace `SugarCraft\Buffer\` (if that package exposes this namespace)
2. `sugar-dash/src/Foundation/` with names like `SugarCraft\Dash\Foundation\Buffer`

The fix requires determining the actual namespace of Buffer/Cell in candy-buffer and updating Chart.php imports accordingly. If `DiffEncoder` does not exist anywhere, it may need to be implemented or the diff logic in Chart.php lines 700-717 may need redesign.

---

### 1.3 C-3: Fix EventDispatcher::dispatch() Mutation

**File:** `sugar-dash/src/Events/EventDispatcher.php:98-120`
**Severity:** Critical
**Category:** Immutability Violation

**What is expected:** Redesign `dispatch()` to return `[Event, self]` (tuple of event and new dispatcher) instead of mutating `$this->listeners` and `$this->onceKeysToRemove` in-place. The Elm-architecture pattern used throughout the Module system returns `[newModule, ?Cmd]` from `update()` — the EventDispatcher should follow the same immutable pattern.

**Why the change should be done:**
1. The class uses clone-based immutability throughout (`on()`, `once()`, `off()`, `clear()` all return new instances). `dispatch()` violates this contract.
2. The `array_push()` at line 60 returns the new count, not a boolean. `$key - 1` gives the correct index only when there are no gaps. After `off()` removes handlers (using `array_filter` at lines 83-86, which preserves numeric keys), the numeric indices stored in `onceKeysToRemove` point to wrong slots.
3. Mutating `$this` after `dispatch()` means the "old" dispatcher instance shared with any subscriber has already lost its once-handlers.

**Conditions for success:**
- `dispatch()` returns `[Event, self]` tuple
- `once()` handlers are removed only in the new returned dispatcher instance
- Calling `dispatch()` twice on the same dispatcher instance produces correct behavior both times
- Unit tests verify immutability: original dispatcher unchanged after dispatch of a `once` event

**Related code locations:**
- `sugar-dash/src/Events/EventDispatcher.php:52-70` (`once()` method)
- `sugar-dash/src/Events/EventDispatcher.php:77-89` (`off()` method using `array_filter`)
- `sugar-dash/src/Events/EventDispatcher.php:98-120` (`dispatch()` method - lines 112-117 are the mutation)
- `sugar-dash/src/Events/EventDispatcher.php:60-61` (`array_push` returning count issue)

**Investigation notes:**
The `array_filter` in `off()` (line 83-86) preserves numeric array keys. After `off()` removes an element at index 2, the array becomes `[0 => handler0, 1 => handler1, 3 => handler3]`. When `once()` stores `onceKey = 2` in `onceKeysToRemove`, and then `dispatch()` does `unset($this->listeners[$eventType][2])`, it unsets whatever is now at index 2 (which might be a different handler that was added after the original once handler). This creates silent cross-contamination of event routing.

The fix should make `dispatch()` return `[$event, $newDispatcher]` and mark the approach as `[ ] **CURRENT**` when implementation begins.

---

## Phase 2: High Severity Issues [PENDING]

### 2.1 H-1: Chart render() Mutates Diff State In-Place

**File:** `sugar-dash/src/Plot/Chart/Chart.php:129-134`
**Severity:** High
**Category:** Immutability Violation

**What is expected:** Either (a) document that `Chart` is intentionally mutable between renders, or (b) refactor `render()` to use a render-context object passed through the call stack that carries `previousFrame`, `prevWidth`, `prevHeight`. This would avoid mutating `$this` while still allowing diff-based rendering.

**Why the change should be done:** The `render()` method mutates `$this->previousFrame`, `$this->prevWidth`, and `$this->prevHeight` in place. This is inconsistent with the wither-based immutability pattern used throughout the rest of the codebase. Calling `render()` twice with different size constraints on the same `Chart` instance will silently produce incorrect output (diff computed against a buffer from a different-size first frame).

**Conditions for success:**
- `Chart` instances can be safely reused across render calls with different size constraints
- No mutation of `$this` during `render()` — either document the intentional mutability or refactor to render-context pattern
- Unit tests verify correct behavior when same Chart is rendered at different sizes

**Related code locations:**
- `sugar-dash/src/Plot/Chart/Chart.php:46-50` (property declarations: `$previousFrame`, `$prevWidth`, `$prevHeight`)
- `sugar-dash/src/Plot/Chart/Chart.php:129-134` (the mutation in render())
- `sugar-dash/src/Plot/Chart/Chart.php:160-167` (where previousFrame is used for diff computation)

**Investigation notes:**
The diff-based rendering pattern (storing `previousFrame` and computing a delta) is a valid optimization to avoid re-rendering unchanged cells. The issue is that this optimization state is stored on `$this` rather than being passed as a render-context parameter. The simplest fix is option (a) — document that Chart is intentionally mutable for diff-rendering purposes, since PHP's value semantics make deep cloning of Buffer objects expensive. However, this creates a temporal coupling hazard that should be documented.

---

### 2.2 H-2: RingBuffer::oldest() Bug (Wrap-Around Partial Buffer)

**File:** `sugar-dash/src/Plot/RingBuffer.php:122-133`
**Severity:** High
**Category:** Fail Fast

**What is expected:** Fix `oldest()` to return the correct oldest element when the buffer has wrapped but is not yet full. The bug: when buffer has wrapped (index > 0) but count < size (partially filled after wrap), `oldest()` returns `data[$index]` instead of `data[0]`.

**Why the change should be done:** The current `oldest()` returns wrong values in the wrapped-partial buffer case. For example, after pushing [A, B, C, D] then [E, F] (size=4), the buffer contains [E, F, C, D] with index=2 and count=4. `oldest()` returns `data[2] = C` but the correct oldest is `data[0] = E`. This silently corrupts time-series data for chart rendering.

**Conditions for success:**
- Unit tests cover the wrapped-partial buffer scenario: push elements until wrap, then push a few more without filling, verify `oldest()` returns the correct element
- `oldest()` result always matches `toArray()[0]` when buffer is non-empty
- All existing RingBuffer tests continue to pass

**Related code locations:**
- `sugar-dash/src/Plot/RingBuffer.php:122-133` (the `oldest()` method)
- `sugar-dash/src/Plot/RingBuffer.php:81-100` (the `toArray()` method — correct reference implementation)

**Investigation notes:**
After thorough investigation, the bug manifests in this specific scenario:
1. Push A, B, C, D (fills buffer, index=0)
2. Push E (wraps, index=1, data=[E,B,C,D])
3. Push F (index=2, data=[E,F,C,D])
4. Now: index=2, count=4, data=[E,F,C,D]
5. `oldest()` returns `data[2] = C` (WRONG — should be E)
6. `toArray()` returns `[C, D, E, F]` (correct chronological order)
7. `latest()` returns `data[1] = F` (correct)

The fix: when `count < size`, return `data[0]` unconditionally (the buffer hasn't started its second wrap, so the oldest is at data[0]). When `count == size` (fully wrapped), return `data[$index]` which correctly points to the oldest after the first full cycle.

Current code at line 132 returns `data[$index]` for ALL cases where `count >= size`. The fix should only return `data[$index]` when `count == size` (exact full condition), not for `count > size` (which shouldn't occur but is guarded by `min(count+1, size)` in push).

Actually, the cleanest fix: `oldest()` when `count > 0` should return `data[($index - $count + $size) % $size]` which simplifies correctly for both partial and full cases.

---

### 2.3 H-3: SystemModule fetchSystemData() Mutates $this Directly

**File:** `sugar-dash/src/Modules/System/SystemModule.php:97-112`
**Severity:** High
**Category:** Immutability Violation

**What is expected:** Refactor `fetchSystemData()` to accept current values as parameters and return new values, OR store all state exclusively in the `$state` array (via `BaseModule::withState()`) rather than as direct private properties. The Elm-architecture contract (`update() → [newModule, ?Cmd]`) is broken because `$this` is mutated before `withSystemState()` is called.

**Why the change should be done:** The `update()` method calls `$this->fetchSystemData()` before `withSystemState()`. Since `fetchSystemData()` mutates direct properties (`$cpuLoad`, `$memLoad`, `$gpuLoad`, `$uptime`, `$cpuHistory`, `$memHistory`) on `$this` in-place, the "old" module instance shared with any subscriber is already mutated when `update()` returns. This violates the Elm architecture's immutability guarantee.

**Conditions for success:**
- SystemModule's `update()` returns an instance where the original instance is unchanged
- Unit tests verify that calling `update()` twice on the same instance produces two different module instances with different state
- All properties that affect `view()` output are stored in the state array returned by `withSystemState()`

**Related code locations:**
- `sugar-dash/src/Modules/System/SystemModule.php:22-31` (direct property declarations)
- `sugar-dash/src/Modules/System/SystemModule.php:43-50` (`update()` method)
- `sugar-dash/src/Modules/System/SystemModule.php:85-95` (`withSystemState()` method)
- `sugar-dash/src/Modules/System/SystemModule.php:97-112` (`fetchSystemData()` — the mutation)

**Investigation notes:**
The dual-storage pattern (direct properties + state array) is the root cause. `fetchSystemData()` mutates direct properties, then `withSystemState()` syncs them into the state array. But the mutation already happened on `$this` before the clone is created. The fix is to eliminate direct property storage and compute state directly in `withSystemState()` by reading from `/proc/*` files at that point.

---

## Phase 3: Medium Severity Issues [PENDING]

### 3.1 M-1: WeatherModule $_SERVER['HOME'] Fallback

**File:** `sugar-dash/src/Modules/Weather/WeatherModule.php:196-200`
**Severity:** Medium
**Category:** Compatibility Issues

**What is expected:** Improve the `cachePath()` method to use `getenv('HOME')` as an additional fallback and document that `/tmp` is the ultimate fallback. Also address the `@file_get_contents($path)` silent failure at line 154.

**Why the change should be done:** On some CGI/FastCGI configurations, `$_SERVER['HOME']` may not be set. While the `??` operator handles the undefined index warning, `getenv()` is the more portable way to read environment variables and should be preferred as a primary source.

**Conditions for success:**
- `cachePath()` works correctly on systems without `$_SERVER['HOME']` (e.g., some PHP-FPM configurations)
- Code uses `getenv('HOME') ?: $_SERVER['HOME'] ?: $_SERVER['USERPROFILE'] ?: sys_get_temp_dir()`
- `@file_get_contents` failure is logged or the method returns a more descriptive result

**Related code locations:**
- `sugar-dash/src/Modules/Weather/WeatherModule.php:196-200` (cachePath())
- `sugar-dash/src/Modules/Weather/WeatherModule.php:154` (silent file_get_contents failure)

---

### 3.2 M-2: Plugin/Discovery Uses Legacy Directory Iteration

**File:** `sugar-dash/src/Plugin/Discovery.php:32-54`
**Severity:** Medium
**Category:** Compatibility Issues

**What is expected:** Replace `opendir()`/`readdir()`/`closedir()` with `FilesystemIterator` using `SKIP_DOTS | CURRENT_AS_FILEINFO` flags. Replace individual `is_file()`/`is_executable()` calls with `FilesystemIterator::isExecutable()`.

**Why the change should be done:** The legacy directory iteration pattern is considered obsolete. `FilesystemIterator` is more memory-efficient for large directories, provides better object orientation, and groups the file metadata retrieval into a single system call per entry rather than separate `is_file()` and `is_executable()` stat calls.

**Conditions for success:**
- `Discovery::scan()` returns the same results using `FilesystemIterator` as it did with `opendir()`/`readdir()`
- All existing plugin discovery tests pass

**Related code locations:**
- `sugar-dash/src/Plugin/Discovery.php:24-57` (the `scan()` method)

---

### 3.3 M-3: LegacyModuleAdapter Discards Msg Parameter

**File:** `sugar-dash/src/Module/LegacyModuleAdapter.php:54-60`
**Severity:** Medium
**Category:** Missing Features

**What is expected:** Add a docblock comment to the `update()` method explicitly documenting that the `Msg` parameter is discarded and that legacy modules advance state on every message regardless of message type. Alternatively, implement basic message-type filtering where the legacy module's `update()` is only called when the message type matches a registered type.

**Why the change should be done:** The `Msg` parameter is completely discarded. The adapter's state advances on every message, not just messages relevant to the legacy module. Without documentation, this design limitation is invisible to consumers of the API.

**Conditions for success:**
- The limitation is clearly documented in the class docblock and the `update()` method docblock
- Unit tests demonstrate the adapter's behavior with mixed message types

**Related code locations:**
- `sugar-dash/src/Module/LegacyModuleAdapter.php:54-60` (`update()` method)

---

### 3.4 M-4: Chart Wither Boilerplate (254 lines)

**File:** `sugar-dash/src/Plot/Chart/Chart.php:436-690`
**Severity:** Medium
**Category:** Duplicated Logic

**What is expected:** Refactor to a single `with()` method accepting an overrides array or partial parameters, or introduce a named constructor that handles optional parameter inheritance. This would reduce ~254 lines of repeated boilerplate to ~20 lines.

**Why the change should be done:** Every wither (12 methods: `withDataPoints`, `withType`, `withWidth`, `withHeight`, `withGrid`, `withShowValues`, `withShowLabels`, `withXAxisLabel`, `withYAxisLabel`, `withColor`, `withGridColor`, `withLabelColor`) duplicates all 11 constructor parameters. Adding a new parameter requires updating all 12+ withers. This is a maintenance hazard.

**Conditions for success:**
- All 12 existing `with*()` methods continue to work (backward compatible)
- A new `with(array $overrides)` method is introduced as the canonical refactored form
- Unit tests for all withers continue to pass
- Code size reduced by ~200 lines

**Related code locations:**
- `sugar-dash/src/Plot/Chart/Chart.php:44-435` (constructor with 11 params)
- `sugar-dash/src/Plot/Chart/Chart.php:436-690` (12 wither methods)

**Investigation notes:**
The recommended pattern mirrors `BaseModule::withState(array $state)` from the Module system. A single `with(array $changes): self` method would construct a new instance by merging `$changes` into the current property values. The existing 12 withers can remain as convenience wrappers calling `with()` internally.

---

### 3.5 M-5: FocusManager array_search() False Handling

**File:** `sugar-dash/src/Layout/FocusManager.php:63-91`
**Severity:** Medium
**Category:** Fail Fast

**What is expected:** Add explicit handling for `array_search()` returning `false` when `$this->focusedId` is not found in the `$ids` array. In PHP 8.3, `false % count($ids)` returns `0` (silently selecting the first ID), which could cause focus to jump to an unexpected element.

**Why the change should be done:** `array_search()` returns `false` when the value is not found. If `$this->focusedId` is set but not in `$this->focusMap` (which can happen after `blur()` and subsequent `unregister()` calls), `false + 1 = 1` which then `% count($ids)` silently selects a valid but incorrect focus target.

**Conditions for success:**
- `focusNext()` and `focusPrevious()` correctly handle the case where `$this->focusedId` is not in the focus map
- Unit tests cover the edge case: register IDs, blur one, unregister it, then call focusNext()

**Related code locations:**
- `sugar-dash/src/Layout/FocusManager.php:63-76` (`focusNext()`)
- `sugar-dash/src/Layout/FocusManager.php:78-91` (`focusPrevious()`)

**Investigation notes:**
At line 70-72, `array_search()` is used without checking for `false`. If `focusedId` is not in `$ids`, `array_search()` returns `false`. Then `$currentIndex = false` (which is coerced to 0 in the `%` operation in PHP 8.3), and `focusNext()` proceeds to focus the second element (index 1) instead of the first.

---

## Phase 4: Low Severity Issues [PENDING]

### 4.1 L-1: Remove Empty EventHandler Interface

**File:** `sugar-dash/src/Events/EventHandler.php:16-17`
**Severity:** Low
**Category:** Missing Features

**What is expected:** Either remove the interface entirely (use `callable` directly throughout the codebase), or implement it with a single `__invoke()` method to make it a proper invokable class interface. The phpDocumentor `@template T of Event` annotation is not a PHP language feature.

**Why the change should be done:** The interface is completely empty — no methods, no constants. It serves only as documentation. In PHP 8.3, `callable` is the natural type for event handlers. The interface adds indirection without adding value.

**Conditions for success:**
- All references to `EventHandler` in type positions are replaced with `callable`
- The `EventHandler` interface file is removed (or retained as documentation-only with a `@deprecated` tag)
- All event registration (`on()`, `once()`) continues to accept the same callable signatures

**Related code locations:**
- `sugar-dash/src/Events/EventHandler.php` (entire file)
- `sugar-dash/src/Events/EventDispatcher.php:13` (import and usage in type hints at lines 33, 50)

---

### 4.2 L-3: Gauge Ratio Double Clamp

**File:** `sugar-dash/src/Plot/Chart/Gauge.php:43-56, 80`
**Severity:** Low
**Category:** Duplicated Logic

**What is expected:** Clamp the ratio in only one place — either in `new()` or in `render()`, not both. Document the chosen location. If clamping only in `new()`, add clamping to all withers.

**Why the change should be done:** If `new()` clamps to `[0, 1]` and `render()` clamps again, the second clamp in `render()` is redundant. If a caller creates a `Gauge` with out-of-range ratio and then uses withers without rendering, the withers would carry the unclamped value.

**Conditions for success:**
- Ratio is clamped exactly once in the codebase
- Unit tests verify behavior at ratio values of -0.5, 0.0, 0.5, 1.0, 1.5

**Related code locations:**
- `sugar-dash/src/Plot/Chart/Gauge.php:43-56` (`new()` clamps at line 46)
- `sugar-dash/src/Plot/Chart/Gauge.php:80` (`render()` clamps at line 80)

---

### 4.3 L-4: Sizer/Drawable Interfaces — VALIDATED AS EXISTING

**Finding status:** L-4 is a false positive. The interfaces exist at:
- `sugar-dash/src/Foundation/Sizer.php`
- `sugar-dash/src/Foundation/Drawable.php`

No action needed. This finding should be marked as resolved.

---

### 4.4 H-2 (ClockModule) — VALIDATED AS FALSE POSITIVE

**Finding status:** H-2 in the original report claimed `$this->time` was declared `readonly` at line 19, making `withTime()` fail at runtime. Investigation at `src/Modules/Clock/ClockModule.php:19` shows:

```php
private \DateTimeImmutable $time;
```

The property is **NOT** `readonly`. The `withTime()` method at lines 67-72 works correctly:

```php
private function withTime(\DateTimeImmutable $time): static
{
    $clone = clone $this;
    $clone->time = $time;  // ✓ works — $time is not readonly
    return $clone;
}
```

No action needed. This finding should be marked as resolved.

---

## Phase 5: Missing Features [PENDING]

### 5.1 F-2: Add Signal Handlers to PluginSdk::run()

**File:** `sugar-dash/src/Plugin/PluginSdk.php:47-75`
**Severity:** Medium
**Category:** Missing Features

**What is expected:** Add `pcntl_signal()` handlers for `SIGTERM` and `SIGINT` to enable graceful shutdown. Also add a check for empty string returned from `fgets()` to prevent potential infinite loop.

**Why the change should be done:** Currently if the process receives a SIGTERM or SIGINT, the loop exits silently with `exit(0)`. There is no way for the host to signal "please stop" that the plugin can respond to. Additionally, if `fgets()` returns an empty string `""` (not `false`), the `trim()` at line 57 produces `""` and the loop `continue`s — but a single empty line from STDIN will spin the loop forever without consuming CPU (empty string immediately returns).

**Conditions for success:**
- Plugin responds to SIGTERM/SIGINT with a graceful exit (call `exit(0)` only after cleanup)
- Empty string from `fgets()` is handled — either breaks the loop or is processed as a valid empty request
- Unit tests or integration tests verify signal handling behavior

**Related code locations:**
- `sugar-dash/src/Plugin/PluginSdk.php:47-75` (the run() method loop)

---

### 5.2 F-4: Add Test for EventDispatcher once() With Array-Gap Scenario

**File:** `sugar-dash/src/Events/EventDispatcher.php` (test file)
**Severity:** Low
**Category:** Missing Features

**What is expected:** Add a PHPUnit test case that covers the scenario: register `once()` handler → call `off()` to create array gaps → call `dispatch()` — verifying that the once handler is correctly removed without corrupting other handlers.

**Why the change should be done:** This is the exact scenario that triggers the index-mismatch bug in C-3. Having a test ensures the fix for C-3 is correct and prevents regression.

**Conditions for success:**
- New test `testOnceHandlerRemovedAfterDispatchWithPriorOffCall()` exists in the EventDispatcher test file
- Test demonstrates that after `once()` → `off()` → `dispatch()`, the dispatcher is in a consistent state

**Related code locations:**
- `sugar-dash/tests/Events/EventDispatcherTest.php` (or similar path)

---

## Phase 6: Duplicated Logic & Compatibility Issues [PENDING]

### 6.1 D-1/D-2: Chart Wither Boilerplate (covered in M-4) and No AbstractChart Base

**File:** Multiple files in `sugar-dash/src/Plot/Chart/`
**Severity:** Low
**Category:** Duplicated Logic

**What is expected (for AbstractChart refactoring):** Extract common rendering utilities into an `AbstractChart` base class that provides:
- `generateGridLines()` logic
- Value range computation
- Dimension validation
- Label formatting utilities

**Why the change should be done:** 25+ chart files each implement similar rendering patterns. Duplication makes it impossible to change grid line generation or value range computation in one place and have it apply everywhere.

**Conditions for success:**
- Common chart rendering logic is extracted to `AbstractChart`
- Area, Bar, Line, Bubble, Candlestick, Donut, Funnel, Gauge, Heatmap, OHLC, Partition, Radar, Sparkline, Waterfall all extend `AbstractChart`
- Existing tests continue to pass

**Note:** This is a large refactoring. It should be scoped as a separate effort after critical/high issues are fixed.

---

### 6.2 D-3/D-4: State Persistence Pattern Not Reused

**Files:** `sugar-dash/src/State/Persistence.php`, `sugar-dash/src/Layout/FocusManager.php:122-147`
**Severity:** Low
**Category:** Duplicated Logic

**What is expected:** Refactor `FocusManager::persistState()` and `FocusManager::restoreState()` to delegate to `State\Persistence` instead of reimplementing the save/load logic. Also check `WeatherModule::saveCache()` at lines 172-194 for the same issue.

**Why the change should be done:** DRY violation. `FocusManager` replicates the save/load pattern without using `State\Persistence` directly.

**Conditions for success:**
- `FocusManager` uses `State\Persistence` for all persistence operations
- `WeatherModule::saveCache()` is reviewed and either uses `Persistence` or is documented as a separate pattern

**Related code locations:**
- `sugar-dash/src/Layout/FocusManager.php:122-147` (FocusManager persistence)
- `sugar-dash/src/Modules/Weather/WeatherModule.php:172-194` (WeatherModule cache)
- `sugar-dash/src/State/Persistence.php:22-51` (Persistence::save — the canonical pattern)

---

## Phase 7: Verify RingBuffer oldest() — Actual Bug Location

After extensive analysis, the RingBuffer `oldest()` method is actually correct when the buffer is **full** (count == size). The bug in the findings report (which claimed `oldest()` returns the wrong element when full) is incorrect based on tracing.

However, a different bug exists when the buffer is **partially full after a wrap**:
- After pushing A, B, C, D (fills size-4 buffer, index=0)
- After pushing E (wraps, index=1)
- After pushing F (index=2, count=4, data=[E,F,C,D])
- `oldest()` returns `data[2] = C` (WRONG — should be E)
- `toArray()` returns `[C, D, E, F]` (chronological order, correct)

This is a different scenario than what the findings report described, but it IS a real bug that warrants fixing. The correct fix is: when `count > 0`, `oldest()` should return `data[($index - $count + $size) % $size]`, which correctly handles all cases (partial-before-wrap, full, partial-after-wrap).

---

## Phase 8: Recommendations Summary and Test Plan

### Immediate Actions (Before Any Other Work)

| Priority | Action | Issue |
|----------|--------|-------|
| 1 | Fix Stack.php:78 — add space before `&&` | C-1 (Critical) |
| 2 | Fix Chart.php imports — determine correct Buffer/Cell namespace | C-2 (Critical) |
| 3 | Fix EventDispatcher::dispatch() — return [Event, self] tuple | C-3 (Critical) |
| 4 | Write test for EventDispatcher once+off+dispatch scenario | F-4 |
| 5 | Verify RingBuffer oldest() fix with wrapped-partial test | H-4 |

### Test Coverage Requirements

Every public method in the following classes must have at least one test covering the specific issue:

| Class | Required Tests |
|-------|----------------|
| `EventDispatcher` | `on()` works, `once()` removes handler after dispatch, `off()` creates gaps that don't corrupt subsequent dispatch, `dispatch()` is immutable (original unchanged after dispatch) |
| `RingBuffer` | `oldest()` matches `toArray()[0]` for: empty, partial (pre-wrap), partial (post-wrap), full |
| `SystemModule` | `update()` returns new instance, original instance state unchanged |
| `ClockModule` | `update()` returns new instance with new time, original instance unchanged |
| `Chart` | `render()` can be called twice on same instance with different sizes, second render is correct |
| `FocusManager` | `focusNext()`/`focusPrevious()` when focusedId not in focusMap |

---

## Notes

- **2026-06-30:** Investigation completed. Key corrections to findings report: (1) H-2 (ClockModule readonly) is a false positive — `$time` is not declared `readonly`. (2) L-5 (Sizer/Drawable interfaces missing) is a false positive — both interfaces exist at `src/Foundation/`. (3) H-4 (RingBuffer) bug description in the findings was incorrect about the "full buffer" case — the actual bug manifests in the wrapped-partial buffer case, which is a different scenario.
- **2026-06-30:** C-2 (Chart.php imports) requires investigating the actual namespace exposed by `candy-buffer` package. The composer.json shows `sugarcraft/candy-buffer` as a path dependency, but the namespace `SugarCraft\Buffer\*` needs to be verified against candy-buffer's actual PSR-4 autoload configuration.
- **2026-06-30:** The 25+ chart files duplication (D-2) is a long-term refactoring goal, not a blocker. Focus on critical and high severity items first.
