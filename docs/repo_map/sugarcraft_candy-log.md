# SugarCraft/candy-log

## Metadata
- **URL:** https://github.com/sugarcraft/candy-log
- **Language:** PHP 8.3+
- **License:** MIT
- **Description:** PHP port of charmbracelet/log — a minimal, colorful leveled logging library with structured context, Text/JSON/Logfmt formatters, sub-loggers, and standard log/PSR-3 adapters.
- **Status:** 🟢 v1 ready
- **Upstream:** [charmbracelet/log](https://github.com/charmbracelet/log) (Go, ~700 stars)

## Architecture

### Package Structure
```
candy-log/
├── src/
│   ├── Log.php                  # Static facade (debug/info/warn/error/fatal/print)
│   ├── Logger.php               # Core logger (immutable + fluent)
│   ├── Level.php                # 5-level enum (Debug/Info/Warn/Error/Fatal)
│   ├── Formatter.php             # Formatter interface contract
│   ├── Formatter/
│   │   ├── TextFormatter.php     # Human-readable ANSI-colored output
│   │   ├── JsonFormatter.php     # JSON Lines output
│   │   └── LogfmtFormatter.php   # key=value pairs for log aggregators
│   ├── Styles.php               # Per-level and per-field ANSI styling
│   ├── StandardLogAdapter.php    # net/http *log.Logger compatibility
│   ├── PsrBridge.php            # PSR-3 LoggerInterface bridge
│   ├── Hook/
│   │   ├── Hook.php              # Hook interface contract
│   │   └── HookRegistry.php      # Per-level callback registry
│   ├── PanicFormatter.php        # Exception prettifier + panic handler
│   ├── PartsOrder.php            # Config DTO for log-part ordering
│   ├── CallerFormatter.php       # Call-site finder via debug_backtrace
│   └── Lang.php                  # i18n wrapper (extends Core\I18n\Lang)
├── lang/                        # 15 locales (en, fr, de, es, pt, pt-br, zh-cn, zh-tw, ja, ru, it, ko, pl, nl, tr, cs, ar)
├── tests/                       # 9 test files, 200+ assertions
├── examples/
│   ├── demo.php                  # All features demo
│   ├── panic-handler.php         # installPanicHandler() demo
│   └── panic-restore.php
└── .vhs/demo.tape + demo.gif     # VHS demo for CI rendering
```

## Feature Analysis

### Log Level Design

**candy-log uses a 5-level syslog-aligned enum:**

| Level  | Value | Short | Label  |
|--------|-------|-------|--------|
| Debug  | -4    | DBG   | DEBUG  |
| Info   |  0    | INF   | INFO   |
| Warn   |  4    | WRN   | WARN   |
| Error  |  8    | ERR   | ERROR  |
| Fatal  | 12    | FTL   | FATAL  |

**File:** `src/Level.php:10-38`

```php
enum Level: int
{
    case Debug = -4;
    case Info  =  0;
    case Warn  =  4;
    case Error =  8;
    case Fatal = 12;
}
```

**Atomic level checking** is done via integer comparison in `Logger::log()`:
```php
// src/Logger.php:98-100
if ($level->value < $this->minLevel->value) {
    return;  // level filtered, skip
}
```

**Upstream difference:** Go's charmbracelet/log uses `sync/atomic` for thread-safe level checking because Go is concurrent. PHP is single-threaded, so a plain integer comparison suffices. The PHP port correctly identifies this as a non-issue.

**Not ported:** The Go version has a `Helper()` method that marks functions as "helpers" for caller skip, using `sync.Map` to track helper functions. This is a Go-specific pattern (similar to `testing.TB.Helper()`) that has no direct PHP equivalent and wasn't needed for the port.

### Formatter Implementations

#### TextFormatter
**File:** `src/Formatter/TextFormatter.php:16-123`

Colorized human-readable output with configurable timestamp, caller, prefix, and structured context. Uses `candy-sprinkles/Style` for ANSI SGR rendering:

```php
// Level coloring via candy-sprinkles
private function styledLevel(Level $level): string
{
    $label = $level->shortLabel();
    return match ($level) {
        Level::Debug => Style::new()->foreground(Color::ansi(8))->render($label),   // grey
        Level::Info  => Style::new()->foreground(Color::ansi(4))->render($label),   // blue
        Level::Warn  => Style::new()->foreground(Color::ansi(3))->render($label),     // yellow
        Level::Error => Style::new()->foreground(Color::ansi(1))->render($label),    // red
        Level::Fatal => Style::new()->foreground(Color::ansi(7))->background(Color::ansi(1))->render($label),
    };
}
```

Context values formatted as `key=value` pairs:
```php
// src/Formatter/TextFormatter.php:97-107
private function formatContext(array $context): string
{
    $pairs = [];
    foreach ($context as $k => $v) {
        $val = $this->formatValue($v);
        $pairs[] = $this->useColors
            ? Style::new()->foreground(Color::ansi(8))->render("{$k}={$val}")
            : "{$k}={$val}";
    }
    return \implode(' ', $pairs);
}
```

#### JsonFormatter
**File:** `src/Formatter/JsonFormatter.php:14-68`

Simple JSON Lines output with ATOM timestamps:
```php
$record = [
    'level' => $level->label(),
    'msg'   => $message,
];
if ($this->reportTimestamp) {
    $record['time'] = $time->format(\DateTimeInterface::ATOM);
}
// ... context merged as top-level keys
return (string) \json_encode($record, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n";
```

#### LogfmtFormatter
**File:** `src/Formatter/LogfmtFormatter.php:14-76`

Logfmt format with proper escaping:
```php
private function escape(string $s): string
{
    if (\preg_match('/[\s="]/', $s)) {
        return '"' . \str_replace('"', '\\"', $s) . '"';
    }
    return $s;
}
```

### Structured Context Handling

**File:** `src/Logger.php:96-114`

Child loggers via `with()` merge fields:
```php
public function log(Level $level, string $message, array $context = []): void
{
    // Merge child fields with call-site context
    $merged = \array_merge($this->fields, $context);
    // ...
}
```

**Sub-logger creation** (immutable + fluent):
```php
// src/Logger.php:183-188
public function with(array $fields): self
{
    $child = clone $this;
    $child->fields = \array_merge($this->fields, $fields);
    return $child;
}
```

### slog.Handler Implementation

**Not implemented.** The Go version implements `slog.Handler` interface (Go 1.21+ stdlib integration). PHP has no equivalent `log/slog` standard library interface. This is a fundamental platform difference, not a missing feature.

### StandardLogAdapter

**File:** `src/StandardLogAdapter.php:12-54`

Wraps `Logger` for compatibility with code expecting Go's `*log.Logger` (e.g., `net/http.Server.ErrorLog`):
```php
final class StandardLogAdapter
{
    public function print(...$args): void
    {
        $msg = \implode(' ', \array_map(fn($a) => (string) $a, $args));
        $level = $this->forceLevel ?? Level::Info;
        $this->logger->log($level, $msg);
    }
    // fatal() and panic() both call logger->fatal()
}
```

### PSR-3 Bridge

**File:** `src/PsrBridge.php:21-101`

Full PSR-3 `LoggerInterface` compatibility with hook system integration:
```php
public function log(string $level, string|\Stringable $message, array $context = []): void
{
    $sugarCraftLevel = self::toSugarCraftLevel($level);
    $this->hooks->fire($sugarCraftLevel, $level, (string) $message, $context);
    $this->logger->log($sugarCraftLevel, (string) $message, $context);
}
```

Level mapping:
- `EMERGENCY`, `ALERT`, `CRITICAL` → `Level::Fatal`
- `ERROR` → `Level::Error`
- `WARNING` → `Level::Warn`
- `NOTICE`, `INFO` → `Level::Info`
- `DEBUG` → `Level::Debug`

### Hook System

**File:** `src/Hook/HookRegistry.php:13-57`

Registry for log hooks — callbacks fire when log level meets threshold:
```php
public function onLevel(Level $level, callable $callback): int
{
    $id = $this->nextId++;
    $this->handlers[$level->value][] = $callback;
    return $id;
}

public function fire(Level $level, string $psrLevel, string $message, array $context): void
{
    foreach ($this->handlers as $minLevel => $callbacks) {
        if ($level->value >= $minLevel) {
            foreach ($callbacks as $callback) {
                $callback($level, $psrLevel, $message, $context);
            }
        }
    }
}
```

**Note:** The original `remove(int $id)` method was removed because `Closure::fromCallable` rejects integers — only `onLevel` and `fire` are needed per the CALIBER_LEARNINGS.

### Panic Handler

**File:** `src/Log.php:69-113`, `src/PanicFormatter.php`

Installs exception + shutdown handlers for uncaught exception beautification:
- Restores terminal from altscreen mode (SGR 1049)
- Shows cursor (SGR 1049)
- Prints colorized banner with exception class + message
- Prints backtrace with file paths and line numbers
- Collapses repeated stack frames
- Appends hint to run `caliber refresh`

```php
Log::installPanicHandler();
// or with options:
Log::installPanicHandler(PanicFormatter::pretty($showLocals, $redactPaths));
```

Two formatter presets:
- `PanicFormatter::pretty()` — colored for TTY
- `PanicFormatter::plain()` — no ANSI for file/CI output

### Probe-Driven Color Decision

**File:** `src/Logger.php:42-68`

```php
public function __construct(
    ?Formatter $formatter = null,
    ?Level $minLevel = null,
    ?string $prefix = null,
    bool $reportTimestamp = true,
    ?string $timeFormat = null,
    bool $reportCaller = false,
    $stream = null,
) {
    // Probe-driven color decision: disable colors when terminal cannot render them
    $useColors = Probe::colorProfile()->allowsColor();

    $this->formatter = $formatter ?? new TextFormatter(
        $reportTimestamp,
        $timeFormat,
        $reportCaller,
        $useColors,
    );
    // ...
}
```

**Integration:** `candy-palette`'s `Probe::colorProfile()` respects `NO_COLOR` and `FORCE_COLOR` environment variables.

### String Builder Pooling

**Not implemented in PHP.** The Go version uses `sync.Pool` for `strings.Builder` reuse:
```go
// Upstream charmbracelet/log
var bufPool = sync.Pool{
    New: func() any { return new(strings.Builder) },
}
```

PHP has no equivalent to Go's `sync.Pool`. This is a Go-specific optimization for reducing allocations during string escaping. In PHP, string concatenation is relatively cheap and the overhead of a pool implementation wouldn't be justified. The text formatter builds strings via `implode()` which is efficient enough for PHP's use case.

## Performance Characteristics

### Level Filtering
Integer comparison is O(1): `$level->value < $this->minLevel->value`

### String Formatting
- **TextFormatter:** Direct string building with `implode()` — minimal allocations
- **JsonFormatter:** Single `json_encode()` call — native C extension
- **LogfmtFormatter:** Loop with `preg_match()` for escaping — O(n) with string scanning

### Memory
- No string builder pooling (see above)
- Immutable loggers use `clone` — shallow copy, only fields array is copied
- Formatters are stateless — no per-instance allocation overhead

### Atomic Level Checking
PHP's integer comparison is atomic at the PHP engine level for simple reads. No `sync/atomic` equivalent needed.

## Comparison with Mapped Upstream

### charmbracelet/log (Go)
| Feature | Go Original | PHP Port | Notes |
|--------|------------|----------|-------|
| Leveled logging | ✅ | ✅ | 5 levels, syslog-aligned values |
| Structured context | ✅ | ✅ | key=value pairs |
| TextFormatter | ✅ | ✅ | ANSI-colored output |
| JsonFormatter | ✅ | ✅ | JSON Lines |
| LogfmtFormatter | ✅ | ✅ | logfmt format |
| Sub-loggers | ✅ | ✅ | immutable with() |
| Probe color detection | ✅ | ✅ | Probe::colorProfile() |
| Caller reporting | ✅ | ✅ | debug_backtrace() |
| Helper function skip | ✅ | ❌ | No Go-like helper tracking |
| Context propagation | ✅ | ❌ | No context.Context in PHP |
| slog.Handler | ✅ | ❌ | No Go-style interface |
| String builder pool | ✅ | ❌ | No sync.Pool in PHP |
| Multiline value handling | ✅ | ❌ | Simple [item1 item2] format |
| String escaping | ✅ | ⚠️ | Logfmt has escaping; Text minimal |
| Sync/atomic level check | ✅ | N/A | Not needed in single-threaded PHP |
| stdlog adapter | ✅ | ✅ | StandardLogAdapter |
| PSR-3 bridge | ⚠️ | ✅ | Custom PsrBridge implementation |
| Panic handler | ❌ | ✅ | Added value beyond upstream |
| PartsOrder | ❌ | ✅ | Added config DTO |
| Hook system | ❌ | ✅ | Added middleware-style interceptors |

### pterm/pterm (Go)
| Feature | pterm Logger | candy-log |
|---------|-------------|------------|
| Log levels | 7 (TRACE/DEBUG/INFO/WARN/ERROR/FATAL/PRINT) | 5 (Debug/Info/Warn/Error/Fatal) |
| Formatters | Text (colorful) + JSON | Text + JSON + Logfmt |
| Key-value args | ✅ | ✅ |
| Caller info | ✅ | ✅ |
| slog integration | ✅ (SlogHandler) | ❌ (no equivalent) |
| Max-width wrapping | ✅ | ❌ |
| Global instance | ✅ | ✅ (Log facade) |
| Sub-logger | ❌ | ✅ (with()) |
| Hook/middleware | ❌ | ✅ (HookRegistry) |
| PSR-3 compatibility | ❌ | ✅ (PsrBridge) |

## Key Innovation Points

### 1. Syslog-Aligned Level Values
Using -4/0/4/8/12 instead of sequential integers (0-4) enables:
- Threshold comparisons with external log systems
- Easy integration with syslog, journald, and log aggregators
- No value collisions with standard log levels

### 2. Probe-Driven Color Intelligence
Delegating color decision to `candy-palette`'s `Probe::colorProfile()` means:
- Respects `NO_COLOR` environment variable
- Respects `FORCE_COLOR` for CI environments
- Proper degradation for non-TTY output
- Single source of truth for color detection across SugarCraft

### 3. Hook System for Observability
The `HookRegistry` enables middleware-style log interception:
- Dispatch to external services (Datadog, Sentry, etc.)
- Enrich context with runtime metadata
- Filter sensitive data before logging
- Rotate log files based on entry counts

### 4. Panic Handler for TTY Recovery
The `installPanicHandler()` system provides:
- Terminal state restoration (exit altscreen, show cursor)
- Beautiful exception rendering with backtrace
- Frame collapsing for repeated calls
- Path redaction for security-sensitive paths
- Caliber hint for configuration issues

### 5. PartsOrder Config DTO
Configurable log-part ordering enables:
- Default: timestamp level prefix? caller? message fields?
- Syslog-friendly: timestamp level message fields
- Message-first: message level timestamp fields
- Custom ordering for specific use cases

## Test Coverage

**9 test files** covering all public API:
- `LevelTest.php` — 6 tests for enum values and labels
- `LoggerTest.php` — 12 tests for logging, filtering, formatting, sub-loggers
- `LogTest.php` — 16 tests for static facade, panic handler, context merging
- `StylesTest.php` — 6 tests for per-level and per-field styling
- `PsrBridgeTest.php` — 6 tests for PSR-3 level mapping
- `HookRegistryTest.php` — 5 tests for callback registration and firing
- `PanicFormatterTest.php` — 10 tests for exception formatting, redaction, ANSI
- `PartsOrderTest.php` — 6 tests for preset orders and has()
- `CallerFormatterTest.php` — 3 tests for call-site detection
- `CoverageBoostTest.php` — 16 tests for formatted variants, in-place setters, formatters

## Dependencies

```json
{
    "require": {
        "php": "^8.3",
        "psr/log": "^3.0",
        "sugarcraft/candy-core": "dev-master",
        "sugarcraft/candy-palette": "dev-master",
        "sugarcraft/candy-sprinkles": "dev-master"
    }
}
```

- **candy-core** — `Tty::restoreLast()` for terminal recovery
- **candy-palette** — `Probe::colorProfile()->allowsColor()` for color detection
- **candy-sprinkles** — `Style` for ANSI SGR rendering

## Strengths

1. **Clean API Design** — Static `Log::info()` facade plus instance `Logger` gives both global convenience and testability
2. **Multiple Formatters** — Text/JSON/Logfmt cover human-readable, machine-parseable, and log-aggregator formats
3. **Immutable + Fluent** — `with()` returns a clone, making logger composition safe in concurrent contexts
4. **PSR-3 Bridge** — Works anywhere a PSR-3 logger is expected, enabling drop-in integration
5. **Hook System** — Middleware-style callbacks for dispatching to external services
6. **TTY Intelligence** — Probe-driven color respects environment variables automatically
7. **Panic Handler** — Beautiful exception rendering for CLI debugging
8. **Comprehensive Tests** — 80+ tests across 9 files with good coverage of edge cases
9. **i18n Support** — 15 locales for translated messages

## Weaknesses

1. **No slog.Handler Equivalent** — PHP has no standard structured logging interface to bridge to
2. **No String Pooling** — Higher allocation churn than Go's `sync.Pool` for high-volume logging
3. **No Context Propagation** — PHP's request-scoped globals can't match Go's `context.Context`
4. **Minimal String Escaping** — Text formatter doesn't handle ANSI sequences in values like Go version
5. **No Multiline Handling** — Values with newlines get collapsed to `[item1 item2]` instead of indented continuation
6. **No Max-Width Wrapping** — Long messages aren't wrapped as they are in pterm's logger

## Verdict

candy-log is a well-executed PHP port of charmbracelet/log that successfully translates the Go API into idiomatic PHP patterns. The immutable + fluent design, syslog-aligned level values, and probe-driven color intelligence are all preserved. The addition of a hook system and panic handler adds value beyond the upstream.

The main limitations (no slog.Handler, no context propagation, no string pooling) are fundamental platform differences between Go and PHP, not oversights. For PHP applications needing structured logging with beautiful terminal output, candy-log is the canonical choice in the SugarCraft ecosystem.

---

*Report generated from analysis of candy-log v1 source code, tests, and examples. Upstream comparison against charmbracelet/log (Go) and pterm/pterm (Go).*
