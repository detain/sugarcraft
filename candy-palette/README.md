<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-palette)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-palette)
[![Packagist Version](https://img.shields.io/packagist/v/candycore/candy-palette?label=packagist)](https://packagist.org/packages/candycore/candy-palette)
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

## Install

```bash
composer require candycore/candy-palette
```

## Quick Start

```php
use CandyCore\Palette\Palette;
use CandyCore\Palette\Profile;
use CandyCore\Palette\Color;

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
use CandyCore\Palette\Palette;
use CandyCore\Palette\Profile;
use CandyCore\Palette\Color;

$color = new Color(100, 50, 255, 255);

// Auto-detect
$converted = Palette::convert($color, Palette::detect());

// Manual downgrade
$ansi256 = Palette::convert($color, Profile::ANSI256);
$ansi    = Palette::convert($color, Profile::ANSI);
```

## License

[MIT](LICENSE)
