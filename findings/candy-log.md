# Code Review: candy-log

**Date:** 2026-06-29  
**Auditor:** Automated Research Agent  
**Files Reviewed:** 16 source files, 13 test files  
**Overall Assessment:** APPROVE WITH_REMARKS

---

## Summary

`candy-log` is a well-architected PHP port of `charmbracelet/log` providing colorful leveled logging with Text/JSON/Logfmt formatters, a PSR-3 bridge, hook system, panic handlers, and caller reporting. The codebase follows strict types, PSR-4/PSR-12, and the immutable-with-clone pattern throughout. Several issues were identified ranging from a hardcoded stream resource type annotation, inconsistent hook invocation design, duplicated value-coercion logic, missing ReactPHP async integration, and an incomplete stream-type generic that bypasses PHP's type system.

---

## Files Reviewed

### Source (`src/`)
- `Log.php` — Static facade / panic handler installer
- `Logger.php` — Core logger implementation
- `Level.php` — Syslog-aligned int enum
- `Styles.php` — ANSI style definitions for text output
- `Formatter.php` — Formatter interface contract
- `Formatter/TextFormatter.php` — Human-readable text formatter
- `Formatter/JsonFormatter.php` — JSON line formatter
- `Formatter/LogfmtFormatter.php` — Logfmt key=value formatter
- `Formatter/ValueCoercion.php` — Safe value stringification
- `PsrBridge.php` — PSR-3 LoggerInterface bridge
- `CallerFormatter.php` — Call-stack walker for caller info
- `PanicFormatter.php` — Exception → styled panic report
- `StandardLogAdapter.php` — `*log.Logger` interface adapter
- `PartsOrder.php` — Config DTO for log-part ordering
- `Hook/Hook.php` — Hook interface contract
- `Hook/HookRegistry.php` — Per-level hook dispatcher
- `Lang.php` — i18n translation facade

### Tests (`tests/`)
- `LoggerTest.php`, `LogTest.php`, `TextFormatterTest.php`, `JsonFormatterTest.php`
- `LevelTest.php`, `StylesTest.php`, `PartsOrderTest.php`
- `PsrBridgeTest.php`, `HookRegistryTest.php`, `StandardLogAdapterTest.php`
- `CallerFormatterTest.php`, `PanicFormatterTest.php`, `FormatterValueCoercionTest.php`
- `CoverageBoostTest.php`

---

## Critical Issues (🔴 Must Fix)

### 1. [Type Safety] `$stream` typed as `mixed` in constructor instead of `resource`

**File:** `src/Logger.php:30, 56`

```php
/** @var resource */
private $stream;
```

and constructor parameter:

```php
public function __construct(
    ...
    $stream = null,   // line 56 — untyped, would accept any mixed
    ...
)
```

`$stream` is declared `mixed` in the constructor signature (no type hint) but the docblock says `@var resource`. PHP 8.3+ allows `?resource` as a nullable type hint. Using `mixed` bypasses the type system entirely — any value (objects, arrays, scalars) would be accepted and only fail at `is_resource()` check inside `setOutput`/`withOutput`. The constructor itself never validates the resource until those setter calls.

**Recommendation:** Add `?resource $stream = null` to the constructor at `Logger.php:49-58` and remove the `@var resource` annotation at line 29. This makes the invalid-state unrepresentable at construction time.

---

### 2. [Type Safety] `$stream` param in `setOutput()`/`withOutput()` untyped

**File:** `src/Logger.php:297-303, 311-319`

```php
public function setOutput($stream): void   // line 297 — untyped parameter
{
    if (!\is_resource($stream)) {
        throw new \InvalidArgumentException(Lang::t('logger.invalid_stream'));
    }

public function withOutput($stream): self   // line 311 — untyped parameter
{
    if (!\is_resource($stream)) {
        throw new \InvalidArgumentException(Lang::t('logger.invalid_stream'));
    }
```

Both methods accept `mixed` and only validate via `is_resource()`. The exception carries a translated message but the parameter itself has no type. This is defensible (the validation is explicit) but inconsistent with the rest of the codebase which uses strict typing throughout.

**Recommendation:** Add `object $stream`... wait — `$stream` is a resource, so `public function setOutput($stream): void` → `public function setOutput(object $stream): void` is wrong. Use `#[\SensitiveParameter]` attribute if the stream might contain sensitive data. Actually — `setOutput(?resource $stream): void` with `?\Resource` not valid syntax. The best approach is `mixed $stream` with a clear docblock, or add a native union: `public function setOutput(mixed $stream): void` with `/** @param resource|mixed $stream */`. However, since resources are being phased out in PHP 8.4+, consider redesigning to accept a `SplFileObject` or a `WriteableStream` interface instead.

---

### 3. [Compatibility] Missing async/ReactPHP integration

**File:** `composer.json:27-32`

The library depends on `react/promise` and `react/event-loop` (both present in vendor) but **never uses them**. All I/O is blocking `fwrite()` calls. A logging library in a ReactPHP ecosystem should offer an async sink that writes to a stream in a non-blocking manner.

**Missing features:**
- No async logger wrapper (e.g., `AsyncLogger` that queues writes to a ReactPHP stream)
- No `LoopInterface` integration
- No Promise-based log dispatch

**Recommendation:** Consider adding an `AsyncLogger` wrapper that accepts a `LoopInterface` and writes to a `WritableStreamInterface`. This would make `candy-log` first-class in the ReactPHP ecosystem.

---

## Major Issues (🟠 Should Fix)

### 4. [Design] Hooks fire ONLY via `PsrBridge`, not `Logger` directly

**File:** `src/PsrBridge.php:79`, `src/Hook/HookRegistry.php:58-67`

From the README (line 166):
> **Note:** Hooks fire **only via `PsrBridge`** — they do not fire when calling `Logger->info()` etc. directly.

The `Logger` class has no hook mechanism. Only `PsrBridge::log()` calls `$this->hooks->fire()`. This is a significant design asymmetry — a user who calls `Logger->info()` directly (without PSR-3 bridge) does not get hook dispatch. This is documented, but the inconsistency could surprise users.

**Root cause:** `Logger::emit()` at line 129 is the internal emit path but it has no hook callback. Hooks live entirely in `PsrBridge`.

**Recommendation:** Either:
- Add an optional hook registry to `Logger` itself (`Logger::setHookRegistry()`), OR
- Document the limitation prominently at the top of the Hook System section, OR
- Refactor so `Logger::emit()` itself fires hooks (but this would require making hook dispatch an explicit step in the emit path, which may be intentional for performance)

**Confidence: 85%**

---

### 5. [Bug] `Log::installPanicHandler` — previous handler chaining is fragile

**File:** `src/Log.php:97-110`

```php
$previousHandler = \set_exception_handler(static function (): void {});

// Now register the real handler with the captured previous handler.
\set_exception_handler(static function (\Throwable $e) use ($formatter, $previousHandler): void {
    self::restoreTerminal();
    $report = $formatter->format($e);
    \fwrite(\STDERR, "\n{$report}\n");

    // Chain to previous handler if one was registered.
    if ($previousHandler !== null) {
        $previousHandler($e);
    }
});
```

The pattern of capturing the return value of `set_exception_handler` into a variable, then calling it later, is correct. However, the initial `set_exception_handler(static function (): void {});` is a no-op solely to capture the **previous** handler — this is a known PHP idiom but it has a subtle issue: if any code between the first no-op handler registration and the real handler registration triggers an exception, the no-op handler would swallow it silently.

**More importantly:** The no-op handler at line 98 is immediately replaced by the real handler at line 101. This means the no-op is only a brief intermediate state. This is fine but could be simplified.

**Recommendation:** The pattern is sound, but consider adding a comment explaining WHY the no-op is necessary (to capture what was previously registered before overwriting). This is a known PHP quirk that future maintainers may misunderstand.

---

### 6. [Bug] `PanicFormatter::$redactPaths` incorrectly reorders type

**File:** `src/PanicFormatter.php:135-137`

```php
foreach ($this->redactPaths as $path) {
    $frameFile = str_replace($path, '[redacted]', (string) $frameFile);
}
```

`$frameFile` is cast to `(string)` but `$frameFile` is already a string from `$frame['file'] ?? '?'`. This cast is unnecessary and confusing. Additionally, the replacement does a literal string replace — if a path appears multiple times in the same string it only replaces the first occurrence (use `str_replace()` with arrays to replace all).

Wait — `str_replace($path, '[redacted]', (string) $frameFile)` DOES replace all occurrences because `str_replace` replaces all by default. However, `str_replace` is case-sensitive and paths on different case-sensitive filesystems might not match. Also there's no check for path separator normalization (e.g., `/etc/secrets` won't match `\etc\secrets` on Windows).

**Recommendation:** 
1. Remove the unnecessary `(string)` cast — `$frameFile` is already `string`.
2. Add a comment noting that path redaction is case-sensitive and filesystem-dependent.
3. Consider using `realpath()` normalization before comparison if cross-platform support is needed.

**Confidence: 75%** — this is more of a robustness concern than a bug.

---

### 7. [Performance] `CallerFormatter::find()` calls `debug_backtrace` on every log call

**File:** `src/CallerFormatter.php:24`

```php
$traces = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 20);
```

When `$reportCaller = true`, every log emission walks the entire call stack 20 levels deep. `debug_backtrace()` is expensive — it collects full stack frame information including arguments (even with `DEBUG_BACKTRACE_IGNORE_ARGS`). This is a known performance concern.

**Recommendation:** Consider caching the result per call-site using a static cache keyed by the calling file:line. However, this would require tracking when to invalidate the cache. A simpler approach: document the performance cost and let users disable caller reporting in hot paths.

**Confidence: 90%**

---

### 8. [Code Quality] Duplicated value-coercion logic across formatters

**File:** `src/Formatter/JsonFormatter.php:65-86` vs `src/Formatter/LogfmtFormatter.php:63-66` vs `src/Formatter/ValueCoercion.php:25-65`

`JsonFormatter::coerceValue()` (lines 65-86) and `LogfmtFormatter::formatValue()` (lines 63-66) both duplicate similar type-checking logic from `ValueCoercion::stringify()`. Specifically:

- `JsonFormatter::coerceValue()` handles: bool, int, float, string, array, null, Stringable, objects
- `ValueCoercion::stringify()` handles: bool, int, float, string, array, object, resource, callable, and falls through to 'unknown'
- `LogfmtFormatter::formatValue()` delegates to `ValueCoercion::stringify()` (good)

`JsonFormatter::coerceValue()` reimplements most of the same type checks as `ValueCoercion::stringify()` instead of reusing it. This is code duplication with subtle differences (e.g., `JsonFormatter::coerceValue()` uses `get_class($v)` for objects while `ValueCoercion::stringify()` uses `\get_class($v)` with anonymous-class detection).

**Recommendation:** `JsonFormatter::coerceValue()` should be replaced with calls to `ValueCoercion::stringify()` for non-scalar types. For JSON-specific coercion (where you want to preserve int/bool/float as JSON primitives rather than strings), refactor `ValueCoercion` to have a `coerce()` method that returns mixed (preserving native JSON types) and a `stringify()` method that returns strings.

**Confidence: 85%**

---

### 9. [Design] `HookRegistry::fire()` uses `>=` comparison for level filtering

**File:** `src/Hook/HookRegistry.php:60-66`

```php
foreach ($this->handlers as $minLevel => $callbacks) {
    if ($level->value >= $minLevel) {
        foreach ($callbacks as $callback) {
            $callback($level, $psrLevel, $message, $context);
        }
    }
}
```

The hook fires for all handlers whose `minLevel` is **less than or equal to** the emitted level. This matches the semantic of "fire for all events at or above `$level`". However, there's no way to fire a hook ONLY at a specific level (not at or above). Additionally, there's no `remove()` mechanism — the CALIBER_LEARNINGS.md notes the original `remove(int $id)` was broken and removed, but this means hooks can never be unregistered, which could cause memory leaks in long-running processes that register many hooks.

**Recommendation:** Document the "at or above" semantics clearly. Consider adding a `HookRegistry::remove(int $id)` that stores handlers by their returned ID for later removal. Even though the original implementation was broken, a working version using a closure wrapper could solve this.

**Confidence: 80%**

---

### 10. [Type Safety] `Styles::$levels` is `array` not `array<int, Style>`

**File:** `src/Styles.php:16-17`

```php
/** @var array<int, Style> keyed by Level->value */
public array $levels = [];
```

The docblock is accurate (it's indexed by `Level->value`, an int) but the property is declared as `array` with no generic parameter. PHP doesn't support generics on arrays, but the intent should be documented. More importantly, `Level::cases()` is iterated at construction (line 39) to populate this array — if a new level is added to the enum, the array would silently not include it unless the constructor is also updated.

**Recommendation:** Add a `private function assertAllLevelsCovered(): void` that validates `\count($this->levels) === \count(Level::cases())` in the constructor to catch missing levels if the enum is extended.

---

## Minor Issues (🟡 Nice to Fix)

### 11. [Style] `Logger::new()` duplicates constructor logic

**File:** `src/Logger.php:88-99`

```php
public static function new(
    ?Formatter $formatter = null,
    ?Level $level = null,
    ?string $prefix = null,
    bool $reportTimestamp = true,
    ?string $timeFormat = null,
    bool $reportCaller = false,
    $stream = null,
    ?PartsOrder $partsOrder = null,
): self {
    return new self($formatter, $level, $prefix, $reportTimestamp, $timeFormat, $reportCaller, $stream, $partsOrder);
}
```

This is a factory method that simply forwards to the constructor with identical arguments. Per the AGENTS.md conventions, factories mirror upstream — so `::new()` is correct. However, the constructor parameter order and the factory parameter order should be kept in sync. Currently both use the same 8-parameter signature.

**Observation:** The `?Level $level` param in the constructor at line 52 becomes `?Level $level` in the factory — note the different name (`$minLevel` vs `$level`) in constructor vs factory. This inconsistency is minor but could confuse IDE users.

**Recommendation:** Rename the constructor parameter from `$minLevel` to `$level` for consistency with the factory. This is a non-breaking change as it's just a parameter rename.

---

### 12. [Style] `Logger::setReportCaller` and `setReportTimestamp` rebuild TextFormatter incompletely

**File:** `src/Logger.php:258-284`

```php
public function setReportCaller(bool $on): void
{
    $this->reportCaller = $on;
    if ($this->formatter instanceof TextFormatter) {
        $this->formatter = new TextFormatter(
            $this->reportTimestamp,
            $this->timeFormat,
            $on,
            $this->useColors,
        );
        // ⚠️ Missing: $this->styles and $this->partsOrder are NOT passed!
    }
}
```

When `setReportCaller(true)` rebuilds the `TextFormatter`, it passes 4 arguments but the `TextFormatter` constructor expects 6 (`$reportTimestamp`, `$timeFormat`, `$reportCaller`, `$useColors`, `?$styles`, `?$partsOrder`). The constructor defaults `?$styles = null` (→ `Styles::default()`) and `?$partsOrder = null` (→ `PartsOrder::default()`). This means calling `setReportCaller` resets the styles and parts order to defaults!

Same issue at `setReportTimestamp` (line 272-284).

**Impact:** If you configure `Logger::withPartsOrder()` or `Logger::setStyles()` then call `setReportCaller()`, your custom styles/parts order are lost.

**Confidence: 95%** — this is a real bug.

---

### 13. [Test Quality] Some tests close `$tempFile` before reading content, but in different order

**File:** `tests/LoggerTest.php:49-53, 59-63`

```php
public function testInfoEmitsMessage(): void
{
    $log = $this->logger();
    $log->info('hello');
    \fclose($this->tempFile);   // close BEFORE reading

    $content = \file_get_contents($this->tempPath);   // read after close
    ...
}
```

The test `getContent()` helper in `LogTest.php` (line 52-56) also closes before reading:

```php
private function getContent(): string
{
    \fclose($this->tempFile);
    return \file_get_contents($this->tempPath) ?: '';
}
```

This is correct behavior — you must close a stream before reading from the underlying file. However, there's inconsistency in whether tests use the helper or manually close inline. This is a minor style issue, not a bug.

---

### 14. [Performance] `Styles` constructor creates new `Style` instances for every log level

**File:** `src/Styles.php:32-47`

```php
public function __construct()
{
    $this->timestamp = Style::new()->foreground(Color::ansi(8));
    ...
    foreach (Level::cases() as $level) {
        $this->levels[$level->value] = match ($level) {
            Level::Debug => Style::new()->foreground(Color::ansi(8)),
            Level::Info  => Style::new()->foreground(Color::ansi(4)),
            ...
        };
    }
}
```

Every `new Styles()` creates 5+ new `Style` objects. `Logger` creates a `new TextFormatter` which creates `new Styles` in its constructor if none is provided. In a typical application, this means multiple `Styles` instances could be created. Since `Style` objects are likely immutable, these could be cached as constants.

**Recommendation:** Consider adding `Styles::default()` that returns a static cached instance, or make the default styles constants on the `Styles` class itself.

**Confidence: 70%** — may be premature optimization.

---

### 15. [Design] `Log::installPanicHandler` calls `restoreTerminal` even when Tty is unavailable

**File:** `src/Log.php:129-145`

```php
public static function restoreTerminal(): void
{
    \fwrite(\STDERR, "\x1b[?1049l");   // Always runs
    \fwrite(\STDERR, "\x1b[?25h");     // Always runs
    \fflush(\STDERR);

    if (class_exists(\SugarCraft\Core\Util\Tty::class)) {   // Optional path
        try {
            \SugarCraft\Core\Util\Tty::restoreLast();
        } catch (\Throwable) {
            // Best-effort
        }
    }
}
```

The SGR escape codes (`\x1b[?1049l` and `\x1b[?25h`) are written unconditionally on every call. This is correct for the panic handler (you want to restore the terminal on crash). However, there's no guard for non-TTY environments — writing ANSI codes to a file or non-TTY stream produces garbage characters.

**Recommendation:** Check `\defined('STDERR')` and `is_resource(\STDERR)` before writing. Or check if `function_exists('posix_isatty')` and `posix_isatty(STDERR)` before writing the terminal sequences.

---

### 16. [DX] `PanicFormatter` hardcodes the "caliber refresh" hint

**File:** `src/PanicFormatter.php:84`

```php
$lines[] = $this->mutedStyle->render('  consider `caliber refresh` if this is config-related');
```

This hint is hardcoded in the panic formatter. If a user installs `candy-log` outside of the SugarCraft CLI ecosystem, this hint is irrelevant. The hint should be configurable via a constructor parameter.

**Recommendation:** Add a `?string $hint` parameter to `PanicFormatter::pretty()` and `PanicFormatter::plain()` constructors.

---

### 17. [Type Safety] `StandardLogAdapter::print` variadic args not typed

**File:** `src/StandardLogAdapter.php:31-36`

```php
public function print(...$args): void
{
    $msg = \implode(' ', \array_map(fn($a) => (string) $a, $args));
    $level = $this->forceLevel ?? Level::Info;
    $this->logger->log($level, $msg);
}
```

The `...$args` is untyped (variadic). The `(string)` cast handles the conversion but the intent should be documented. This is a minor DX issue.

---

### 18. [Test] `LoggerTest::testFatalCallsExit` has confusing control flow

**File:** `tests/LoggerTest.php:87-98`

```php
public function testFatalCallsExit(): void
{
    $log = $this->logger();
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('exit(1)');
    try {
        $log->fatal('bye');
    } catch (\RuntimeException $e) {
        throw new \RuntimeException('exit(1)');
    }
}
```

The test catches the `RuntimeException` thrown by `Logger::emit()` at line 145 and then throws a NEW `RuntimeException` with the message `'exit(1)'`. But the original exception from `Logger::emit()` is `Lang::t('logger.fatal', ...)` — a completely different message. The test assertion for `expectExceptionMessage('exit(1)')` doesn't match the actual exception that would be thrown. This test appears to be testing the wrong thing.

**Confidence: 80%** — the test logic looks wrong.

---

### 19. [Security] Redact paths only in backtrace frames, not primary exception location

**File:** `src/PanicFormatter.php:135-137` (per-file redaction)

The path redaction in `formatBacktrace()` only applies to `$frame['file']` (backtrace frame paths), not to `$file` and `$line` (the primary exception location at lines 97-98, printed at line 122). This means if the primary exception file contains a secret path, it won't be redacted.

**Recommendation:** Apply redaction to `$file` before rendering at line 122.

---

## Positive Observations (🟢 What's Done Well)

1. **Immutable with-clone pattern** — All `with*()` methods in `Logger` properly clone and modify, never mutating the original. The `PartsOrder` readonly DTO is a clean pattern.

2. **Strict types everywhere** — Every file has `declare(strict_types=1)` as line 1. No `mixed` return types. Proper enum usage with `Level` backed enum.

3. **`ValueCoercion::stringify()` depth limit** — The `MAX_DEPTH = 4` guard prevents infinite recursion on circular object references. Good edge-case handling.

4. **`PsrBridge` doesn't formally implement `LoggerInterface`** — Comment at `src/PsrBridge.php:15-17` explicitly notes this to avoid signature incompatibilities. Smart approach.

5. **`CallerFormatter::find()` skips all internal frames** — Uses `strpos($file, $selfDir) === 0` to skip the entire `SugarCraft\Log` namespace. This is the correct approach to find the true call site.

6. **Probe-driven color decision** — Color is determined by `Probe::colorProfile()->allowsColor()` respecting `NO_COLOR`/`FORCE_COLOR` environment variables. Proper for a CLI library.

7. **`LogfmtFormatter::escape()` handles special characters** — Quotes and escapes logfmt values properly. Good edge-case handling.

8. **Hook registry stores handlers per level** — The `HookRegistry` approach of storing callbacks keyed by minimum level allows O(1) lookup for level-filtered dispatch.

9. **Good test coverage** — Multiple formatters tested, value coercion edge cases covered, field merge precedence tested, formatter preservation on setters tested.

10. **`PartsOrder` static factories** — `PartsOrder::default()`, `syslog()`, `messageFirst()` are clean preset factories that mirror upstream charmbracelet patterns.

---

## Philosophy Compliance

The 5 Laws of Elegant Defense (from code-philosophy skill):

1. **Data guides flow** ✅ — Log level values guide filtering decisions in `emit()` at line 131. Context data guides field formatting in all three formatters.
2. **Errors are early and explicit** ✅ — `InvalidArgumentException` thrown for invalid streams at line 300. Level filtering at line 131 returns early.
3. **State transitions are constrained** ✅ — Logger state (minLevel, formatter, styles) can only be modified via explicit setters. Cloning for `with*()` creates new instances.
4. **Interfaces are minimal** ✅ — `Formatter` interface has a single `format()` method. `Hook` interface has a single `onLevel()` method.
5. **Complexity is localized** ✅ — Complex logic (value coercion, backtrace walking, caller detection) is isolated in single-purpose classes.

---

## Recommendations Summary (By Priority)

### Immediate (Before Next Release)
1. Fix `setReportCaller`/`setReportTimestamp` to pass `$this->styles` and `$this->partsOrder` when rebuilding `TextFormatter` (`Logger.php:263-269, 277-283`)
2. Add `?resource` type hint to `Logger::$stream` property and constructor parameter (`Logger.php:29, 30, 56`)
3. Apply path redaction to `$file` in `PanicFormatter::formatBacktrace()` (`PanicFormatter.php:122`)

### Soon (Next Sprint)
4. Extract duplicated `JsonFormatter::coerceValue()` logic into shared `ValueCoercion::coerce()` method
5. Add working `HookRegistry::remove(int $id)` method for hook cleanup in long-running processes
6. Document the "hooks only via PsrBridge" design decision prominently in README

### Eventually (Post-1.0)
7. Add ReactPHP async logger wrapper (`AsyncLogger` with `LoopInterface` integration)
8. Add optional `SplFileObject`/`WritableStreamInterface` support for stream abstraction (PHP 8.4+ resource deprecation)
9. Add `Styles::default()` static cache to avoid repeated `Style` instantiation
10. Add configurable hint text to `PanicFormatter`

---

*Review completed per code-review skill methodology. All findings ≥80% confidence unless marked otherwise. Critical/Major issues should be addressed before merge. Minor issues are nice-to-have.*
