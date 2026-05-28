# charmbracelet/log

## Metadata
- URL: https://github.com/charmbracelet/log
- Language: Go
- Stars: ~700+ (estimate based on repo age and activity)
- License: MIT
- Description: A minimal and colorful Go logging library that provides leveled structured human readable logging with a small API. Uses Lip Gloss for styling/coloring output and implements slog.Handler for stdlib compatibility.

## Feature List
- **Leveled Logging**: Debug, Info, Warn, Error, Fatal + level-agnostic Print
- **Structured Key-Value Logging**: All log methods accept arbitrary key-value pairs
- **Three Output Formatters**:
  - `TextFormatter` (default) — colorful, human-readable with ANSI styling
  - `JSONFormatter` — JSON output for machine parsing
  - `LogfmtFormatter` — logfmt format for log aggregators
- **Customizable Styling**: Per-level colors, per-key/value styles via Lip Gloss
- **Configurable Options**: Time format, time function, prefix, caller reporting, caller offset, caller formatter
- **Sub-loggers**: `With()` for child loggers with pre-attached fields; `WithPrefix()` for prefixed children
- **Context Propagation**: Store logger in `context.Context` via `WithContext()`/`FromContext()`
- **slog.Handler Interface**: Direct integration with `log/slog` standard library
- **Standard `log.Logger` Adapter**: Wrap logger to satisfy libraries expecting `*log.Logger`
- **Helper Function Marking**: `Helper()` marks functions to skip for caller location reporting (like `testing.TB.Helper()`)
- **Automatic TTY Detection**: Detects color profile and disables styling for non-TTY output
- **Multiline Value Handling**: Text formatter preserves multiline values with proper indentation
- **Thread-Safe**: Uses `sync/atomic` for level checks and `sync.RWMutex` for state
- **String Escaping**: Proper escaping of non-printable characters and ANSI sequences in values

## Key Classes and Methods

### Logger (struct)
- `Log(level Level, msg any, keyvals ...any)` — core logging method
- `Logf(level Level, format string, args ...any)` — formatted logging
- `Debug/Info/Warn/Error/Fatal(msg any, keyvals ...any)` — level-specific logging
- `Debugf/Infof/Warnf/Errorf/Fatalf(format string, args ...any)` — formatted level-specific
- `Print/Printf(msg any, ...any)` — level-agnostic logging
- `Helper()` — mark calling function as helper for caller skip
- `With(keyvals ...any) *Logger` — create sub-logger with additional fields
- `WithPrefix(prefix string) *Logger` — create sub-logger with prefix
- `SetReportTimestamp/SetReportCaller/SetLevel/SetPrefix/SetFormatter/SetStyles()` — mutators
- `SetOutput(w io.Writer)` — change output destination
- `SetColorProfile(profile colorprofile.Profile)` — force color profile
- `SetTimeFormat(format string)` / `SetTimeFunction(f TimeFunction)` — time customization
- `StandardLog(opts ...StandardLogOptions) *log.Logger` — return stdlib adapter

### Package-Level Functions
- `New(w io.Writer) *Logger` — create new logger with defaults
- `NewWithOptions(w io.Writer, o Options) *Logger` — create with custom options
- `Default() *Logger` — get global default logger
- `SetDefault(logger *Logger)` — set global default
- `SetReportTimestamp/SetReportCaller/SetLevel/SetPrefix/SetFormatter/SetStyles()` — global setters
- `With/WithPrefix(keyvals ...any) *Logger` — global sub-loggers
- `Debug/Info/Warn/Error/Fatal/Print(msg any, keyvals ...any)` — global logging
- `Debugf/Infof/Warnf/Errorf/Fatalf/Printf(format string, args ...any)` — global formatted
- `Log(level Level, msg any, keyvals ...any)` — global leveled logging
- `Helper()` — mark calling function as helper globally
- `FromContext(ctx context.Context) *Logger` — retrieve from context
- `WithContext(ctx context.Context, logger *Logger) context.Context` — store in context
- `StandardLog(opts ...StandardLogOptions) *log.Logger` — global stdlib adapter

### Options (struct)
- `TimeFunction TimeFunction` — custom time func (default: `time.Now`)
- `TimeFormat string` — time format string (default: `"2006/01/02 15:04:05"`)
- `Level Level` — minimum log level (default: `InfoLevel`)
- `Prefix string` — log prefix
- `ReportTimestamp bool` — include timestamp (default: `false` for New, `true` for Default)
- `ReportCaller bool` — include caller location (default: `false`)
- `CallerFormatter CallerFormatter` — format caller string
- `CallerOffset int` — adjust caller frame skip
- `Fields []any` — default fields for logger
- `Formatter Formatter` — output format (Text/JSON/Logfmt)

### Level (type)
- Constants: `DebugLevel = -4`, `InfoLevel = 0`, `WarnLevel = 4`, `ErrorLevel = 8`, `FatalLevel = 12`
- `String() string` — string representation
- `ParseLevel(string) (Level, error)` — parse from string

### Styles (struct)
- `DefaultStyles() *Styles` — create default styled set
- Fields: `Timestamp`, `Caller`, `Prefix`, `Message`, `Key`, `Value`, `Separator`, `Levels`, `Keys`, `Values`

### CallerFormatter (type)
- `ShortCallerFormatter(file string, line int, fn string) string` — 2-level path + line
- `LongCallerFormatter(file string, line int, fn string) string` — full path + line

### TimeFunction (type)
- `NowUTC(t time.Time) time.Time` — convert to UTC

### StandardLogOptions (struct)
- `ForceLevel Level` — force all output to specific level

## Notable Algorithms / Named Patterns

### Caller Path Trimming (from zap)
```go
// trimCallerPath returns the last n segments of the path
// Uses forward slash even on Windows (runtime.Caller returns forward slashes)
func trimCallerPath(path string, n int) string
```

### String Builder Pool
```go
var bufPool = sync.Pool{
    New: func() any { return new(strings.Builder) },
}
```
Reused via `bufPool.Get()`/`bufPool.Put()` to reduce allocations during string escaping.

### Atomic Level Checking
```go
if atomic.LoadInt64(&l.level) > int64(level) {
    return  // level filtered, skip logging
}
```
Thread-safe level comparison without mutex lock.

### Helper Function Skip Map
```go
func (l *Logger) helper(skip int) {
    var pcs [1]uintptr
    n := runtime.Callers(skip+2, pcs[:])
    frames := runtime.CallersFrames(pcs[:n])
    frame, _ := frames.Next()
    l.helpers.LoadOrStore(frame.Function, struct{}{})
}
```
Uses `sync.Map` to track functions marked as helpers, skipping them when finding the "real" caller for location reporting.

### Slog Handler Implementation
Logger implements `slog.Handler` interface (Go 1.21+) with:
- `Enabled(ctx context.Context, level slog.Level) bool`
- `Handle(ctx context.Context, record slog.Record) error`
- `WithAttrs(attrs []slog.Attr) slog.Handler`
- `WithGroup(name string) slog.Handler`

### Text Formatter Value Escaping
Handles:
- Unicode printable characters
- ANSI escape sequences (detected and properly quoted)
- Control characters (escaped as `\x`, `\u`, `\U`)
- Multi-line values (indented with `│`)
- Quoting detection for values containing spaces/special chars

### StdLog Adapter Pattern
```go
func (l *stdLogWriter) Write(p []byte) (n int, err error) {
    // Parse standard log prefixes (DEBUG, INFO, WARN, ERROR, ERR)
    // Forward to appropriate log level
}
```
Uses prefix parsing or forced level to bridge `*log.Logger` to charm log levels.

## Strengths

1. **Excellent API Design**: Clean, fluent interface that is intuitive to use
2. **First-Class slog Support**: Implements `slog.Handler` directly for Go 1.21+ stdlib integration
3. **Beautiful Text Output**: Lip Gloss styling makes console logs visually appealing without external dependencies
4. **No External Logging Dependencies**: Only depends on `lipgloss` (styling), `colorprofile` (color detection), and `go-logfmt` (logfmt encoding)
5. **Automatic TTY Detection**: Automatically disables colors/styles when output is not a terminal
6. **Flexible Styling**: Users can customize per-level colors, per-key colors, per-value colors via Styles
7. **Sub-logger Pattern**: `With()` makes it easy to create child loggers with pre-attached context
8. **Thread-Safe**: Uses `sync/atomic` for hot paths (level checking) and `sync.RWMutex` for state
9. **Context Integration**: Proper propagation via standard `context.Context`
10. **Multiline Support**: Text formatter properly handles multiline values with visual indentation
11. **String Escaping**: Comprehensive handling of non-printable chars and ANSI sequences
12. **Standard Logger Adapter**: Bridges gap for libraries expecting `*log.Logger`
13. **Minimal Boilerplate**: `log.Debug("message")` works out of the box with global logger

## Weaknesses

1. **Frame Skipping Performance**: Calling `runtime.CallersFrames()` on every log call when `ReportCaller` is enabled
2. **Helper Map Growth**: `sync.Map` for helpers never prunes entries, potential memory leak over long-running processes
3. **String Pool Only for Escaping**: Other allocations (buffer creation, etc.) not pooled
4. **No Log Rotation**: Built-in file rotation not supported; must wrap with `io.MultiWriter` or external solution
5. **No Structured Field Types**: Keys/values are `any` interface; no special handling for custom types beyond `Stringer`/`error`
6. **Lipgloss Dependency**: Heavy dependency for a logging library; adds visual styling coupling
7. **Deprecated Module Path**: Uses `charm.land/log/v2` module path which may cause import confusion
8. **Go 1.21+ Only for Full slog**: Uses `golang.org/x/exp/slog` for older Go versions (compatibility shim)

## SugarCraft Mapping

The charmbracelet/log library maps to several SugarCraft libraries for TUI/logging output:

| charmbracelet/log Feature | SugarCraft Library | Notes |
|--------------------------|---------------------|-------|
| Structured logging with levels | `sugar-bits` | Output/rendering infrastructure |
| Text formatting with ANSI colors | `candy-shine` | ANSI/SGR rendering via Lip Gloss |
| JSON/Logfmt formatters | `sugar-bits` | Structured output formatting |
| Color/profile detection | `candy-shine` | TTY color profile detection |
| Context propagation | `candy-core` | Context utilities |
| slog.Handler interface | `sugar-bits` | Handler pattern implementation |
| Sub-loggers with fields | `sugar-bits` | Immutable builder pattern |
| Standard log adapter | `candy-core` | Adapter/wrapper patterns |

### Direct Port Candidates
- **`sugar-log`** (new lib): A direct PHP port of `charmbracelet/log` would provide leveled structured logging with Text/JSON/Logfmt formatters
- **`sugar-log-styles`** (extension): Lip Gloss-style styling system for log levels/keys/values
- **`candy-logger`** (new lib): Could wrap PSR-3 or provide native leveled logging with charm's API

### SugarCraft lib Relevance
- **sugar-bits**: Core output/rendering — shares concepts of buffered output, formatting
- **candy-shine**: ANSI styling — directly ports Lip Gloss styling concepts
- **candy-core**: Utilities and patterns — context, adapter patterns

## Analysis

charmbracelet/log is a well-crafted logging library that succeeds in its goal of providing "minimal and colorful" logging. Its most impressive aspect is the seamless integration with Go's standard library `log/slog` (introduced in Go 1.21), making it a first-class logging handler that can replace the default slog handler. The library uses Lip Gloss for styling, which is part of the charmbracelet ecosystem, resulting in beautifully colored console output that automatically degrades when output is not a TTY.

The architecture is clean with clear separation: `Logger` handles the core logic, `Options` configures creation, `Formatter` constants select formatters (text/json/logfmt), `Styles` controls visual appearance, and platform-specific files (`logger_121.go`/`logger_no121.go`) handle slog compatibility. The use of `sync/atomic` for level checking in the hot path shows performance consideration, and the string builder pool in the text formatter reduces allocations.

The main weaknesses are the runtime frame walking when caller reporting is enabled (necessary for accurate source locations but costly at high log volumes) and the `sync.Map` for helper functions that grows unbounded. However, these are reasonable trade-offs for the functionality provided. The Lip Gloss dependency, while heavy, is justified by the visual quality it produces.

For SugarCraft, this library would primarily inform the design of `sugar-bits` (output infrastructure) and potential new logging libraries. The structured logging with key-value pairs, multiple formatters, and the slog handler pattern are all portable concepts. The text formatter's multiline handling and string escaping algorithms would be particularly useful reference implementations for PHP ports.
