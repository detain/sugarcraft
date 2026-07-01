# Code Review: sugar-prompt

## Summary

`sugar-prompt` is almost entirely a backward-compatibility facade / re-export layer over `candy-forms` (`SugarCraft\Forms\*`). Of the 21 PHP source files in `src/`, **20 are `class_alias` re-exports** pointing to `candy-forms`, `candy-fuzzy`, or other foundation packages. Only one file contains any real implementation logic:

- **`src/Spinner.php`** (193 lines) — A blocking "loading spinner" that uses `pcntl_fork` to run an action in a child process while animating a spinner on the parent.

All field types (Input, Text, Confirm, Select, MultiSelect, Note, FilePicker), validators (Required, Email, MinLength, MaxLength, Pattern), form-level classes (Form, Group, Theme, KeyMap), and helper traits (HasHideFunc, HasDynamicLabels) are pure re-exports from `candy-forms`. The tests similarly either verify alias resolution correctness or delegate to `candy-forms` behavior.

This means the effective code under review is almost entirely `candy-forms` — **the library adds no meaningful business logic of its own**. Any bugs, missing features, or architectural issues are inherited from `candy-forms`.

---

## Critical Issues

### 1. Spinner: `$pid` used uninitialized if fork fails inline (`Spinner.php:103-106`)

**File:** `src/Spinner.php:103-106`

```php
$pid = @pcntl_fork();
if ($pid === -1) {
    $action();        // runs inline, $pid is NEVER assigned
    return;
}
if ($pid === 0) {
```

When `pcntl_fork()` fails and returns `-1`, `$action()` runs inline and `run()` returns. However, signal handlers are installed **before** the fork attempt (lines 133–162) and capture `$pid` **by reference**:

```php
$prevSigintHandler = pcntl_signal(SIGINT, function (int $signo) use ($pid, $isTty) {
    if (function_exists('posix_kill')) {
        posix_kill($pid, SIGTERM);  // $pid is UNDEFINED here if fork failed!
```

If a signal arrives while or after the inline action runs, the handler calls `posix_kill($pid, SIGTERM)` where `$pid` is an **undefined variable**. On most PHP configurations this results in `posix_kill(0, SIGTERM)` — which sends SIGTERM to the **current process** (suicide), rather than a child. This is erratic and platform-dependent behavior.

**Fix:** Initialize `$pid = null` before the fork, guard all signal handlers with `if ($pid > 0)`, and only install signal handlers inside the `if ($pid > 0)` branch.

---

## High Severity Issues

### 2. Spinner: No way to cancel/stop a running spinner

The `Spinner::run()` is a blocking call with no cancellation mechanism. Once started, the only ways to exit are:

- The action completes normally
- The action throws (caught in child, converted to exit code)
- SIGINT or SIGTERM arrives and the handler kills+reaps the child

There is no `cancel()`, `stop()`, or timeout parameter on the `Spinner` itself. If the user wants a spinner that times out, they must implement it externally (e.g., alarm signal, or run the whole spinner in a subprocess).

**Recommendation:** Add a `withTimeout(int $ms)` method to `Spinner` that uses `pcntl_alarm` or a fourth argument to the loop to break out after N milliseconds.

### 3. Spinner: Zombie child on early parent exit without pcntl_signal

The signal handlers at lines 133–162 are only installed when `pcntl_async_signals(true)` succeeds. If a parent process receives SIGINT or SIGTERM **before** the signal handlers are installed (e.g., `pcntl_signal` or `pcntl_async_signals` returns false), the child process is orphaned and becomes a zombie until the parent eventually exits.

There is no SIGCHLD handler to auto-reap the child in this edge case.

**Recommendation:** Add a SIGCHLD handler inside the fork path that calls `pcntl_waitpid(-1, $status, WNOHANG)` to prevent zombie processes.

### 4. Spinner: Silent `@` error suppression on critical pcntl calls

Throughout `Spinner.php`, critical `pcntl_*` calls are suppressed with `@`:

```php
$pid = @pcntl_fork();           // line 103 — failure silently falls through to inline mode
$check = @pcntl_waitpid(...);   // line 170 — wait failure silently ignored
```

- If `pcntl_fork` fails silently and the inline action takes a very long time, there is no warning to the caller.
- If `pcntl_waitpid` fails, the loop could spin forever on `WNOHANG` returning `-1`.

**Recommendation:** At minimum log or throw on `pcntl_fork` failure (not silently fall through), and handle `pcntl_waitpid` returning `-1` explicitly.

### 5. Spinner: Inline path shares all state with parent

When fork fails and the action runs inline (line 100-101), there is no animation (spinner does not display), but more importantly — if the action takes a very long time, the parent process is completely blocked with no spinner animation. The inline fallback silently downgrades the UX without any notification to the caller.

---

## Medium Severity Issues

### 6. Spinner: 50ms `usleep` floor caps animation at 20fps unconditionally

**File:** `src/Spinner.php:168`

```php
usleep(max(50_000, $usleepInterval)); // 50ms floor caps animation at 20fps
```

The comment admits this is a clamp for "a custom high-fps Style." The stock styles all have intervals ≤ ~83ms (miniDot at ~12fps), so the floor doesn't bite for stock styles. However, the floor is undocumented in the constructor/API and the `withStyle()` method doesn't warn that a high-fps style will be throttled.

For a library whose primary purpose is animation, this undocumented cap is a footgun.

**Recommendation:** Document the floor, or make it configurable via `withFpsCap(int)` or `withMinSleep(int)`.

### 7. Spinner: `withStyle()` clones the Spinner but the `SpinnerStyle` itself is not cloned

**File:** `src/Spinner.php:70-75`

```php
public function withStyle(SpinnerStyle $s): self
{
    $clone = clone $this;
    $clone->style = $s;
    return $clone;
}
```

`SpinnerStyle` from `sugar-bits` is likely an immutable value object (following the project convention), so this is probably fine. But if `SpinnerStyle` has any internal mutable state (e.g., a frame counter), the cloned `Spinner` would share it with the original.

**Recommendation:** Verify that `SpinnerStyle` is truly immutable, or call `$s = clone $s` before assignment.

### 8. Signal handler restoration doesn't restore previous handlers

**File:** `src/Spinner.php:177-180`

```php
if ($hadAsyncSignals) {
    pcntl_signal(SIGINT, SIG_DFL);
    pcntl_signal(SIGTERM, SIG_DFL);
}
```

The previous handlers (`$prevSigintHandler`, `$prevSigtermHandler`) are captured but **never restored**. On PHP 8+, `pcntl_signal` returns a callable that can be used to restore the previous handler. The handlers should be restored on exit:

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

### 9. Spinner is a one-shot object — `run()` cannot be called twice

The `run()` method sets `$this->action = null` after executing, but there's no guard against calling `run()` twice:

```php
public function run(): void
{
    if ($this->action === null) {
        return;  // second call is silent no-op
    }
```

This works but is not explicitly documented. The more significant issue is that a `Spinner` with an action **cannot be reused** after `run()` completes (the action is not reset). A user would need to create a new `Spinner` instance for each action.

---

## Low Severity Issues

### 10. Spinner: `$waitStatus` is not initialized before capture by signal handlers

**File:** `src/Spinner.php:169`

```php
$waitStatus = 0;   // initialized here
while (true) {
    // ...
    $check = @pcntl_waitpid($pid, $waitStatus, WNOHANG);
```

`$waitStatus` is initialized before the loop, so it is properly initialized when reaped inside the loop (line 171). However, the signal handlers capture `$waitStatus` by reference (line 136, 149), and while this is safe in practice because `$waitStatus` is always set by the time the signal fires (the handler is only called after `pcntl_waitpid` has set it), the code is confusing. A comment explaining the timing guarantee would help.

### 11. `Spin`/`Spinner` naming inconsistency in imports

**File:** `src/Spinner.php:7-8`

```php
use SugarCraft\Bits\Spinner\Spinner as BitsSpinner;
use SugarCraft\Bits\Spinner\Style as SpinnerStyle;
```

The import alias `BitsSpinner` is declared but never used. Either remove it or use it consistently. This appears to be leftover from a refactor.

### 12. Missing `@throws` documentation on `run()`

The `run()` method can throw `\RuntimeException` when the child action fails (line 190), but this is not documented in the docblock.

**Recommendation:** Add `@throws \RuntimeException` to the docblock.

---

## Missing Features

### 13. No ReactPHP event-loop integration for Spinner

The Spinner uses a blocking `usleep` animation loop (line 168), which is incompatible with ReactPHP's event loop. In a ReactPHP-based application (which `candy-async` is designed for), calling `Spinner::run()` would block the event loop and prevent other async operations from running concurrently.

**Recommendation:** Provide an async-compatible `AsyncSpinner` variant that uses ReactPHP's event loop (`Loop::addTimer`) for animation, and `defer()` or `async()` for the action.

### 14. No `Spinner::withTimeout()` method

Unlike `Form::withTimeout()`, `Spinner` has no timeout capability. A user who wants a spinner that gives up after N seconds must implement this externally.

**Recommendation:** Add `withTimeout(int $ms): self` which uses `pcntl_alarm` or a check inside the animation loop to abort.

### 15. No composable spinner — can't chain multiple actions

If a user wants to run multiple sequential spinner actions, they must create multiple `Spinner` instances. There's no `SequentialSpinner` or `Spinner::then(callable)` composer.

### 16. No way to get the result of the spinner action

The entire rationale of the Spinner is that the action's return value cannot cross the fork boundary. But there's no ergonomic way to retrieve results. The caller must use tempfile/pipe/database out-of-band. A `SpinnerResult` class that wraps the communication channel would make this easier.

### 17. sugar-prompt adds no value over candy-forms directly

Since all 20 re-exported classes are deprecated `class_alias` calls pointing to `candy-forms`, using `SugarCraft\Prompt\*` over `SugarCraft\Forms\*` provides zero additional functionality. The deprecation notices (which are not actually triggered — only doc-comments say "deprecated") offer no migration path.

**Consideration:** Either:
- Actually trigger `E_USER_DEPRECATED` in the aliases so migration is enforced
- Remove the re-export layer entirely and redirect users to `candy-forms`
- Add meaningful sugar: convenience constructors, common presets, integration helpers

---

## Duplicated Logic

### 18. All source files are identical `class_alias` boilerplate

20 source files follow the exact same 10-line pattern:

```php
<?php
declare(strict_types=1);
namespace SugarCraft\Prompt\Field;
class_alias(\SugarCraft\Forms\Field\X::class, X::class);
```

This is not "duplicated logic to refactor" in the traditional sense (you can't factor out a class_alias call), but it represents a maintenance burden: every new class added to `candy-forms` requires a corresponding re-export file in `sugar-prompt`. The `AliasResolutionTest` tests verify the mapping stays correct, but there is no code generation — manual synchronization is required.

### 19. The wither pattern (`withX()` → clone + set + return) is repeated identically in Spinner

The three wither methods in `Spinner` (lines 63-83) are structurally identical:

```php
public function withTitle(string $t): self {
    $clone = clone $this; $clone->title = $t; return $clone;
}
public function withStyle(SpinnerStyle $s): self {
    $clone = clone $this; $clone->style = $s; return $clone;
}
public function withAction(\Closure $fn): self {
    $clone = clone $this; $clone->action = $fn; return $clone;
}
```

The canonical reference in `candy-sprinkles/src/Style.php` uses a `mutate()` private method:

```php
private function mutate(callable $fn): self {
    $clone = clone $this;
    $fn($clone);
    return $clone;
}
```

**Recommendation:** Consider adopting the `mutate()` pattern for consistency with the rest of the ecosystem and to reduce boilerplate.

---

## Compatibility Issues

### 20. No CI verification that all candy-forms classes are re-exported

The `AliasResolutionTest` tests the current set of known aliases, but there is no automated check that `sugar-prompt` re-exports every public class from `candy-forms`. If `candy-forms` adds a new field type or validator, `sugar-prompt` silently falls out of sync.

**Recommendation:** Add a test that enumerates all classes in `SugarCraft\Forms\*` namespaces and asserts each has a corresponding alias in `SugarCraft\Prompt\*`.

### 21. Spinner requires pcntl but has undocumented fallback

The Spinner works without `pcntl_fork` (falls back to inline execution), but this is completely undocumented in the method signature or class-level docblock. Only the class-level docblock (lines 22-33) and `run()` docblock (lines 86-89) mention this.

**Recommendation:** Document in `withAction()` docblock that on non-pcntl hosts, the action runs inline without animation.

### 22. Platform-specific fork behavior is inherently non-portable

The entire fork-based spinner has these platform-specific behaviors:
- No `pcntl` on Windows → inline fallback
- `pcntl_async_signals` availability varies by PHP SAPIs (php-fpm, cli, etc.)
- `posix_*` functions not available on Windows

The README documents some of this, but users on Windows or in certain SAPIs will have a silently degraded experience.

---

## Async Pattern Improvements

### 23. Spinner should use ReactPHP event loop for animation, not blocking usleep

The current `while(true)` loop with `usleep()` at line 168 is a blocking, synchronous animation loop. In the ReactPHP ecosystem that this library is part of, the recommended pattern would be:

```php
public function run(): void
{
    if ($this->action === null) return;
    $action = $this->action;
    if (!function_exists('pcntl_fork')) {
        $action();
        return;
    }
    // Fork child for action, use Loop for parent animation
    $loop = Loop::get();
    $loop->addTimer($interval, function($timer) use (&$frame) {
        // advance frame
    });
}
```

This would allow the spinner to coexist with other ReactPHP async operations.

### 24. No async wrapper for Form

`Form` is a Bubble Tea Model (synchronous update/view loop). While this is correct for TUI use, there is no async wrapper for non-interactive form validation or batch processing of forms with async suggestion fetchers.

The `AsyncSuggestionsTest.php` tests `withAsyncSuggestions` which dispatches `SuggestionsReadyMsg` via the Bubble Tea cmd system (using `React\Async::defer`), not via ReactPHP's event loop directly. This is architecturally correct for the TEA pattern but means the form cannot be used in a pure ReactPHP async context.

### 25. No Stream-based spinner variant for web/API contexts

The Spinner writes ANSI escape codes to STDERR. There is no variant that writes to a stream, buffer, or could be used in a non-TTY context (e.g., a web API that wants to stream spinner frames as SSE).

---

## Recommendations Summary Table

| ID | Severity | Issue | Recommendation |
|----|----------|-------|----------------|
| 1 | **Critical** | `$pid` uninitialized on fork failure, used in signal handlers | Initialize `$pid = null` before fork; guard signal handlers with `if ($pid > 0)` |
| 2 | High | No spinner cancellation/stop mechanism | Add `withTimeout(int $ms)` and/or `Spinner::cancel()` |
| 3 | High | Zombie child if parent exits before signal handlers installed | Add SIGCHLD handler or use `pcntl_wait(-1)` in parent |
| 4 | High | `@` suppresses `pcntl_fork` / `pcntl_waitpid` failures silently | At minimum log/throw on fork failure; handle waitpid returning -1 |
| 5 | High | Inline fallback silently disables animation | Log or throw when falling back to inline mode |
| 6 | Medium | 50ms usleep floor undocumented; throttles custom high-fps styles | Document the cap; make configurable |
| 7 | Medium | `SpinnerStyle` not cloned in `withStyle()` | Verify `SpinnerStyle` immutability; clone defensively |
| 8 | Medium | Previous signal handlers not restored | Restore `$prevSigintHandler` / `$prevSigtermHandler` on exit |
| 9 | Medium | Spinner is one-shot; cannot reuse after `run()` | Document this; consider resetting action on run() |
| 10 | Low | `$waitStatus` capture-by-ref in signal handlers is confusing | Add comment explaining the timing guarantee |
| 11 | Low | Unused import alias `BitsSpinner` | Remove unused import |
| 12 | Low | Missing `@throws \RuntimeException` on `run()` docblock | Add to docblock |
| 13 | Missing | No ReactPHP event-loop integration for Spinner | Add `AsyncSpinner` using `Loop::addTimer` |
| 14 | Missing | No `Spinner::withTimeout()` | Add timeout support via `pcntl_alarm` or loop check |
| 15 | Missing | No sequential/composable spinner | Add `Spinner::then()` composer or `SequentialSpinner` |
| 16 | Missing | No way to retrieve spinner action result | Add `SpinnerResult` wrapper or document tempfile pattern |
| 17 | Missing | sugar-prompt adds no value over candy-forms directly | Consider triggering `E_USER_DEPRECATED` or removing re-export layer |
| 18 | Duplicated | 20 identical class_alias boilerplate files | Consider code generation from candy-forms manifests |
| 19 | Duplicated | wither pattern boilerplate in Spinner | Use `mutate()` helper for consistency with codebase |
| 20 | Compatibility | No CI verification that all candy-forms classes are re-exported | Add automated alias coverage test |
| 21 | Compatibility | pcntl fallback undocumented in `withAction()` | Add `@requires pcntl_fork` or document inline fallback |
| 22 | Compatibility | Platform-specific fork behavior non-portable | Document Windows/SAPI limitations clearly |
| 23 | Async | Blocking usleep loop for animation | Replace with ReactPHP `Loop::addTimer` |
| 24 | Async | No async wrapper for non-interactive form use | Consider `AsyncForm::validateAll()` with async validators |
| 25 | Missing | No stream-based spinner for non-TTY contexts | Add `Spinner::forStream(resource $stream)` variant |
