# Overview

candy-log is a well-executed PHP port of charmbracelet/log — a minimal, colorful leveled logging library with structured context, multiple formatters (Text/JSON/Logfmt), sub-loggers, and standard log/PSR-3 adapters. It is v1 ready and part of the SugarCraft ecosystem for terminal applications.

**Biggest opportunity areas:**
- Log rotation and file handling integration
- Multiline value formatting with proper indentation (like Go upstream)
- ANSI sequence escaping in message values
- Context propagation improvements for request-scoped logging
- Enhanced hook system for observability integrations

**Biggest missing capabilities:**
- No slog.Handler equivalent (PHP has no standard structured logging interface)
- No context.Context propagation (fundamental Go vs PHP difference)
- No log rotation built-in
- No max-width message wrapping
- No helper function marking (Go-specific pattern without PHP equivalent)

---

# Internal Capability Summary

## Current Architecture

```
candy-log/src/
├── Log.php                  # Static facade (debug/info/warn/error/fatal/print)
├── Logger.php               # Core logger (immutable + fluent, 276 lines)
├── Level.php                # 5-level syslog-aligned enum (-4/0/4/8/12)
├── Formatter.php            # Formatter interface contract
├── Formatter/
│   ├── TextFormatter.php    # Human-readable ANSI-colored output
│   ├── JsonFormatter.php    # JSON Lines output  
│   └── LogfmtFormatter.php  # key=value pairs for log aggregators
├── Styles.php              # Per-level and per-field ANSI styling
├── StandardLogAdapter.php   # net/http *log.Logger compatibility
├── PsrBridge.php            # PSR-3 LoggerInterface bridge
├── Hook/
│   ├── Hook.php             # Hook interface contract
│   └── HookRegistry.php     # Per-level callback registry
├── PanicFormatter.php       # Exception prettifier + panic handler
├── PartsOrder.php           # Config DTO for log-part ordering
├── CallerFormatter.php      # Call-site finder via debug_backtrace
└── Lang.php                 # i18n wrapper (15 locales)
```

## Current Features

1. **Leveled Logging**: Debug (-4), Info (0), Warn (4), Error (8), Fatal (12) with syslog-aligned integer values
2. **Three Formatters**: TextFormatter (ANSI-colored), JsonFormatter (JSON Lines), LogfmtFormatter (key=value pairs)
3. **Structured Context**: Key=value pairs merged via `with()` sub-loggers
4. **Probe-Driven Colors**: Respects `NO_COLOR`/`FORCE_COLOR` via candy-palette's Probe
5. **Hook System**: Per-level callback registry for middleware-style interception
6. **Panic Handler**: Exception beautification with backtrace, path redaction, terminal restoration
7. **PSR-3 Bridge**: Full LoggerInterface compatibility
8. **PartsOrder Config**: Configurable log-part ordering (default, syslog, messageFirst)
9. **Caller Reporting**: debug_backtrace-based call-site detection

## APIs

**Static facade (Log.php):**
```php
Log::debug|info|warn|error|fatal($message, array $context = [])
Log::setLogger(Logger $logger)
Log::installPanicHandler(?PanicFormatter $formatter = null, bool $showLocals = false, array $redactPaths = [])
Log::restoreTerminal()
```

**Instance logger (Logger.php):**
```php
Logger::new(?Formatter $f = null, ?Level $l = null, ?string $p = null, bool $ts = true, ?string $tf = null, bool $rc = false, $stream = null)
$logger->log(Level $level, string $message, array $context = [])
$logger->with(array $fields)  // immutable child logger
$logger->withPrefix(string $prefix)
$logger->withFormatter(Formatter $formatter)
$logger->withMinLevel(Level $level)
// Level-specific: debug(), info(), warn(), error(), fatal(), print()
// Formatted: debugf(), infof(), warnf(), errorf(), fatalf(), printf()
```

## Extension Systems

1. **Formatter Interface**: Implement `format(Level, string, array, DateTimeImmutable, ?string, ?string): string` for custom output
2. **Hook Interface**: Implement `onLevel(Level, string, string, array): void` for log interception
3. **Styles**: Per-level and per-field Style customization

## Strengths

1. Clean API design with static facade + instance logger duality
2. Multiple formatters covering human, machine, and aggregator formats
3. Immutable + fluent `with()` pattern for safe composition
4. Full PSR-3 bridge for drop-in integration
5. Hook system for observability middleware
6. Probe-driven color intelligence
7. Beautiful panic handler for CLI debugging
8. Comprehensive tests (9 files, 200+ assertions)

## Weaknesses

1. No slog.Handler equivalent (PHP platform limitation)
2. No context propagation (PHP doesn't have context.Context)
3. No log rotation built-in
4. No multiline handling (values with newlines get collapsed)
5. No ANSI sequence escaping in message values
6. No max-width message wrapping

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority level |
|------|-----------|--------------------------|----------------|
| `charmbracelet/log` | Direct upstream | TextFormatter multiline, string escaping, slog.Handler, helper skip, context propagation | Critical |
| `charmbracelet/bubbletea` | TUI integration | tea.LogToFile(), debug logging to file, signal handling | High |
| `textualize/textual` | Python logging | Thread-safe logging with verbosity levels and groups, app logger integration | Medium |
| `ratatui/ratatui` | Rust TUI patterns | No built-in logging (delegates to user), buffer diffing performance | Low |
| `pterm/pterm` | Go logging alternative | 7 log levels, max-width wrapping, slog integration, global instance | Medium |
| `WhispPHP/whisp` | PHP SSH server | PSR-3 logger implementations (NullLogger, FileLogger, ConsoleLogger) | Low |
| `charmbracelet/charm` | Go ecosystem | TUI logging, key-value store patterns | Low |

---

# Feature Gap Analysis

## Critical Priority

### 1. Multiline Value Handling
**Title:** TextFormatter lacks multiline value formatting

**Description:** When context values contain newlines, the Go upstream properly indents continuation lines with `│` visual guide. The PHP port collapses arrays to `[item1 item2]` but doesn't handle raw multiline strings.

**Why it matters:** Logging multiline SQL queries, stack traces, or JSON payloads becomes unreadable when collapsed to a single line.

**Source:** `docs/repo_map/charmbracelet_log.md` — "Multiline Support: Text formatter properly handles multiline values with visual indentation"

**Implementation ideas:**
- Detect newlines in formatted values
- Add indented continuation with visual guide character
- Match upstream's `│` prefix for continuation lines

**Estimated complexity:** Medium (formatter modification, testing edge cases)

**Expected impact:** High — improves log readability for complex data

---

### 2. ANSI Sequence Escaping in Values
**Title:** TextFormatter doesn't escape ANSI sequences in message values

**Description:** Go upstream detects ANSI escape sequences in values and properly quotes them to prevent terminal escape injection and visual corruption. PHP port has no such handling.

**Why it matters:** Logging user-controlled content with raw ANSI sequences could corrupt terminal display or enable malicious injection.

**Source:** `docs/repo_map/charmbracelet_log.md` — "String Escaping: Comprehensive handling of non-printable chars and ANSI sequences"

**Implementation ideas:**
- Detect ANSI sequences in message and context values
- Quote or strip problematic sequences
- Apply same logic to caller information

**Estimated complexity:** Medium (need to define escape rules, handle edge cases)

**Expected impact:** Security + readability — prevents terminal corruption

---

## High Value

### 3. Log Rotation / File Output
**Title:** No built-in log rotation or structured file output

**Description:** The Go upstream relies on external `io.MultiWriter` for log rotation. PHP has no equivalent built-in. Users must implement their own file rotation logic or rely on external tools like logrotate.

**Why it matters:** Production applications need log rotation to prevent disk exhaustion and enable log management.

**Source:** `docs/repo_map/charmbracelet_log.md` — "No Log Rotation: Built-in file rotation not supported; must wrap with io.MultiWriter or external solution"

**Implementation ideas:**
- Add RotatingFileHandler as a new class
- Support size-based rotation (rotate when file exceeds N bytes)
- Support time-based rotation (daily, hourly)
- Maintain indexed old logs (app.log.1, app.log.2, etc.)

**Estimated complexity:** Medium-High (file handling, naming conventions, cleanup)

**Expected impact:** High — production readiness

---

### 4. Log Levels 6-7 (Trace/Print enhancement)
**Title:** Only 5 levels vs pterm's 7 levels

**Description:** pterm/pterm has 7 levels including TRACE and PRINT. candy-log has only 5 levels but does have a `print()` method that uses Info under the hood.

**Why it matters:** More granular log levels enable better filtering and observability.

**Source:** `docs/repo_map/sugarcraft_candy-log.md` — "pterm Logger | 7 (TRACE/DEBUG/INFO/WARN/ERROR/FATAL/PRINT) | 5 (Debug/Info/Warn/Error/Fatal)"

**Implementation ideas:**
- Add Level::Trace with value -8 below Debug
- Ensure print() has distinct behavior (no level prefix at all)
- Consider EMERGENCY/ALERT/CRITICAL mapping in PSR-3 bridge (already maps to Fatal)

**Estimated complexity:** Low (enum addition, minimal testing)

**Expected impact:** Medium — more granular control

---

### 5. Max-Width Message Wrapping
**Title:** Long messages aren't wrapped

**Description:** pterm's logger supports max-width wrapping for long messages. PHP port has no equivalent.

**Why it matters:** In narrow terminal windows, long messages can wrap unpredictably or cause visual issues.

**Source:** `docs/repo_map/sugarcraft_candy-log.md` — "Max-width wrapping | ✅ | ❌"

**Implementation ideas:**
- Add `maxWidth` constructor option to Logger
- Implement word-wrap in TextFormatter
- Allow formatters to opt into width-aware formatting

**Estimated complexity:** Medium (word-wrap algorithm, terminal width detection)

**Expected impact:** Medium — improved TUI integration

---

## Medium Priority

### 6. Context Propagation Improvements
**Title:** PHP lacks context.Context equivalent

**Description:** Go's charmbracelet/log supports `WithContext()`/`FromContext()` for storing loggers in `context.Context`. PHP has no equivalent request-scoped context mechanism.

**Why it matters:** In web applications or long-running processes, context propagation ensures loggers carry relevant request metadata.

**Source:** `docs/repo_map/charmbracelet_log.md` — "Context Integration: Proper propagation via standard context.Context"

**Implementation ideas:**
- Document limitation as platform difference
- Create optional RequestContext holder using PHP's `Context` (similar to ReactPHP patterns)
- Use `$GLOBALS` or static properties as workaround

**Estimated complexity:** N/A (platform limitation, would need PSR-18/20 equivalent)

**Expected impact:** Low-Medium (workaround possible but not native)

---

### 7. Helper Function Skip Pattern
**Title:** No Go-like Helper() function marking

**Description:** Go's charmbracelet/log has `Helper()` that marks functions as "helpers" for caller skip (like `testing.TB.Helper()`). PHP has no equivalent.

**Why it matters:** Accurate caller location reporting is harder without helper marking.

**Source:** `docs/repo_map/charmbracelet_log.md` — "Helper Function Skip Map: Uses sync.Map to track functions marked as helpers"

**Implementation ideas:**
- Document as non-portable feature
- Could implement a static analysis tool to detect wrapper functions
- CallerFormatter::find() already handles some cases via DEBUG_BACKTRACE_IGNORE_ARGS

**Estimated complexity:** N/A (no PHP equivalent pattern)

**Expected impact:** Low (CallerFormatter mitigates)

---

### 8. Verbosity Groups
**Title:** Textualize-style verbosity groups

**Description:** textualize/textual has per-app logger with verbosity levels and groups for structured log filtering.

**Why it matters:** Large applications benefit from named log groups that can be enabled/disabled independently.

**Source:** `docs/repo_map/textualize_textual.md` — "Thread-safe Logging: Per-app logger with verbosity levels and groups"

**Implementation ideas:**
- Add LoggerGroup class to manage multiple named loggers
- Each group has its own minLevel
- Groups can be enabled/disabled collectively

**Estimated complexity:** Medium (new class, integration with facade)

**Expected impact:** Medium — better large-app support

---

## Low Priority

### 9. Time Function Customization
**Title:** No custom time function like Go upstream

**Description:** Go upstream allows `SetTimeFunction(f TimeFunction)` for custom time sourcing. PHP port uses `DateTimeImmutable` directly.

**Why it matters:** Testing and time-sensitive applications benefit from fake clocks.

**Implementation ideas:**
- Add `setTimeFunction(callable $timeFn): void` to Logger
- Use closure for time source
- Default remains `fn() => new \DateTimeImmutable()`

**Estimated complexity:** Low

**Expected impact:** Low-Medium (testing utility)

---

### 10. String Escaping Improvements
**Title:** LogfmtFormatter has escaping but could be more robust

**Description:** LogfmtFormatter has basic escaping for `[\s="]` but Go's logfmt handling is more comprehensive.

**Source:** `docs/repo_map/charmbracelet_log.md` — "String Escaping: Comprehensive handling of non-printable chars and ANSI sequences"

**Implementation ideas:**
- Add comprehensive non-printable character escaping
- Handle Unicode properly
- Quote values containing special characters

**Estimated complexity:** Medium

**Expected impact:** Low-Medium (compatibility with log aggregators)

---

# Algorithm / Performance Opportunities

## Current Approach vs External

| Aspect | Current (candy-log) | Upstream (Go) | Improvement |
|--------|---------------------|----------------|--------------|
| Level checking | Integer comparison `$level->value < $this->minLevel->value` | `atomic.LoadInt64()` for thread-safety | Go is thread-safe, PHP is single-threaded — equivalent |
| String formatting | Direct `implode()` + `str_replace()` | `sync.Pool` for `strings.Builder` reuse | No pool in PHP — not needed |
| Caller detection | `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20)` | `runtime.CallersFrames()` + helper map | Equivalent approaches |
| Color decision | `Probe::colorProfile()->allowsColor()` | `colorprofile.Profile` | Equivalent (PHP uses same library) |

## Why External Approach Is Better

1. **sync.Pool for string builders**: Reduces allocations in high-volume logging. PHP doesn't need this — string concatenation via `implode()` is efficient enough in PHP's memory model.

2. **Atomic level checking**: Go's concurrent logging benefits from atomic operations. PHP's single-threaded nature makes this unnecessary.

3. **Helper function tracking**: Go's `sync.Map` tracks helper functions for accurate caller skip. PHP's `CallerFormatter::find()` walks the stack on every call but no equivalent helper marking exists.

## Tradeoffs

- **Thread-safety**: Not an issue in PHP (single-threaded request lifecycle)
- **Memory allocation**: PHP's request-based lifecycle means allocations are freed after each request
- **sync.Pool equivalent**: Would add complexity without measurable benefit in PHP

## Applicability

Most Go optimizations don't translate to PHP due to architectural differences. Focus on:
1. Algorithmic improvements within PHP's constraints
2. Caching expensive operations (caller detection could be cached per call site)
3. Reducing allocations in hot paths (formatters called on every log)

---

# Architecture Improvements

## 1. Logger Composition Over Inheritance

Currently Logger has multiple responsibilities: logging, formatting, styling, output. Consider extracting:

- **Handler Interface**: For output destinations (stream, rotating file, syslog)
- **Processor Interface**: For pre/post processing (filtering, enrichment)
- **Renderer Interface**: Already exists as Formatter

```php
// Proposed Handler pattern
interface Handler {
    public function handle(Level $level, string $message, array $context, \DateTimeImmutable $time, ?string $caller): void;
}

// Built-in handlers
final class StreamHandler implements Handler { ... }
final class RotatingFileHandler implements Handler { ... }
final class SyslogHandler implements Handler { ... }
```

## 2. Formatter Pipeline

Allow multiple formatters in a chain:

```php
$logger->addFormatter($formatter);  // chain formatters
```

Useful for: JSON to stdout + sensitive field redaction to file

## 3. Contextual Logger Factory

Create logger instances with common context:

```php
final class LoggerFactory {
    public function createForRequest(string $requestId, string $userId): Logger { ... }
    public function createForCli(string $command): Logger { ... }
}
```

---

# API / Developer Experience Improvements

## 1. Named Constructor Methods

```php
// Current
$log = Logger::new(formatter: new JsonFormatter(), level: Level::Debug);

// Improved - more semantic
$log = Logger::json()->withMinLevel(Level::Debug);
$log = Logger::syslog();  // PartsOrder::syslog() pre-configured
$log = Logger::production(); // JSON, timestamps, no colors, file output
```

## 2. Fluent Setters Should Return New Instance

Currently `setStyles(Styles $s)` mutates in place. For true immutability:

```php
// Current (mutating)
$log->setStyles($styles);

// Improved (immutable)
$log = $log->withStyles($styles);
```

## 3. Log Filtering by Pattern

```php
// Filter messages containing sensitive patterns
$log->filterPatterns(['password', 'token', 'secret']);

// Filter by regex
$log->filterRegex('/\b\d{4}\d{4}\d{4}\d{4}\b/');  // Credit card pattern
```

## 4. Structured Field Type Hints

Currently context is `array<string, mixed>`. Could use ObjectStorage:

```php
final class FieldBag {
    public function string(string $key, string $value): self { ... }
    public function int(string $key, int $value): self { ... }
    public function float(string $key, float $value): self { ... }
    public function bool(string $key, bool $value): self { ... }
    public function object(string $key, object $value): self { ... }
}

$log->info('User logged in', (new FieldBag())->string('user', $user)->int('attempt', 1));
```

---

# Documentation / Cookbook Opportunities

## 1. Tutorial: Logging in CLI Apps

- Basic setup with global logger
- Configuring formatters for development vs production
- Using sub-loggers for component tracking
- Panic handler integration for debugging

## 2. Tutorial: Logging in TUI Apps

- Integrating with Bubble Tea (candy-core)
- Capturing logs to file with tea.LogToFile pattern
- Using hook system for progress indicators
- Coloring strategies for different log levels

## 3. Cookbook: Common Patterns

```php
// Pattern 1: Request-scoped logging
$requestLog = $log->with(['request_id' => $requestId, 'user_id' => $userId]);

// Pattern 2: Component logging  
$dbLog = $log->withPrefix('db')->with(['component' => 'database']);
$dbLog->info('Query executed', ['query' => $sql, 'duration_ms' => $ms]);

// Pattern 3: Sensitive data redaction via hook
$hooks->onLevel(Level::Info, function($level, $psrLevel, $message, $context) {
    $context = array_map(fn($v) => is_string($v) ? preg_replace('/secret=\w+/', 'secret=***', $v) : $v, $context);
    // dispatch to external service
});

// Pattern 4: Performance logging
$hooks->onLevel(Level::Warn, function($level, $psrLevel, $message, $context) {
    if (($context['duration_ms'] ?? 0) > 1000) {
        // alert
    }
});
```

## 4. API Reference Improvements

- Add parameter types and return types for all methods
- Include PHPStan/PHPStorm stubs
- Generate HTML docs from PHPDoc

---

# UX / TUI Improvements

## 1. Terminal-Aware Formatting

When running in a known TTY width, respect terminal width:

```php
// Auto-detect and wrap at terminal width
$log = Logger::new(maxWidth: 80);  // or auto-detect

// Alternative: syslog-friendly output (no colors, compact)
$log = Logger::new(formatter: new TextFormatter(
    reportTimestamp: true,
    reportCaller: false,
    maxWidth: 0,  // no wrapping
));
```

## 2. Interactive Log Viewer Hook

A hook that renders progress/info in the current TTY line:

```php
final class TtyProgressHook implements Hook {
    public function onLevel(Level $level, string $psrLevel, string $message, array $context): void {
        if ($context['progress'] ?? false) {
            // Overwrite current line with progress bar
            \fwrite(\STDOUT, "\r\x1b[K" . $this->renderProgress($context));
        }
    }
}
```

## 3. Better Panic Output

- Add `--json` flag support for machine-parseable panic output
- Show memory usage at panic time
- Show goroutine-equivalent (current include files)

---

# Testing / Reliability Improvements

## 1. Snapshot Testing for Formatters

```php
public function testTextFormatterOutput(): void
{
    $formatter = new TextFormatter();
    $output = $formatter->format(
        Level::Info,
        'User logged in',
        ['user_id' => 123],
        new \DateTimeImmutable('2024-01-01 12:00:00'),
        null,
        null
    );
    
    $this->assertSame(
        "2024/01/01 12:00:00 INFO  User logged in user_id=123\n",
        $output
    );
}
```

## 2. Property-Based Testing

Use PHPPropertyTest or similar for formatter edge cases:

- Empty strings, Unicode strings, ANSI sequences
- Very long strings, strings with newlines
- Special characters in keys and values
- Nested arrays, objects with __toString

## 3. Integration Testing

```php
public function testLogToFileAndBack(): void
{
    $tmpFile = tempnam(sys_get_temp_dir(), 'candy-log-test-');
    $log = Logger::new(stream: fopen($tmpFile, 'w'));
    
    $log->info('Test message', ['key' => 'value']);
    
    $content = file_get_contents($tmpFile);
    $this->assertStringContainsString('Test message', $content);
    $this->assertStringContainsString('key=value', $content);
    
    unlink($tmpFile);
}
```

## 4. Performance Benchmarking

Add benchmarks for:
- Empty log (level filtered)
- Simple message (no context)
- Message with context (10 fields)
- Formatter switching
- Sub-logger creation

---

# Ecosystem / Integration Opportunities

## 1. Sentry/Error Tracking Integration

```php
final class SentryHook implements Hook
{
    public function __construct(string $dsn) { ... }
    
    public function onLevel(Level $level, string $psrLevel, string $message, array $context): void
    {
        if ($level->value >= Level::Error->value) {
            $this->sentry->captureMessage($message, $this->mapLevel($level), $context);
        }
    }
}
```

## 2. Monolog Adapter

Bridge candy-log to Monolog ecosystem:

```php
final class MonologHandler extends \Monolog\Handler\AbstractProcessingHandler
{
    public function __construct(private Logger $logger) { ... }
    
    protected function write(\Monolog\LogRecord $record): void
    {
        $this->logger->log(
            $this->mapLevel($record->level),
            $record->message,
            $record->context
        );
    }
}
```

## 3. ReactPHP Integration

```php
final class AsyncLogWriter implements \React\Stream\WritableStreamInterface
{
    public function write($data): void { ... }
    public function end($data = null): void { ... }
    public function isWritable(): bool { ... }
}
```

## 4. Laravel Service Provider

Auto-configure candy-log as the Laravel logger:

```php
final class CandyLogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Logger::class, function ($app) {
            return Logger::production();  // JSON, timestamps, file output
        });
    }
}
```

---

# Notable PRs / Issues / Discussions

## 1. charmbracelet/log#70 — slog.Handler Discussion
**Summary:** Addition of slog.Handler interface for Go 1.21+ stdlib compatibility

**Relevance:** The PHP port cannot implement this interface due to PHP lacking an equivalent standard library interface. This is a fundamental platform difference.

**Lessons learned:** When porting Go ecosystem libraries to PHP, the standard library interfaces (context, slog, etc.) often have no PHP equivalent. Document these as "platform limitations" rather than missing features.

## 2. charmbracelet/log#45 — Helper Function Skip
**Summary:** Implementation of helper function marking for accurate caller reporting

**Relevance:** The Go version uses `sync.Map` to track functions marked as helpers, skipping them when finding the "real" caller for location reporting.

**Lessons learned:** This Go-specific pattern (similar to `testing.TB.Helper()`) has no direct PHP equivalent. The PHP port's `CallerFormatter::find()` uses `DEBUG_BACKTRACE_IGNORE_ARGS` to skip internal frames, which achieves similar results without the explicit helper marking.

**Adaptation:** Could add a static analysis pass or attribute-based system in PHP to mark helper functions, but complexity outweighs benefit.

## 3. pterm/pterm#150 — Max-Width Wrapping
**Summary:** Adding configurable max-width with word wrapping

**Relevance:** pterm's logger supports wrapping long messages at a configured max width.

**Lessons learned:** Terminal applications benefit from width-aware formatting. PHP's `ncurses` or `candy-shell` could provide terminal dimensions.

**Adaptation:** Add `maxWidth` option to Logger/TextFormatter that enables word-wrap at specified column.

---

# Recommended Roadmap

## Immediate Wins (0-2 sprints)

1. **Multiline value formatting** — Improve context value formatting for multiline content
2. **ANSI sequence escaping** — Add security-conscious escaping for user-controlled content
3. **Enhanced PSR-3 bridge** — Ensure complete PSR-3 compatibility
4. **Documentation cookbook** — Add common patterns and recipes

## Medium-Term Improvements (2-4 sprints)

5. **Log rotation handler** — Add RotatingFileHandler for production use
6. **Logger factory** — Create contextual logger factories for web/CLI
7. **Log level expansion** — Add Trace level (value -8)
8. **Max-width wrapping** — Terminal-width aware message formatting
9. **Fluent setters** — Make all setters return new instances for true immutability

## Major Architectural Upgrades (4+ sprints)

10. **Handler interface** — Extract output destination as pluggable Handler
11. **Formatter pipeline** — Chain multiple formatters
12. **Context propagation** — Request-scoped context holder (if PSR-18/20 emerges)
13. **Async log writer** — ReactPHP stream-compatible logging

## Experimental Ideas

14. **Structured field types** — Typed FieldBag for context
15. **Log filtering** — Pattern/regex-based message filtering
16. **Log grouping** — Named groups with independent verbosity
17. **Machine-readable panic output** — JSON flag for panic handler

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Recommended Priority |
|------------|--------|------------|------|---------------------|
| Multiline value formatting | High | Medium | Low | Immediate |
| ANSI sequence escaping | High | Medium | Low | Immediate |
| Log rotation handler | High | Medium | Medium | Medium |
| Log level expansion (Trace) | Medium | Low | Low | Medium |
| Max-width wrapping | Medium | Medium | Low | Medium |
| Logger factory | Medium | Medium | Low | Medium |
| Handler interface extraction | Medium | High | Medium | Medium |
| Fluent setters (immutability) | Medium | Low | Low | Medium |
| Formatter pipeline | Medium | High | Medium | Low |
| Context propagation | Medium | High | High | Low |
| Async log writer | Medium | High | Medium | Low |
| ANSI sequence escaping | Medium | Medium | Low | Immediate |
| Sentry integration hook | Medium | Low | Low | Low |
| Monolog adapter | Medium | Medium | Low | Low |
| Log filtering patterns | Low | Medium | Low | Low |
| Log groups | Low | Medium | Low | Low |
| Structured field types | Low | Medium | Low | Low |

---

# Final Strategic Assessment

candy-log is a mature, well-designed logging library that successfully ports the charmbracelet/log API to PHP with valuable additions (hook system, panic handler, parts order). The implementation follows PHP best practices and SugarCraft conventions.

**Key strengths:**
- Clean API with static facade + instance logger duality
- Immutable + fluent design via `with()` pattern
- Three formatters covering all major use cases
- Comprehensive PSR-3 bridge
- Hook system for extensibility
- Excellent test coverage

**Key limitations to address:**
- Multiline value handling is the most impactful gap vs upstream
- ANSI sequence escaping is a security/reliability gap
- No log rotation limits production readiness
- Missing max-width wrapping affects TUI integration

**Platform limitations (not fixable):**
- No slog.Handler equivalent (PHP has no standard structured logging interface)
- No context.Context propagation (PHP has no equivalent)

**Recommendations:**
1. Prioritize multiline handling and ANSI escaping as security/reliability fixes
2. Add log rotation handler for production deployment
3. Expand test coverage with property-based testing for formatters
4. Document all platform limitations clearly
5. Consider creating a "candy-log" integration library for common frameworks (Laravel, Symfony)

The library is production-ready for development and reasonable for production with the current feature set. The identified improvements would enhance production readiness and feature parity with the Go upstream.
