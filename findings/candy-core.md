# candy-core Code Review Findings

## Overview

candy-core (`SugarCraft\Core`) is the foundational Elm-architecture TUI runtime for the SugarCraft monorepo. It provides the core `Model`/`Msg`/`Cmd` lifecycle, `Program` runner, `Renderer`, `InputReader`, and essential utilities (TTY, ANSI, i18n). Other libs (candy-sprinkles, sugar-bits, candy-shell, etc.) depend on this for their TUI infrastructure.

---

## Critical Issues

### 1. BUG: InputReader uses undefined variable

**File:** `src/InputReader.php:195`

```php
if (($i + $len) > $len_of_buf) {
```

`$len_of_buf` is undefined. The enclosing scope defines `$len = strlen($this->buf)` at line 57, so the comparison should be against `$len` (the actual buffer length):

```php
if (($i + $len) > strlen($this->buf)) {
```

**Severity:** Bug — would cause fatal "undefined variable" error when parsing UTF-8 multibyte sequences that span read boundaries.

---

### 2. Model interface docblock references non-existent property

**File:** `src/Model.php:20-21`

```php
 * @property list<\SugarCraft\Core\Msg> $log RecordingModel message log
```

`$log` is not declared in `Model`. The `@property` annotation appears to describe a convention used by `RecordingModel` (a test helper), not an interface contract. Removing this reduces confusion for downstream implementors.

---

## Medium Issues

### 3. Mutable trait performance concern for large objects

**File:** `src/Concerns/Mutable.php:35-37`

```php
protected function mutate(array $changes): static
{
    return new static(...array_merge(get_object_vars($this), $changes));
}
```

`get_object_vars($this)` is called on every `with*()` invocation. For objects with many properties, this is a per-call allocation. However, this is a standard PHP pattern and the performance impact is likely negligible for typical use (models with 5-20 properties). The current implementation is correct.

---

### 4. Intentional exception swallowing in Program::dispatch()

**File:** `src/Program.php:508-512`

The exception handler's throw is caught and swallowed because the error is already surfaced via `ExceptionMsg`. The swallowing is deliberate but could use an inline comment clarifying this "safety net" rationale.

---

### 5. ProgramOptions constructor has 17 parameters

**File:** `src/ProgramOptions.php:22-143`

While the builder pattern (`ProgramOptions::builder()`) is the documented preferred path, the 17-param constructor is still used. This is not a bug — just worth noting as a maintainability consideration.

---

## Suggestions for Improvement

### 6. ScreenStack should implement Countable

**File:** `src/ScreenStack.php`

`ScreenStack` has a `count()` method but does not implement `Countable`. For consistency with PHP idioms:

```php
final class ScreenStack implements \Countable
{
    public function count(): int
    {
        return count($this->screens);
    }
}
```

---

### 7. reconcileWantedSubscriptions() is called twice on startup

**File:** `src/Program.php:195` (pre-loop) vs `src/Program.php:557` (post-dispatch)

The reconciler runs once before the loop and again after the init Cmd's resulting Msgs are dispatched. The double-call is harmless but redundant for the startup case.

---

### 8. WorkerPool temp script security

**File:** `src/WorkerPool.php:395-405`

Worker scripts are created via `tempnam()` with mode 0700. This is acceptable for a CLI tool. No change required.

---

### 9. Renderer token cache grows indefinitely

**File:** `src/Renderer.php:51`

```php
private array $tokenCache = [];
```

The token cache is keyed by string and never evicted. For long-running programs rendering many unique lines, this could grow unbounded. Monitor; add LRU cap if memory becomes an issue.

---

### 10. AsyncCmd promise rejection silently caught

**File:** `src/Program.php:499-512`

The `otherwise()` callback's potential throw from `dispatch(new ExceptionMsg($e))` is swallowed by the surrounding try-catch. This is acceptable since the error is already being captured, but worth documenting.

---

## Test Quality Assessment

### Test suite is comprehensive

**Key test files reviewed:**
- `tests/RendererTest.php` — 346 lines; excellent coverage including `ShortWriteStream` custom stream wrapper
- `tests/ProgramTest.php` — 991 lines; extensive integration tests using `stream_socket_pair`
- `tests/I18n/TTest.php` — 228 lines; covers locale fallback chain, placeholder interpolation

**Strengths:**
- `RecordingModel` test double is clean and reusable
- Tests verify behavior not implementation
- Good use of custom stream wrappers for edge cases
- `failOnWarning="true"` in phpunit.xml enforces strict quality

---

## Summary of Recommendations (Priority Order)

| Priority | File | Issue | Action |
|----------|------|-------|--------|
| **Critical** | `InputReader.php:195` | `$len_of_buf` undefined variable | Fix to `$len` or `strlen($this->buf)` |
| **Medium** | `Model.php:20-21` | Docblock references non-existent `$log` | Remove `@property $log` from interface |
| **Low** | `ScreenStack.php` | Missing `Countable` interface | Implement `\Countable` |
| **Low** | `Program.php:508-512` | Exception swallowing needs inline comment | Add safety-net rationale comment |
| **N/A** | `Mutable.php` | `get_object_vars()` allocation | No action — works correctly |
| **N/A** | `Renderer.php` | Token cache growth | Monitor; add LRU cap if needed |

---

*Generated by code audit on candy-core library. All file:line references are based on the current codebase at time of review.*