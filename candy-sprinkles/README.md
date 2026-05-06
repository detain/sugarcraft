<img src=".assets/icon.png" alt="candy-sprinkles" width="160" align="right">

# CandySprinkles

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-sprinkles)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-sprinkles)
[![Packagist Version](https://img.shields.io/packagist/v/candycore/candy-sprinkles?label=packagist)](https://packagist.org/packages/candycore/candy-sprinkles)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/dashboard.gif)

PHP port of [charmbracelet/lipgloss](https://github.com/charmbracelet/lipgloss) —
declarative styling and layout for terminal UIs.

```sh
composer require candycore/candy-sprinkles
```

## Quickstart

```php
use CandyCore\Sprinkles\Style;
use CandyCore\Core\Util\Color;

$banner = Style::new()
    ->bold()
    ->foreground(Color::hex('#ff5f87'))
    ->padding(0, 2)
    ->render('hello, candy world');

echo $banner . "\n";
```

## Layout helpers

```php
use CandyCore\Sprinkles\Layout;
use CandyCore\Sprinkles\Position;

// Side-by-side
echo Layout::joinHorizontal(Position::TOP, $left, $right);

// Top-down
echo Layout::joinVertical(Position::LEFT, $header, $body, $footer);

// Place inside a fixed rectangle
echo Layout::place(40, 10, Position::CENTER, Position::CENTER, 'centered text');
```

## Tables

```php
use CandyCore\Sprinkles\Border;
use CandyCore\Sprinkles\Table\Table;
use CandyCore\Sprinkles\Style;

$styled = Table::new()
    ->headers('Name', 'Age')
    ->row('Alice', '30')
    ->row('Bob',   '25')
    ->border(Border::rounded())
    ->styleFunc(static fn(int $row, int $col): Style
        => $row === Table::HEADER_ROW
            ? Style::new()->bold()
            : Style::new())
    ->render();
echo $styled;
```

## Trees & lists

```php
use CandyCore\Sprinkles\Listing\{Enumerator, ItemList};
use CandyCore\Sprinkles\Tree\Tree;

echo ItemList::new()
    ->items(['Apples', 'Bananas', 'Cherries'])
    ->enumerator(Enumerator::roman())
    ->render();

echo Tree::new()
    ->root('Documents')
    ->child(Tree::new()->root('Travel')->child('Italy.md')->child('Japan.md'))
    ->child('Resume.pdf')
    ->render();
```

## Public API

- **`Style`** — every lipgloss prop (~40 `with*()` methods): fg/bg/border
  colours (incl. per-side), bold/italic/underline/strikethrough/faint/blink/
  reverse, padding/margin (1/2/4-arg shorthand + per-side), width/height,
  maxWidth/maxHeight, align (Align/VAlign), inline, transform, tabWidth,
  marginBackground, colorWhitespace. Plus 21 getters and 15 `unset*()`.
- **`Border`** — `normal()`, `rounded()`, `thick()`, `double()`, `block()`,
  `hidden()`. Per-side toggles via `Style::border*`.
- **`AdaptiveColor` / `CompleteColor` / `CompleteAdaptiveColor`** — pick the
  right concrete colour at render time per `ColorProfile` (TrueColor / 256 /
  Ansi) or per dark-vs-light background.
- **`LightDark`** — pick helper for dark-bg vs light-bg colour schemes.
- **`Layout`** — `Place`, `PlaceHorizontal`, `PlaceVertical`,
  `JoinHorizontal`, `JoinVertical`, `Width`, `Height`, `Size` (all
  package-level layout primitives from lipgloss).
- **`Position`** — `TOP / LEFT / CENTER / RIGHT / BOTTOM` floats for layout
  anchors.
- **`Listing\ItemList`** + **`Listing\Enumerator`** — bullet, dash, asterisk,
  arabic, alphabet, roman, romanUpper, decimal, none. Nested sublists +
  per-item style hooks.
- **`Tree\Tree`** + **`Tree\Enumerator`** — default / rounded / ascii
  connector sets; per-section style overrides; custom indenter.
- **`Table\Table`** — `headers` / `row(s)` / `border` / `align` /
  `headerAlign` / `rowAlign` / `styleFunc` / per-side border toggles /
  `width` / `offset` / `clearRows` / `data(Data)`.
- **`Table\Data`** — row-reader interface (`rows` / `columns` /
  `at($r, $c)`). Default impl `Table\StringData::fromMatrix(iterable)`.
- **`Output`** — top-level `print` / `println` / `sprint` / `printf` /
  `fprint($stream, …)` (style-agnostic).
- **`Renderer`** — per-writer rendering context: `withColorProfile` /
  `withHasDarkBackground` / `newStyle()` / `lightDark()` /
  `resolveAdaptive(AdaptiveColor)` / `fromEnvironment()`.
- **`Palette`** — named ANSI 16-slot constants (Black / Red / …
  BrightWhite) + `hasDarkBackground()` helper.
- **`UnderlineStyle`** — enum (None / Single / Double / Curly /
  Dotted / Dashed) for SGR `4:N` sub-style emit.

## Custom Renderer & color-profile

`Sprinkles\Renderer` bundles a colour profile + a dark-background
flag so you can branch behaviour without threading the values
through every `Style` call:

```php
$r     = Renderer::new()
    ->withColorProfile(ColorProfile::Ansi256)   // forced 256-colour
    ->withHasDarkBackground(false);              // light terminal
$style = $r->newStyle()->bold()->foreground(...);
$pick  = $r->lightDark();                        // closure(light, dark)
```

Each `Style` owns a `colorProfile()` setter too — the renderer is
just a convenience wrapper. `Renderer::fromEnvironment()` calls
`ColorProfile::detect()` for you (consults `NO_COLOR`,
`CLICOLOR_FORCE`, `TERM_PROGRAM`, etc.).

PHP's stream model is coarser than Go's, so the writer-binding
`NewRenderer(out)` shape is *not* mirrored. Pair with
`Output::fprint($stream, ...)` when you need to write to a
specific stream.

## Color blending utilities

`Util\Color` ships the lipgloss-equivalent helpers:

| Method | Returns |
|---|---|
| `Color::hex('#ff5f87')` | RGB |
| `Color::ansi(13)` | named ANSI slot |
| `Color::ansi256(213)` | xterm-256 |
| `$c->blend($other, $t)` | linear LERP, t ∈ [0, 1] |
| `Color::blend1D($a, $b, int $steps)` | list of N stops |
| `Color::blend2D($tl, $tr, $bl, $br, $w, $h)` | 2D grid |
| `$c->lighten($amount)` / `darken($amount)` | luminance ±amount |
| `$c->alpha($amount)` | premultiply alpha onto a backdrop |
| `$c->complementary()` | hue + 180° |

Named ANSI slots live on `Sprinkles\Palette`:
`Palette::Red`, `Palette::BrightWhite`, etc. Use
`Palette::hasDarkBackground()` for a no-args terminal-detect.

## OSC-8 hyperlinks & underline styles

`Style::hyperlink($url)` wraps the rendered output in `\x1b]8;;URL…`
escapes; modern terminals turn it into a clickable link, the rest
fall through to plain text. Combine with `Style::underline()` +
`Style::underlineStyle(UnderlineStyle::Curly)` (the SGR `4:3`
sub-style) and `Style::underlineColor(Color::hex('#ff0000'))` to
emit a wavy red spell-check-style underline that degrades cleanly
to a plain SGR 4 underline on terminals that don't speak the
sub-style.

## Style copy-vs-inheritance

Every `with*()` setter returns a new `Style` — the receiver is
never mutated. `Style::copy()` returns a shallow clone for those
moments you want a known checkpoint to branch from.

`Style::inherit($parent)` merges *unset* props from the parent.
Inheritable properties: `bold` / `italic` / `underline` / `strike` /
`faint` / `blink` / `reverse` / `fg` / `bg` / `borderFg` / `borderBg`
(only if the child hasn't set them). Layout / structural properties
(width / height / padding / margin / border / borderSides) **don't**
inherit — every component is layout-independent. This matches
lipgloss v2's "explicit wins" rule.

## Measurement utilities

`Sprinkles\Layout` exposes the three measurement helpers from
lipgloss's package level:

- `Layout::Width($s)` — visible cell width of a (possibly
  ANSI-coloured, multibyte) string.
- `Layout::Height($s)` — number of `\n`-separated lines.
- `Layout::Size($s)` — `[width, height]` tuple.

All three strip ANSI escapes before measuring, honour East-Asian
wide cells, and round the same way `Style::render()` does — so
you can `Layout::Width($style->render('xx'))` to budget a
component's footprint reliably.

## Table StyleFunc + per-section borders

`Table::styleFunc(\Closure(int $row, int $col): Style)` runs once
per cell to pick its style. Row index `Table::HEADER_ROW` (constant
`-1`) identifies the header.

```php
$striped = Table::new()
    ->headers('Name', 'Score')
    ->rows([['Alice', '93'], ['Bob', '87']])
    ->styleFunc(static fn (int $r, int $c): Style =>
        $r === Table::HEADER_ROW
            ? Style::new()->bold()
            : ($r % 2 === 0 ? Style::new()->faint() : Style::new()),
    );
```

The four border-section flags (`borderHeader` / `borderRow` /
`borderColumn` / `borderTop/Right/Bottom/Left`) decide which
separators draw. Defaults: rounded outer + header rule + column
verticals; row separators off.

## Graceful colour degradation

A `Style` carries a `ColorProfile` (TrueColor / Ansi256 / Ansi /
NoTty). `render()` downsamples every colour to that tier before
emit:

```
TrueColor  → SGR 38;2;R;G;B  (24-bit)
Ansi256    → SGR 38;5;N      (xterm-256 nearest match)
Ansi       → SGR 30..37 / 90..97  (named slots)
NoTty      → no SGR at all   (clean text)
```

Use `Style::colorProfile(ColorProfile::Ansi)` for a forced
downgrade or `Renderer::fromEnvironment()` to auto-detect from
`NO_COLOR` / `CLICOLOR_FORCE` / `TERM` / `COLORTERM` /
`TERM_PROGRAM` / `WT_SESSION` / CI markers.

## Test

```sh
cd candy-sprinkles && composer install && vendor/bin/phpunit
```

## Demos

### Border styles

![border](.vhs/border.gif)

### Canvas (multi-layer compositor)

![canvas](.vhs/canvas.gif)

### Layout dashboard

![dashboard](.vhs/dashboard.gif)

### List

![list](.vhs/list.gif)

### Style

![style](.vhs/style.gif)

### Table

![table](.vhs/table.gif)

### Tree

![tree](.vhs/tree.gif)

