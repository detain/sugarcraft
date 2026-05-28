# SugarCraft/candy-palette — Innovation & Comparison Report

## Overview

**candy-palette** is a PHP port of Go's [charmbracelet/colorprofile](https://github.com/charmbracelet/colorprofile) — providing terminal color profile detection and automatic ANSI color degradation. It enables PHP CLI applications to produce richly colored terminal output that gracefully downgrades to match the connected terminal's capabilities.

**Key statistics:**
- **8 source files** across `src/`
- **6 test files** with comprehensive coverage
- **16 language translations** (en + 15 locales)
- **4 VHS demo tapes** (detect.gif, convert.gif, degrade.gif, standard-colors.gif)
- **4 example scripts** (detect, convert, degrade, standard-colors)
- **Status**: 🟢 v1-ready in MATCHUPS.md
- **Upstream**: [charmbracelet/colorprofile](https://github.com/charmbracelet/colorprofile) (Go, ~111 stars)

**Primary consumers within SugarCraft**: `candy-log`, `candy-mosaic`, `candy-freeze`, `candy-vt` consume `Probe::colorProfile()` + `ColorProfile` enum directly.

---

## Architecture

### Class Inventory

```
candy-palette/src/
├── Color.php          — RGBA value object with conversion methods
├── ColorProfile.php   — SSOT detection enum (Probe-driven)
├── Palette.php        — Instance-based detection + degradation + ProfileWriter
├── Profile.php        — Legacy detection enum (richest→simplest ordering)
├── Probe.php           — Static env-probe layer (12-step detection hierarchy)
├── StandardColors.php — 16-color ANSI palette as named Color objects
├── ProfileWriter.php   — Stream wrapper for automatic color degradation
└── Lang.php           — i18n facade (extends SugarCraft\Core\I18n\Lang)
```

### ColorProfile Enum (SSOT for Probe)

```php
enum ColorProfile: string {
    case NoTTY     = 'notty';     // No TTY connected — strip all ANSI
    case Ascii     = 'ascii';     // Two-color black/white mode
    case Ansi      = 'ansi';      // Classic 16-color ANSI
    case Ansi256   = 'ansi256';   // 256-color ANSI (216 cube + 24 greyscale + 16 standard)
    case TrueColor = 'truecolor'; // Full 24-bit TrueColor (16.7 million)
}
```

**Ordered from richest to simplest** — `degradedTo()` walks the chain downward. This is the enum consumed by `Probe::colorProfile()` and by downstream libs.

### Profile Enum (instance-based alternative)

```php
enum Profile: string {
    case TrueColor;
    case ANSI256;
    case ANSI;
    case Ascii;
    case NoTTY;
}
```

Note: `Profile` and `ColorProfile` are two separate enums with slightly different orderings. `ColorProfile` is SSOT (driven by `Probe`), while `Profile` is what `Palette` instances hold.

### Color Value Object

```php
final class Color {
    public readonly int $r;  // 0-255
    public readonly int $g;  // 0-255
    public readonly int $b;  // 0-255
    public readonly int $a;  // 0-255 (alpha, CSS convention: 0=transparent, 255=opaque)

    public static function fromHex(int $hex, int $a = 255): self;
    public static function parse(string $hex, int $a = 255): self;  // "#rgb" or "#rrggbb"
    public function convert(Profile $profile): self;  // downsamples to target
    public function toAnsi256Index(): int;  // 0-255 index
    public function toAnsi16Index(): int;  // 0-15 index with bright adjustment
    public function toAnsiForeground(): string;   // \x1b[38;2;R;G;Bm
    public function toAnsiBackground(): string;   // \x1b[48;2;R;G;Bm
    public function toAnsi256Foreground(): string; // \x1b[38;5;Idx;m
    public function toAnsi16Foreground(): string;  // \x1b[38;5;Idx;m
    public function toHex(): string;  // "#rrggbb"
    public static function namedColors(): array;  // delegates to StandardColors::catalog()
    public function equals(Color $other): bool;
}
```

### Probe: Static Detection Layer

The `Probe` class provides the **canonical detection hierarchy** used throughout SugarCraft. It is the SSOT for terminal color capability detection.

**Detection precedence** (12 steps, mirrors Go upstream exactly):

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

**Key design**: The `Probe` class is **purely static** — no mutable state. This makes it trivially testable (env vars are set per-test, no static cache to reset).

### Palette: Instance-Based Detection + Degradation

```php
final class Palette {
    public function __construct($stream = null, array $env = []);  // auto-detects
    public function profile(): Profile;  // detected profile
    public function withProfile(Profile $profile): self;  // immutable override
    public function convert(Color $color): Color;
    public static function detect($stream = null, array $env = []): Profile;  // static shortcut
    public static function toProfile(Color $color, Profile $profile): Color;  // static shortcut
    public function degrade(string $ansi): string;  // rewrite SGR sequences in string
    public static function stripAnsi(string $s): string;  // strip all ANSI sequences
    public function comment(): string;  // "fancy", "1990s fancy", "normcore", "ancient", "naughty!"
    public function describe(): string;  // "Your terminal supports TrueColor (24-bit full color)."
}
```

### ProfileWriter: Transparent Stream Wrapper

```php
final class ProfileWriter {
    public static function wrap($stream, array $env = []): self;  // auto-detect + wrap
    public function profile(): Profile;
    public function withProfile(Profile $profile): self;  // immutable override
    public function write(string $data): int|false;  // auto-degrades on write
    public function printf(string $format, ...$args): int|false;
}
```

Usage: `ProfileWriter::wrap(STDOUT)->write("\x1b[38;2;255;0;0mred\x1b[0m")` auto-degrades to ANSI256/ANSI/Ascii as needed.

### StandardColors: Named Palette

```php
final class StandardColors {
    public static Color $black;
    public static Color $red;
    public static Color $green;
    public static Color $yellow;
    public static Color $blue;
    public static Color $magenta;
    public static Color $cyan;
    public static Color $white;
    public static Color $brightBlack;   // grey
    public static Color $brightRed;
    public static Color $brightGreen;
    public static Color $brightYellow;
    public static Color $brightBlue;
    public static Color $brightMagenta;
    public static Color $brightCyan;
    public static Color $brightWhite;

    public static function all(): array;        // [0..15] indexed array
    public static function fromIndex(int $index): Color;  // throws OutOfBoundsException
    public static function catalog(): array;      // list of 16 names
}
```

Static initialization uses the classic XTerm/ANSI palette values (not the Windows console defaults):
- Black: `#000000`, Red: `#cd0000`, Green: `#00cd00`, Yellow: `#cdcd00`
- Blue: `#0000cd`, Magenta: `#cd00cd`, Cyan: `#00cdcd`, White: `#e5e5e5`
- Bright Black: `#7f7f7f`, Bright Red: `#ff0000`, etc.

---

## Color Theory Implementation

### Color Space Model

The `Color` class operates in **RGB color space** (R, G, B, A components, 0-255 each). It does **not** use perceptual color spaces (CIELAB, LCH, OKLAB) for conversion — the conversion uses:

1. **For ANSI256**: Naive 6x6x6 color cube quantization + 24-step greyscale ramp
2. **For ANSI16**: Euclidean distance in RGB space to the 8 basic ANSI colors
3. **For Ascii**: Perceived brightness threshold (128)

### Greyscale Detection

```php
private function isGreyscale(): bool {
    return \max($r, $g, $b) - \min($r, $g, $b) <= 10;
}
```

Simple max-min range check. Colors where R, G, B are within 10 of each other are treated as greyscale and mapped to the 24-step ramp (indices 232-255).

### ANSI256 Index Calculation

```php
public function toAnsi256Index(): int {
    // Greyscale: map to the 24-step grey ramp (232-255)
    if ($this->isGreyscale()) {
        $grey = (int) \round($this->luminance() / 255 * 23);
        return 232 + $grey;
    }
    // 6×6×6 color cube
    $r = (int) \round($this->r / 255 * 5);
    $g = (int) \round($this->g / 255 * 5);
    $b = (int) \round($this->b / 255 * 5);
    return 16 + ($r * 36) + ($g * 6) + $b;
}
```

- Greys: 232 + floor(luminance / 255 * 23)
- Color cube: 16 + (r*36) + (g*6) + b, where r,g,b ∈ {0..5}

### ANSI16 Index Calculation

```php
public function toAnsi16Index(): int {
    $index = $this->closestAnsi16();  // 0-7
    // If original color is "bright" (high luminance), add 8
    if ($this->perceivedBrightness() > 128) {
        $index += 8;
    }
    return $index;
}

private function closestAnsi16(): int {
    // Euclidean distance in RGB space to 8 basic palette colors
    // Palette: black, red, green, yellow, blue, magenta, cyan, white
}

private function perceivedBrightness(): float {
    return \sqrt(0.299 * ($this->r ** 2) + 0.587 * ($this->g ** 2) + 0.114 * ($this->b ** 2));
}
```

The **perceived brightness** uses thecoefficients (0.299, 0.587, 0.114) which are the **luma** coefficients from the BT.601 standard. This is distinct from **luminance** which uses (0.299, 0.587, 0.114) as well — the difference is that perceived brightness uses squared RGB values (RMS-like), while luminance uses linear RGB.

### Luminance vs Perceived Brightness

```php
private function luminance(): float {
    return 0.299 * $this->r + 0.587 * $this->g + 0.114 * $this->b;  // linear
}

private function perceivedBrightness(): float {
    return \sqrt(0.299 * ($this->r ** 2) + 0.587 * ($this->g ** 2) + 0.114 * ($this->b ** 2));  // RMS-like
}
```

Luminance is used for greyscale mapping in ANSI256. Perceived brightness is used for the bright/dark distinction in ANSI16.

### ANSI Stripping

The `Palette::stripAnsi()` method strips **all** ANSI escape sequences using a comprehensive regex:

```php
public static function stripAnsi(string $s): string {
    return \preg_replace(
        '/(?:\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)|   // OSC
         \x1b\[[0-9;]*[A-Za-z]|                       // CSI
         \x1b[PX^_][^\x07\x1b]*(?:\x07|\x1b\\\\)|    // DCS/APC/PM
         \x1b[OopeHMJKhCBDsu])/',                     // SS3/other
        '',
        $s,
    ) ?? $s;
}
```

Handles: CSI sequences, OSC (including OSC 8 hyperlinks), DCS, SS3, APC, PM.

### String Degradation (ProfileWriter's rewriteAnsi)

The `Palette::rewriteAnsi()` method uses a regex callback to rewrite TrueColor SGR sequences to the target profile:

```php
private function rewriteAnsi(string $s, Profile $targetProfile): string {
    return \preg_replace_callback(
        '/(\x1b\[)(38|48);2;(\d+);(\d+);(\d+)(m)/',  // SGR 38;2;R;G;B or 48;2;R;G;B
        function (array $m) use ($targetProfile): string {
            $color = new Color((int)$m[3], (int)$m[4], (int)$m[5]);
            $converted = $color->convert($targetProfile);
            if ($targetProfile === Profile::ANSI256) {
                $idx = $converted->toAnsi256Index();
                return "\x1b[{$m[1]}{$m[2]};5;{$idx}{$m[6]}";
            }
            if ($targetProfile === Profile::ANSI) {
                $idx = $converted->toAnsi16Index();
                return "\x1b[{$m[1]}{$m[2]};5;{$idx}{$m[6]}";
            }
            // Ascii
            $ascii = $converted->toAscii();
            return "\x1b[{$m[1]}{$m[2]};5;" . $ascii->toAnsi16Index() . "{$m[6]}";
        },
        $s,
    ) ?? $s;
}
```

**Limitation**: Only handles SGR `38;2;R;G;B` and `48;2;R;G;B` (24-bit foreground/background). Does not handle:
- 256-color indexed (`38;5;N`)
- 16-color indexed (`38;N`)
- Compound SGR sequences with additional parameters

---

## Profile Detection Logic

### Phase 1: Environment Variable Hierarchy

The `Palette::detectProfile()` method implements the detection algorithm:

```php
private static function detectProfile($stream, array $env): Profile {
    // 1. FORCE_COLOR: 0=Ascii, 1=ANSI, 2=ANSI256, 3+=TrueColor
    $force = $env['FORCE_COLOR'] ?? null;
    if ($force !== null && $force !== '') {
        $level = \intval($force);
        return match (true) {
            $level >= 3 => Profile::TrueColor,
            $level === 2 => Profile::ANSI256,
            $level === 1 => Profile::ANSI,
            default => Profile::Ascii,
        };
    }

    // 2. NO_COLOR (per no-color.org): presence disables colors
    if (\array_key_exists('NO_COLOR', $env)) {
        return Profile::NoTTY;
    }

    // 3. COLORTERM: any value other than "none" implies TrueColor
    $ct = $env['COLORTERM'] ?? null;
    if ($ct !== null && \strtolower($ct) !== 'none') {
        return Profile::TrueColor;
    }

    // 4. TERM_PROGRAM hints for known TrueColor terminals
    $known = [
        'iTerm.app' => Profile::TrueColor,
        'Apple_Terminal' => Profile::TrueColor,
        'Hyper' => Profile::TrueColor,
        'WezTerm' => Profile::TrueColor,
        'vscode' => Profile::TrueColor,
        'Ghostty' => Profile::TrueColor,
    ];

    // 5. TERM capability heuristics
    // TrueColor terminals: *-truecolor, *24bit, *direct suffixes
    // ANSI256 terminals: *-256color, xterm-16color, rxvt-unicode, ansi, screen, tmux
    // ANSI terminals: *color, vt100, linux, cygwin

    // 6. TTY detection
    if ($stream !== null && \function_exists('stream_isatty')) {
        if (!@\stream_isatty($stream)) { return Profile::NoTTY; }
    } elseif (\function_exists('posix_isatty')) {
        if (!@posix_isatty(\STDOUT)) { return Profile::NoTTY; }
    }

    // Conservative default: ANSI256
    return Profile::ANSI256;
}
```

### Probe's infocmp Phase 2 Upgrade

When `Probe::colorProfile()` falls back to `Ansi` (step 11), it attempts to upgrade via `infocmp`:

```php
private static function infocmpUpgrade(ColorProfile $profile): ColorProfile {
    if ($profile !== ColorProfile::Ansi) { return $profile; }
    $term = self::term();
    if ($term === null || $term === '') { return $profile; }
    if (!self::infocmpAvailable()) { return $profile; }

    $output = @shell_exec('infocmp -1 ' . \escapeshellarg($term) . ' 2>/dev/null');
    if ($output === null) { return $profile; }

    // Tc (True-color) or RGB (direct color) capability present
    if (\preg_match('/\bTc\b/', $output) || \preg_match('/\bRGB\b/', $output)) {
        return ColorProfile::TrueColor;
    }
    return $profile;
}

private static function infocmpAvailable(): bool {
    static $available = null;
    return $available ??= \is_file('/usr/bin/infocmp') || \is_file('/bin/infocmp');
}
```

**Limitation**: Uses `shell_exec()` — adds latency and spawns a subprocess. The static memoization `static $available = null` caches only the binary availability, not the detection result.

### Reduced Motion Detection

```php
public static function reducedMotion(): bool {
    $reduceMotion = self::getEnv('REDUCE_MOTION');
    if ($reduceMotion !== null && $reduceMotion !== '0' && $reduceMotion !== '') {
        return true;
    }
    $prefersReduced = self::getEnv('PREFERS_REDUCED_MOTION');
    if ($prefersReduced !== null && $prefersReduced !== '0' && $prefersReduced !== '') {
        return true;
    }
    return false;
}
```

Checks both `REDUCE_MOTION` (nonstandard) and `PREFERS_REDUCED_MOTION` (standard).

---

## Standards Compliance

### NO_COLOR (no-color.org)

✅ Fully compliant. Per the standard: "When the NO_COLOR environment variable is present (regardless of its value), programs should suppress color output."

```php
// In Probe
if (self::isNoColor()) { return ColorProfile::NoTTY; }

// In Palette
if (\array_key_exists('NO_COLOR', $env)) { return Profile::NoTTY; }
```

### CLICOLOR / CLICOLOR_FORCE (bixense.com/clicolors)

✅ Fully compliant:
- `CLICOLOR_FORCE=1` → TrueColor (overrides everything)
- `CLICOLOR=0` → NoTTY
- `CLICOLOR=1` → no special behavior (let TERM decide)

### FORCE_COLOR

✅ Implemented as level-based override:
- `FORCE_COLOR=0` → Ascii
- `FORCE_COLOR=1` → ANSI
- `FORCE_COLOR=2` → ANSI256
- `FORCE_COLOR=3+` → TrueColor

### Terminfo / Terminal Database

✅ Phase 2 `infocmpUpgrade()` queries the terminfo database for `Tc` or `RGB` capabilities.

### TMUX / STY Detection

✅ Detects multiplexer presence and checks if the base terminal (screen/tmux) supports 256 colors. Important because tmux/screen multiplexers may not advertise full color capability in their TERM value.

### Windows Terminal

✅ Detects `WT_SESSION` env var (set by Windows Terminal) and forces TrueColor.

---

## Performance Considerations

### Detection Performance

- **Environment variable reads**: O(1) — direct `$_ENV` / `getenv()` lookups
- **infocmp subprocess**: O(subprocess) — spawns `/usr/bin/infocmp` once per detection
- **Memoization**: None in `Palette` (stateless factory pattern). `infocmpAvailable()` uses static memoization but only for the binary's existence, not the result
- **Thread safety**: `Probe` class has no mutable state — all methods are static, trivially thread-safe

### Color Conversion Performance

- **Ansi256 indexing**: Pure arithmetic, O(1)
- **Ansi16 nearest-color search**: Iterates over 8 palette entries, O(8) — constant time
- **Perceived brightness**: O(1) arithmetic
- **String degradation** (`rewriteAnsi`): Single `preg_replace_callback` over the full string — O(n) where n = string length. The callback creates `Color` objects and calls `convert()` — no caching of results

### Memory

- **Color objects**: Small (4 x int + overhead, ~56 bytes)
- **ANSI palette arrays**: Static 16-entry arrays in `StandardColors` and inside `Color` methods
- **No unbounded growth**: No caches, no memoization of color conversions

### ProfileWriter Write Performance

```php
public function write(string $data): int|false {
    if ($this->profile === Profile::NoTTY) {
        $data = Palette::stripAnsi($data);  // regex over full string
    } elseif ($this->profile !== Profile::TrueColor) {
        $palette = new Palette($this->stream, []);  // new instance per write!
        $data = $palette->degrade($data);  // regex again
    }
    return \fwrite($this->stream, $data);
}
```

**Issue**: A new `Palette` instance is created on **every call** to `write()` when the profile is ANSI256 or ANSI. This is wasteful — the instance is thrown away after the single `degrade()` call. Should reuse the instance.

---

## Extension Opportunities

### 1. Color Conversion Cache

The Go upstream uses a thread-safe `map[Profile]map[color.Color]color.Color` cache with `sync.RWMutex`. The PHP port has **no caching** — every conversion recomputes from scratch. For applications that repeatedly convert the same colors (e.g., a progress bar that updates every second with the same set of status colors), a static cache would eliminate redundant computation.

```php
// Example: static cache in Color::convert()
private static array $conversionCache = [];

public function convert(Profile $profile): self {
    $key = "{$this->r},{$this->g},{$this->b},{$this->a}|{$profile->value}";
    if (isset(self::$conversionCache[$profile->value][$key])) {
        return self::$conversionCache[$profile->value][$key];
    }
    $result = match ($profile) { ... };
    self::$conversionCache[$profile->value][$key] = $result;
    return $result;
}
```

### 2. CIELAB / Perceptual Color Conversion

The current RGB→ANSI256 conversion uses naive 6x6x6 cube quantization. The Go upstream (`go-colorful`) uses perceptually uniform color spaces for better downsampling. A PHP port could add:

- RGB → CIELAB conversion
- Nearest-color search in CIELAB space
- Delta-E (CIE76 or CIE2000) for color difference

### 3. HSL / HSV Color Space Support

The `Color` class could gain:
- `Color::fromHsl(float $h, float $s, float $l, int $a = 255): self`
- `Color::toHsl(): array{h: float, s: float, l: float, a: int}`
- `Color::lighten(float $amount): self`
- `Color::darken(float $amount): self`
- `Color::saturate(float $amount): self`
- `Color::desaturate(float $amount): self`

### 4. Color Harmony Utilities

- `Color::complementary(): self`
- `Color::triadic(): array{self, self}`
- `Color::analogous(): array{self, self}`
- `Color::splitComplementary(): array{self, self}`
- `Color::tetradic(): array{self, self, self, self}`

### 5. Gradient Generation

Similar to `pterm`'s `Fade()` method — multi-stop gradient interpolation:

```php
public static function gradient(Color $from, Color $to, int $steps): array;
public static function gradientMulti(array $stops, int $steps): array;
```

### 6. CSS Color Parsing Extension

Currently supports `#rgb` and `#rrggbb`. Could extend to support:
- `rgb(r, g, b)` / `rgba(r, g, b, a)`
- `hsl(h, s%, l%)` / `hsla(h, s%, l%, a)`
- Named colors (`red`, `cornflowerblue`, etc.)
- `currentColor` keyword (for inherited foreground/background)

### 7. SGR Sequence Parsing Extension

The `rewriteAnsi()` method only handles `\x1b[38;2;R;G;Bm` / `\x1b[48;2;R;G;Bm`. Could extend to handle:
- `\x1b[38;5;Nm` (256-color indexed)
- `\x1b[58;2;R;G;Bm` (underline color, CSI 58)
- Compound SGR sequences (bold+italic+color in one sequence)

### 8. Palette::write() Instance Reuse

Fix the `ProfileWriter::write()` efficiency issue by reusing the `Palette` instance:

```php
private ?Palette $palette = null;

public function write(string $data): int|false {
    if ($this->profile === Profile::NoTTY) {
        $data = Palette::stripAnsi($data);
    } elseif ($this->profile !== Profile::TrueColor) {
        $this->palette ??= new Palette($this->stream, []);
        $data = $this->palette->degrade($data);
    }
    return \fwrite($this->stream, $data);
}
```

---

## Comparison Against Mapped Repositories

### vs. charmbracelet/colorprofile (Go, ~111 stars)

**Direct port** — candy-palette is a faithful 1:1 port of the Go library's detection logic and color conversion.

| Aspect | Go colorprofile | candy-palette |
|---|---|---|
| Color conversion | `go-colorful` library (perceptually accurate) | Naive RGB cube quantization |
| Caching | Thread-safe RWMutex cache | None |
| Tmux detection | `tmux info` subprocess + parsing | Not implemented |
| Windows detection | `RtlGetNtVersionNumbers` API, ANSICON, ConEmuANSI | `WT_SESSION` only |
| Terminfo | `Terminfo()` function | `infocmpUpgrade()` only |
| Writer | `NewWriter(w, environ)` drop-in | `ProfileWriter::wrap()` equivalent |
| Re-detection | No automatic re-detection | No automatic re-detection |

**Key gaps vs Go upstream:**
1. No tmux `tmux info` detection (environment variables only)
2. No ConEmuANSI / ANSICON detection (Windows detection is `WT_SESSION`-only)
3. No perceptual color conversion (go-colorful's `Clamp` / `ToXyz` / `ToLab`)
4. No conversion caching
5. No automatic re-detection when terminal capabilities change

**Verdict**: candy-palette is a 70% port — covers the core detection hierarchy and color degradation but lacks the perceptual color science and tmux/Windows depth of the Go original.

---

### vs. pterm/pterm (Go, ~6000 stars)

**pterm** has a comprehensive color system:

| Feature | pterm | candy-palette |
|---|---|---|
| Color types | `Color` (ANSI 16), `RGB` (24-bit), `Style` (composed) | `Color` only (RGBA) |
| TrueColor | ✅ `RGB` struct | ✅ via SGR 38;2 |
| ANSI256 | ✅ `Color` with `.Sprint()` | ✅ via `toAnsi256Index()` |
| Gradient fade | ✅ `Fade()` multi-stop | ❌ Not implemented |
| Color caching | Not documented | None |
| Color detection | `GetTerminalColorProfile()` via `isatty` | ✅ More comprehensive |
| Palette stripping | Via `DisableStyling()` | ✅ Via `stripAnsi()` |

**Verdict**: pterm's color system is richer (gradients, Style composition, Fade) but candy-palette's detection is more standards-compliant (NO_COLOR, CLICOLOR, infocmp).

---

### vs. ratatui/ratatui (Rust, ~19600 stars)

**Ratatui** has a minimal color abstraction:

```rust
pub enum Color {
    Reset, Black, Red, Green, Yellow, Blue, Magenta, Cyan, White,
    BrightBlack, BrightRed, ... BrightWhite,
    AnsiValue(u8),    // 256-color index
    Rgb(u8, u8, u8),   // 24-bit
    Indexed(u8),
}
```

| Feature | ratatui | candy-palette |
|---|---|---|
| Color types | Enum (no alpha) | Class with alpha |
| Conversion | No public conversion API | `Color::convert()` |
| Detection | Via `crossterm` backend | Via `Palette::detect()` |
| Downsampling | Implicit via backend | Explicit `convert()` |
| Palette | No StandardColors equivalent | ✅ 16 named colors |

**Verdict**: ratatui delegates color detection to the backend (crossterm). candy-palette's detection is more comprehensive but lacks the buffer integration.

---

### vs. textualize/textual (Python, ~35000 stars)

**Textual** has a sophisticated color system:

```python
class Color:
    def parse(color: str) -> "Color": ...
    def darken(self, level: float) -> "Color": ...
    def lighten(self, level: float) -> "Color": ...
    def from_hsl(h: float, s: float, l: float) -> "Color": ...
    def from_rgb(r: int, g: int, b: int) -> "Color": ...
    def to_rgb(self) -> tuple: ...
    def to_hsl(self) -> tuple: ...
    def blend(self, other: "Color", factor: float) -> "Color": ...
```

| Feature | textual | candy-palette |
|---|---|---|
| Color space | RGB + HSL + Lab | RGB only |
| Alpha | Yes | Yes (rgba) |
| Color operations | darken/lighten/blend | None |
| Detection | Via rich library | ✅ Comprehensive |
| Named colors | Via theme | ✅ StandardColors |
| Palette stripping | Via `NO_COLOR` | ✅ Via `stripAnsi()` |

**Verdict**: textual's color system is richer (HSL, Lab, darken/lighten/blend operations). candy-palette's detection is more comprehensive (12-step vs implicit).

---

## Missing Features

1. **No color conversion caching** — every `convert()` call recomputes from scratch
2. **No CIELAB / perceptual color space conversion** — RGB cube quantization is naive
3. **No tmux `tmux info` detection** — only environment variables
4. **No Windows ConEmuANSI / ANSICON detection** — only `WT_SESSION`
5. **No automatic re-detection** — profile is set once at construction
6. **No SGR sequence parsing for indexed colors** — `rewriteAnsi()` only handles 24-bit SGR
7. **No gradient / fade utilities** — no multi-stop color interpolation
8. **No HSL/HSV color space** — only RGB
9. **No color harmony operations** — complementary, triadic, analogous
10. **No CSS color parsing extension** — only `#rgb` and `#rrggbb`
11. **No ProfileWriter instance reuse** — new `Palette` created per `write()` call
12. **No `Color::fromRgb(int $r, int $g, int $b)` named constructor** — only `__construct`, `fromHex`, `parse`

---

## Test Coverage Analysis

### Test Files

| File | Focus |
|---|---|
| `ColorTest.php` | Construction clamping, ANSI index conversion, escape sequence generation, equals, named colors |
| `ProbeTest.php` | All 12 detection steps via data provider, env var preservation in setUp/tearDown |
| `ProbeInfocmpTest.php` | infocmp binary availability + Tc capability parsing (group: infocmp) |
| `ProfileTest.php` | Enum labels, descriptions, maxColors, degradation chain |
| `PaletteTest.php` | Detection shortcuts, FORCE_COLOR levels, COLORTERM, TERM_PROGRAM, ANSI stripping |
| `CoverageBoostTest.php` | Edge case coverage: toHex, ANSI16 bright threshold, describe/comment for all profiles |

### Notable Testing Patterns

**Env preservation in ProbeTest**:
```php
protected function setUp(): void {
    $keys = ['CLICOLOR_FORCE', 'NO_COLOR', ...];
    foreach ($keys as $key) {
        $this->savedEnv[$key] = $_ENV[$key] ?? null;
        unset($_ENV[$key]);
        putenv($key);  // clear for this test
    }
}
```

This pattern ensures clean state per test. Uses `putenv()` alongside `$_ENV` so both sources agree.

**Data provider for Probe**:
```php
public static function colorProfileProvider(): array {
    return [
        'clicolor_force_1 returns TrueColor' => [
            ['CLICOLOR_FORCE' => '1', 'TERM' => 'dumb', 'NO_COLOR' => '1'],
            ColorProfile::TrueColor,
        ],
        ...
    ];
}
```

Comprehensive coverage of all 12 detection steps.

**CoverageBoostTest** fills gaps to push line coverage toward 80%+. Uses immutable `withProfile()` overrides to force specific code paths.

---

## Implementation References

| File | Lines | Purpose |
|---|---|---|
| `src/Color.php` | 299 | RGBA value object with conversion methods |
| `src/Profile.php` | 88 | Profile enum with degradation chain |
| `src/ColorProfile.php` | 51 | SSOT detection enum (Probe-driven) |
| `src/Probe.php` | 247 | 12-step static detection layer |
| `src/Palette.php` | 301 | Instance-based detection + degradation |
| `src/ProfileWriter.php` | 114 | Stream wrapper for auto-degradation |
| `src/StandardColors.php` | 109 | 16 named ANSI colors |
| `src/Lang.php` | 22 | i18n facade |
| `tests/ColorTest.php` | 164 | Color construction and conversion tests |
| `tests/ProbeTest.php` | 254 | Static detection data provider tests |
| `tests/ProbeInfocmpTest.php` | 106 | infocmp Phase 2 upgrade tests |
| `tests/ProfileTest.php` | 41 | Profile enum tests |
| `tests/PaletteTest.php` | 152 | Instance detection and stripping tests |
| `tests/CoverageBoostTest.php` | 157 | Edge case coverage push |
| `examples/detect.php` | 49 | Detection demo |
| `examples/convert.php` | 62 | Color conversion demo |
| `examples/degrade.php` | 55 | ProfileWriter demo |
| `examples/standard-colors.php` | — | StandardColors demo |

---

## Architectural Notes

### Two-Enum Design

The existence of both `Profile` and `ColorProfile` enums is a source of confusion. They serve different purposes:
- `ColorProfile` (in `Probe`) is the **SSOT** for environment-driven detection — consumed by `candy-log`, `candy-mosaic`, `candy-freeze`, `candy-vt`
- `Profile` (in `Palette` instances) is what the **degradation engine** operates on

The `Profile` enum has the **same cases** as `ColorProfile` but different string values (`'ansi'` vs `'ansi'` — actually same, but the enum names differ: `ANSI256` vs `Ansi256`).

### The CALIBER_LEARNINGS Pattern

```markdown
- `[probe-ssot]` — Probe::colorProfile() + ColorProfile enum is the SSOT for
  terminal-color env detection. Other libs consume it directly; do not
  re-implement detection logic in consumers.

- `[infocmp-phase2]` — Probe::infocmpUpgrade() silently upgrades Ansi → TrueColor
  when infocmp reports Tc or RGB capability.
```

The `[probe-ssot]` tag documents the Single Source of Truth principle — downstream libs should not re-implement detection, they should call `Probe::colorProfile()`.

### i18n Wiring

`Lang` extends `SugarCraft\Core\I18n\Lang` with baked-in namespace `'palette'` and directory `__DIR__ . '/../lang'`. Only one translation string currently exists (`standard.ansi16_out_of_range`). The infrastructure supports 16 locales but is minimally used.

---

## Conclusion

candy-palette is a well-engineered PHP port of the Go charmbracelet/colorprofile library. It provides a comprehensive 12-step terminal color profile detection hierarchy that is standards-compliant (NO_COLOR, CLICOLOR, CLICOLOR_FORCE) and includes a color degradation pipeline for transparent ANSI sequence rewriting. The `Probe` class is the clear SSOT for color capability detection within SugarCraft.

The main gaps relative to the Go upstream are the lack of perceptual color science (CIELAB conversion, go-colorful), no tmux `tmux info` detection, no conversion caching, and no automatic re-detection when terminal capabilities change mid-stream. The `Color` class could benefit from HSL color space support, color harmony utilities, and gradient generation.

The library's strengths are its faithful adherence to the upstream detection algorithm, comprehensive test coverage, clean static/deterministic `Probe` API, and the `ProfileWriter` pattern for transparent stream degradation. As the SugarCraft ecosystem's terminal color infrastructure, it correctly separates concerns: `Probe` for raw detection, `Palette` for instance-based detection+conversion, and `ProfileWriter` for transparent stream wrapping.
