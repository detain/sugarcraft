# candy-log Library Research

**Date:** 2026-05-13
**Upstream:** [charmbracelet/log](https://github.com/charmbracelet/log) (v2.0.0)
**Context:** PHP 8.3+ TUI logging library, part of SugarCraft monorepo

---

## Executive Summary

This research compares terminal logging libraries across Go, Rust, and Python to identify patterns and features that could improve `candy-log`. The upstream `charmbracelet/log` v2 introduces modern color profile handling and cleaner APIs. Several libraries offer features worth adopting.

---

## 1. Current candy-log Implementation

**Source:** `/home/sites/sugarcraft/candy-log/src/`

### Architecture
- `Logger` — Core logger with formatters, levels, styles
- `Log` — Static facade for process-wide default logger
- `Level` enum — Debug(0), Info(1), Warn(2), Error(3), Fatal(4)
- `Styles` — Hard-coded ANSI color values for level styling
- `Formatter/TextFormatter` — Human-readable output with TTY detection
- `Formatter/JsonFormatter` — JSON structured output
- `Formatter/LogfmtFormatter` — logfmt key=value output
- `PanicFormatter` — Styled exception/backtrace rendering

### Current Limitations
1. **Color handling**: Hard-coded ANSI color numbers (0-8) without terminal capability detection
2. **No color profile**: No concept of TrueColor vs 256-color vs 16-color terminals
3. **Static styles**: `Styles` class uses numeric ANSI codes, not semantic colors
4. **Level values**: Current enum values 0-4 don't match upstream v2 (which uses -4, 0, 4, 8, 12)
5. **No Slog handler**: Missing `log/slog` integration that Go version provides
6. **Limited formatting**: No per-field styling (only level + key/value pairs)

---

## 2. Library Comparison

### 2.1 Go Libraries

#### charmbracelet/log (Upstream) v2.0.0
**Source:** [GitHub](https://github.com/charmbracelet/log)

| Feature | Implementation |
|---------|---------------|
| Log Levels | Debug(-4), Info(0), Warn(4), Error(8), Fatal(12) |
| Color Profile | Uses `colorprofile` library for auto-detection + downsampling |
| Styling | `lipgloss/v2` for pure styling, no I/O conflicts |
| Text Format | `{time} {prefix} {level} {caller} {message} {key=value...}` |
| Formatters | TextFormatter, JSONFormatter, LogfmtFormatter |
| Sub-loggers | `With()` method for contextual fields |
| Slog Handler | Built-in `log/slog` handler support |
| Caller | Configurable `CallerFormatter` (ShortCallerFormatter, LongCallerFormatter) |

**Key Code Pattern (styles.go):**
```go
// DefaultStyles returns the default styles.
func DefaultStyles() *Styles {
    return &Styles{
        Timestamp: lipgloss.NewStyle(),
        Caller:    lipgloss.NewStyle().Faint(true),
        Prefix:    lipgloss.NewStyle().Bold(true).Faint(true),
        Message:   lipgloss.NewStyle(),
        Key:       lipgloss.NewStyle().Faint(true),
        Value:     lipgloss.NewStyle(),
        Separator: lipgloss.NewStyle().Faint(true),
        Levels: map[Level]lipgloss.Style{
            DebugLevel: lipgloss.NewStyle().
                SetString(strings.ToUpper(DebugLevel.String())).
                Bold(true).
                MaxWidth(4).
                Foreground(lipgloss.Color("63")),     // Purple
            InfoLevel: lipgloss.NewStyle().
                SetString(strings.ToUpper(InfoLevel.String())).
                Bold(true).
                MaxWidth(4).
                Foreground(lipgloss.Color("86")),     // Green
            WarnLevel: lipgloss.NewStyle().
                SetString(strings.ToUpper(WarnLevel.String())).
                Bold(true).
                MaxWidth(4).
                Foreground(lipgloss.Color("192")),    // Yellow
            ErrorLevel: lipgloss.NewStyle().
                SetString(strings.ToUpper(ErrorLevel.String())).
                Bold(true).
                MaxWidth(4).
                Foreground(lipgloss.Color("204")),    // Red
            FatalLevel: lipgloss.NewStyle().
                SetString(strings.ToUpper(FatalLevel.String())).
                Bold(true).
                MaxWidth(4).
                Foreground(lipgloss.Color("134")),    // Magenta
        },
        Keys:   map[string]lipgloss.Style{},
        Values: map[string]lipgloss.Style{},
    }
}
```

**Key Features to Port:**
1. ✅ Color profile auto-detection and downsampling
2. ✅ `SetColorProfile(colorprofile.TrueColor)` API
3. ✅ Per-level `MaxWidth(4)` for aligned level labels
4. ✅ `Keys` and `Values` maps for per-field styling
5. ✅ `CallerFormatter` type for customizable caller display

#### zerolog
**Source:** [GitHub](https://github.com/rs/zerolog)

| Feature | Implementation |
|---------|---------------|
| Philosophy | Zero-allocation, JSON-only core, ConsoleWriter for pretty output |
| ConsoleWriter | Highly customizable `PartsOrder`, `FieldsOrder`, `FieldsExclude` |
| Formatting | `Formatter` functions per component (FormatLevel, FormatMessage, etc.) |
| Color | Per-level via `LevelColors` map + `FormattedLevels` |

**Key Code Pattern:**
```go
output := zerolog.ConsoleWriter{Out: os.Stdout, TimeFormat: time.RFC3339}
output.FormatLevel = func(i interface{}) string {
    return strings.ToUpper(fmt.Sprintf("| %-6s|", i))
}
output.FormatMessage = func(i interface{}) string {
    return fmt.Sprintf("***%s****", i)
}
output.FormatFieldName = func(i interface{}) string {
    return fmt.Sprintf("%s:", i)
}
output.FormatFieldValue = func(i interface{}) string {
    return strings.ToUpper(fmt.Sprintf("%s", i))
}
```

**Key Patterns to Consider:**
1. Per-component formatter functions (more flexible than Styles object)
2. `PartsOrder` for customizing log line structure
3. `FormatPartValueByName` for name-based part value formatting

#### logrus
**Source:** [GitHub](https://github.com/sirupsen/logrus)

| Feature | Implementation |
|---------|---------------|
| TTY Detection | `ForceColors`, `DisableColors`, `EnvironmentOverrideColors` (CLICOLOR) |
| Level Truncation | `DisableLevelTruncation`, `PadLevelText` for alignment |
| Hooks | `LevelHooks` for external integrations (error tracking, StatsD) |

**Key Code Pattern:**
```go
logrus.SetFormatter(&logrus.TextFormatter{
    ForceColors:       false,
    DisableColors:     false,
    EnvironmentOverrideColors: true,  // CLICOLOR/CLICOLOR_FORCE
    DisableTimestamp:  false,
    FullTimestamp:     true,
    TimestampFormat:   "2006-01-02T15:04:05",
    DisableLevelTruncation: false,
    PadLevelText:      true,  // All levels same width
    QuoteEmptyFields:  true,
})
```

**Key Patterns to Consider:**
1. Environment variable awareness (CLICOLOR, CLICOLOR_FORCE, NO_COLOR, FORCE_COLOR)
2. `PadLevelText` for visual alignment
3. Hook system for extensibility

---

### 2.2 Rust Libraries

#### tracing + tracing-subscriber
**Source:** [tracing.rs](https://www.tracing.rs/)

| Feature | Implementation |
|---------|---------------|
| Spans | Structured spans with begin/end instead of flat log messages |
| Layers | Composable `Layer` trait for different outputs |
| Formatting | `tracing-human-layer` for colorful terminal, `tracing-print` for simple |

**Key Code Pattern:**
```rust
use tracing_subscriber::layer::SubscriberExt;
use tracing_subscriber::util::SubscriberInitExt;
use tracing_human_layer::HumanLayer;

tracing_subscriber::registry()
    .with(HumanLayer::new())
    .init();
```

**Key Insight:** Span-based logging is more powerful than flat messages for async/t并发 code. PHP port not feasible but worth noting for architecture.

#### fern
**Source:** [docs.rs/fern](https://docs.rs/fern/latest/fern/)

| Feature | Implementation |
|---------|---------------|
| Dispatch | Recursive branching configuration (like fern plant) |
| Colors | `ColoredLevelConfig` with per-level `Color` enum |
| Format | Closure-based formatter receiving `out.finish(format_args!(...))` |

**Key Code Pattern:**
```rust
use fern::colors::{Color, ColoredLevelConfig};

let colors = ColoredLevelConfig::new()
    .info(Color::Green)
    .debug(Color::Magenta);

fern::Dispatch::new()
    .format(move |out, message, record| {
        out.finish(format_args!(
            "[{}] {}",
            colors.color(record.level()),
            message
        ))
    })
    .chain(std::io::stdout())
    .apply()?;
```

**Key Patterns to Consider:**
1. Builder pattern for complex configuration
2. Per-level `Color` enum instead of numeric ANSI codes

---

### 2.3 Python Libraries

#### loguru
**Source:** [loguru.readthedocs.io](https://loguru.readthedocs.io/)

| Feature | Implementation |
|---------|---------------|
| Philosophy | No Handler/Formatter/Filter — one function to rule them all |
| Sinks | `add()` method with sink, format, colorize, serialize, rotation |
| Colors | Markup tags in format string: `<red>`, `<yellow>`, etc. |
| Levels | Runtime-configurable with `logger.level()` |

**Key Code Pattern:**
```python
fmt = ("<red>{time}</red> - "
       "<yellow>{name}</yellow> - "
       "{level} - {message}")

logger.add(sys.stdout, format=fmt, level="DEBUG", colorize=True)

# Custom levels with colors
logger.level("INFO", color="<green>")
logger.level("WARNING", color="<yellow><bold>")
```

**Key Patterns to Consider:**
1. Environment variable support: `NO_COLOR`, `FORCE_COLOR`
2. Per-level color tags in format string
3. `opt()` method for per-call customization (colors, depth, lazy evaluation)

#### structlog
**Source:** [structlog.org](https://www.structlog.org/)

| Feature | Implementation |
|---------|---------------|
| Processor Chain | Configurable processors that transform event dict |
| ConsoleRenderer | Column-based output with `Column` + `ColumnFormatter` |
| Rich Integration | Uses `rich` for pretty exception rendering |

**Key Code Pattern:**
```python
cr = structlog.dev.ConsoleRenderer(
    columns=[
        structlog.dev.Column(
            structlog.dev.KeyValueColumnFormatter(
                key_style=None,
                value_style=colorama.Fore.YELLOW,
                reset_style=colorama.Style.RESET_ALL,
                value_repr=str,
            )
        ),
    ],
    level_styles={
        "error": "red",
        "warn": "yellow",
        "info": "green",
    }
)
```

**Key Patterns to Consider:**
1. Column-based formatter with per-column styling
2. `level_styles` dict for level-based colors
3. Environment variable awareness (FORCE_COLOR, NO_COLOR)

---

## 3. Comparative Analysis

### 3.1 Log Levels

| Library | Debug | Info | Warn | Error | Fatal |
|---------|-------|------|------|-------|-------|
| candy-log | 0 | 1 | 2 | 3 | 4 |
| charmbracelet/log v2 | -4 | 0 | 4 | 8 | 12 |
| zerolog | debug | info | warn | error | fatal |
| logrus | debug | info | warn | error | fatal |
| tracing | TRACE | DEBUG | INFO | WARN | ERROR |
| structlog | trace | debug | info | warn | error |

**Recommendation:** Align level values with upstream (-4, 0, 4, 8, 12) for compatibility with Go ecosystem tooling.

### 3.2 Color Handling

| Library | Detection | Downsampling | Per-Level Styles |
|---------|-----------|--------------|------------------|
| candy-log | TTY check | None | Yes (hard-coded) |
| charmbracelet/log v2 | colorprofile | Auto | Yes (semantic) |
| zerolog | NoColor flag | None | Via ConsoleWriter |
| logrus | CLICOLOR env | None | ForceColors flag |
| loguru | TTY + env vars | Strip on non-TTY | Per-level tags |
| structlog | TTY + FORCE_COLOR | Auto via Rich | level_styles dict |

**Recommendation:** Implement color profile detection similar to `colorprofile` library:
1. Detect TrueColor (24-bit), 256-color, 16-color, or no color
2. Auto-downsample colors based on terminal capability
3. Honor NO_COLOR and FORCE_COLOR environment variables

### 3.3 Structured Logging

| Library | Context Method | Per-Field Styling | Sub-loggers |
|---------|---------------|-------------------|-------------|
| candy-log | `$log->with(['key' => 'val'])` | No (all keys share style) | Yes (`with()`) |
| charmbracelet/log | `With()` variadic | Yes (`Styles.Keys[key]`) | Yes |
| zerolog | `.Str().Int().Msg()` chain | Via ConsoleWriter | Yes (`.With()`) |
| logrus | `WithField()`, `WithFields()` | No | Yes |
| loguru | `bind()` + format interpolation | Via format tags | Yes (`bind()`) |
| structlog | Processor chain | Column-based | Via context |

**Recommendation:** Add per-field styling via `Styles::Keys[key]` map (matching upstream).

### 3.4 Output Formatting

| Library | PartsOrder | Custom Formatters | Alignment |
|---------|------------|-------------------|-----------|
| candy-log | Fixed | No | No |
| charmbracelet/log | Fixed | No | MaxWidth per level |
| zerolog | Yes | Yes (FormatLevel, etc.) | Via padding |
| logrus | Fixed | No | PadLevelText |
| loguru | Via format string | Yes (function) | Via format |
| structlog | Via columns | Via ColumnFormatter | Yes (pad_event) |

**Recommendation:** Consider adding:
1. `PadLevelText` equivalent (all level labels same width)
2. Alignment options for context fields

---

## 4. Prioritized Recommendations

### 4.1 High Priority (Low Effort, High Impact)

| # | Improvement | Description | Effort |
|---|-------------|-------------|--------|
| 1 | **Environment Variable Support** | Honor `NO_COLOR` and `FORCE_COLOR` env vars | 1-2 hours |
| 2 | **PadLevelText** | Align level labels to same width (e.g., "INFO " vs "WARN") | 1 hour |
| 3 | **Level Value Alignment** | Change to -4, 0, 4, 8, 12 to match upstream | 30 min |

### 4.2 Medium Priority (Moderate Effort, High Impact)

| # | Improvement | Description | Effort |
|---|-------------|-------------|--------|
| 4 | **Per-Field Key Styles** | Add `Styles::Keys[key]` map for custom key styling | 2-3 hours |
| 5 | **Color Profile Detection** | Implement terminal color capability detection | 3-4 hours |
| 6 | **Color Downsampling** | Downsample colors for limited terminals (16-color) | 2-3 hours |

### 4.3 Lower Priority (Higher Effort, Nice to Have)

| # | Improvement | Description | Effort |
|---|-------------|-------------|--------|
| 7 | **CallerFormatter** | Configurable caller display (short vs long path) | 2 hours |
| 8 | **Slog Handler** | PSR-3 compatible handler for interoperability | 4-6 hours |
| 9 | **Hook System** | Extensibility for external integrations | 4-6 hours |
| 10 | **PartsOrder** | Reconfigurable log line structure | 3-4 hours |

---

## 5. Specific Implementation Suggestions

### 5.1 Color Profile Enum

```php
// New file: ColorProfile.php
enum ColorProfile: int
{
    case None = 0;      // No colors
    case Basic = 1;     // 16 colors (ANSI)
    case Extended = 2;  // 256 colors
    case TrueColor = 3; // 24-bit true color

    public function downsample(Color $color): Color
    {
        return match ($this) {
            self::None => $color->withoutColor(),
            self::Basic => $color->toBasic(),
            self::Extended => $color->to256(),
            self::TrueColor => $color,
        };
    }
}
```

### 5.2 Environment Variable Detection

```php
// In Logger constructor or Styles
public static function detectColorProfile(): ColorProfile
{
    // NO_COLOR takes precedence
    if (getenv('NO_COLOR') !== false) {
        return ColorProfile::None;
    }

    // FORCE_COLOR forces color output
    if (getenv('FORCE_COLOR') !== false) {
        $depth = getenv('FORCE_COLOR');
        return match (true) {
            $depth === 'true' || $depth === '1' => ColorProfile::TrueColor,
            $depth === '256' => ColorProfile::Extended,
            default => ColorProfile::Basic,
        };
    }

    // Otherwise, detect from terminal
    // ... implementation using posix_isatty(), etc.
}
```

### 5.3 Per-Field Key Styles

```php
// In Styles.php
final class Styles
{
    /** @var array<int, Style> keyed by Level->value */
    public array $levels = [];

    /** @var array<string, Style> per-key styles (e.g., ["err" => red bold]) */
    public array $keys = [];

    /** @var array<string, Style> fallback styles */
    public array $values = [];

    // ... existing code
}
```

### 5.4 Updated Level Enum

```php
// Updated Level.php to match upstream v2
enum Level: int
{
    case Debug = -4;
    case Info  = 0;
    case Warn  = 4;
    case Error = 8;
    case Fatal = 12;

    // ... existing methods
}
```

---

## 6. References

| Library | URL |
|---------|-----|
| charmbracelet/log (upstream) | https://github.com/charmbracelet/log |
| charmbracelet/log v2 blog | https://charm.sh/blog/the-charm-logger/ |
| zerolog | https://github.com/rs/zerolog |
| logrus | https://github.com/sirupsen/logrus |
| tracing | https://www.tracing.rs/ |
| fern | https://docs.rs/fern/latest/fern/ |
| loguru | https://loguru.readthedocs.io/ |
| structlog | https://www.structlog.org/ |
| Color profile (Go) | https://github.com/charmbracelet/colorprofile |

---

## 7. File Changes Required

```
candy-log/src/
├── Level.php                      # Update values to -4, 0, 4, 8, 12
├── Styles.php                     # Add Keys/Values maps, ColorProfile support
├── Logger.php                     # Add PadLevelText, detect color profile
├── ColorProfile.php               # New enum for color capability detection
├── Formatter/TextFormatter.php    # Honor NO_COLOR/FORCE_COLOR, alignment
└── Formatter/JsonFormatter.php    # No changes needed
└── Formatter/LogfmtFormatter.php  # No changes needed
```

---

*Research completed by researcher agent — 2026-05-13*
