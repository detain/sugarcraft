---
status: not-started
phase: 1
updated: 2026-06-30
---

# Implementation Plan

## Goal
Fix all critical and medium-severity issues in candy-core (undefined variable, misleading docblock) and implement low-priority improvements (Countable, inline comment)

## Context & Decisions
| Decision | Rationale | Source |
|----------|-----------|--------|
| `$len` is reassigned inside the UTF-8 block so line 195's comparison against undefined `$len_of_buf` is a real bug | The `$len` variable is set to `strlen($this->buf)` at line 57, but then reassigned to the expected UTF-8 sequence length (2/3/4) at line 186 inside the `$code >= 0x80` block. Line 195 uses `$len_of_buf` which is never defined. | `ref:inputreader-investigation` |
| `$log` in Model docblock describes RecordingModel test double, not the interface contract | The `@property list<\SugarCraft\Core\Msg> $log` annotation in `Model.php:20-21` references a property that only exists on `RecordingModel` (the test double in `ProgramTest.php:39`), not on the `Model` interface itself. | `ref:model-investigation` |
| ScreenStack already has `count()` method; implementing `Countable` is a one-line change | The class already has the method; adding `implements \Countable` with the existing method satisfies the PHP idiom for iteration and `count()` function support. | `ref:screenstack-investigation` |

## Phase 1: Critical Bug Fixes [PENDING]

- [ ] **1.1 Fix undefined variable `$len_of_buf` in InputReader.php:195** ← CURRENT
  - **File:** `candy-core/src/InputReader.php:195`
  - **What:** Replace undefined `$len_of_buf` with `strlen($this->buf)`
  - **Why:** Fatal "undefined variable" error when parsing UTF-8 multibyte sequences that span read boundaries
  - **Severity:** Critical
  - **Conditions for success:** `cd candy-core && composer install && vendor/bin/phpunit --filter InputReaderTest` passes; existing UTF-8 multi-byte tests cover this path
  - **Related code:** `candy-core/src/InputReader.php:57` (`$len = strlen($this->buf)` — original buffer length), `candy-core/src/InputReader.php:186-191` (`$len` reassigned to UTF-8 sequence length), `candy-core/src/InputReader.php:195` (**BUG**), `candy-core/tests/InputReaderTest.php` (existing tests)
  - **Investigation notes:** The UTF-8 block (line 184-214) handles lead bytes `$code >= 0x80`. At line 186, `$len` is reassigned to the expected sequence length (2/3/4). At line 195, the check `($i + $len) > $len_of_buf` was meant to verify all continuation bytes are in the buffer. `$len_of_buf` is never defined anywhere in the enclosing scope. The original buffer length `$len` at line 57 gets shadowed. The correct comparison is against `strlen($this->buf)`.

- [ ] **1.2 Remove non-existent `$log` property from Model interface docblock**
  - **File:** `candy-core/src/Model.php:20-21`
  - **What:** Remove `@property list<\SugarCraft\Core\Msg> $log RecordingModel message log` annotation from interface docblock
  - **Why:** `$log` only exists on `RecordingModel` test double (at `tests/ProgramTest.php:39`), not on the `Model` interface. Downstream implementors could reasonably expect to implement `$log`, which is incorrect. Removing eliminates confusion.
  - **Severity:** Medium
  - **Conditions for success:** PHPStan level 9 passes; no warnings about missing property; no changes needed to existing Model implementors
  - **Related code:** `candy-core/src/Model.php:20-21` (annotation to remove), `candy-core/tests/ProgramTest.php:39` (`RecordingModel::$log`), `candy-core/src/SubscriptionCapable.php` (other Model implementors that don't have `$log`)
  - **Investigation notes:** The `@property` annotation in phpDocumentor is informational only and does not enforce implementation. `RecordingModel::$log` is `public array $log = []` — a test-specific message log. The `Model` interface itself declares no such property.

## Phase 2: Low-Priority Improvements [PENDING]

- [ ] **2.1 Implement `\Countable` on ScreenStack**
  - **File:** `candy-core/src/ScreenStack.php:12`
  - **What:** Add `implements \Countable` to class declaration; existing `count()` method at lines 81-84 already satisfies the interface
  - **Why:** Idiomatic PHP — enables `count($screenStack)` and `foreach` iteration without explicit method call
  - **Severity:** Low
  - **Conditions for success:** `count($screenStack)` returns correct integer; `foreach ($stack as $screen)` works; existing tests pass
  - **Related code:** `candy-core/src/ScreenStack.php:12` (class declaration), `candy-core/src/ScreenStack.php:81-84` (existing `count()` method — no changes needed), `candy-core/src/ScreenStack.php:73-76` (`isEmpty()`)
  - **Investigation notes:** `ScreenStack` is already `final readonly`, adding `\Countable` is backward-compatible and purely additive. The existing `count()` method already returns `count($this->screens)`.

- [ ] **2.2 Add inline comment for exception swallowing in Program::dispatch()**
  - **File:** `candy-core/src/Program.php:508-512`
  - **What:** Expand the `catch (\Throwable)` comment block to reference the rationale already documented at lines 500-507
  - **Why:** Prevents future maintainers from "fixing" what they think is a bug. The existing comment at 500-507 explains the safety-net rationale but the catch block comment is sparse. Adding a reference improves readability.
  - **Severity:** Low
  - **Conditions for success:** `failOnWarning="true"` in phpunit.xml still passes; no new warnings
  - **Related code:** `candy-core/src/Program.php:499-513` (full AsyncCmd promise rejection handler), `candy-core/src/Program.php:500-507` (existing rationale comment — the key text: "The error is already surfaced via ExceptionMsg at line 502, so swallow the rethrow here to keep that contract without the noise")
  - **Investigation notes:** Lines 499-513 handle rejected promises from `AsyncCmd`. `ExceptionMsg` is dispatched at line 502 to surface the error to the model. The try-catch at 508-512 catches the exception handler's potential rethrow. This is a deliberate safety net.

## Phase 3: No-Action Items (Documented for Reference) [PENDING]

- [x] 3.1 Mutable trait `get_object_vars()` allocation — NO ACTION
  - **File:** `candy-core/src/Concerns/Mutable.php:35-37`
  - **Finding:** `get_object_vars($this)` is called on every `with*()` invocation. Standard PHP pattern; negligible performance impact for typical use (models with 5-20 properties). Implementation is correct.

- [x] 3.2 Renderer token cache growth — NO ACTION (monitor)
  - **File:** `candy-core/src/Renderer.php:51`
  - **Finding:** The token cache (`private array $tokenCache = []`) is keyed by string and never evicted. For long-running programs rendering many unique lines, this could grow unbounded. Monitor for memory issues; add LRU cap if needed.

- [x] 3.3 reconcileWantedSubscriptions() double-call on startup — NO ACTION (documented)
  - **File:** `candy-core/src/Program.php:195` (pre-loop) vs `Program.php:557` (post-dispatch)
  - **Finding:** The reconciler runs once before the loop (line 195) and again after init Cmd's resulting Msgs are dispatched (line 557). The pre-loop call handles edge cases where init triggers subscriptions synchronously before the loop starts. Removing could break subscription timing.

- [x] 3.4 WorkerPool temp script security — NO ACTION
  - **File:** `candy-core/src/WorkerPool.php:395-405`
  - **Finding:** Worker scripts are created via `tempnam()` with mode 0700. This is acceptable for a CLI tool. No change required.

- [x] 3.5 AsyncCmd promise rejection handling — NO ACTION (documented)
  - **File:** `candy-core/src/Program.php:499-512`
  - **Finding:** The `otherwise()` callback's potential throw from `dispatch(new ExceptionMsg($e))` is caught by the surrounding try-catch. This is intentional since the error is already surfaced via `ExceptionMsg` at line 502. The swallowing prevents "Unhandled promise rejection" noise.

- [x] 3.6 ProgramOptions 17-parameter constructor — NO ACTION (documented)
  - **File:** `candy-core/src/ProgramOptions.php:22-143`
  - **Finding:** While the builder pattern (`ProgramOptions::builder()`) is the documented preferred path per CALIBER_LEARNINGS.md, the 17-param constructor is still used. This is a maintainability consideration, not a bug. The constructor is kept for full back-compat.

## Phase 4: Verification [PENDING]

- [ ] 4.1 Run full test suite
  - **Command:** `cd /home/sites/sugarcraft/candy-core && composer install && vendor/bin/phpunit`
  - **Expected:** All tests pass; `failOnWarning="true"` produces no warnings; PHPStan level 9 reports no errors

## Summary of Required Changes

| Item | File | Lines | Change | Severity |
|------|------|-------|--------|----------|
| 1.1 | `candy-core/src/InputReader.php` | 195 | `($i + $len) > $len_of_buf` → `($i + $len) > strlen($this->buf)` | Critical |
| 1.2 | `candy-core/src/Model.php` | 20-21 | Remove `@property list<\SugarCraft\Core\Msg> $log` annotation | Medium |
| 2.1 | `candy-core/src/ScreenStack.php` | 12 | Add `implements \Countable` | Low |
| 2.2 | `candy-core/src/Program.php` | 508-512 | Expand catch-block comment for clarity | Low |
| 3.1–3.6 | — | — | No action (documented) | N/A |

## Notes
- 2026-06-30: Plan created based on `findings/candy-core.md` code review
- All file:line references based on current codebase at time of investigation
- Changes are backward-compatible; `ScreenStack implements \Countable` is purely additive
- No changes to public API contracts (except `ScreenStack` which is an internal runtime type)
