<img src=".assets/icon.png" alt="sugar-veil" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-veil)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-veil)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcore/sugar-veil?label=packagist)](https://packagist.org/packages/sugarcore/sugar-veil)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarVeil

PHP port of [rmhubbert/bubbletea-overlay](https://github.com/rmhubbert/bubbletea-overlay) — modal/overlay compositing for terminal UIs. Composite one string (foreground) over another (background) at any position with optional pixel offsets.

## Features

- **9 position modes**: Top, Right, Bottom, Left, Center, and the 4 corners (TopRight, BottomRight, BottomLeft, TopLeft)
- **Pixel-precise offsets**: X/Y offsets fine-tune any position
- **Pure rendering**: composites any background + foreground strings
- **Works with any TUI framework**: render your models first, then composite
- **Backdrop dimming**: apply ANSI dim overlay (0–100 opacity) to background
- **Animated transitions**: Slide, Fade, and Scale animations driven by honey-bounce CubicBezier easing
- **No dependencies**: pure PHP, no FFI

## Install

```bash
composer require sugarcraft/sugar-veil
```

## Quick Start

```php
use SugarCraft\Veil\Veil;

$veil = Veil::new();

// Background: a 40x10 box
$bg = "┌──────────────────────────────────────┐\n" .
      "│         Main Application             │\n" .
      "│                                      │\n" .
      "│   [content]                          │\n" .
      "└──────────────────────────────────────┘";

// Foreground: a smaller overlay
$fg = "╔════════╗\n║ MODAL  ║\n╚════════╝";

// Composite fg centered over bg
$output = $veil->composite($fg, $bg, Position::CENTER, Position::CENTER);
echo $output;
```

## Positioning

```php
$veil->composite(
    string  $foreground,
    string  $background,
    Position $vertical,    // TOP | CENTER | BOTTOM
    Position $horizontal,  // LEFT | CENTER | RIGHT
    int      $xOffset = 0, // shift right (+N) or left (-N) cells
    int      $yOffset = 0  // shift down  (+N) or up   (-N) lines
): string
```

## Corner positions

```php
// Top-right corner
$veil->composite($fg, $bg, Position::TOP, Position::RIGHT);

// Bottom-left corner with offset
$veil->composite($fg, $bg, Position::BOTTOM, Position::LEFT, xOffset: 2, yOffset: -1);
```

## Backdrop Dimming

Dim the background behind the overlay using `withBackdrop(int $opacity)` where opacity ranges from 0 (no dimming) to 100 (fully dimmed). The backdrop is applied via ANSI SGR codes before compositing.

```php
// Dim the background to 50% intensity
$veil = Veil::new()->withBackdrop(50);
$output = $veil->composite($fg, $bg, Position::CENTER, Position::CENTER);
```

## Animations

Overlay transitions can be animated using `withAnimation(AnimationKind)`. The `animate()` method accepts a `float $progress` parameter (0.0–1.0) to drive the transition. Animations use honey-bounce `CubicBezier` easing internally.

### Available Animation Kinds

| Kind   | Behavior |
|--------|----------|
| `SLIDE` | Foreground enters from the anchor direction |
| `FADE`  | Foreground opacity increases from 0 to 1 (terminal-dependent) |
| `SCALE` | Lines appear from the center outward |

```php
use SugarCraft\Veil\Veil;
use SugarCraft\Veil\Animation\AnimationKind;
use SugarCraft\Veil\Position;

$veil = Veil::new()
    ->withAnimation(AnimationKind::SLIDE)
    ->withBackdrop(30);

// Animate from 0% to 100% progress
for ($p = 0.0; $p <= 1.0; $p += 0.1) {
    $output = $veil->animate($fg, $bg, Position::CENTER, Position::CENTER, progress: $p);
    // render $output ...
}
```

The `animate()` method composes the overlay with the animation applied at the given progress value. For `SLIDE`, the foreground is offset toward the anchor direction. For `SCALE`, lines are revealed from the center outward. For `FADE`, the foreground is returned unchanged but the easing progress is calculated for external use.

## License

[MIT](LICENSE)
