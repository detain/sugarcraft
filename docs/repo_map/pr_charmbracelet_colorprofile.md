# Second-Stage Ecosystem Intelligence: charmbracelet/colorprofile

## 1. Repository Overview

| Attribute | Value |
|-----------|-------|
| URL | https://github.com/charmbracelet/colorprofile |
| Stars | ~111 |
| Language | Go |
| License | MIT |
| Contributors | 9 (top: aymanbagabas, dependabot, raphamorim) |
| Releases | 22 |
| Latest | v0.4.3 (2026-03-09) |
| Default Branch | main |

**Core Functionality**: Detects terminal color profiles (NoTTY, ASCII, ANSI, ANSI256, TrueColor) and provides automatic color degradation via a `Writer` type that intercepts ANSI sequences and downgrades them based on detected capability.

**Key Dependency Tree**:
- `github.com/charmbracelet/x/ansi` - ANSI sequence parsing
- `github.com/charmbracelet/x/term` - Terminal detection
- `github.com/lucasb-eyer/go-colorful` - Perceptual color quantization
- `github.com/xo/terminfo` - Terminfo database queries
- `golang.org/x/sys` - Windows API calls

---

## 2. Existing SugarCraft Mapping

From the first-stage analysis, `colorprofile` maps indirectly to:

- **`candy-core`**: Terminal capability detection and color management would be foundational in the core TUI rendering layer
- **`sugar-bits`**: Utility service available to components
- **`candy-shine`**: Complementary - `shine` produces styled ANSI output, `colorprofile` detects capabilities

**Architecture**: A SugarCraft port would likely be a `SugarCraft\Core\Terminal\ColorProfile` service class rather than a component library.

---

## 3. Previously Identified Gaps

From `repo_map/charmbracelet_colorprofile.md`:

- No automatic re-detection when terminal capabilities change mid-stream
- Cache memory growth risk (unbounded color conversion cache)
- Unix-only terminfo by default on non-Windows platforms
- Tmux detection requires spawning `tmux info` subprocess

---

## 4. High-Signal Open Issues

### Issue #76: tmux-256color with COLORTERM=truecolor detected as ANSI256 instead of TrueColor (OPEN)

**Author**: cpcloud | **Created**: 2026-03-17

**Bug Description**: `envColorProfile()` caps TERM values prefixed with `tmux` at ANSI256, even when `COLORTERM=truecolor` is set. The comment on line 187 says "Tmux doesn't support $COLORTERM", but modern tmux does support COLORTERM.

**Setup**:
- Terminal Emulator: Ghostty, Kitty, Alacritty
- Terminal Multiplexer: tmux 3.5a
- TERM: `tmux-256color`
- COLORTERM: `truecolor`

**Root Cause Analysis**:
```go
// GNU Screen doesn't support TrueColor
// Tmux doesn't support $COLORTERM
if colorTerm(env) && !strings.HasPrefix(term, "screen") && !strings.HasPrefix(term, "tmux") {
    return TrueColor
}
```

The tmux prefix check was historically correct (older tmux versions didn't pass through COLORTERM), but is now stale. Modern tmux passes COLORTERM through from the outer terminal.

**Impact**: Users in tmux sessions with TrueColor-capable inner terminals get ANSI256 detection, causing unnecessary color degradation.

**Workaround**: Use `TERM=xterm-256color` instead of `TERM=tmux-256color`

**Strategic Lesson for SugarCraft**:
- **Direct Risk**: HIGH - Same stale tmux assumption could affect PHP detection
- **Recommendation**: SugarCraft should probe tmux capabilities dynamically rather than assuming based on TERM prefix

---

## 5. Important Closed Issues

### Issue #74: colorprofile.Writer violates io.Writer contract (CLOSED - Fixed in v0.4.3)

**Author**: abhinav | **Created**: 2026-03-09 | **Closed**: 2026-03-09

**Severity**: HIGH - Breaking bug for middleware composition

**Problem**: The `Writer.Write()` method returned the number of bytes written to the underlying writer, not the number of bytes consumed from input. This violates `io.Writer` contract which states "Write must return a non-nil error if it returns n < len(p)".

**Impact**: Broke composition with `bufio.Writer`, causing `short write` errors on flush:
```go
bw := bufio.NewWriter(colorprofileWriter)
bw.WriteString("\x1b[31mhello\x1b[0m\n")
bw.Flush() // "short write" error
```

**Fix Applied** (PR #75):
```go
// Before (WRONG)
case w.Profile <= NoTTY:
    return io.WriteString(w.Forward, ansi.Strip(string(p)))

// After (CORRECT)
case w.Profile <= NoTTY:
    _, err := io.WriteString(w.Forward, ansi.Strip(string(p)))
    return len(p), err  // Return input length, not output length
```

**Defensive Lesson for SugarCraft**:
- **Critical Pattern**: Writer middleware MUST return `len(p)` on success, not the bytes actually written after transformation
- This is a common middleware mistake in Go and other languages
- SugarCraft MUST test writer composition with `bufio.Writer` explicitly

---

## 6. Recurring Pain Points

### 6.1 Terminal Multiplexer Detection Complexity

**Pattern**: tmux and GNU Screen have different capabilities and environment handling. The library has accumulated special cases:
- tmux doesn't support COLORTERM (historically)
- Screen doesn't support TrueColor
- tmux needs `tmux info` command to probe actual capabilities
- Windows Terminal with bash.exe doesn't set TERM

**Evidence**:
- PR #72: Windows Terminal bash.exe detection
- PR #60: Enhanced tmux Tc support detection
- v0.3.1: "Tmux doesn't support $COLORTERM" comment added
- Issue #76: Now tmux DOES support COLORTERM (contradiction)

**Strategic Insight**: Terminal multiplexer detection is inherently fragile due to layered terminal architectures. SugarCraft should:
- Document that multiplexers may cause detection inaccuracies
- Provide manual override mechanisms (like `CLICOLOR_FORCE`)
- Consider probing actual capability rather than assuming

### 6.2 Environment Variable Precedence Complexity

The detection logic has evolved to handle multiple competing standards:
- `NO_COLOR` (no-color.org)
- `CLICOLOR` / `CLICOLOR_FORCE` (clicolor.org)
- `COLORTERM` (truecolor/24bit/yes/true)
- `TERM` terminfo database
- Platform-specific (Windows Registry, WT_SESSION)

**Complexity Evidence**:
- `isTTYForced()` with `SKIP_TTY_CHECK`/`TTY_FORCE` env var
- `isDumb` checks with platform-specific behavior
- `envNoColor()`, `cliColorForced()`, `cliColor()`, `colorTerm()` helper functions

**Maintainer Burden**: Each new terminal/multiplexer combination potentially requires new special cases.

---

## 7. Frequently Requested Features

Based on issue/PR analysis:

### 7.1 Dynamic Terminal Re-detection

**Request**: When terminal capabilities change mid-stream (e.g., user resizes, switches theme, connects via different multiplexer)

**Current Limitation**: `Writer.Profile` is set once at creation

**Workaround**: Create new Writer instances

**SugarCraft Opportunity**: Could implement a `Refreshable` interface or provide explicit `detect()` method

### 7.2 Enhanced tmux Detection (Merged in v0.3.3)

**PR #60**: "enhance tc support detection"

Added proper probing of tmux info output for Tc/RGB capabilities instead of assuming based on TERM prefix.

### 7.3 Cache Management

**Added in v0.3.2**: Thread-safe color conversion cache with `sync.RWMutex`

**Gap**: No eviction policy - long-running applications accumulate unbounded cache entries

**SugarCraft Consideration**: PHP's shared-nothing architecture makes caching less critical (per-request lifetime), but could implement LRU eviction if needed.

---

## 8. Important PRs

### PR #75: fix(writer): ensure Write returns the number of processed bytes

**Author**: aymanbagabas | **Merged**: 2026-03-09

Critical fix for io.Writer contract compliance. Changed return value semantics:
```go
// OLD (returned bytes written to Forward)
return w.downsample(p)

// NEW (returned bytes consumed from input)
_, err := w.downsample(p)
return len(p), err
```

**Reviewers**: andreynering

### PR #72: fix: detect bash running in windows terminal

**Author**: aymanbagabas | **Merged**: 2026-02-12

Added `WT_SESSION` detection in `envColorProfile()` to catch Windows Terminal with any shell (bash.exe, etc.):

```go
if len(env["WT_SESSION"]) > 0 {
    return TrueColor
}
```

**Related**: Removed redundant check from `env_windows.go` since detection moved earlier in pipeline.

### PR #60: feat: enhance tc support detection

**Author**: aymanbagabas | **Merged**: 2025-11-03

Improved tmux detection to parse `tmux info` output for actual Tc/RGB capability flags instead of assuming based on TERM prefix.

### PR #36: feat: skip tty check by environment variable

**Author**: raphamorim | **Merged**: 2025-03-25

Added `SKIP_TTY_CHECK` environment variable support. Renamed to `TTY_FORCE` in later commit.

### PR #43: perf: cache color conversion

**Author**: aymanbagabas | **Merged**: 2025-04-29

Added thread-safe caching for color conversions to avoid recomputation:
```go
var (
    cache = map[Profile]map[color.Color]color.Color{
        ANSI256: {},
        ANSI:    {},
    }
    mu sync.RWMutex
)
```

---

## 9. Architectural Changes

### Profile Type Evolution

**v0.4.0**: Introduced `Unknown` profile (iota = 0), renamed `Ascii` to `ASCII` with alias for backwards compatibility:
```go
const (
    Unknown Profile = iota
    NoTTY
    ASCII   // renamed from Ascii
    ANSI
    ANSI256
    TrueColor
)

const Ascii = ASCII // backwards compat
```

**Impact**: Cleaned up ordinal ordering but required test updates across the codebase.

### Writer.Write() Switch Statement Evolution

**v0.4.0**: Changed from simple equality switch to boolean expressions:
```go
// BEFORE
switch w.Profile {
case TrueColor:
    return w.Forward.Write(p)
case NoTTY:
    return io.WriteString(w.Forward, ansi.Strip(string(p)))
case Ascii, ANSI, ANSI256:
    return w.downsample(p)
}

// AFTER
switch {
case w.Profile == TrueColor:
    return w.Forward.Write(p)
case w.Profile <= NoTTY:
    return io.WriteString(w.Forward, ansi.Strip(string(p)))
case w.Profile == ASCII, w.Profile == ANSI, w.Profile == ANSI256:
    return w.downsample(p)
default:
    return 0, fmt.Errorf("invalid profile: %v", w.Profile)
}
```

**Reason**: Enables `<=` comparisons for profile ranges and explicit handling of invalid values.

### Color Conversion Architecture (v0.3.2)

Refactored from manual hex-to-ANSI conversion to using `go-colorful` + `ansi` package conversions:
```go
// Now delegates to ansi.Convert256() and ansi.Convert16()
// Caches results to avoid repeated conversion
```

---

## 10. Performance Discussions

### Caching Strategy (v0.3.2)

**Before**: No caching, every color conversion computed fresh

**After**: `sync.RWMutex` protected cache map per profile

**Benchmark** (from v0.4.0 release):
```go
func BenchmarkWriter(b *testing.B) {
    input := []byte("\x1b[1;3;59mthe quick\x1b[m ...")
    for _, profile := range []Profile{TrueColor, ANSI256, ANSI, ASCII, NoTTY, Unknown} {
        // benchmarks each profile
    }
}
```

**SugarCraft Consideration**: PHP request lifetime is typically short; caching may be less valuable unless running long-lived ReactPHP processes.

### bytes.SplitSeq Allocation Reduction (v0.4.2)

Changed from `bytes.Split()` to `bytes.SplitSeq()` in tmux parsing:
```go
// BEFORE
for _, line := range bytes.Split(out, []byte("\n")) {

// AFTER
for line := range bytes.SplitSeq(out, []byte("\n")) {
```

**Benefit**: Reduced memory allocations by using iterator instead of creating slice

---

## 11. Extensibility Discussions

### 11.1 Writer Profile Override

The `Writer` struct has a public `Profile` field that can be manually set:
```go
type Writer struct {
    Forward io.Writer
    Profile Profile  // Can override detected profile
}
```

**Use Case**: Force specific profile for testing or user preference.

**SugarCraft Pattern**: Could implement similar override in terminal detection service.

### 11.2 Environment Precedence

The library documents a clear hierarchy:
```
NO_COLOR > CLICOLOR_FORCE > CLICOLOR > TERM capabilities > terminfo > tmux
```

**Extensibility**: The `environ` interface allows dependency injection for testing:
```go
func Detect(output io.Writer, env []string) Profile
func Env(env []string) Profile
```

---

## 12. API/UX Complaints

### 12.1 Profile Naming

**Issue**: `Ascii` vs `ASCII` naming inconsistency

**Resolution (v0.4.0)**: Standardized to `ASCII` with `Ascii` as backwards-compatible alias

**Test Evidence**:
```go
// v0.3.x tests used "Ascii"
case Ascii:
    expected = c.expectedAscii

// v0.4.0+ uses "ASCII"
case ASCII:
    expected = c.expectedASCII
```

**Lesson**: Avoid non-standard acronyms in public APIs; standardize early.

### 12.2 Hidden Complexity in Detection

**Complaint Pattern**: Users don't understand why detection produces unexpected results in complex terminal setups (tmux over SSH, Windows Terminal, etc.)

**Documentation Effort**: Added extensive comments to detection logic explaining precedence rules

**SugarCraft Recommendation**: Document detection logic thoroughly, provide diagnostic mode

---

## 13. Migration Problems

### 13.1 Go Version Hikes

| Version | Go Required | Breaking Changes |
|--------|-------------|------------------|
| v0.4.0 | 1.24.2 | Unknown profile, Ascii→ASCII rename |
| v0.3.0 | 1.23.0 | isTTYForced added, detection logic refactored |
| v0.2.2→v0.3.0 | 1.18→1.23 | Major Go version jump |

**SugarCraft Note**: PHP port would not have Go version concerns, but PHP 8.x compatibility matters similarly.

### 13.2 API Deprecations

**Known Deprecations**:
- `Ascii` constant → use `ASCII` (v0.4.0)
- Various internal function renames (e.g., `isTTYForced`)

---

## 14. Clever Fixes & Workarounds

### 14.1 Stale tmux Assumption Workaround

**Issue #76 Workaround**: Users set `TERM=xterm-256color` instead of `TERM=tmux-256color`

**Why it works**: The tmux prefix check in `envColorProfile()` only caps when TERM starts with "tmux". Using xterm bypasses this limitation but may not accurately represent capabilities.

### 14.2 bufio.Writer Composition Fix

**Workaround for Issue #74**: Users could work around by not using bufio.Writer with colorprofile.Writer, or by flushing after each write.

### 14.3 Windows Terminal Detection via WT_SESSION

**Detection order**:
1. Check `WT_SESSION` directly in envColorProfile()
2. Fall back to Windows API if no TERM

**Why**: Windows Terminal doesn't export TERM or COLORTERM, only WT_SESSION

---

## 15. Community Workarounds

Based on issue comments and discussions:

### 15.1 Terminal Emulator Workarounds

Users in tmux with TrueColor terminals report setting:
```bash
# In ~/.tmux.conf
set -g default-terminal "xterm-256color"
```

This bypasses tmux's TERM limitation by using xterm-256color instead of tmux-256color.

### 15.2 CLICOLOR_FORCE Override

Users who want color output regardless of detection use:
```bash
CLICOLOR_FORCE=1 ./program
```

---

## 16. Maintainer Guidance Patterns

### 16.1 Fast Patch Releases

**Pattern**: Critical bugs get patches within hours:
- Issue #74 reported 2026-03-09, fixed and released v0.4.3 same day
- Issue #76 (open) has no fix yet as of 2026-03-30

**Release Cadence**: ~1 month between minor versions, hotfixes for critical issues

### 16.2 Lint-Driven Development

**Evidence**: Extensive golangci-lint configuration with strict settings:
```yaml
linters:
  enable:
    - bodyclose
    - exhaustive
    - gofumpt
    - gosec
    - wrapcheck
    # ... many more
```

### 16.3 Dependabot Automation

**Pattern**: All dependencies auto-updated via Dependabot:
- Regular bumps to golang.org/x/sys
- charmbracelet/x/ansi updates
- Minimal human review needed

---

## 17. Rejected Ideas Worth Revisiting

### 17.1 Automatic Re-detection

**Mentioned in first-stage analysis**: No automatic re-detection when terminal capabilities change

**Not formally proposed as feature**: No GitHub issue found proposing this

**Value if Implemented**: Would help long-running CLI tools adapt to terminal changes

### 17.2 Cache Eviction Policy

**Not proposed**: No issue requesting LRU or TTL cache eviction

**Current behavior**: Unbounded growth

**Risk**: Long-running processes (e.g., ReactPHP servers) could accumulate many unique colors

---

## 18. Problems Likely Relevant To SugarCraft

### 18.1 Terminal Detection Complexity

**Direct Risk**: HIGH

PHP CLI applications face identical detection challenges:
- Detecting terminal capabilities from environment variables
- Windows Terminal support (PHP runs on Windows)
- Tmux/screen multiplexer compatibility

**SugarCraft Strategy**: Build a robust terminal detection service that:
- Supports NO_COLOR, CLICOLOR, CLICOLOR_FORCE, COLORTERM environment variables
- Handles Windows via PHP OS detection
- Can query terminfo if available (though PHP lacks native terminfo bindings)

### 18.2 Color Conversion Accuracy

**Direct Risk**: MEDIUM

Using `go-colorful` for perceptually accurate quantization is the right approach. SugarCraft should use similar algorithms for TrueColor→ANSI256 conversion.

**Option for PHP**:
- Port color quantization logic
- Use a PHP color library with similar algorithms
- Accept less accurate nearest-neighbor (but this produces ugly results)

### 18.3 Writer Contract Compliance

**Direct Risk**: CRITICAL

Any writer middleware in SugarCraft that transforms output MUST return the number of bytes consumed from input, NOT the number of bytes written to the underlying stream.

**Correct Pattern**:
```php
public function write(string $bytes): int
{
    $transformed = $this->transform($bytes);
    $written = $this->forward->write($transformed);
    return strlen($bytes); // Return input length, not written length
}
```

### 18.4 tmux COLORTERM Assumption

**Direct Risk**: MEDIUM

The stale assumption that "tmux doesn't support COLORTERM" causes incorrect detection. SugarCraft should:
- Probe tmux capabilities directly if TMUX env var is present
- Not assume based on TERM prefix alone
- Allow manual override

---

## 19. Features SugarCraft Should Consider

### 19.1 Terminal Capability Service

Provide a `SugarCraft\Core\Terminal\CapabilityDetector` that:
- Detects profile from environment
- Respects NO_COLOR, CLICOLOR, CLICOLOR_FORCE, COLORTERM
- Queries Tmux info if in tmux session
- Handles Windows Terminal via WT_SESSION
- Provides manual override

### 19.2 Color Conversion Service

Provide a `SugarCraft\Core\Terminal\ColorConverter` that:
- Converts TrueColor to ANSI256/ANSI using perceptual quantization
- Caches conversions (with optional LRU eviction)
- Provides discrete color palette generation

### 19.3 Filtering Writer

Provide a `SugarCraft\Core\Terminal\FilteringWriter` that:
- Implements `io.Writer` interface correctly (returns input length)
- Transforms ANSI sequences based on detected/manual profile
- Supports profile changes mid-stream (via setter)

### 19.4 Diagnostic Mode

A mode that outputs detection details:
```
Terminal Detection Report:
  TERM: tmux-256color
  COLORTERM: truecolor
  TMUX: 1 (socket /tmp/tmux-1000/default)
  Detected Profile: ANSI256 (tmux limitation)
  Override: Use CLICOLOR_FORCE=1 or set profile manually
```

---

## 20. Architectural Lessons

### 20.1 Layered Detection with Max Selection

**Pattern Used**:
```go
return max(envp, max(tip, tmuxp))
```

**Why**: Take the highest common denominator across environment, terminfo, and tmux layers.

**SugarCraft Adaptation**: Should use similar max-based selection across detection sources.

### 20.2 Standards Compliance Priority

The library prioritizes compliance with:
- no-color.org (NO_COLOR)
- clicolor.org (CLICOLOR, CLICOLOR_FORCE)
- termstandard/colors (COLORTERM values)

**Lesson**: Implementing standards compliance prevents "works on my terminal" issues.

### 20.3 Environment Abstraction for Testability

**Pattern**:
```go
type environ interface {
    lookup(key string) (string, bool)
    get(key string) string
}
```

**SugarCraft Adaptation**: Use an environment abstraction to allow testing detection logic without setting actual env vars.

---

## 21. Defensive Design Lessons

### 21.1 io.Writer Contract is Sacred

**Lesson**: When wrapping a writer and transforming output, ALWAYS return `len(p)` on success, not the bytes actually written.

**Why**: Callers like `bufio.Writer` use the return value to track buffer state. Returning a smaller value triggers "short write" errors.

### 21.2 Profile Range Comparisons

**Lesson**: Use explicit comparisons instead of switch fallthrough when handling profile ranges:

```go
// GOOD: Explicit range check
case w.Profile <= NoTTY:  // Handles NoTTY, ASCII (if any), ANSI, etc.

// BAD: Implicit fallthrough (v0.3.x style)
case NoTTY, ASCII, ANSI, ANSI256:  // What if new profiles added?
```

### 21.3 Default Case Handling

**Lesson**: Always handle the invalid/unknown profile case explicitly:

```go
default:
    return 0, fmt.Errorf("invalid profile: %v", w.Profile)
```

**Why**: Without this, invalid profiles silently pass through, causing confusing behavior.

### 21.4 Thread Safety for Shared State

**Lesson**: Any global or package-level cache must use proper synchronization:

```go
var mu sync.RWMutex
var cache = map[Profile]map[color.Color]color.Color{...}

func Convert(c color.Color) color.Color {
    mu.RLock()
    defer mu.RUnlock()
    // read from cache
}
```

---

## 22. Ecosystem Trends

### 22.1 Terminal Capability Standardsmaturation

**Trend**: Standards like NO_COLOR and clicolor are gaining adoption. Terminal emulators increasingly set COLORTERM=truecolor.

**Implication**: Detecting TrueColor is becoming easier as more terminals support it.

### 22.2 Multiplexer Compatibility Improvements

**Trend**: Modern tmux (3.x) supports COLORTERM passthrough, contrary to historical limitations.

**Implication**: Stale assumptions about tmux limitations need updating.

### 22.3 Windows Terminal Growth

**Trend**: Windows Terminal is becoming the default Windows terminal, requiring platform-specific detection via WT_SESSION.

**Implication**: Cross-platform libraries must handle Windows Terminal specially.

### 22.4 Color quantization Libraries

**Trend**: go-colorful is becoming the de facto standard for perceptually accurate color conversion in Go terminal apps.

**Implication**: SugarCraft should either port go-colorful algorithms or use an equivalent PHP library.

---

## 23. Strategic Opportunities

### 23.1 SugarCraft Superior Detection

**Opportunity**: PHP's native support for Windows (via PHP_OS, php_uname) combined with Windows-specific detection (WT_SESSION on Windows) could provide better cross-platform detection than Go libraries that often neglect Windows.

### 23.2 ReactPHP Integration

**Opportunity**: Long-running ReactPHP processes could benefit from a refreshable terminal detection service that adapts to capability changes.

### 23.3 PHP Color Libraries

**Opportunity**: PHP ecosystem lacks a go-colorful equivalent for perceptually accurate color quantization. SugarCraft could fill this gap.

### 23.4 Standards Compliance Testing

**Opportunity**: Provide comprehensive test suite covering all standard env vars and edge cases, ensuring compliance.

---

## 24. Cross-Ecosystem Pattern Matches

### 24.1 Lip Gloss (Go)

`charmbracelet/lipgloss` uses colorprofile for rendering. The dependency relationship shows how color detection integrates with styling libraries.

**SugarCraft Parallel**: `candy-shine` would depend on terminal detection service.

### 24.2 Bubble Tea (Go)

`charmbracelet/bubbletea` (the flagship TUI framework) uses colorprofile for all rendering. This validates that terminal detection is foundational infrastructure.

**SugarCraft Parallel**: `candy-core` would depend on terminal detection.

### 24.3 Gum (Go)

`charmbracelet/gum` uses colorprofile for styled terminal output.

**SugarCraft Parallel**: `sugar-bits` components would use the terminal detection service.

---

## 25. High ROI Recommendations

### 25.1 Priority 1: io.Writer Contract Implementation

**Action**: Implement terminal filtering writer with correct contract compliance

**Why**: The #74 issue proves this is a common mistake with serious consequences

**Verification**: Must test composition with PHP buffered writers

### 25.2 Priority 2: Environment Detection with Standards Compliance

**Action**: Build terminal capability detector respecting NO_COLOR, CLICOLOR, CLICOLOR_FORCE, COLORTERM

**Why**: Foundation for all terminal-aware functionality

**Verification**: Comprehensive test matrix covering all standard env var combinations

### 25.3 Priority 3: Windows Terminal Support

**Action**: Implement WT_SESSION detection for Windows Terminal

**Why**: Windows Terminal is increasingly common; users will expect support

### 25.4 Priority 4: Tmux Dynamic Probing

**Action**: Query tmux capabilities directly instead of assuming based on TERM prefix

**Why**: Issue #76 shows stale assumptions cause incorrect detection

**Note**: Requires executing `tmux info` command; need to consider security/performance

### 25.5 Priority 5: Color Quantization Library

**Action**: Either port go-colorful algorithms or identify PHP equivalent

**Why**: Accurate color conversion is essential for good-looking output on limited terminals

### 25.6 Priority 6: Diagnostic Mode

**Action**: Provide debug output showing detection decision path

**Why**: Helps users understand why certain profiles are detected

---

## Appendix: Version History Reference

| Version | Date | Key Changes |
|--------|------|------------|
| v0.4.3 | 2026-03-09 | Fixed io.Writer contract violation |
| v0.4.2 | 2026-02-12 | Windows Terminal bash.exe detection, bytes.SplitSeq |
| v0.4.0 | 2025-12-11 | Unknown profile, ASCII rename |
| v0.3.3 | 2025-11-03 | Enhanced tmux Tc support |
| v0.3.2 | 2025-08-13 | Color conversion caching |
| v0.3.1 | 2025-04-24 | COLORTERM fix, direct color terminals |
| v0.3.0 | 2025-03-25 | SKIP_TTY_CHECK env var |
| v0.2.2 | pre-2025 | Historical baseline |

---

*Report generated: Second-stage ecosystem intelligence analysis*
*Data sources: GitHub issues, PRs, releases, code diffs, documentation*
