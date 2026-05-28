# Overview

candy-palette is a well-engineered PHP port of charmbracelet/colorprofile (~111 stars Go library) that provides terminal color profile detection and automatic ANSI color degradation for PHP CLI applications. It is the foundational terminal color infrastructure within SugarCraft, consumed directly by candy-log, candy-mosaic, candy-freeze, and candy-vt.

**Biggest opportunity areas:**
1. **Color conversion caching** — Every conversion recomputes from scratch; Go upstream has thread-safe RWMutex cache
2. **Perceptual color science** — Naive RGB cube quantization vs Go's go-colorful (CIELAB-based)
3. **Enhanced tmux detection** — Go upstream parses `tmux info` for actual Tc/RGB capability; PHP port only checks environment variables
4. **ProfileWriter efficiency** — Creates new Palette instance per `write()` call, causing redundant detection
5. **Color manipulation APIs** — No HSL/HSV, no darken/lighten/blend, no color harmony utilities

**Biggest missing capabilities:**
1. No SGR sequence parsing for indexed colors (only handles 24-bit SGR 38;2;R;G;B)
2. No gradient/fade utilities for multi-stop color interpolation
3. No color harmony operations (complementary, triadic, analogous)
4. No automatic re-detection when terminal capabilities change mid-stream
5. No CIELAB/perceptual color space conversion

---

# Internal Capability Summary

## Current Architecture

### Class Inventory (8 source files)

| Class | Purpose | Key Methods |
|-------|---------|--------------|
| `Color` | RGBA value object | `fromHex()`, `parse()`, `convert()`, `toAnsi256Index()`, `toAnsi16Index()`, `toAnsiForeground()`, `toHex()` |
| `ColorProfile` | SSOT detection enum | 5 cases: NoTTY/Ascii/Ansi/Ansi256/TrueColor |
| `Profile` | Legacy detection enum | Same 5 cases, different ordering |
| `Probe` | Static env-probe layer | `colorProfile()`, `isNoColor()`, `isForceColor()`, `reducedMotion()`, `infocmpUpgrade()` |
| `Palette` | Instance detection + degradation | `detect()`, `convert()`, `degrade()`, `stripAnsi()`, `comment()`, `describe()` |
| `ProfileWriter` | Transparent stream wrapper | `wrap()`, `write()`, `printf()` |
| `StandardColors` | 16-color ANSI palette | 16 static Color properties, `all()`, `fromIndex()`, `catalog()` |
| `Lang` | i18n facade | Extends `SugarCraft\Core\I18n\Lang` |

### Detection Hierarchy (12 steps)

```
1.  CLICOLOR_FORCE=1  → TrueColor  (overrides everything)
2.  NO_COLOR (any)    → NoTTY     (per no-color.org standard)
3.  CLICOLOR=0        → NoTTY
4.  TERM=dumb         → NoTTY
5.  COLORTERM=24bit|truecolor|yes → TrueColor
6.  WT_SESSION (set)  → TrueColor  (Windows Terminal)
7.  GOOGLE_CLOUD_SHELL=true → TrueColor
8.  TMUX || STY + screen*/tmux* → Ansi256
9.  TERM=xterm-kitty|xterm-ghostty|*-256color → Ansi256
10. TERM=xterm*|screen*|tmux* → Ansi
11. Default → Ansi, then Phase 2 infocmp upgrade
12. infocmp Tc/RGB capability → upgrade Ansi → TrueColor
```

### Color Conversion Approach

**RGB → ANSI256**: Naive 6×6×6 color cube quantization + 24-step greyscale ramp
**RGB → ANSI16**: Euclidean distance in RGB space to 8 basic ANSI colors + perceived brightness threshold
**RGB → Ascii**: Perceived brightness threshold (128)

### Strengths

1. **Standards-compliant** — Fully implements NO_COLOR, CLICOLOR, CLICOLOR_FORCE, COLORTERM, terminfo
2. **Pure static Probe** — No mutable state, trivially testable
3. **ProfileWriter pattern** — Transparent stream wrapping for automatic degradation
4. **Comprehensive test coverage** — 6 test files, data providers, env preservation
5. **i18n infrastructure** — 16 locales supported, extensible via Lang facade
6. **Standards-compliant infocmp Phase 2** — Silently upgrades Ansi → TrueColor when Tc/RGB capability found

### Weaknesses

1. **No conversion caching** — Every `Color::convert()` call recomputes from scratch
2. **Naive color quantization** — Uses RGB cube instead of perceptual color spaces
3. **ProfileWriter inefficiency** — Creates new Palette instance on every `write()` call
4. **Limited SGR handling** — Only handles `\x1b[38;2;R;G;Bm` / `\x1b[48;2;R;G;Bm`
5. **No tmux info probing** — Only checks environment variables, not actual tmux capabilities
6. **Two-enum confusion** — Both `Profile` and `ColorProfile` exist with slightly different orderings
7. **No automatic re-detection** — Profile set once at construction
8. **No color manipulation** — No HSL, no darken/lighten/blend operations

---

# Relevant External Repositories

| Repo | Relevance | Major Applicable Concepts | Priority |
|------|-----------|---------------------------|----------|
| `charmbracelet/colorprofile` (Go, ~111 stars) | **Direct upstream** | Perceptual color quantization (go-colorful), thread-safe caching, tmux info probing, ConEmuANSI detection | CRITICAL |
| `charmbracelet/lipgloss` (Go, ~11k stars) | **Primary consumer** | Color downsampling at output layer, v2 architecture (render always full ANSI, writer handles degradation) | CRITICAL |
| `textualize/textual` (Python, ~35k stars) | **Sister TUI framework** | Color HSL/HSV/Lab spaces, darken/lighten/blend operations, color parsing | HIGH |
| `ratatui/ratatui` (Rust, ~19.6k stars) | **Reference TUI arch** | Color enum (no alpha), RGB/Indexed/ANSI color types | MEDIUM |
| `charmbracelet/log` (Go, ~700 stars) | **Consumer lib** | Uses colorprofile.Writer for automatic TTY detection and color stripping | MEDIUM |
| `charmbracelet/bubbletea` (Go, ~41k stars) | **Reference TUI** | Synchronized output mode (ANSI 2026), color profile integration | MEDIUM |
| `pterm/pterm` (Go, ~6k stars) | **Color system ref** | Gradient/fade utilities, Style composition | MEDIUM |

---

# Feature Gap Analysis

## Critical Priority

### 1. Color Conversion Caching
- **Title**: Add static cache for color conversion results
- **Description**: Go upstream uses thread-safe `map[Profile]map[color.Color]color.Color` with `sync.RWMutex`. PHP port has zero caching — every conversion recomputes from scratch.
- **Why it matters**: Applications that repeatedly convert the same colors (progress bars, live status displays) waste CPU on redundant computation.
- **Source repo**: `charmbracelet/colorprofile` (PR #43, v0.3.2)
- **Source PR/issue**: `docs/repo_map/pr_charmbracelet_colorprofile.md` lines 235-248
- **Implementation ideas**: Add `private static array $conversionCache = []` in `Color::convert()`, keyed by "r,g,b,a|profile"
- **Estimated complexity**: LOW — 10-15 lines of code
- **Expected impact**: HIGH — Eliminates redundant computation for repeated colors

### 2. SGR Sequence Parsing for Indexed Colors
- **Title**: Extend `Palette::rewriteAnsi()` to handle 256-color and 16-color indexed SGR
- **Description**: Current `rewriteAnsi()` only handles `\x1b[38;2;R;G;Bm` / `\x1b[48;2;R;G;Bm`. Does not handle `\x1b[38;5;Nm` or `\x1b[38;Nm`.
- **Why it matters**: Applications that emit 256-color or 16-color indexed SGR sequences will not be degraded properly when running on lower-capability terminals.
- **Source repo**: `charmbracelet/colorprofile` handles all SGR forms via `charmbracelet/x/ansi` package
- **Implementation ideas**: Add regex patterns for `38;5;N` and `48;5;N` in `rewriteAnsi()`, pass through unchanged for TrueColor, convert for lower profiles
- **Estimated complexity**: MEDIUM — Need to handle multiple SGR pattern types
- **Expected impact**: HIGH — Proper degradation of all color sequences

### 3. ProfileWriter Instance Reuse
- **Title**: Reuse Palette instance across ProfileWriter::write() calls
- **Description**: Current implementation creates new `Palette` instance on every `write()` call when profile is ANSI256 or ANSI (line 487: `$palette = new Palette($this->stream, [])`).
- **Why it matters**: Wastes CPU on redundant detection; creates objects that are immediately discarded.
- **Source repo**: N/A — internal efficiency issue
- **Implementation ideas**: Store `$this->palette` as instance property, create lazily once
- **Estimated complexity**: LOW — 2-line fix
- **Expected impact**: MEDIUM — Reduced allocation and detection overhead

## High Value

### 4. Perceptual Color Conversion (CIELAB)
- **Title**: Add CIELAB color space conversion for better downsampling
- **Description**: Go's `go-colorful` uses perceptually uniform color spaces for color quantization. PHP port uses naive RGB cube quantization.
- **Why it matters**: TrueColor → ANSI256/ANSI conversion produces more accurate colors when using perceptual color distance (Delta-E).
- **Source repo**: `charmbracelet/colorprofile` uses `lucasb-eyer/go-colorful` for `ToXyz()` / `ToLab()` / `Clamp()`
- **Source PR/issue**: `docs/repo_map/pr_charmbracelet_colorprofile.md` lines 543-551
- **Implementation ideas**: Port go-colorful's CIELAB conversion algorithms, use Delta-E for nearest-color search
- **Estimated complexity**: HIGH — Requires mathematical color science implementation
- **Expected impact**: MEDIUM — Better color accuracy, but naive RGB is "good enough" for most use cases

### 5. tmux Info Probing
- **Title**: Probe actual tmux capabilities via `tmux info` command
- **Description**: Go upstream (v0.3.3+) parses `tmux info` output for actual Tc/RGB capability flags instead of assuming based on TERM prefix.
- **Why it matters**: Modern tmux (3.x) passes COLORTERM through from outer terminal; stale assumption that "tmux doesn't support COLORTERM" causes incorrect detection (Issue #76).
- **Source repo**: `charmbracelet/colorprofile` (PR #60, v0.3.3)
- **Source PR/issue**: `docs/repo_map/pr_charmbracelet_colorprofile.md` lines 224-227, 569-576
- **Implementation ideas**: Execute `tmux info` subprocess, parse for `Tc` or `RGB` flags in terminal-overrides
- **Estimated complexity**: MEDIUM — Subprocess execution + parsing
- **Expected impact**: HIGH — Correct TrueColor detection in tmux sessions

### 6. HSL/HSV Color Space Support
- **Title**: Add `Color::fromHsl()`, `Color::toHsl()`, `Color::lighten()`, `Color::darken()`
- **Description**: Add hue/saturation/lightness color space support to the Color class.
- **Why it matters**: HSL is more intuitive for humans; textual and other libs support it natively.
- **Source repo**: `textualize/textual` has `from_hsl()`, `darken()`, `lighten()`, `blend()`
- **Source PR/issue**: `docs/repo_map/textualize_textual.md` lines 61-62
- **Implementation ideas**: Add static factory `fromHsl(float $h, float $s, float $l, int $a = 255): self`, instance methods `toHsl(): array`, `lighten(float $amount): self`, `darken(float $amount): self`
- **Estimated complexity**: LOW — Standard color math formulas
- **Expected impact**: MEDIUM — More intuitive API, common expectation

### 7. Color Harmony Utilities
- **Title**: Add `Color::complementary()`, `Color::triadic()`, `Color::analogous()`
- **Description**: Add color theory operations for generating harmonious color combinations.
- **Why it matters**: Applications that generate UI themes or visualizations need color harmony calculations.
- **Source repo**: `textualize/textual` has `blend()` for color mixing
- **Implementation ideas**: Add static methods that work in HSL space for intuitive results
- **Estimated complexity**: LOW — Simple HSL math
- **Expected impact**: LOW — Niche use case

### 8. Gradient Generation
- **Title**: Add `Color::gradient()` for multi-stop color interpolation
- **Description**: Generate a series of intermediate colors between two or more colors.
- **Why it matters**: pterm has `Fade()` for gradient effects; users expect gradient support.
- **Source repo**: `pterm/pterm` has `Fade()` multi-stop gradients
- **Source PR/issue**: `docs/repo_map/sugarcraft_candy-palette.md` line 547
- **Implementation ideas**: `public static function gradient(Color $from, Color $to, int $steps): array`
- **Estimated complexity**: LOW — Linear interpolation in appropriate color space
- **Expected impact**: MEDIUM — Common expectation for color libraries

### 9. Automatic Re-detection Capability
- **Title**: Add `Palette::refresh()` or `ProfileWriter::refresh()`
- **Description**: Allow re-probing terminal capabilities after initial detection.
- **Why it matters**: Long-running applications may need to adapt if terminal capabilities change (e.g., user switches to a different terminal mid-session).
- **Source repo**: `charmbracelet/colorprofile` Issue #76 discussion
- **Source PR/issue**: `docs/repo_map/pr_charmbracelet_colorprofile.md` lines 164-172
- **Implementation ideas**: Add `refresh(): Profile` method that re-runs detection with current env/state
- **Estimated complexity**: LOW — Already has detection logic, just need to expose re-entry point
- **Expected impact**: MEDIUM — Better for long-running apps

## Medium Priority

### 10. CSS Color Parsing Extension
- **Title**: Extend `Color::parse()` to support `rgb()`, `rgba()`, `hsl()`, `hsla()`, named colors
- **Description**: Currently only supports `#rgb` and `#rrggbb`. CSS has many more formats.
- **Why it matters**: Developers expect CSS-compatible color parsing.
- **Source repo**: N/A
- **Implementation ideas**: Extend regex in `parse()` to handle multiple CSS color formats
- **Estimated complexity**: LOW-MEDIUM — Regex patterns for each format
- **Expected impact**: MEDIUM — Better developer experience

### 11. Color::fromRgb() Named Constructor
- **Title**: Add `Color::fromRgb(int $r, int $g, int $b, int $a = 255): self`
- **Description**: Currently only `__construct()`, `fromHex()`, and `parse()`. Named constructors improve API clarity.
- **Why it matters**: API consistency with other SugarCraft libraries and upstream expectations.
- **Source repo**: `textualize/textual` has `from_rgb()`
- **Source PR/issue**: `docs/repo_map/sugarcraft_candy-palette.md` line 702
- **Implementation ideas**: Simple alias/wrapper around constructor
- **Estimated complexity**: TRIVIAL — 5 lines
- **Expected impact**: LOW — Minor API improvement

### 12. Windows ConEmuANSI/ANSICON Detection
- **Title**: Add Windows detection for ConEmuANSI and ANSICON
- **Description**: Go upstream checks for `ConEmuANSI` and `ANSICON` environment variables for enhanced Windows color support.
- **Why it matters**: Users running in Windows consoles with ANSI emulation may get wrong profile.
- **Source repo**: `charmbracelet/colorprofile`
- **Source PR/issue**: `docs/repo_map/pr_charmbracelet_colorprofile.md` lines 600, 790
- **Implementation ideas**: Add environment variable checks alongside WT_SESSION detection
- **Estimated complexity**: LOW — Env var checks
- **Expected impact**: LOW — Niche Windows use case

### 13. io.Writer Contract Compliance for ProfileWriter
- **Title**: Ensure ProfileWriter::write() returns input length, not output length
- **Description**: Go's colorprofile.Writer had a bug (Issue #74, fixed v0.4.3) where `Write()` returned bytes written to underlying writer instead of bytes consumed from input, breaking `bufio.Writer` composition.
- **Why it matters**: SugarCraft's ProfileWriter could have the same issue if used with buffered writers.
- **Source repo**: `charmbracelet/colorprofile` (PR #75, v0.4.3)
- **Source PR/issue**: `docs/repo_map/pr_charmbracelet_colorprofile.md` lines 85-118
- **Implementation ideas**: Return `strlen($bytes)` on success, not `strlen($written)`
- **Estimated complexity**: TRIVIAL — 1-line fix
- **Expected impact**: HIGH — Correct middleware composition

### 14. Diagnostic Mode
- **Title**: Add `Palette::diagnose()` method for debugging detection
- **Description**: Provide debug output showing detection decision path and final result.
- **Why it matters**: Users often don't understand why detection produces unexpected results.
- **Source repo**: `charmbracelet/colorprofile` has extensive comments in detection logic
- **Source PR/issue**: `docs/repo_map/pr_charmbracelet_colorprofile.md` lines 606-614
- **Implementation ideas**: Add method that returns array of detection steps and results
- **Estimated complexity**: LOW — Formatting of existing detection logic
- **Expected impact**: MEDIUM — Better developer experience

## Low Priority

### 15. Compound SGR Sequence Handling
- **Title**: Handle compound SGR sequences (bold+italic+color in one ESC[...m)
- **Description**: `rewriteAnsi()` only handles color SGR, not compound sequences with attributes.
- **Why it matters**: Applications emit compound sequences; proper parsing needed for accurate degradation.
- **Source repo**: `charmbracelet/colorprofile` handles via `charmbracelet/x/ansi`
- **Implementation ideas**: Parse full SGR sequences, preserve non-color attributes while degrading colors
- **Estimated complexity**: MEDIUM — Need proper SGR parser
- **Expected impact**: LOW — Most applications emit color-only sequences

---

# Algorithm / Performance Opportunities

## Current Approach vs External Approach

### Color Quantization

**Current (candy-palette)**:
- RGB → ANSI256: Naive 6×6×6 color cube quantization
- RGB → ANSI16: Euclidean distance in RGB space
- No caching

**Go upstream (colorprofile via go-colorful)**:
- RGB → ANSI256: Perceptually uniform color space (CIELAB)
- RGB → ANSI16: Delta-E (CIE76 or CIE2000) color difference
- Thread-safe conversion cache

**Why external is better**: Perceptual color spaces place colors that appear similar to humans near each other in the color space. RGB cube quantization groups colors by raw component values, not by human perception. This matters especially for blues and cyans which the RGB cube handles poorly.

**Tradeoffs**:
- CIELAB conversion is more CPU-intensive
- Naive RGB is "good enough" for most terminal use cases
- PHP's single-request lifetime makes caching less valuable than in Go's long-running processes

**Applicability**: MEDIUM — Would improve color accuracy, but RGB is acceptable for most use cases

### tmux Detection

**Current (candy-palette)**:
- Checks `TMUX` env var and `TERM` prefix
- Assumes tmux caps based on TERM value

**Go upstream (colorprofile v0.3.3+)**:
- Executes `tmux info` subprocess
- Parses output for actual `Tc` or `RGB` capability flags
- Considers actual terminal capability, not assumptions

**Why external is better**: Modern tmux passes COLORTERM through; assumptions based on TERM prefix are stale. Direct probing gives accurate results.

**Tradeoffs**:
- Subprocess execution adds latency
- tmux may not be installed
- Requires error handling for failed commands

**Applicability**: HIGH — Correctness matters for tmux users

### Writer Efficiency

**Current (candy-palette)**:
- Creates new `Palette` instance per `write()` call
- Regex-based ANSI rewriting per call

**Go upstream (colorprofile)**:
- Single Profile set at construction
- Reuses state across writes

**Why current is worse**: Object allocation overhead, redundant detection

**Tradeoffs**: Simple fix, minimal risk

**Applicability**: HIGH — Easy win

---

# Architecture Improvements

## 1. Unify Profile and ColorProfile Enums

**Current state**: Two separate enums (`Profile` and `ColorProfile`) with slightly different orderings create confusion.

**Proposed**: Deprecate `Profile` enum, make `ColorProfile` the single enum throughout. Update `Palette` to use `ColorProfile` internally.

**Rationale**: `ColorProfile` is already the SSOT consumed by Probe and downstream libs. The duplication serves no purpose.

## 2. Add Color Conversion Cache

**Current state**: Every conversion computes from scratch.

**Proposed**: Add `private static array $conversionCache = []` in `Color::convert()` with key `"r,g,b,a|profile"` and LRU-like eviction for unbounded growth.

**Rationale**: Eliminates redundant computation for repeated colors.

## 3. Extract SGR Parser

**Current state**: SGR handling is inline in `Palette::rewriteAnsi()`.

**Proposed**: Extract to `SgrParser` class that handles all SGR forms (38;2, 38;5, 48;2, 48;5, etc.).

**Rationale**: Cleaner separation of concerns, easier to extend.

## 4. Add Color Space Abstraction

**Current state**: Color operates only in RGB.

**Proposed**: Add optional ColorSpace parameter to `Color::convert()` for perceptual conversion when needed.

**Rationale**: Future-proofing for CIELAB conversion without breaking current API.

---

# API / Developer Experience Improvements

## 1. Named Constructors for Color

Add clarity through named constructors:
```php
Color::fromRgb(int $r, int $g, int $b, int $a = 255): self
Color::fromHex(string $hex, int $a = 255): self
Color::fromHsl(float $h, float $s, float $l, int $a = 255): self
```

## 2. Color Manipulation Fluent API

```php
$color->lighten(0.1)  // new Color with 10% lighter
$color->darken(0.1)   // new Color with 10% darker
$color->saturate(0.2) // increase saturation
$color->desaturate(0.2) // decrease saturation
$color->mix($other)   // 50/50 blend
$color->mix($other, 0.3) // 30% other, 70% this
```

## 3. Diagnostic Output

```php
$diagnostics = Palette::diagnose($stream, $env);
echo $diagnostics['detection_path']; // ['CLICOLOR_FORCE=1' => 'TrueColor', ...]
echo $diagnostics['final_profile'];   // 'TrueColor'
echo $diagnostics['reason'];           // 'CLICOLOR_FORCE override'
```

## 4. Gradient Utilities

```php
$gradient = Color::gradient(Color::red(), Color::blue(), 10); // 10 steps
$multiGradient = Color::gradientMulti([
    [Color::red(), 0],
    [Color::blue(), 0.5],
    [Color::green(), 1.0],
], 20);
```

---

# Documentation / Cookbook Opportunities

## 1. Detection Flow Diagram

Add ASCII diagram showing the 12-step detection hierarchy to README.

## 2. Migration Guide

Document differences between `Profile` and `ColorProfile`, when to use which.

## 3. Cookbook Examples

- **Detecting once, using throughout**: `ProfileWriter::wrap()` pattern
- **Conditional coloring**: Detecting profile and selecting appropriate escape codes
- **CI/CD non-TTY output**: How NO_COLOR and non-TTY detection works in pipelines
- **Tmux TrueColor**: Why `TERM=tmux-256color` might give wrong results and how to fix

## 4. Color Theory Primer

Document the color science behind the conversion: why RGB cube is naive, why CIELAB is better, how Delta-E works.

---

# UX / TUI Improvements

## 1. Profile Comment Improvements

Current `Palette::comment()` returns "fancy", "1990s fancy", "normcore", "ancient", "naughty!" — these are somewhat condescending.

**Proposed**: Use more informative descriptions like "Full 24-bit color", "256-color palette", "16-color ANSI", etc.

## 2. Better Error Messages

When SGR parsing fails for unexpected formats, provide debug info (at verbose level).

## 3. Shell Completions

Add shell completion for `FORCE_COLOR` values (0=Ascii, 1=ANSI, 2=ANSI256, 3+=TrueColor).

---

# Testing / Reliability Improvements

## 1. Property-Based Testing

Add property-based tests for color conversion: round-trip conversion should be close to original, conversion to higher profile should preserve color relationships.

## 2. Snapshot Tests for SGR Output

Add snapshot tests asserting the exact SGR bytes produced by `toAnsiForeground()`, `toAnsiBackground()`, etc.

## 3. Integration Test with Tmux

Add test that spawns actual tmux session and verifies `tmux info` probing works (skipped if tmux unavailable).

## 4. bufio.Writer Composition Test

Explicitly test ProfileWriter with a buffered writer to ensure io.Writer contract compliance.

---

# Ecosystem / Integration Opportunities

## 1. sugar-bits Integration

Ensure sugar-bits components (TextInput, etc.) use `Probe::colorProfile()` for color decisions rather than re-implementing detection.

## 2. candy-shine Theme System

Provide `Color::adaptive()` method for light/dark theme support (like lipgloss v2's `LightDark`).

## 3. candy-log Styling

Ensure candy-log's per-level coloring respects the detected profile via ProfileWriter.

## 4. PHP-FIG PSR Standards

Consider implementing a PSR-6 (Caching) compatible cache for color conversions in long-running ReactPHP processes.

---

# Notable PRs / Issues / Discussions

## Issue #76: tmux-256color with COLORTERM=truecolor detected as ANSI256 (OPEN)

**Summary**: Modern tmux (3.5a) passes COLORTERM through from outer terminal, but `colorprofile` still assumes tmux doesn't support COLORTERM based on historical behavior.

**Relevance**: Same issue affects candy-palette — tmux detection uses `TERM` prefix, not actual probing.

**Lessons learned**: Terminal multiplexer capabilities evolve; assumptions become stale; need dynamic probing.

**Potential adaptation**: Implement `tmux info` parsing (like Go upstream v0.3.3+) instead of relying on TERM prefix.

## Issue #74: colorprofile.Writer violates io.Writer contract (CLOSED - Fixed v0.4.3)

**Summary**: `Writer.Write()` returned bytes written to underlying writer, not bytes consumed from input. Broke `bufio.Writer` composition with "short write" errors.

**Relevance**: Critical pattern for middleware composition; same issue could affect SugarCraft's ProfileWriter.

**Lessons learned**: When wrapping a writer and transforming output, ALWAYS return `len(p)` on success.

**Potential adaptation**: Audit ProfileWriter::write() to ensure it returns input length, not output length.

## Issue #75: Fix writer contract (MERGED v0.4.3)

**Summary**: The fix changed return value semantics:
```go
// OLD (returned bytes written to Forward)
return w.downsample(p)

// NEW (returned bytes consumed from input)
_, err := w.downsample(p)
return len(p), err
```

**Relevance**: Direct pattern for ProfileWriter fix.

## PR #43: perf: cache color conversion (MERGED v0.3.2)

**Summary**: Added thread-safe caching for color conversions with `sync.RWMutex`.

**Relevance**: Direct model for candy-palette caching implementation.

## PR #60: enhance tc support detection (MERGED v0.3.3)

**Summary**: Improved tmux detection to parse `tmux info` output for actual Tc/RGB capability.

**Relevance**: Direct model for candy-palette tmux info probing.

## Issue #593: Markdown Redirection to Files Not Working in v2 (CLOSED)

**Summary**: lipgloss v2's architecture changed — `Render()` always emits full ANSI; `Print` layer handles downsampling.

**Relevance**: The v2 architecture (render full, output degrades) is the correct approach SugarCraft should follow.

**Lessons learned**: Separation of rendering from output is architecturally correct.

---

# Recommended Roadmap

## Immediate Wins (v0.x)

1. **Fix ProfileWriter instance reuse** — 2-line fix, reduces allocation overhead
2. **Fix io.Writer contract** — Return input length not output length
3. **Add `Color::fromRgb()` named constructor** — 5 lines, better API
4. **Add SGR 38;5;N and 48;5;N handling** — Proper indexed color degradation
5. **Add Color conversion cache** — Eliminates redundant computation

## Medium-Term Improvements (v1.0)

6. **Implement tmux info probing** — Correct TrueColor detection in tmux
7. **Add HSL color space** — `fromHsl()`, `toHsl()`, `lighten()`, `darken()`
8. **Add gradient utilities** — `Color::gradient()`, `Color::gradientMulti()`
9. **Unify Profile/ColorProfile** — Remove duplicated enum
10. **Add diagnostic mode** — Better debugging for detection issues
11. **Add CSS color parsing** — `rgb()`, `hsl()`, named colors

## Major Architectural Upgrades (v2.0)

12. **Add CIELAB perceptual conversion** — Better color accuracy
13. **Add color harmony operations** — complementary, triadic, analogous
14. **Add automatic re-detection** — For long-running apps
15. **Consider ColorSpace abstraction** — For pluggable color space conversion

## Experimental Ideas

16. **Cache with LRU eviction** — For unbounded growth prevention
17. **Async detection support** — For ReactPHP integration
18. **Terminal capability queries** — OSC 11 (background color) like lipgloss compat

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Recommended Priority |
|-------------|--------|------------|------|---------------------|
| ProfileWriter instance reuse fix | MEDIUM | LOW | NONE | IMMEDIATE |
| io.Writer contract fix | HIGH | LOW | NONE | IMMEDIATE |
| Color conversion cache | HIGH | LOW | NONE | IMMEDIATE |
| SGR indexed color handling | HIGH | MEDIUM | LOW | IMMEDIATE |
| HSL color space | MEDIUM | LOW | NONE | MEDIUM-TERM |
| tmux info probing | HIGH | MEDIUM | LOW | MEDIUM-TERM |
| Gradient utilities | MEDIUM | LOW | NONE | MEDIUM-TERM |
| Profile/ColorProfile unification | MEDIUM | LOW | LOW | MEDIUM-TERM |
| Diagnostic mode | MEDIUM | LOW | NONE | MEDIUM-TERM |
| CSS color parsing | MEDIUM | MEDIUM | NONE | MEDIUM-TERM |
| CIELAB conversion | MEDIUM | HIGH | MEDIUM | POST-v1.0 |
| Color harmony | LOW | LOW | NONE | POST-v1.0 |
| Automatic re-detection | MEDIUM | MEDIUM | MEDIUM | POST-v1.0 |
| Compound SGR handling | LOW | MEDIUM | LOW | POST-v1.0 |
| Windows ConEmu detection | LOW | LOW | NONE | LOW |
| Shell completions | LOW | LOW | NONE | LOW |

---

# Final Strategic Assessment

candy-palette is a solid, standards-compliant port of charmbracelet/colorprofile that serves as the foundational terminal color detection infrastructure for the SugarCraft ecosystem. Its 12-step detection hierarchy, standards compliance (NO_COLOR, CLICOLOR, CLICOLOR_FORCE), and comprehensive test coverage make it production-ready for its core mission.

**Critical gaps** that should be addressed immediately:
1. **Color conversion caching** — Performance issue for repeated color conversions
2. **ProfileWriter inefficiency** — Creates new Palette per write()
3. **SGR indexed color handling** — Only handles 24-bit SGR, misses indexed

**Medium-term priorities**:
1. **tmux info probing** — Correct TrueColor detection in tmux sessions
2. **HSL color space** — Developer ergonomics and API completeness
3. **Unified enum** — Remove Profile/ColorProfile confusion

**Strategic opportunities**:
1. **Perceptual color conversion (CIELAB)** — Would differentiate from Go upstream by using more accurate color science
2. **Unified theming** — Integrate with candy-sprinkles for a complete theming system
3. **ReactPHP integration** — For long-running async PHP applications

The library's clean separation between `Probe` (static detection), `Palette` (instance-based detection+conversion), and `ProfileWriter` (transparent stream wrapper) provides a solid foundation for future enhancements. The main risk is feature creep — adding HSL, gradients, and color harmony could bloat the library beyond its scope as a detection/degradation engine. These should be optional extensions, not core API additions.

**Recommendation**: Focus on the detection and degradation core, make color manipulation operations optional utilities, and maintain the clean separation of concerns that makes the library testable and predictable.
