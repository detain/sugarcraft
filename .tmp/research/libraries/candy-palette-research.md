# candy-palette Research: Terminal Color Detection & Profiles

**Date:** 2026-05-13
**Library:** candy-palette (SugarCraft monorepo)
**Upstream:** charmbracelet/colorprofile
**Related:** muesli/termenv, alacritty

---

## 1. Current Implementation Analysis

### 1.1 Source Files Overview

| File | Purpose |
|------|---------|
| `src/Profile.php` | Enum: `TrueColor`, `ANSI256`, `ANSI`, `Ascii`, `NoTTY` with degradation chain |
| `src/Color.php` | RGBA value object with conversion methods (toAnsi256, toAnsi16, toAscii) |
| `src/Palette.php` | Detection logic + ANSI rewriting for profile downgrade |
| `src/ProfileWriter.php` | Stream wrapper for automatic color degradation |
| `src/StandardColors.php` | Static 16-color ANSI palette |
| `src/Lang.php` | i18n facade |

**Source:** `/home/sites/sugarcraft/candy-palette/src/`

### 1.2 Current Detection Algorithm (Palette::detectProfile)

Priority order:
1. `FORCE_COLOR` → override level (0=Ascii, 1=ANSI, 2=ANSI256, 3+=TrueColor)
2. `NO_COLOR` → NoTTY
3. `COLORTERM` (any value except "none") → TrueColor
4. `TERM_PROGRAM` → iTerm.app, Apple_Terminal, Hyper, WezTerm, vscode, Ghostty → TrueColor
5. `TERM` pattern matching:
   - `-truecolor`, `24bit`, `direct` suffix → TrueColor
   - `-256color`, xterm-16color, rxvt-unicode, eterm, ansi, screen, tmux → ANSI256
   - `color` keyword, vt100, linux, cygwin → ANSI
6. TTY detection (stream_isatty/posix_isatty)
7. Default fallback → ANSI256

**Source:** `candy-palette/src/Palette.php:L174-266`

### 1.3 Current Gaps vs Upstream

| Feature | candy-palette | charmbracelet/colorprofile v0.4.3 |
|---------|---------------|-----------------------------------|
| CLICOLOR/CLICOLOR_FORCE support | ❌ Missing | ✅ Full support |
| Terminfo `Tc`/`RGB` capability detection | ❌ Missing | ✅ Via terminfo.Load() |
| Windows color detection (WinAPI) | ❌ Missing | ✅ windowsColorProfile() |
| TERM=dumb special handling | ⚠️ Partial | ✅ Full dumb terminal handling |
| `TERM=xterm-*-truecolor` patterns | ❌ Missing | ✅ `xterm-ghostty`, `xterm-kitty` |
| Google Cloud Shell detection | ❌ Missing | ✅ `GOOGLE_CLOUD_SHELL=true` |
| WT_SESSION (Windows Terminal) | ❌ Missing | ✅ TrueColor on Windows |
| tmux/screen COLORTERM handling | ❌ Incorrect | ✅ Ignores COLORTERM for screen |

---

## 2. Upstream Analysis: charmbracelet/colorprofile

### 2.1 Key Detection Logic (env.go)

```go
// Source: charmbracelet/colorprofile/env.go:33-60
func Detect(output io.Writer, env []string) Profile {
    out, ok := output.(term.File)
    environ := newEnviron(env)
    isatty := isTTYForced(environ) || (ok && term.IsTerminal(out.Fd()))
    term, ok := environ.lookup("TERM")
    isDumb := !ok || term == dumbTerm
    envp := colorProfile(isatty, environ)
    // ...
    if isatty && !isDumb {
        tip := Terminfo(term)      // <-- Uses terminfo database
        tmuxp := tmux(environ)
        return max(envp, max(tip, tmuxp))  // <-- Maximum of all sources
    }
    return envp
}
```

**Key insight:** Upstream takes the **maximum** of env-based detection, terminfo capabilities, and tmux override — not just the first match.

**Source:** `charmbracelet/colorprofile/env.go:L33-60`

### 2.2 CLICOLOR/CLICOLOR_FORCE Environment Variables

```go
// Source: charmbracelet/colorprofile/env.go:71-95
func cliColorForced(env environ) bool {
    if forced := os.Getenv("CLICOLOR_FORCE"); forced != "" {
        return forced != "0"
    }
    return false
}

func cliColor(env environ) bool {
    return os.Getenv("CLICOLOR") == "1"
}
```

- `CLICOLOR_FORCE=1` → Forces ANSI minimum even if terminal claims no colors
- `CLICOLOR=1` → Enable colors if TTY and TERM not dumb

**Source:** `charmbracelet/colorprofile/env.go` (CLICOLOR standard: https://bixense.com/clicolors/)

### 2.3 Terminfo Detection (Terminfo function)

```go
// Source: charmbracelet/colorprofile/env.go:term.go
func Terminfo(term string) (p Profile) {
    if len(term) == 0 || term == "dumb" {
        return NoTTY
    }
    ti, err := terminfo.Load(term)
    if err != nil {
        return
    }
    // Check for RGB or Tc capabilities
    if _, ok := ti.ExtBoolCapsShort()["Tc"]; ok {
        return TrueColor
    }
    if _, ok := ti.ExtBoolCapsShort()["RGB"]; ok {
        return TrueColor
    }
    return
}
```

**Key insight:** The `Tc` (TrueColor) and `RGB` extended boolean capabilities in terminfo directly advertise 24-bit support. This is how `alacritty-direct` and `xterm-direct` declare TrueColor.

**Source:** `charmbracelet/colorprofile/term.go`

### 2.4 tmux/screen Special Handling

```go
// tmux and GNU screen don't pass through COLORTERM
// They also limit to ANSI256 max even if terminal advertises TrueColor
// Source: charmbracelet/colorprofile/env.go
case strings.HasPrefix(term, "tmux"), strings.HasPrefix(term, "screen"):
    if p < ANSI256 {
        p = ANSI256
    }
```

**Source:** `charmbracelet/colorprofile/env.go:envColorProfile()`

### 2.5 Windows Terminal Detection

```go
// WT_SESSION indicates Windows Terminal
// Source: charmbracelet/colorprofile/env.go
if _, ok := environ.lookup("WT_SESSION"); ok {
    return TrueColor
}
```

**Source:** `charmbracelet/colorprofile/env.go`

---

## 3. Related Library Analysis: muesli/termenv

### 3.1 Color Profile Detection

```go
// Source: muesli/termenv/termenv_unix.go:45-85
func (o *Output) ColorProfile() Profile {
    if !o.isTTY() {
        return Ascii
    }
    if o.environ.Getenv("GOOGLE_CLOUD_SHELL") == "true" {
        return TrueColor
    }
    term := o.environ.Getenv("TERM")
    colorTerm := o.environ.Getenv("COLORTERM")
    switch strings.ToLower(colorTerm) {
    case "24bit", "truecolor":
        if strings.HasPrefix(term, "screen") {
            // tmux supports TrueColor, screen only ANSI256
            if o.environ.Getenv("TERM_PROGRAM") != "tmux" {
                return ANSI256
            }
        }
        return TrueColor
    case "yes", "true":
        return ANSI256
    }
    switch term {
    case "alacritty", "contour", "rio", "wezterm", "xterm-ghostty", "xterm-kitty":
        return TrueColor
    case "linux", "xterm":
        return ANSI
    }
    // ...
}
```

**Notable differences from candy-palette:**
- Uses `COLORTERM=24bit` as TrueColor indicator (candy-palette accepts any value)
- Explicit Google Cloud Shell detection
- xterm-ghostty/xterm-kitty pattern support
- Falls through to check COLORTERM before explicit term matches

**Source:** `muesli/termenv/termenv_unix.go:L45-85`

### 3.2 termenv Features Not in candy-palette

| Feature | termenv | candy-palette |
|---------|---------|---------------|
| Light/dark terminal theme detection | ✅ `Theme()` | ❌ |
| Background/foreground color query | ✅ `BackgroundColor()` / `ForegroundColor()` | ❌ |
| XTerm color query via DCS | ✅ `termStatusReport(10/11)` | ❌ |
| COLORFGBG env var parsing | ✅ | ❌ |
| Force profile per-output instance | ✅ `WithProfile()` | ✅ `withProfile()` |

**Source:** `muesli/termenv/termenv_unix.go:L88-130`

---

## 4. Alacritty Color Detection

### 4.1 Terminfo Entry (alacritty-direct)

```
# Source: alacritty/alacritty/extra/alacritty.info
alacritty-direct|alacritty with direct color indexing,
    use=alacritty+common,
    RGB,        <-- Extended boolean capability advertising 24-bit
    colors#0x1000000, pairs#0x7FFF,
```

The `RGB` capability is how applications query whether the terminal supports 24-bit direct color.

**Source:** `alacritty/alacritty/extra/alacritty.info`

### 4.2 XTGETTCAP Protocol (DCS +q)

Alacritty now supports the XTGETTCAP query mechanism (added 2026-03-27):

```rust
// Source: alacritty/alacritty/commit/83424ffa66df5fc83116751a200c555d48280ae7
fn xtgettcap(&mut self, name: &[u8]) {
    match name {
        b"Co" | b"colors" => xtgettcap_response(name, Some(b"256")),
        b"RGB" => xtgettcap_response(name, Some(b"8/8/8")),  // 8 bits per channel
        _ => xtgettcap_response(name, None),
    }
}
```

**Implications:** Applications can query the terminal directly via DCS +q escape sequences to get RGB capability.

### 4.3 Practical Terminal Support Matrix

| Terminal | TrueColor via TERM | TrueColor via COLORTERM | Terminfo RGB | XTGETTCAP |
|----------|-------------------|------------------------|--------------|-----------|
| alacritty | alacritty-direct | ✅ | ✅ | ✅ (2026+) |
| foot | foot-direct | ✅ | ✅ | ✅ |
| kitty | kitty | ✅ | N/A | N/A |
| wezterm | wezterm | ✅ | ✅ | ✅ |
| iTerm2 | xterm-256color | ✅ | N/A | N/A |
| Windows Terminal | xterm-256color | ✅ | N/A | ✅ |
| tmux | tmux-256color | ❌ (ignored) | N/A | N/A |
| GNU screen | screen-256color | ❌ (ignored) | N/A | N/A |

**Source:** Terminal documentation and alacritty extra/alacritty.info

---

## 5. Color Space Standards

### 5.1 sRGB — Terminal Display Standard

Terminals universally assume **sRGB** color space for TrueColor output:

```
# Source: w3.org/Graphics/Color/srgb
Chromaticity coordinates (ITU-R BT.709):
  R: x=0.64, y=0.33
  G: x=0.30, y=0.60
  B: x=0.15, y=0.06

Reference white: D65 (x=0.3127, y=0.3290)
Gamma: ~2.2 (piecewise: 2.4 exponent, 0.055 offset)
```

**Implication:** All TrueColor implementations (38;2;R;G;B SGR sequences) operate in sRGB.

### 5.2 ANSI 256-Color Palette

The 256-color ANSI palette is **not sRGB**. It's a fixed palette:

```
0-15:   Standard 16 colors (may vary by terminal theme)
16-231: 6×6×6 RGB cube
        R: 0x00, 0x5f, 0x87, 0xaf, 0xd7, 0xff
        G: 0x00, 0x5f, 0x87, 0xaf, 0xd7, 0xff
        B: 0x00, 0x5f, 0x87, 0xaf, 0xd7, 0xff
232-255: 24-step greyscale (8, 18, 28, ... 238)
```

**Source:** ANSI/SGR standards, consistent across terminals

### 5.3 ICC Color Profiles

ICC profiles define color space transformations but are **not used by terminals**. Terminal color handling is:
- **Input:** 8-bit per channel RGB (SGR 38;2;R;G;B)
- **Assumed space:** sRGB (IEC 61966-2-1)
- **No profile embedding:** Terminals don't carry ICC profiles

**ICC relevance to candy-palette:** Limited. The library outputs sRGB-compatible ANSI sequences. Future enhancements could:
- Add color space conversion if terminal advertises wide-gamut
- Support DCI-P3 or Display-P3 on supported terminals (macOS Terminal, iTerm2)

---

## 6. Identified Improvements

### 6.1 High Priority (Semantic Correctness)

| # | Improvement | Rationale | Effort |
|---|-------------|-----------|--------|
| H1 | Add CLICOLOR/CLICOLOR_FORCE support | Standard env var for color control, widely used | 0.5d |
| H2 | Fix tmux/screen COLORTERM handling | Upstream correctly ignores COLORTERM for screen/tmux since they don't proxy it | 0.5d |
| H3 | Add `TERM=dumb` special case | Upstream returns NoTTY for dumb unless CLICOLOR_FORCE=1 | 0.25d |
| H4 | Add `GOOGLE_CLOUD_SHELL` detection | Cloud Shell always TrueColor regardless of TERM | 0.25d |
| H5 | Add Windows Terminal (`WT_SESSION`) detection | Windows Terminal supports TrueColor | 0.25d |

### 6.2 Medium Priority (Capability Parity)

| # | Improvement | Rationale | Effort |
|---|-------------|-----------|--------|
| M1 | Add xterm-ghostty/xterm-kitty patterns | Upstream supports these xterm-TERMNAME variants | 0.25d |
| M2 | Implement COLORTERM=24bit support | termenv uses this; aligns with common practice | 0.25d |
| M3 | Use maximum of env + terminfo capabilities | Upstream takes max(envProfile, terminfo, tmux) | 2d |
| M4 | Add terminfo Tc/RGB capability detection | True way to detect 24-bit support via terminfo | 3d |

### 6.3 Low Priority (Feature Enhancement)

| # | Improvement | Rationale | Effort |
|---|-------------|-----------|--------|
| L1 | Background/foreground color query via DCS | termenv feature, useful for contrast detection | 2d |
| L2 | Light/dark theme detection | termenv Theme() for terminal background | 1d |
| L3 | sRGB gamut warning for wide-gamut terminals | Detect and warn if output may appear oversaturated | 1d |

---

## 7. Recommended Implementation Plan

### Phase 1: Quick Wins (0.5d)

Fix the most impactful detection gaps:

```
Priority: H1, H2, H3, H4, H5, M1, M2
Files affected: src/Palette.php

Detection order changes:
1. Add CLICOLOR_FORCE/CLICOLOR before NO_COLOR check
2. Fix COLORTERM to check value (24bit=truecolor, yes=truecolor)
3. Add tmux/screen check BEFORE COLORTERM processing
4. Add GOOGLE_CLOUD_SHELL=true → TrueColor
5. Add WT_SESSION → TrueColor
6. Add xterm-ghostty, xterm-kitty pattern matching
7. Add TERM=dumb → NoTTY (unless CLICOLOR_FORCE=1)
```

### Phase 2: Terminfo Integration (3d)

Add proper terminfo support:

```
New method: Palette::queryTerminfo(string $term): ?Profile
- Use shell_exec('infocmp $term 2>/dev/null') to parse terminfo
- Look for 'Tc' or 'RGB' in extended booleans
- Return TrueColor if found, null if can't determine

Modify detectProfile to take max of:
- env-based detection
- terminfo detection
- tmux detection (if TERM starts with tmux/screen)
```

### Phase 3: Advanced Features (3d)

Optional enhancements:

```
- Background/foreground color queries (L1)
- Light/dark theme detection (L2)
- Wide-gamut warnings (L3)
```

---

## 8. Testing Considerations

### 8.1 Required Test Cases

```php
// CLICOLOR/CLICOLOR_FORCE
['CLICOLOR_FORCE=1', 'TERM=dumb', Profile::ANSI],           // Force ANSI even for dumb
['CLICOLOR=1', 'TERM=xterm', Profile::ANSI],                // Enable colors
['NO_COLOR=1', 'CLICOLOR_FORCE=1', Profile::Ascii],         // NO_COLOR wins

// tmux/screen COLORTERM handling
['TERM=tmux', 'COLORTERM=truecolor', Profile::ANSI256],     // tmux ignores COLORTERM
['TERM=screen', 'COLORTERM=truecolor', Profile::ANSI256],   // screen ignores COLORTERM

// Windows Terminal
['WT_SESSION=1', Profile::TrueColor],                       // Windows Terminal

// Google Cloud Shell
['GOOGLE_CLOUD_SHELL=true', Profile::TrueColor],            // Always TrueColor

// TERM patterns
['TERM=xterm-ghostty', Profile::TrueColor],                 // xterm-TERMNAME variants
['TERM=xterm-kitty', Profile::TrueColor],
['TERM=alacritty-direct', Profile::TrueColor],              // direct suffix
['TERM=dumb', Profile::NoTTY],                              // dumb terminal
```

### 8.2 Mocking TTY Detection

```php
// Use $stream parameter for testing
public function testNoTTYWhenNotATty(): void {
    $palette = new Palette(tmpfile(), ['TERM=xterm']);  // tmpfile is not a TTY
    $this->assertSame(Profile::NoTTY, $palette->profile());
}
```

---

## 9. Dependencies

### 9.1 No New Dependencies Required

candy-palette currently has no runtime dependencies. All detection is done via environment variables and shell commands.

### 9.2 Optional: terminfo parsing

If terminfo detection is added (Phase 2), no external library needed — parse `infocmp` output via shell_exec.

---

## 10. References

- **charmbracelet/colorprofile:** https://github.com/charmbracelet/colorprofile (v0.4.3)
- **muesli/termenv:** https://github.com/muesli/termenv (v0.16.0)
- **Alacritty color detection:** https://github.com/alacritty/alacritty/extra/alacritty.info
- **NO_COLOR standard:** https://no-color.org/
- **CLICOLOR standard:** https://bixense.com/clicolors/
- **ANSI escape codes:** https://en.wikipedia.org/wiki/ANSI_escape_code
- **sRGB specification:** https://www.w3.org/Graphics/Color/srgb
- **ICC profiles:** https://color.org/ICC1-2022-05.pdf
