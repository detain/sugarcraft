---
status: not-started
phase: 1
updated: 2026-06-30
---

# Implementation Plan: sugar-prompt Code Review Findings

## Goal

Address all 25 findings from the `sugar-prompt` code review, covering critical crash bugs in `Spinner.php`, missing features, medium/low severity issues, and architectural recommendations.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Use `mutate()` helper from `candy-core/src/Concerns/Mutable.php` for wither pattern consistency | Canonical pattern in codebase; reduces boilerplate and centralizes clone logic | `candy-sprinkles/src/Style.php:260-264` |
| `SpinnerStyle` is immutable (readonly props, no setters) | Confirmed via `candy-forms/src/Spinner/Style.php:17-30` — defensive cloning in `withStyle()` is unnecessary | `candy-forms/src/Spinner/Style.php:L17-30` |
| Fork-based spinner in `sugar-prompt` is separate from Bubble Tea `Spinner` in `candy-forms` | `candy-forms/src/Spinner/Spinner.php` is a TEA Model; `sugar-prompt/src/Spinner.php` is a blocking fork-and-spin driver | `sugar-prompt/src/Spinner.php:11-21` vs `candy-forms/src/Spinner/Spinner.php:11-29` |
| `$waitStatus` is properly initialized before use | `$waitStatus = 0` at line 169 before signal handlers capture it by reference; signal is only fired after waitpid sets it | `sugar-prompt/src/Spinner.php:169` investigation |
| SIGCHLD handler needed for edge case when parent exits before signal handlers installed | Signal handlers at lines 133-162 are conditional on `pcntl_async_signals(true)` succeeding; if it fails, no SIGCHLD handler exists | `sugar-prompt/src/Spinner.php:133-162` |

## Phase 1: Critical Bug Fixes [PENDING]

- [ ] **1.1 Fix `$pid` uninitialized on fork failure** ← CURRENT
  - **File:** `sugar-prompt/src/Spinner.php:103-106`
  - **What:** When `pcntl_fork()` returns `-1`, `$action()` runs inline and `run()` returns. Signal handlers at lines 133-162 capture `$pid` by reference. If a signal arrives during inline execution, `posix_kill($pid, SIGTERM)` uses undefined `$pid` → sends SIGTERM to current process (suicide).
  - **Why:** Critical crash/nondeterministic bug. Most PHP configs: `posix_kill(0, SIGTERM)` kills current process group.
  - **Verification:** Test sends SIGINT during inline fallback; no process suicide.
  - **Implementation:**
    1. Initialize `$pid = null` before line 103
    2. Move signal handler installation inside `if ($pid > 0)` branch
    3. Guard `posix_kill($pid, ...)` calls with `if ($pid > 0)`
  - **Related:** `sugar-prompt/src/Spinner.php:136-147` (SIGINT handler)

## Phase 2: High Severity Issues [PENDING]

- [ ] **2.1 Add `Spinner::withTimeout(int $ms)` method** (Finding #2)
  - **File:** `sugar-prompt/src/Spinner.php`
  - **What:** No cancellation/stop mechanism on blocking `run()`.
  - **Why:** Users must implement externally; common need.
  - **Verification:** `Spinner::new()->withTimeout(100)->withAction(fn)->run()` terminates after 100ms.
  - **Implementation:** Mirror `Form::withTimeout()` pattern at `candy-forms/src/Form.php:186-188`; track elapsed time in animation loop.

- [ ] **2.2 Add SIGCHLD handler to prevent zombie children** (Finding #3)
  - **File:** `sugar-prompt/src/Spinner.php`
  - **What:** If `pcntl_async_signals(true)` fails (line 133), no signal handlers installed. Parent receives SIGINT/SIGTERM → child orphaned as zombie.
  - **Why:** Zombie processes exhaust PIDs.
  - **Verification:** Simulate `pcntl_async_signals` failure; observe no zombie after parent exit.
  - **Implementation:** Inside `if ($pid > 0)` branch, install SIGCHLD handler calling `pcntl_waitpid(-1, $status, WNOHANG)`.

- [ ] **2.3 Remove silent `@` error suppression on critical pcntl calls** (Finding #4)
  - **File:** `sugar-prompt/src/Spinner.php:103, 170`
  - **What:** `@pcntl_fork()` silently falls through to inline; `@pcntl_waitpid()` silently ignores failures.
  - **Why:** Fork failure loses animation with no warning; waitpid failure could infinite-loop.
  - **Verification:** Fork failure emits warning or throws; waitpid `-1` handled explicitly.
  - **Implementation:** Remove `@` from `pcntl_fork()` with error_log or exception; handle `pcntl_waitpid() === -1` with break/throw.

- [ ] **2.4 Log when falling back to inline mode** (Finding #5)
  - **File:** `sugar-prompt/src/Spinner.php:99-106`
  - **What:** Fork failure silently disables animation.
  - **Why:** UX silently degrades; user may think spinner is animated.
  - **Verification:** Error log or user warning emitted when falling back.
  - **Implementation:** Add `error_log()` or `trigger_error(E_USER_WARNING, ...)` in inline fallback path.

## Phase 3: Medium Severity Issues [PENDING]

- [ ] **3.1 Document 50ms usleep floor or make configurable** (Finding #6)
  - **File:** `sugar-prompt/src/Spinner.php:168`
  - **What:** `usleep(max(50_000, $usleepInterval))` undocumented floor caps animation at 20fps.
  - **Why:** Animation library with undocumented FPS cap is a footgun.
  - **Verification:** Constructor/withStyle docblock mentions 50ms floor.
  - **Implementation:** Add docblock noting floor, OR add `withFpsCap(int)` / `withMinSleep(int)` configurator.

- [ ] **3.2 Verify SpinnerStyle immutability / add defensive clone** (Finding #7)
  - **File:** `sugar-prompt/src/Spinner.php:70-75`
  - **What:** `withStyle()` clones Spinner but not SpinnerStyle.
  - **Why:** If mutable, they'd share state. Investigation shows SpinnerStyle is immutable.
  - **Verification:** Confirmed `candy-forms/src/Spinner/Style.php` has readonly props; no setters.
  - **Implementation:** Add comment verifying immutability, or `$s = clone $s` defensively.

- [ ] **3.3 Restore previous signal handlers on exit** (Finding #8)
  - **File:** `sugar-prompt/src/Spinner.php:177-180`
  - **What:** `$prevSigintHandler` / `$prevSigtermHandler` captured but never restored (SIG_DFL used instead).
  - **Why:** Previous handlers overwritten and lost.
  - **Verification:** Previous handlers restored on exit path.
  - **Implementation:**
    ```php
    if ($hadAsyncSignals) {
        if ($prevSigintHandler !== null) {
            pcntl_signal(SIGINT, $prevSigintHandler);
        }
        if ($prevSigtermHandler !== null) {
            pcntl_signal(SIGTERM, $prevSigtermHandler);
        }
    }
    ```

- [ ] **3.4 Document Spinner one-shot behavior** (Finding #9)
  - **File:** `sugar-prompt/src/Spinner.php:148-152`
  - **What:** Spinner cannot be reused after `run()`; action not reset.
  - **Why:** Not documented; users may expect reuse.
  - **Verification:** Class docblock notes one-shot restriction.
  - **Implementation:** Add `@note Spinner instances are not reusable after run() completes` to class docblock.

- [ ] **3.5 Add comment explaining `$waitStatus` timing guarantee** (Finding #10)
  - **File:** `sugar-prompt/src/Spinner.php:169`
  - **What:** `$waitStatus` captured by reference in signal handlers; timing not obvious.
  - **Why:** Code clarity.
  - **Verification:** Comment added explaining signal handler only fires after waitpid sets it.
  - **Implementation:** Add `// $waitStatus is always set by waitpid before any signal handler fires`.

- [ ] **3.6 Remove unused import alias `BitsSpinner`** (Finding #11)
  - **File:** `sugar-prompt/src/Spinner.php:7`
  - **What:** `use SugarCraft\Bits\Spinner\Spinner as BitsSpinner;` declared but never used.
  - **Why:** Dead code from refactor.
  - **Verification:** Code search confirms unused.
  - **Implementation:** Remove line 7.

- [ ] **3.7 Add `@throws \RuntimeException` to run() docblock** (Finding #12)
  - **File:** `sugar-prompt/src/Spinner.php:91`
  - **What:** `run()` throws `\RuntimeException` on child failure but not documented.
  - **Why:** API completeness.
  - **Verification:** Docblock has `@throws \RuntimeException`.
  - **Implementation:** Add `@throws \RuntimeException if the forked action exits with non-zero status`.

## Phase 4: Missing Features [PENDING]

- [ ] **4.1 Add ReactPHP event-loop integration (AsyncSpinner)** (Finding #13)
  - **File:** New file `sugar-prompt/src/AsyncSpinner.php`
  - **What:** Blocking `usleep` loop incompatible with ReactPHP event loop.
  - **Why:** Part of ReactPHP ecosystem; blocks entire event loop.
  - **Verification:** AsyncSpinner works alongside other ReactPHP async ops.
  - **Implementation:** Create `AsyncSpinner` using `React\EventLoop\Loop::addTimer()` for animation and `defer()` for action.

- [ ] **4.2 Add `Spinner::withTimeout()` method** (Finding #14) — same as 2.1, merged.

- [ ] **4.3 Add `Spinner::then()` composer or SequentialSpinner** (Finding #15)
  - **File:** New file or method in `sugar-prompt/src/Spinner.php`
  - **What:** No way to chain multiple sequential spinner actions.
  - **Why:** Convenience for multi-step operations.
  - **Verification:** SequentialSpinner chains two actions with two spinners.
  - **Implementation:** Add `SequentialSpinner` class or `Spinner::then(callable)` method.

- [ ] **4.4 Add SpinnerResult wrapper or document tempfile pattern** (Finding #16)
  - **File:** New file `sugar-prompt/src/SpinnerResult.php`
  - **What:** No ergonomic way to retrieve spinner action results.
  - **Why:** Fork boundary prevents return values; tempfile pattern is cumbersome.
  - **Verification:** `SpinnerResult::read()` retrieves action output.
  - **Implementation:** Create `SpinnerResult` class wrapping communication channel (tempfile/pipe).

- [ ] **4.5 Trigger `E_USER_DEPRECATED` or remove re-export layer** (Finding #17)
  - **File:** All 20 re-export files in `sugar-prompt/src/`
  - **What:** Re-export classes say "deprecated" in doccomment but never trigger `E_USER_DEPRECATED`.
  - **Why:** No enforcement; users get no migration incentive.
  - **Verification:** Using sugar-prompt classes triggers deprecation warning.
  - **Implementation:** Choose one:
    - (A) Add `trigger_error($msg, E_USER_DEPRECATED)` in each alias file
    - (B) Remove re-exports, redirect to `candy-forms`
    - (C) Add meaningful sugar (presets, helpers)

## Phase 5: Duplicated Logic [PENDING]

- [ ] **5.1 Consider code generation for class_alias boilerplate** (Finding #18)
  - **File:** All 20 re-export files in `sugar-prompt/src/`
  - **What:** 20 identical `class_alias` files require manual sync with `candy-forms`.
  - **Why:** Maintenance burden; every new candy-forms class needs corresponding re-export.
  - **Verification:** Code generation script produces all alias files from candy-forms manifest.
  - **Implementation:** Create generation script that scans `candy-forms/src/` and generates alias files, OR improve `AliasResolutionTest` to auto-enumerate.

- [ ] **5.2 Use `mutate()` helper for wither pattern consistency** (Finding #19)
  - **File:** `sugar-prompt/src/Spinner.php:63-83`
  - **What:** Three wither methods repeat clone+set+return pattern instead of using `mutate()`.
  - **Why:** Inconsistency with codebase convention.
  - **Verification:** `mutate()` trait used; wither methods reduce to single line.
  - **Implementation:**
    ```php
    use SugarCraft\Core\Concerns\Mutable;

    final class Spinner
    {
        use Mutable;

        public function withTitle(string $t): self { return $this->mutate(['title' => $t]); }
        public function withStyle(SpinnerStyle $s): self { return $this->mutate(['style' => $s]); }
        public function withAction(\Closure $fn): self { return $this->mutate(['action' => $fn]); }
    }
    ```

## Phase 6: Compatibility Issues [PENDING]

- [ ] **6.1 Add CI verification that all candy-forms classes are re-exported** (Finding #20)
  - **File:** `sugar-prompt/tests/AliasResolutionTest.php`
  - **What:** `AliasResolutionTest` only tests known aliases, not all candy-forms classes.
  - **Why:** New candy-forms class → sugar-prompt silently out of sync.
  - **Verification:** New test enumerates all `SugarCraft\Forms\*` classes and asserts aliases exist.
  - **Implementation:** Add test method using reflection to enumerate all Forms classes; assert each has Prompt alias.

- [ ] **6.2 Document pcntl fallback in `withAction()` docblock** (Finding #21)
  - **File:** `sugar-prompt/src/Spinner.php:77-83`
  - **What:** Fallback to inline execution undocumented in `withAction()`.
  - **Why:** API completeness; users should know animation disabled on non-pcntl.
  - **Verification:** `withAction()` docblock mentions non-pcntl fallback.
  - **Implementation:**
    ```php
    /**
     * @param \Closure(): void $fn  long-running work to perform
     *                              On non-pcntl hosts, runs inline without animation.
     */
    ```

- [ ] **6.3 Document Windows/SAPI limitations clearly** (Finding #22)
  - **File:** `sugar-prompt/src/Spinner.php` class docblock
  - **What:** Platform-specific fork behavior undocumented in method signatures.
  - **Why:** Windows/SAPI users get silently degraded experience.
  - **Verification:** README/class docblock clearly states Windows/SAPI limitations.
  - **Implementation:** Expand class docblock (lines 11-44) with platform compatibility notes.

## Phase 7: Async Pattern Improvements [PENDING]

- [ ] **7.1 Replace blocking usleep with ReactPHP Loop::addTimer** (Finding #23)
  - **File:** `sugar-prompt/src/Spinner.php:168` — same as Finding #13, merged.

- [ ] **7.2 Add async wrapper for non-interactive form validation** (Finding #24)
  - **File:** New file or extension to `sugar-prompt/src/Form.php`
  - **What:** `Form` is synchronous TEA model; no async wrapper for batch validation.
  - **Why:** Async suggestion fetchers need async validation path.
  - **Verification:** `AsyncForm::validateAll()` works with async validators.
  - **Implementation:** Create `AsyncForm` class or `validateAllAsync()` method.

- [ ] **7.3 Add stream-based spinner for non-TTY contexts** (Finding #25)
  - **File:** New method or class in `sugar-prompt/src/Spinner.php`
  - **What:** Spinner writes ANSI to STDERR; no variant for web/SSE.
  - **Why:** Web APIs wanting to stream spinner frames have no option.
  - **Verification:** `Spinner::forStream($stream)` writes to any stream resource.
  - **Implementation:** Add `public static function forStream(resource $stream): self` accepting custom output stream.

---

## Notes

- 2026-06-30: Investigation complete. All 25 findings analyzed against source code. Critical bug (Finding #1) must be fixed first — it can cause process suicide. `mutate()` pattern confirmed canonical via `candy-core/src/Concerns/Mutable.php`. SpinnerStyle confirmed immutable — Finding #7 is low risk.
