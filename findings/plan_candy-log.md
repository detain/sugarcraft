---
status: not-started
phase: 1
updated: 2026-06-30
---

# Implementation Plan: candy-log Code Review Findings

## Goal

Address all 19 findings from the candy-log code review (`findings/candy-log.md`), organized into phases by priority: Critical (before merge), Major (next sprint), and Minor (eventually).

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Use `?resource` nullable type hint for `$stream` | PHP 8.3+ supports nullable type hints; resources are a valid type | `candy-log.md:49-69` |
| Skip ReactPHP async integration for now | Requires significant API design; revisit post-1.0 | `candy-log.md:97-108` |
| Add hook registry to Logger directly | Avoids PsrBridge-only hook firing asymmetry | `candy-log.md:114-128` |
| Refactor JsonFormatter to use shared ValueCoercion | DRY principle; coerceValue duplicates stringify logic | `candy-log.md:201-215` |
| Add `assertAllLevelsCovered()` validation in Styles | Catches missing levels if Level enum is extended | `candy-log.md:241-253` |
| Add working `HookRegistry::remove(int $id)` | Original was broken; hooks can't be unregistered | `candy-log.md:219-236` |

## Phase 1: Critical Issues — Before Next Release [PENDING]

- [ ] **1.1 Fix `setReportCaller`/`setReportTimestamp` to pass `$this->styles` and `$this->partsOrder`** ← CURRENT
  - **What:** `setReportCaller()` (Logger.php:263-268) and `setReportTimestamp()` (Logger.php:277-282) rebuild `TextFormatter` with only 4 args instead of 6, silently resetting styles and partsOrder to defaults.
  - **Why:** Users lose custom styles/partsOrder after calling these setters. Silent data loss bug.
  - **Severity:** Critical
  - **Verify:** `testSetReportCallerPreservesCustomFormatter` and `testSetReportTimestampKeepsCustomFormatter` pass; manual test with custom Styles+PartsOrder followed by setReportCaller preserves them
  - **Code:** `src/Logger.php:258-284`, `src/Formatter/TextFormatter.php:29-43`

- [ ] **1.2 Add `?resource` type hint to Logger `$stream` property and constructor param**
  - **What:** Remove `@var resource` docblock (Logger.php:29) and add `?resource $stream = null` to constructor (Logger.php:56).
  - **Why:** `mixed` bypasses type system; any value accepted until `is_resource()` check at runtime
  - **Severity:** Critical
  - **Verify:** `vendor/bin/phpunit` passes; PHPStan level 9 clean
  - **Code:** `src/Logger.php:29-30, 49-58, 80`

- [ ] **1.3 Add type hints/docblocks to `setOutput()` and `withOutput()` parameters**
  - **What:** Add `@param resource` docblock or explicit type to `setOutput($stream)` and `withOutput($stream)` params that already have docblocks saying `@param resource` but no actual type.
  - **Why:** Consistency with strict typing throughout; self-documents the API
  - **Severity:** Critical
  - **Verify:** `vendor/bin/phpunit` passes
  - **Code:** `src/Logger.php:291-303, 305-319`

- [ ] **1.4 Apply path redaction to `$file` in PanicFormatter::formatBacktrace()**
  - **What:** Redact paths in `$file` (line 97) before rendering at line 122, not just in backtrace frames (135-137).
  - **Why:** Security — if primary exception file contains a secret path, it won't be redacted
  - **Severity:** Critical
  - **Verify:** New test that PanicFormatter redacts primary exception file path when it matches redactPaths
  - **Code:** `src/PanicFormatter.php:97, 122, 135-137`

## Phase 2: Major Issues — Next Sprint [PENDING]

- [ ] **2.1 Add hook registry to Logger directly (or document limitation prominently)**
  - **What:** Either add `Logger::setHookRegistry()` and call from `emit()`, or add prominent documentation in README about hooks only firing via PsrBridge.
  - **Why:** Users calling `Logger->info()` directly don't get hook dispatch — significant design asymmetry
  - **Severity:** High
  - **Verify:** Either hook fires for direct Logger calls, or README clearly documents the limitation
  - **Code:** `src/Logger.php:129-147`, `src/PsrBridge.php:75-82`

- [ ] **2.2 Extract duplicated JsonFormatter::coerceValue() into shared ValueCoercion method**
  - **What:** Add `ValueCoercion::coerce()` method returning mixed (preserving JSON types) and refactor `JsonFormatter::coerceValue()` to delegate non-scalar handling.
  - **Why:** DRY — JsonFormatter reimplements type checks from ValueCoercion; risk of diverging behavior
  - **Severity:** High
  - **Verify:** All formatter tests pass; JsonFormatter output identical
  - **Code:** `src/Formatter/JsonFormatter.php:65-86`, `src/Formatter/ValueCoercion.php:25-65`

- [ ] **2.3 Fix testFatalCallsExit test logic**
  - **What:** The test catches RuntimeException and re-throws with message `'exit(1)'` but actual exception is `Lang::t('logger.fatal', ...)`. Fix to expect the actual message or rename/test the right thing.
  - **Why:** Test is testing wrong thing; name says "callsExit" but fatal() throws, doesn't exit
  - **Severity:** High
  - **Verify:** Test passes and accurately reflects fatal() behavior
  - **Code:** `tests/LoggerTest.php:87-98`, `src/Logger.php:144-146`

- [ ] **2.4 Add working HookRegistry::remove(int $id) method**
  - **What:** Implement remove() using closure wrapper approach (wrap each callback in a closure that captures its ID; remove() sets to null; fire() skips null).
  - **Why:** Without remove(), hooks accumulate in long-running processes causing memory leaks
  - **Severity:** High
  - **Verify:** HookRegistry test that registers, removes, and verifies hook no longer fires
  - **Code:** `src/Hook/HookRegistry.php:28-37, 58-67`

- [ ] **2.5 Add assertAllLevelsCovered() validation in Styles constructor**
  - **What:** Add `private function assertAllLevelsCovered(): void` that validates `\count($this->levels) === \count(Level::cases())`.
  - **Why:** If Level enum is extended without updating Styles match, the new level silently not included
  - **Severity:** High
  - **Verify:** Adding a new Level case without updating Styles triggers assertion error
  - **Code:** `src/Styles.php:16-17, 32-47`

- [ ] **2.6 Add comment explaining no-op handler in Log::installPanicHandler**
  - **What:** Add comment at Log.php:98 explaining WHY the no-op `set_exception_handler(static function (): void {});` is necessary (to capture previous handler before overwriting).
  - **Why:** Known PHP idiom but future maintainers may misunderstand or remove it
  - **Severity:** Medium
  - **Verify:** Comment present and accurate
  - **Code:** `src/Log.php:97-110`

- [ ] **2.7 Remove unnecessary (string) cast in PanicFormatter**
  - **What:** Remove `(string)` cast at line 136 — `$frameFile` is already a string.
  - **Why:** Unnecessary cast adds cognitive overhead
  - **Severity:** Medium
  - **Verify:** `vendor/bin/phpunit` passes
  - **Code:** `src/PanicFormatter.php:135-137`

- [ ] **2.8 Rename `$minLevel` to `$level` in Logger constructor**
  - **What:** Rename constructor parameter at line 51 from `$minLevel` to `$level` for consistency with `::new()` factory at line 90.
  - **Why:** Minor inconsistency confusing to IDE users; non-breaking rename
  - **Severity:** Medium
  - **Verify:** `vendor/bin/phpunit` passes
  - **Code:** `src/Logger.php:51, 90`

- [ ] **2.9 Document hook "at or above" semantics in HookRegistry::fire()**
  - **What:** Add docblock to `fire()` explaining `>=` comparison and "at or above" semantics; also document no way to fire at exact level only.
  - **Why:** Semantics not obvious from code; future maintainers may misunderstand
  - **Severity:** Medium
  **Verify:** Docblock present; README updated
  - **Code:** `src/Hook/HookRegistry.php:58-67`

## Phase 3: Minor Issues — Eventually [PENDING]

- [ ] **3.1 Add Styles::default() static cache**
  - **What:** Add `private static ?Styles $defaultInstance = null` and `public static function default(): self { return self::$defaultInstance ??= new self(); }`.
  - **Why:** Every `new Styles()` creates 5+ Style objects; repeated instantiation in typical app
  - **Severity:** Low
  - **Verify:** Multiple calls to `Styles::default()` return same instance; all tests pass
  - **Code:** `src/Styles.php:32-47`

- [ ] **3.2 Add TTY guard to restoreTerminal()**
  - **What:** Check `is_resource(\STDERR)` and `posix_isatty(\STDERR)` before writing ANSI escape sequences.
  - **Why:** Writing ANSI codes to non-TTY stream produces garbage
  - **Severity:** Low
  - **Verify:** restoreTerminal() no-ops gracefully when STDERR is not a TTY
  - **Code:** `src/Log.php:377-390`

- [ ] **3.3 Make PanicFormatter hint configurable**
  - **What:** Add `?string $hint` parameter to `PanicFormatter::pretty()` constructor.
  - **Why:** "caliber refresh" hint is SugarCraft-specific; irrelevant outside SugarCraft ecosystem
  - **Severity:** Low
  - **Verify:** Custom hint appears in panic output when provided
  - **Code:** `src/PanicFormatter.php:35-45, 84`

- [ ] **3.4 Add docblock to StandardLogAdapter::print variadic args**
  - **What:** Add `@param mixed ...$args` docblock to `print(...$args)` method.
  - **Why:** Variadic args untyped; improves DX
  - **Severity:** Low
  - **Verify:** `vendor/bin/phpunit` passes
  - **Code:** `src/StandardLogAdapter.php:31-36`

- [ ] **3.5 Standardize test helper usage across LoggerTest and LogTest**
  - **What:** Ensure consistent pattern (close before read) using shared helper or consistent inline approach.
  - **Why:** Minor style inconsistency
  - **Severity:** Low
  - **Verify:** Test code style consistent
  - **Code:** `tests/LoggerTest.php:49-53, 59-63`, `tests/LogTest.php:52-56`

- [ ] **3.6 Consider ReactPHP AsyncLogger (post-1.0)**
  - **What:** Design and implement AsyncLogger wrapper with LoopInterface integration.
  - **Why:** Library depends on react/promise and react/event-loop but never uses them; would make candy-log first-class in ReactPHP ecosystem
  - **Severity:** Low (deferred)
  - **Verify:** Future consideration
  - **Code:** `composer.json:27-32`

## Notes

- **2026-06-30:** Plan created from `findings/candy-log.md` code review. All 19 findings organized by severity. Investigation confirmed all findings are accurate based on source file examination via codebase-analyzer agent.
- Phase 1 (Critical) issues must be addressed before merge.
- Phase 2 (Major) issues should be addressed in next sprint.
- Phase 3 (Minor) issues are nice-to-have for 1.0 release.
- ReactPHP async integration (3.6) deferred to post-1.0 per reviewer recommendation.
