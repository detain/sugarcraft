<img src=".assets/icon.png" alt="candy-palette" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-palette)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-palette)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-palette?label=packagist)](https://packagist.org/packages/sugarcraft/candy-palette)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# CandyPalette

PHP port of [charmbracelet/colorprofile](https://github.com/charmbracelet/colorprofile) — magical terminal color profile detection and color degradation.

## Features

- **Detect terminal color profile** from environment variables and TTY info
- **Profile enum**: `TrueColor` (24-bit) → `ANSI256` (255-color) → `ANSI` (16-color) → `Ascii` (no color) → `NoTTY`
- **Color conversion**: downsample RGBA colors to any target profile
- **ProfileWriter**: wrap a stream and automatically degrade color codes to match the terminal
- **ANSI stripping**: `NoTTY` strips all ANSI sequences from output
- **Environment-aware**: reads `TERM`, `COLORTERM`, `FORCE_COLOR`, `NO_COLOR`, `TERM_PROGRAM`
- **Probe class**: static env-detection layer with precedence-ordered rules + infocmp Phase 2 upgrade
- **ColorProfile enum**: SSOT env-detection enum (NoTTY/Ascii/Ansi/Ansi256/TrueColor) for libs that need raw profile values without constructing a Palette instance

## Install

```bash
composer require sugarcraft/candy-palette
```

## Quick Start

```php
use SugarCraft\Palette\Palette;
use SugarCraft\Palette\Profile;
use SugarCraft\Palette\Color;

// Detect the terminal's color profile
$profile = Palette::detect();

echo "Your terminal supports: " . $profile->name . "\n";

// Convert a TrueColor color to the detected profile
$color = new Color(0x6b, 0x50, 0xff, 0xff); // #6b50ff
$converted = Palette::convert($color, $profile);
echo "Converted: " . $converted->toAnsi() . "\n";

// Wrap stdout for automatic color degradation
$writer = ProfileWriter::wrap(STDOUT, [
    'TERM' => getenv('TERM'),
    'COLORTERM' => getenv('COLORTERM'),
]);
fwrite($writer, "\x1b[38;2;107;80;255mFancy text\x1b[0m\n");
```

## Profiles

| Profile   | Colors | Description                      |
|-----------|--------|----------------------------------|
| TrueColor | 16.7M  | Full 24-bit RGB (24-bit ANSI)    |
| ANSI256   | 256    | 216 cube + 24 grey + 16 standard |
| ANSI      | 16     | Standard terminal colors         |
| Ascii     | 2      | Black & white                    |
| NoTTY     | 0      | No color (ANSI stripped)         |

## Color Degradation

```php
use SugarCraft\Palette\Palette;
use SugarCraft\Palette\Profile;
use SugarCraft\Palette\Color;

$color = new Color(100, 50, 255, 255);

// Auto-detect
$converted = Palette::convert($color, Palette::detect());

// Manual downgrade
$ansi256 = Palette::convert($color, Profile::ANSI256);
$ansi    = Palette::convert($color, Profile::ANSI);
```

## Probe — Static Environment Detection

The `Probe` class provides precedence-ordered environment probing for terminal color capability and reduced-motion preference. Use it directly when you need raw detection values without constructing a `Palette` instance.

```php
use SugarCraft\Palette\Probe;
use SugarCraft\Palette\ColorProfile;

// Detect the negotiated color profile
$profile = Probe::colorProfile(); // ColorProfile::TrueColor|Ansi256|Ansi|Ascii|NoTTY
echo $profile->label(); // "TrueColor"

// Check for explicit disable/enable flags
if (Probe::isNoColor()) {
    // NO_COLOR env var is set — disable all color output
}
if (Probe::isForceColor()) {
    // CLICOLOR_FORCE=1 — force full color regardless of terminal
}

// Reduced-motion preference (REDUCE_MOTION or PREFERS_REDUCED_MOTION)
if (Probe::reducedMotion()) {
    // Skip animations, spinners, and other motion
}
```

**Detection precedence** (mirrors [charmbracelet/colorprofile](https://github.com/charmbracelet/colorprofile)):
1. `CLICOLOR_FORCE=1` → `TrueColor` (overrides everything)
2. `NO_COLOR` (any value) → `NoTTY`
3. `CLICOLOR=0` → `NoTTY`
4. `TERM=dumb` → `NoTTY`
5. `COLORTERM=24bit|truecolor|yes` → `TrueColor`
6. `WT_SESSION` (set) → `TrueColor` (Windows Terminal)
7. `GOOGLE_CLOUD_SHELL=true` → `TrueColor`
8. `TMUX`/`STY` + `screen*`/`tmux*` base term → `Ansi256`
9. `TERM=xterm-kitty|xterm-ghostty|*-256color` → `Ansi256`
10. `TERM=xterm*|screen*|tmux*` → `Ansi`
11. Default → `Ansi`, then **Phase 2 infocmp upgrade** → `TrueColor` if `Tc`/`RGB` capability found

## ColorProfile Enum

`ColorProfile` is the SSOT enum for environment-driven color capability. It is used by `Probe` and consumed by libs that need the raw profile value (candy-log, candy-mosaic, candy-freeze, candy-vt).

```php
use SugarCraft\Palette\ColorProfile;

$profile = Probe::colorProfile();

// Human-readable label
echo $profile->label(); // "TrueColor"
```

| Case      | Value       | Label        |
|-----------|-------------|--------------|
| `NoTTY`  | `'notty'`    | No TTY       |
| `Ascii`  | `'ascii'`    | ASCII        |
| `Ansi`   | `'ansi'`     | ANSI         |
| `Ansi256` | `'ansi256'`  | ANSI 256     |
| `TrueColor` | `'truecolor'` | TrueColor  |

## Architecture

```
SugarCraft\Palette\
├── Color          — RGBA color value object with conversion methods (Color::namedColors() lists standard names)
├── Palette        — instance-based detection + degradation + ProfileWriter
├── Profile         — legacy detection enum (richest→simplest order)
├── ColorProfile    — new SSOT detection enum (simplest→richest order, Probe-driven)
├── Probe           — static env-probe layer (colorProfile/isNoColor/isForceColor/reducedMotion)
├── StandardColors  — ANSI/ANSI256 standard palette
├── ProfileWriter  — stream wrapper for automatic color degradation
└── Lang           — i18n strings
```

## License

[MIT](LICENSE)
