<img src=".assets/icon.png" alt="sugar-veil" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-veil)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-veil)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcore/sugar-veil?label=packagist)](https://packagist.org/packages/sugarcore/sugar-veil)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
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
- **Z-index stacking**: control render order across multiple overlays via `withZIndex()`
- **Click-outside dismiss**: detect when a mouse click falls outside a veil's zone via `withClickOutsideDismiss()`
- **Auto-size**: compute veil dimensions from content rather than fixed width/height via `withAutoSize()`
- **Border chrome**: wrap veil content in a terminal border via `withBorder()`
- **VeilStack**: ordered collection of veils sorted by z-index for rendering layered overlays
- **Zone manager integration**: integrate with candy-zone `Manager` for hit testing

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

## Z-Index Stacking

Use `withZIndex(int $zIndex)` to control the stacking order when rendering multiple overlays. Veils with higher z-index values render on top of those with lower values.

```php
use SugarCraft\Veil\Veil;
use SugarCraft\Veil\Position;

$veil = Veil::new()
    ->withZIndex(10);  // renders above veils with zIndex < 10

$output = $veil->composite($fg, $bg, Position::CENTER, Position::CENTER);
```

Accessor: `zIndex(): int`

## VeilStack (Multi-Veil Rendering)

`VeilStack` manages multiple veils ordered by z-index. When compositing, it sorts veils ascending by z-index and composites each onto the result of the previous one, so higher z-index veils appear on top of lower ones.

```php
use SugarCraft\Veil\Veil;
use SugarCraft\Veil\VeilStack;
use SugarCraft\Veil\Position;

$stack = VeilStack::new()
    ->add(Veil::new()->withZIndex(0)->withBackdrop(30))           // base dim layer
    ->add(Veil::new()->withZIndex(10)->withBackdrop(0));           // modal on top

$output = $stack->composite($background, Position::CENTER, Position::CENTER);
```

### VeilStack API

| Method | Description |
|--------|-------------|
| `add(Veil $veil): self` | Add a veil (returns new stack) |
| `clear(): self` | Remove all veils |
| `removeWhere(\Closure $pred): self` | Remove veils matching predicate |
| `filter(\Closure $pred): self` | Keep only veils matching predicate |
| `composite($background, $v, $h, $xOff, $yOff): string` | Composite all with shared position |
| `compositeAll($background): string` | Composite all with their own positions |
| `sorted(): list<Veil>` | Veils sorted by z-index ascending |
| `all(): list<Veil>` | All veils in insertion order |
| `maxZIndex(): int` | Highest z-index in stack (0 if empty) |
| `minZIndex(): int` | Lowest z-index in stack (0 if empty) |
| `isEmpty(): bool` | True if stack has no veils |
| `count(): int` | Number of veils in stack |

## Auto-Size

Use `withAutoSize(bool $enabled = true)` to compute veil dimensions from content rather than from fixed width/height. When combined with `withBorder()`, the border chrome is applied to the content before dimension computation.

```php
use SugarCraft\Veil\Veil;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Veil\Position;

$veil = Veil::new()
    ->withAutoSize()
    ->withBorder(Border::new()->style(Border::STYLE_ROUND));

$output = $veil->composite($content, $bg, Position::CENTER, Position::CENTER);
```

Accessor: `autoSize(): bool`

## Border Chrome

Use `withBorder(Border $border)` to wrap veil content in a terminal border rendered by candy-sprinkles `Style`. The `applyBorderChrome(string $content)` method applies the border to arbitrary content.

```php
use SugarCraft\Veil\Veil;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;

$veil = Veil::new()
    ->withBorder(Border::new()->style(Border::STYLE_ROUND));

// Apply border to arbitrary content
$bordered = $veil->applyBorderChrome("Hello, World!");
```

Accessors: `border(): ?Border`

## Click-Outside Dismiss

Use `withClickOutsideDismiss(bool $enabled = true)` to flag a veil for dismissal when a mouse click falls outside its rendered zone. The self-contained `Scanner` (from [candy-mouse](https://github.com/detain/sugarcraft-candy-mouse)) handles hit testing locally — call `scan($rendered)` after rendering to register the veil zone, then use `isClickOutside()` or `hit()` to query it.

```php
use SugarCraft\Veil\Veil;

$veil = Veil::new()
    ->withClickOutsideDismiss();

// ... after rendering the veil output ...
$veiled = $veil->scan($renderedOutput);

// Check if a mouse message is outside the veil's zone
$outside = $veiled->isClickOutside($mouseMsg);
if ($outside) {
    // dismiss the veil
}
```

Accessors: `clickOutsideDismiss(): bool`

The `isClickOutside(MouseMsg $mouse): bool` method returns `true` when `clickOutsideDismiss` is enabled, a rendered output has been scanned, and the click falls outside all tracked zones. Returns `false` when no scan data is available or when click-outside-dismiss is disabled.

## Zone Manager Integration (deprecated)

`withManager(Manager $manager)` is retained for backward compatibility only. It stores the manager but does **not** drive `isClickOutside()`. The self-contained `Scanner` is always used for hit-testing. New code should use `scan()` / `hit()` directly instead of wiring a `Manager`.

```php
use SugarCraft\Veil\Veil;

$modal = Veil::new()->withClickOutsideDismiss();
// Use scan()/hit() for hit-testing — Manager is not consulted by isClickOutside()
```

## Buffer diffing

The `composite()` method maintains a `?Buffer $previousFrame` across calls. On each
call it builds the current Buffer, computes `current->diff(previous)` (from
[candy-buffer](https://github.com/detain/sugarcraft-candy-buffer)), and emits only
the delta ANSI ops via `DiffEncoder::encode($ops)`. The current frame then replaces
`previousFrame` for the next render.

**SSH bandwidth + flicker win:** a one-character change in an 80×24 viewport
produces ~8 bytes of delta ops instead of ~1 940 bytes for a full repaint.
Over an SSH session this means far less per-frame data on the wire and
eliminates the full-screen flicker of rewrite-based terminals. The first render
after startup or a resize still emits a full Buffer (no diff possible), so
behaviour is always correct.

## Shared foundations

Mouse hit-testing is self-contained via [candy-mouse](https://github.com/detain/sugarcraft-candy-mouse). The `Scanner` class handles zone registration and hit testing locally via `scan()` / `hit()`. `withManager()` is retained as a deprecated back-compat wrapper and is not consulted by `isClickOutside()`.

## License

[MIT](LICENSE)
