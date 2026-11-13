<img src=".assets/icon.png" alt="sugar-style" width="160" align="right">

# SugarStyle

> Formerly published as `sugarcraft/candy-sprinkles` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Sprinkles\` → `SugarCraft\Style\`. <!-- @rename-keep -->

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-style)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-style)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-style?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-style)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

> The badges and demo GIFs above point at the **monorepo host** (`github.com/detain/sugarcraft`), where CI runs; the package itself is published standalone as `sugarcraft/sugar-style` on Packagist (its split-repo host is `github.com/sugarcraft/sugar-style`, the target of the composer/support/homepage links). Two hosts by design — the link kinds are not interchangeable.


![An animated multi-panel terminal dashboard built with the layout helpers](.vhs/dashboard.gif)

**SugarStyle** — declarative styling and layout for terminal UIs. Requires PHP 8.3+.

```sh
composer require sugarcraft/sugar-style
```

## Quickstart

```php
use SugarCraft\Style\Style;

echo Style::new()
    ->bold()
    ->fg('#ff5f87')        // accepts hex string or Color instance
    ->on('#1e1e2e')        // background — reads naturally in chains
    ->pad(0, 2)            // CSS-style 1/2/4-arg padding
    ->of('hello, styled world')
    ->render() . "\n";
```

`fg` / `bg` / `on` / `pad` / `mg` / `of` are short-form ergonomic aliases.
The string colour form is **hex-only** (it routes through `Color::hex`):
`fg('white')` / `on('red')` throw — pass `Color` instances built with
`Color::parse('white')`, or use colour names inside `[...]` markup
(`[bg:red]`), which accepts names.
The full-length names work identically — `fg`→`foreground`, `bg` and
`on`→`background`, `pad`→`padding`, `mg`→`margin`, `of`→`setString` (six
short forms over five full-length names); pick whichever reads better at
the call site:

```php
use SugarCraft\Core\Util\Color;
use SugarCraft\Style\Style;

echo Style::new()
    ->bold()
    ->foreground(Color::hex('#ff5f87'))
    ->background(Color::hex('#1e1e2e'))
    ->padding(0, 2)
    ->setString('hello, styled world')
    ->render() . "\n";
```

## Layout helpers

```php
use SugarCraft\Style\Layout;
use SugarCraft\Style\Position;

$left = 'sidebar';
$right = 'content';
$header = 'top';
$body = 'middle';
$footer = 'bottom';

// Side-by-side
echo Layout::joinHorizontal(Position::TOP, $left, $right);

// Side-by-side with 2-cell gap between blocks
echo Layout::joinHorizontalWithSpacing(Position::TOP, 2, $left, $right);

// Top-down
echo Layout::joinVertical(Position::LEFT, $header, $body, $footer);

// Top-down with 1-line gap between blocks
echo Layout::joinVerticalWithSpacing(Position::LEFT, 1, $header, $body, $footer);

// Place inside a fixed rectangle
echo Layout::place(40, 10, Position::CENTER, Position::CENTER, 'centered text');

// Split panes: a divider column / tiled rule between blocks (empty blocks
// are dropped together with their divider)
echo Layout::joinHorizontalWithDivider(Position::TOP, '│', $left, $right);
echo Layout::joinVerticalWithDivider(Position::LEFT, '─', $header, $body);

// Block clips (ANSI-aware): clipHead/clipTail by rows, clipWidth by cells
echo Layout::clipWidth($body, 40, '…');
```

Only lowercase method names are defined (`joinHorizontal`, `place`, …);
the capitalized spellings inherited from the upstream Go API resolve to the
same functions because PHP method names are case-insensitive.

## Zone rows (`Bar\ZoneRow`)

One-row bars of clickable pieces (menu titles, window tabs) that fit a
width budget and report their own click zones. Widths are always measured
on PLAIN text; a marked render wraps zoned pieces afterwards, so painted
and marked rows share one layout. Overflow collapses the tail into a
caller-built marker (`‹N›`, `»`). The full law is in the `ZoneRow`
docblock.

```php
use SugarCraft\Style\Bar\ZoneEntry;
use SugarCraft\Style\Bar\ZonePiece;
use SugarCraft\Style\Bar\ZoneRow;

$cols = 12;
$tabs = [ZoneEntry::of([ZonePiece::of('one', 'tab:1'), ZonePiece::of(' ×', 'x:1')])];

$row = ZoneRow::new()
    ->withLead(ZonePiece::of('[+]', 'win:new'))
    ->withEntries($tabs)                       // list<ZoneEntry>
    ->withOverflow(fn (int $n) => ZoneEntry::of([ZonePiece::of("‹{$n}›")]))
    ->withMarker(fn (string $id, string $s) => $s); // e.g. Mark::zone($id, $s)

echo $row->render($cols, false);
$zones = $row->zones($cols);   // [row, from, to, id] spans
```

## Constraint-based layout

The `Layout` sub-namespace provides a ratatui-inspired constraint solver
that partitions a terminal region into rows or columns:

```php
use SugarCraft\Style\Layout\Constraint;
use SugarCraft\Style\Layout\Layout;
use SugarCraft\Style\Layout\Rect;

// Split a 80×24 area into 3 horizontal rows
$rows = Layout::vertical([
    Constraint::length(3),   // header — fixed 3 rows
    Constraint::min(15),     // body   — at least 15 rows, grows with slack
    Constraint::length(1),   // footer — fixed 1 row
])->split(Rect::fromSize(80, 24));

// Further split the body row into 3 columns
$cols = Layout::horizontal([
    Constraint::length(20),     // sidebar — fixed 20 cols
    Constraint::percentage(60), // main    — 60% of the total width
    Constraint::fill(1),        // extra   — absorbs the rest
])->split($rows[1]);
```

Available constraints:

| Constraint | Behaviour |
|---|---|
| `Constraint::length($n)` | Fixed character-cell count |
| `Constraint::min($n)` | At least `$n` cells; takes more if space is available |
| `Constraint::max($n)` | Upper-bound cap; reclaimed space redistributed to other constraints |
| `Constraint::percentage($n)` | `$n`% of the total area (0–100) |
| `Constraint::ratio($num, $denom)` | Proportional size as `$num/$denom` of the area |
| `Constraint::fill($weight)` | Fills all remaining space; weight controls distribution |

Each wrapper exposes its payload through dynamic property reads
implemented via `__get` forwarding to the wrapped value — see the trait
`Layout\ReadsConstraintPayload` — `n` on `length`/`min`/`max`/`percentage`,
`weight` on `fill` — e.g. `Constraint::length(20)->n`. Internally the value
lives in a private constraints-library object and the trait (reached
through `__get`) forwards the solver's per-class field reads and carries the
one unknown-name rejection; that magic is why reflection-based tooling must
not treat the wrappers as plain property bags.

Constraint-layer error strings are hard-coded English — this sub-namespace
is deliberately not routed through `Lang`.

The solver partitions any combination of constraints with a greedy
re-distribution pass (fixed sizes first, then caps, then minimum floors,
then proportional and fill constraints sharing what is left). It is not a
single linear-scan formula: when one constraint is clamped, its reclaimed or
deficit cells are re-spread over the remaining constraints.
See `examples/constraint-dashboard.php` for a full 3-pane dashboard demo.

Each `solve()` call constructs a fresh solver instance (the layout layer is
stateless); the `SUGARCRAFT_LAYOUT_SOLVER` environment decision itself is
read once per process and cached — see “The layout-solver environment
gate” below.

### The layout-solver environment gate

`Solver::solve()` asks `SolverFactory::fromEnvironment()` for a solver, and
the factory reads `SUGARCRAFT_LAYOUT_SOLVER` once per process:

- unset or any value other than `cassowary` → `GreedySolver` (the canonical
  partitioner).
- exactly `cassowary` → `CassowarySolver`, which today **delegates to
  GreedySolver** — sugar-constraints's simplex engine was removed, so the
  opt-in is a deprecated-but-harmless setting: the factory announces the
  delegation once per process, and the shim itself raises an
  `E_USER_DEPRECATED` notice on **every** `solve()` it takes. Behaviour is
  identical either way.

The decision (not the instance) is memoized in a static; tests that toggle
the variable mid-process call
`SolverFactory::resetEnvironmentResolutionForTesting()`. `SolverFactory::default()`
is a deprecated alias for `fromEnvironment()`.
`sugar-style`'s factory is the only code in the fleet that reads the
variable; sugar-constraints names it only in `CassowarySolver`'s docblock
(as the legacy opt-in its shim answers to), and no other package consults it.

### Sharing regions across packages (`RegionBridge`)

`sugar-constraints` and `sugar-cellbuffer` each model a screen rectangle,
and `sugar-style` is the lowest package that depends on both. The layout
sub-namespace ships `Layout\RegionBridge` as the converter seam:

```php
use SugarCraft\CellBuffer\Position;
use SugarCraft\CellBuffer\Region as BufferRegion;
use SugarCraft\Constraints\Region as ConstraintsRegion;
use SugarCraft\Style\Layout\RegionBridge;

$constraintsRgn = new ConstraintsRegion(0, 0, 80, 24);
$bufferRegion   = RegionBridge::toBuffer($constraintsRgn);   // Position(0,0), 80×24
$roundTrip      = RegionBridge::toConstraints($bufferRegion); // same rectangle back
```

`toBuffer()` always succeeds. `toConstraints()` throws
`\InvalidArgumentException` when the buffer region carries a negative
origin — the constraints `Region` is non-negative-guarded by design, and
the bridge refuses to paper over that rather than silently clamping.

## Tables

```php
use SugarCraft\Style\Border;
use SugarCraft\Style\Table\Table;
use SugarCraft\Style\Style;

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
use SugarCraft\Style\Listing\{Enumerator, ItemList};
use SugarCraft\Style\Tree\Tree;

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

- **`Theme`** — the canonical colour palette: 10 named factories (`dark()` / `light()` / `dracula()` / `tokyoNight()` / `oneDark()` / `githubDark()` / `solarizedDark()` / `solarizedLight()` / `ansi()` / `adaptive()`) and 13 colour slots (`foreground` / `background` / `primary` / `secondary` / `accent` / `muted` / `error` / `warning` / `success` / `info` / `border` / `separator` / `cursor`). Every `with*($color)` setter returns a new `Theme`, and `Theme::catalog()` enumerates the factory names as a `list<string>` for programmatic discovery. A known polarity picks its palette through `Theme::for(bool $isDark)` without reading the environment, `adaptive()`'s optional `fallbackDark` knob chooses the no-signal fallback, and a semantic role is bound with `withStyle($role, $style)` and read back through `style($role)` (null when unbound) — see [Theme — canonical colour palette](#theme--canonical-colour-palette) below for the full `accent`/`muted` slot semantics, the single source of truth (SSOT) for theming across consumer libs.
- **`Style`** — the immutable value object. Style is the fleet's legacy
  builder: its setters are bare-named (`bold()`, `fg()`, `width()`) rather
  than `with*()`-prefixed, yet each returns a **new** `Style` — measured
  2026-10: 72 fluent setters, 0 `with*()` methods, 34 getters (`get*` /
  `is*`), 31 `unset*()`. Re-derive with reflection over non-static public
  methods (return-`self` = setter; `get*`/`is*` = getter; `unset*` =
  unsetter). Every fluent setter routes through the
  private 62-positional-parameter `with()` choke; `inherit()`/`patch()` mint the merged instance directly
  (`new self(...)`, `src/Style.php:1013`/`:1176`) — the memo-reset consequence
  holds either way (fresh instance). Props covered: fg/bg/border
  colours (incl. per-side), bold/italic/underline/strikethrough/faint/blink/
  rapidBlink/reverse/overline/invisible, padding/margin (1/2/4-arg shorthand
  + per-side) with `paddingChar()`/`marginChar()` fill glyphs, width/height,
  maxWidth/maxHeight, align (Align/VAlign), inline, transform, tabWidth,
  marginBackground, colorWhitespace. Plus `copy()` (shallow clone),
  `inherit($parent)` (unset-only merge), and
  `patch($other)` (incremental merge — only props set in $other are applied;
  a null colour/width/border in $other is skipped, not a clear).
  Bare-named methods exist because PHP cannot overload by arity: the short
  forms (`fg`, `bg`, `pad`, `mg`, `of`) are aliases of the long forms
  (`foreground`, …) — the same rationale upstream lipgloss gives for its
  `GetX` getter family.
- **`Border`** — `normal()`, `rounded()`, `thick()`, `double()`, `block()`,
  `ascii()`, `hidden()`, `markdownBorder()`. `Border::catalog()` enumerates the
  factory names as a `list<string>` for programmatic discovery. Per-side toggles
  via `Style::border*`. Titles via `withTitle()` (laid on the edge) or
  `withEmbeddedTitle()` (btop-style `─┐cpu┌─`); `embedJunctions()` and
  `seam()` answer junction runes — see
  [Embedded border titles + seam junctions](#embedded-border-titles--seam-junctions).
- **`Border\BorderGradientBlend`** — `fromColors(Color ...$colors)` accepts 1–5
  colors and returns a blend whose `sides(): list<Color>` (top / right / bottom /
  left) are interpolated around the perimeter. No setter takes the blend
  object: apply its four colours via the per-side
  `Style::borderTopForeground()` / `borderRightForeground()` /
  `borderBottomForeground()` / `borderLeftForeground()`.
  `Style::borderForegroundBlend($start, $end)` is a two-colour shorthand
  that builds the same perimeter interpolation internally.
- **`AdaptiveColor` / `CompleteColor` / `CompleteAdaptiveColor`** — value
  holders carrying per-profile (TrueColor / 256 / Ansi) and dark-vs-light
  colour choices. `Style` really does ship slots for them:
  `foregroundAdaptive()` / `backgroundAdaptive()` store a scheme pair,
  `foregroundComplete()` / `backgroundComplete()` store a per-tier trio,
  and `resolveAdaptive(bool $isDark)` / `resolveProfile()` collapse the
  stored choice into the concrete slot (`resolveProfile()` is zero-arg —
  it picks against the style's own `ColorProfile`). Two truths about
  resolution: an explicitly-set concrete `foreground()`/`background()`
  **wins** over the collapse (lipgloss precedence), and neither ritual
  is ambient at render — upstream lipgloss resolves lazily inside
  `Render()`, but this lib cannot see the terminal's background, so a
  forgotten `resolveProfile()` still paints the designer's pick (render
  collapses the complete triple against the style's own profile), while
  rendering with an uncollapsed *adaptive* pair throws `LogicException`
  naming the slot and the fix. Keep the axes straight:
  `Renderer::resolveAdaptive(AdaptiveColor): Color` is the dark-vs-light
  *scheme* pick, while `Style::resolveProfile()` is the colour-depth
  *tier* pick; `$c = ($r->lightDark())($light, $dark)` is a closure form
  of the scheme pick (`lightDark()` returns a `Closure(Color $light, Color $dark): Color`).
  See [Adaptive and complete colours](#adaptive-and-complete-colours--store-then-collapse)
  for a runnable store-then-collapse example.
- **`LightDark`** — pick helper for dark-bg vs light-bg colour schemes.
- **`Layout`** (package-level) — `place`, `placeHorizontal`, `placeVertical`,
  `joinHorizontal`, `joinVertical`, `joinHorizontalWithSpacing`,
  `joinVerticalWithSpacing`, `joinHorizontalWithDivider`,
  `joinVerticalWithDivider`, `tile`, `clipHead`, `clipTail`, `clipWidth`,
  `width`, `height`, `size` (all plain-string
  layout primitives from lipgloss). The lowercase spellings are canonical;
  capitalized calls resolve identically per the case-insensitivity note
  under **Layout helpers**.
- **`Position`** — `TOP / LEFT / CENTER / RIGHT / BOTTOM` floats for layout
  anchors (start/center/end values: `TOP` names *start* for vertical joins,
  `LEFT` for horizontal — the five anchors are three distinct values).
- **`Layout`** (sub-namespace) — ratatui-inspired constraint solver — the
  second of the lib's two `Layout` classes (the package-level one above is
  the primitive facade; this one is imported as
  `use SugarCraft\Style\Layout\Layout;`):
  `Layout::horizontal($constraints)->split($area)` and
  `Layout::vertical($constraints)->split($area)` return `Rect[]`.
  Constraints: `Constraint::length/min/max/percentage/ratio/fill`.
  State: `Rect` (x, y, width, height) + `Direction` (Horizontal/Vertical).
- **`Listing\ItemList`** + **`Listing\Enumerator`** — bullet, dash, asterisk,
  arabic, alphabet, roman, romanUpper, decimal, tree, none. Nested sublists +
  per-item style hooks.
- **`Tree\Tree`** + **`Tree\Enumerator`** — default / rounded / ascii
  connector sets; per-section style overrides; custom indenter.
- **`Table\Table`** — `headers` / `row(s)` / `border` /
  `headerAlign` / `rowAlign` / `styleFunc` / per-side border toggles /
  `width` / `offset` / `clearRows` / `data(Data)` / `wrap(?\Closure(string $cell,
  int $colWidth): list<string>)` (every returned line renders; a wrapped row
  spans several physical lines; `wrap(null)` clears wrapping).
- **`Table\Data`** — row-reader interface (`rows` / `columns` /
  `at($r, $c)`). Default impl `Table\StringData::fromMatrix(iterable)`.
- **`Output`** — top-level `print` / `println` / `sprint` / `printf` /
  `fprint($stream, …)` (style-agnostic).
- **`Renderer`** — per-writer rendering context: `withColorProfile` /
  `withHasDarkBackground` / `newStyle()` / `lightDark()` /
  `resolveAdaptive(AdaptiveColor)` / `fromEnvironment()`.
- **`Palette`** — the ANSI 16-slot palette as lowercase static factories
  (`Palette::red()`, `Palette::brightWhite()`, … plus a `gray()` alias and
  `Palette::all(): list<Color>`) returning `Color` instances — there are no
  `Palette::Red`-style *constants*, though a capitalized *call* such as
  `Palette::Red()` still resolves: it is a method call, and only the
  lowercase names are declared (same case-insensitivity note as under
  **Layout helpers**). `Palette::hasDarkBackground(?Color $background)`
  answers `true`/`false` for a *given* background colour and returns `null`
  when you pass `null` (meaning “unknown — don't switch schemes”); it does
  not probe the terminal by itself.
- **`UnderlineStyle`** — enum (None / Single / Double / Curly /
  Dotted / Dashed) for SGR `4:N` sub-style emit. Inert on its own: the
  `4:N` form is emitted only when `underline()` is also set.
- **`Hsl`** — CSS-style HSL factory: `Hsl::color($h, $s, $l)` (hue 0-360,
  saturation/lightness 0-100) and `Hsl::parse('hsl(200,80%,50%)')` for
  string input. Note the scale split with `Color::hsl()`, whose `s`/`l`
  take fractions 0-1 — see [HSL colour factory](#hsl-colour-factory) below.
- **`Markup`** — Rich-style `[tag]text[/]` parser returning `list<Cell>`.
  Distinct from `StyleParser` (which uses `[text](fg:red,bold)` syntax).
  Supports color names, fg:/bg: shortcuts, and bold/dim/italic/
  underline/reverse/strikethrough. A bracketed run that applies no effect
  (unknown word, empty tag) is re-emitted **verbatim** — text is never
  silently deleted; within an effective tag, unknown words are still
  ignored. Nesting beyond 128 open tags throws.
- **`StyleParser`** — `StyleParser::parse($input, $defaultStyle)` for the
  termui-style `[text](fg:red,bg:blue,bold)` inline form, returning
  `list<Cell>` (port of sugar-dashboard's inline parser). Both parameters
  are required; there is no one-argument form. Malformed-colour polarity:
  a `#`-prefixed value that fails to parse **throws** (it routes through
  `Color::hex`), while an unknown bare word is dropped and ignored.
- **`Cell`** — one styled grapheme produced by `Markup`/`StyleParser`.
- **`Canvas` / `Layer`** — multi-layer compositor: `Canvas::new()->addLayer(
  Layer::new($block)->withX(...)->withY(...)->withZ(...))->render()`
  composites rectangular styled blocks back-to-front by z-order (see
  `.vhs/canvas.gif`).
- **`Bar\StatusBar` / `Bar\HelpBar`** — one-line chrome widgets: StatusBar
  holds left/center/right `Segment` lists with separator/style/width knobs;
  HelpBar binds key→label pairs (`fromMap`) with key/label styles (see
  [StatusBar example](#statusbar-example) below).
- **`TitlePlacement`** — `@internal` border-title placement enum; not part of
  the public surface.

### StatusBar example

```php
use SugarCraft\Style\Bar\{Segment, StatusBar};
use SugarCraft\Style\Style;

echo StatusBar::new()
    ->width(80)
    ->style(Style::new()->fg('#cdd6f4')->on('#1e1e2e'))
    ->left('main()', Segment::of('2 modified', Style::new()->fg('#e0af68')))
    ->right('142 tests')
    ->render() . "\n";
```

Trap: a bar-wide background `style()` and per-segment styles can be
combined, but a per-segment style that ends in an SGR reset interrupts the
bar background from that segment on — the two modes (bar-wide colour vs
per-segment colour) are normally used one at a time.

### Adaptive and complete colours — store then collapse

`render()` reads only the concrete `Color` slots. A stored *complete*
triple is collapsed automatically against the style's own
`ColorProfile`, but the light-vs-dark `AdaptiveColor` pair needs an
ambient answer only the caller has: `resolveAdaptive(bool $isDark)`
picks light-vs-dark by the bool you pass, and rendering without it
throws `LogicException` (upstream lipgloss resolves lazily inside
`Render()`; this lib keeps the ritual explicit). A concrete
`foreground()` set up front is not overwritten by the collapse.

```php
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Style\Style;

$warm = Color::rgb(200, 0, 0);
$cool = Color::rgb(0, 200, 200);

// Scheme axis: store a pair, collapse with the dark-vs-light answer.
echo Style::new()
    ->foregroundAdaptive($warm, $cool)
    ->resolveAdaptive(isDark: false)
    ->of('red on dark schemes')
    ->render() . "\n";

// Tier axis: store three spellings, collapse against the style's profile.
echo Style::new()
    ->colorProfile(ColorProfile::Ansi)
    ->foregroundComplete(Color::rgb(0, 0, 200), Color::ansi256(21), Color::ansi(4))
    ->resolveProfile()
    ->of('blue at the 16-colour tier')
    ->render() . "\n";
```

## Custom Renderer & color-profile

`Style\Renderer` bundles a colour profile + a dark-background
flag so you can branch behaviour without threading the values
through every `Style` call:

```php
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Style\Renderer;

$r     = Renderer::new()
    ->withColorProfile(ColorProfile::Ansi256)   // forced 256-colour
    ->withHasDarkBackground(false);              // light terminal
$style = $r->newStyle()->bold()->foreground(Color::hex('#ff5f87'));
$pick  = $r->lightDark();                        // Closure(Color $light, Color $dark): Color
```

Each `Style` owns a `colorProfile()` setter too — the renderer is
just a convenience wrapper. `Renderer::fromEnvironment()` calls
`ColorProfile::detect()` for you (consults `NO_COLOR`,
`CLICOLOR_FORCE`, `TERM_PROGRAM`, etc.).

Output is returned as strings rather than written to a bound writer, so
there is no writer-carrying renderer constructor. Pair with
`Output::fprint($stream, ...)` when you need to write to a
specific stream.

## The ambient default profile — `Style::setDefaultProfile()` (process-global)

`Style::setDefaultProfile($profile)` is a **process-global mutable static**:
it changes the profile that every later `Style::new()` (and each private
`new self()` behind the setters) inherits — fleet-wide, including sugar-code's
TUI renderer and agent status paths, which use it to fold capability
detection into style creation. Pass `null` to restore TrueColor.

```php
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Style\Style;

Style::setDefaultProfile(ColorProfile::Ansi256); // everything after is 256-colour
Style::setDefaultProfile(null);                    // back to TrueColor
$was = Style::defaultProfile();                     // read what is in force
```

`defaultProfile()` returns `?ColorProfile` — `null` when nothing was
negotiated, i.e. the ambient TrueColor default is in force. `null`
round-trips harmlessly through the save/restore pattern below, but
dereferencing it (`$was->name`) on a fresh process is a fatal error on
`null`.

Because the setting leaks across object boundaries, **tests must save and
restore it** around any change:

```php
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Style\Style;

$had = Style::defaultProfile();
Style::setDefaultProfile(ColorProfile::Ascii);
// …assertions…
Style::setDefaultProfile($had); // always, e.g. in tearDown
```

## Color blending utilities

`Util\Color` ships the lipgloss-equivalent helpers:

| Method | Returns |
|---|---|
| `Color::hex('#ff5f87')` | RGB |
| `Color::ansi(13)` | named ANSI slot |
| `Color::ansi256(213)` | xterm-256 |
| `Color::parse('cyan')` / `Color::parse('bright-red')` | lookup by CSS/ANSI name |
| `Color::hsl($h, $s, $l)` | from HSL (0-360, 0-1, 0-1) — unlike `Hsl::color()` (percentages 0-100), `s`/`l` here are fractions 0-1; out-of-range values are normalized (hue wraps mod 360, s/l clamp), never throws |
| `$c->blend($other, $t)` | linear LERP, t ∈ [0, 1] |
| `$a->blend1d($b, $steps)` | list of N stops from $a to $b |
| `$tl->blend2d($tr, $bl, $br, $rows, $cols)` | 2D gradient grid |
| `$c->lighten($amount)` / `darken($amount)` | HSL lightness ±amount (clamped) |
| `$c->alpha($amount, $bg)` | composite (alpha-over) onto a backdrop; null backdrop = black; amount clamps to [0,1] |
| `$c->complementary()` | hue + 180° |

Named ANSI slots live on `Style\Palette`: the lowercase factories
`Palette::red()` … `Palette::brightWhite()` (16 slots + a `gray()` alias),
each returning a `Color`. `Palette::hasDarkBackground($bg)` answers
`$bg->isDark()` for a *given* background colour and returns `?bool` — pass
`null` when the terminal's background is unknown and it returns `null`
(“don't switch schemes”); it performs no terminal probing.

## HSL colour factory

`Style\Hsl` provides CSS-style HSL colour construction:

```php
use SugarCraft\Style\Hsl;

// Factory: hue 0-360, saturation 0-100, lightness 0-100
$sky = Hsl::color(200.0, 80.0, 50.0);

// Parse from hsl() string — handles '%' suffixes and whitespace
$teal = Hsl::parse('hsl(174, 70%, 40%)');
$blue = Hsl::parse('hsl(240, 100%, 50%)');
```

`Hsl::color()` normalizes rather than rejects: hue wraps modulo 360 and
saturation/lightness clamp to 0-100 — it never throws. This is the
percentage counterpart of `Color::hsl()`, whose `s`/`l` take fractions
0-1 (see the blending table above). `Hsl::parse()` returns **null** on
malformed input — guard before use (the example assigns unguarded).

## Inline markup parser

`Style\Markup` parses Rich-style `[tag]text[/]` markup into
`Cell` arrays — distinct from `StyleParser`'s `[text](fg:red,bold)`
syntax:

```php
use SugarCraft\Style\{Markup, Style};

$cells = Markup::parse('[bold]hello[/] world', Style::new());
$cells = Markup::parse('[red]error: [bold]oops[/][/]', Style::new());
$cells = Markup::parse('[fg:blue bg:yellow]blue on yellow[/]', Style::new());
```

Supported tags: colour names (black, red, green, yellow, blue,
magenta, cyan, white, bright-\*), bold, dim, italic, underline,
reverse, strikethrough, fg:color, bg:color.

## OSC-8 hyperlinks & underline styles

`Style::hyperlink($url, $id = '')` wraps the rendered output in
OSC 8 escapes — `\x1b]8;;URL\x1b\\` (with the `id=…` parameter segment
inserted only when `$id` is non-empty) and the no-op closing sequence —
so modern terminals turn it into a clickable link, while the rest strip
the sequences and fall through to plain text. URL and id pass through OSC
control-byte stripping, so host-supplied values cannot inject a second
hyperlink envelope. Parameter-splitting is a separate matter: a `;` in the id
would open a new OSC parameter and could relocate the destination, so
`hyperlink()` refuses ids containing `;` or `:` with `InvalidArgumentException`
(these characters are illegal in an id per the kitty OSC-8 grammar anyway).

For underlines, combine `Style::underline()` with
`Style::underlineStyle(UnderlineStyle::Curly)` (the SGR `4:N` sub-style)
and `Style::underlineColor(Color::hex('#ff0000'))` to ask for a wavy red
spell-check-style underline. Truth about the wire: sugar-style emits only
the extended form — **Terminals that understand SGR `4:N` render the
styled underline; terminals that don't typically ignore the extended form
and render no underline at all, since no plain SGR 4 companion is
emitted.** For a guaranteed plain underline, do not set a style: bare
`underline()` alone emits SGR 4.

## Style copy-vs-inheritance

Every `Style` setter returns a new `Style` — the receiver is
never mutated. `Style::copy()` returns a shallow clone for those
moments you want a known checkpoint to branch from.

`Style::inherit($parent)` merges every property the child has not
explicitly set — attribute props (`bold` / `italic` / `underline` /
`strike` / `faint` / `blink` / `reverse`, `fg` / `bg`, `borderFg` /
`borderBg`, …) *and* layout props (`width` / `height` / `padding` /
`margin` / `border` / `borderSides` / alignment). “Explicit wins”: a prop
counts as child-owned only once its setter has been called, tracked via a
per-instance `propsSet` ledger, so an unset child slot takes the parent's
value while a set child slot keeps its own. `unset*()` clears the ledger
slot again, so an un-set prop falls back to the parent on the next
inherit. This matches lipgloss's "explicit wins" inheritance rule.

## Theme — canonical colour palette

`Theme` is the single source of truth for terminal colour schemes
across SugarCraft consumer libs.

```php
use SugarCraft\Core\Util\Color;
use SugarCraft\Style\Theme;

// Pick a built-in theme
$dark = Theme::dark();
$tokyo = Theme::tokyoNight();
$dracula = Theme::dracula();

// Auto-detect from COLORFGBG: the last field's palette slot decides light vs
// dark by luminance (7/15 light, 0/8 dark); unset or unparseable → dark
$theme = Theme::adaptive();

// Enumerate every built-in theme name programmatically
$names = Theme::catalog(); // ['dark', 'light', 'dracula', …, 'adaptive']

// Override one or more colours (all with*() return new Theme)
$custom = $dark->withPrimary(Color::hex('#ff5f87'))
                ->withError(Color::ansi(1));

// Read colours — slots are Color objects (use toHex() to print them)
echo $custom->primary->toHex();      // #ff5f87
echo $custom->foreground->toHex();   // #c5c9d4 — kept from dark()
```

Available factories:

| Method | Palette |
|---|---|
| `Theme::dark()` | Dark; high-contrast **text** (measured fg/bg 11.6:1, AAA) — border/separator slots are decorative non-text (~1.5:1) by design |
| `Theme::light()` | Light theme |
| `Theme::dracula()` | Dracula |
| `Theme::tokyoNight()` | Tokyo Night |
| `Theme::oneDark()` | One Dark |
| `Theme::githubDark()` | GitHub Dark |
| `Theme::solarizedDark()` | Solarized Dark |
| `Theme::solarizedLight()` | Solarized Light |
| `Theme::ansi()` | Terminal ANSI 8-colour |
| `Theme::adaptive()` | Auto-detect via `COLORFGBG` env var |

All themes expose 13 colour slots: `foreground`, `background`,
`primary`, `secondary`, `accent`/`muted`, `error`, `warning`, `success`,
`info`, `border`, `separator`, `cursor`.

Slot semantics for `accent`/`muted`: they fall back to `primary`/`secondary`
only in `dark()`. In `light()`, `accent` equals `primary` but `muted` is its
own grey (`#6b7280`, while `secondary` is green `#10b981`), and in `ansi()`
both slots are distinct palette colours (primary cyan `#00cdcd` vs accent
magenta `#cd00cd`; secondary green `#00cd00` vs muted grey `#7f7f7f`). Every
richer named theme (dracula, tokyoNight, oneDark, githubDark,
solarizedDark/Light) sets them explicitly; consumers must NOT assume
`$theme->accent === $theme->primary`.

## Measurement utilities

`Style\Layout` (the package-level facade) exposes the three measurement
helpers from lipgloss's package level:

- `Layout::width($s)` — visible cell width of a (possibly
  ANSI-coloured, multibyte) string.
- `Layout::height($s)` — number of `\n`-separated lines.
- `Layout::size($s)` — `[width, height]` tuple.

(The capitalized `Width` / `Height` / `Size` spellings resolve to the same
functions per the case-insensitivity note under **Layout helpers** — only
the lowercase names are declared.)

All three strip ANSI escapes before measuring, honour East-Asian
wide cells, and round the same way `Style::render()` does — so
you can `Layout::width($style->render('xx'))` to budget a
component's footprint reliably.

## Table StyleFunc + per-section borders

`Table::styleFunc(\Closure(int $row, int $col): Style)` runs once
per cell to pick its style. Row index `Table::HEADER_ROW` (constant
`-1`) identifies the header.

```php
use SugarCraft\Style\Style;
use SugarCraft\Style\Table\Table;

$striped = Table::new()
    ->headers('Name', 'Score')
    ->rows([['Alice', '93'], ['Bob', '87']])
    ->styleFunc(static fn (int $r, int $c): Style =>
        $r === Table::HEADER_ROW
            ? Style::new()->bold()
            // italic carries the stripe; faint is decoration only
            : ($r % 2 === 0 ? Style::new()->faint()->italic() : Style::new()),
    );
echo $striped->render();
```

Seven border flags decide what draws: three section rules (`borderHeader` /
`borderRow` / `borderColumn`) and four sides (`borderTop` / `borderRight` /
`borderBottom` / `borderLeft`). Defaults: a bare `Table::new()` carries **no**
border — the frame appears only after an explicit `->border(Border::rounded())`
(or other `Border`). Once a border is set, the defaults are header rule +
column verticals on, row separators off.

## Embedded border titles + seam junctions

`Border::withTitle()` lays text straight onto an edge.
`Border::withEmbeddedTitle(string $text, ?TitleAnchor $anchor = null,
?string $open = null, ?string $close = null)` instead brackets it in
junction runes so it reads as cut into the line — btop's box titles and
clock placement are the antecedent design:

```php
use SugarCraft\Style\Border;
use SugarCraft\Style\Border\TitleAnchor;
use SugarCraft\Style\Style;

$border = Border::rounded()
    ->withEmbeddedTitle('cpu')
    ->withEmbeddedTitle('12:00', TitleAnchor::TopCenter)
    ->withEmbeddedTitle('mem', TitleAnchor::BottomLeft)
    ->withEmbeddedTitle('disk', TitleAnchor::BottomLeft);

echo Style::new()->border($border)->width(24)->render('load 0.42');
// ╭─┐cpu┌────┐12:00┌───────╮
// │load 0.42               │
// ╰─┘mem└─┘disk└───────────╯
```

Placement follows btop exactly:

- Left anchors sit one edge rune in from the corner (`╭─┐`); right anchors
  mirror it (`┌─╮`).
- Center anchors open at `floor(run/2) - floor(len/2)` into the full edge
  (btop's clock formula — one cell right of true centre on even/even sizes,
  kept on purpose), falling back to the leftover space only when that would
  overlap a corner title.
- Several embeds on one anchor are glued by one edge rune (`┘mem└─┘disk└`).
- Overflow shrinks the text with a trailing `…`; an embed with no room for
  one text cell plus both junctions is dropped and the edge stays plain.
- An empty `$text` is a no-op; the text is painted verbatim inside the
  edge's title colour, so pass pre-styled text for bold titles.
- `$open` / `$close` override the junctions and must each be exactly one
  cell wide (`\InvalidArgumentException` otherwise).

`Border::embedJunctions(bool $bottom = false): array{string, string}`
returns the default `[open, close]` pair, taken from the border's own
corners with rounded arcs squared off as btop does: `┐┌` / `┘└` for
normal + rounded, `┓┏` / `┛┗` thick, `╗╔` / `╝╚` double, `++` ascii.

`Border::seam(bool $left, bool $right, bool $up, bool $down): string`
answers the rune where lines leave a point in the given directions —
the junction an internal divider makes where it meets the frame (btop's
`div_left ├` / `div_right ┤` / `div_up ┬` / `div_down ┴` law). Every
answer comes from the border's own runes, so the family is preserved:

```php
use SugarCraft\Style\Border;

Border::normal()->seam(left: true, right: true, up: false, down: true); // ┬
Border::thick()->seam(left: false, right: true, up: true, down: true);  // ┣
Border::double()->seam(true, true, true, true);                         // ╬
```

Two-arm turns return the corners as-is (rounded keeps its arcs), a
single arm degrades to the straight edge rune on that axis, and no arms
is a blank cell.

## Graceful colour degradation

A `Style` carries a `ColorProfile` (TrueColor / Ansi256 / Ansi /
Ascii / NoTty). `render()` downsamples every colour to that tier before
emit, matching the degradation a color-profile writer applies:

```
TrueColor  → SGR 38;2;R;G;B  (24-bit)
Ansi256    → SGR 38;5;N      (xterm-256 nearest match)
Ansi       → SGR 30..37 / 90..97  (named slots)
Ascii      → no colour; bold/italic/underline/… and OSC 8 links kept
NoTty      → no escape sequence at all (clean text, layout kept)
```

The tier rewrites apply to RGB colours; palette-slot colours
(`Color::ansi(n)`) always emit their `30..37`/`90..97` form at every
tier — measured at TrueColor, `Color::ansi(13)` still emits `ESC[95m`.

`Ascii` (what `NO_COLOR` selects) forbids colour, not emphasis, so text
attributes and hyperlinks survive. `NoTty` means the output is not a
terminal: the whole render — including
escapes carried in by pre-styled content, a `transform()` callback or a
border title — is stripped, while padding, borders and alignment stay.

One measured exception to "underline colour tints the underline": SGR `58`
has no 4-bit palette spelling, so at the `Ansi` tier `underlineColor()`
falls back to the *foreground* slot and recolours the glyphs —
`Style::new()->colorProfile(ColorProfile::Ansi)->underline()->underlineColor(Color::hex('#ff0000'))->of('x')->render()`
emits `ESC[4m ESC[91m x ESC[0m` (bright-red text, plain underline). At
TrueColor/Ansi256 the `58;2;…`/`58;5;…` underline colour is emitted as
documented and the text keeps its own colour.

Use `Style::colorProfile(ColorProfile::Ansi)` for a forced
downgrade or `Renderer::fromEnvironment()` to auto-detect from
`NO_COLOR` / `CLICOLOR` / `CLICOLOR_FORCE` / `FORCE_COLOR` / `TERM` /
`COLORTERM` / `TERM_PROGRAM` / `WT_SESSION` / CI markers. `CLICOLOR=0`
(the BSD-family colour opt-out) is honoured: it downgrades to `Ascii`,
ranked below `NO_COLOR`, the two force variables, the non-tty check and
`TERM=dumb`, but above the `TERM`/`COLORTERM` capability reads.
`CLICOLOR=1` asserts no extra capability — detection still reads
`TERM`/`COLORTERM` behind it.

## Trust and sanitisation

`sugar-style` is a **renderer, not a sanitizer** — by fleet design it never
strips hostile bytes from the content it wraps:

- ESC / OSC sequences carried in content pass through untouched (they are
  part of width arithmetic — `render()` must measure what a terminal will
  see). `Style::hyperlink()` adds one targeted guard on top of core's OSC
  control-byte stripping: ids containing `;` or `:` are refused so a
  host-supplied id cannot relocate a link destination.
- `\t` is expanded per `tabWidth()` (default 4), `\n` is a structural line
  break, and `\r` is never collapsed or stripped.
- Wrapping **pre-styled content** (`$outer->of($inner->render())`) composes
  at the byte level: where the inner block emits its own reset (`ESC[0m`),
  the outer style is **not** re-applied to the remainder — attributes are
  lost from that point on, and `width()` truncation can make the loss
  visible. Keep style composition to one pass, or re-render inner content
  under the outer style.

Untrusted strings (tool output, prompts, file names) must be sanitized
**before** being styled, at the consumer boundary — the fleet law, honoured
by e.g. sugar-navstack (`NavStack` / `Breadcrumb`), sugar-overlay (`Veil`),
sugar-gallery, sugar-quickpick and sugar-readline, which route ingress
through `Sanitize::untrusted()` / `Sanitize::untrustedForMarkedFrames()`.
The same applies one layer out: refusal messages quote the offending value
verbatim (e.g. `Color::hex` echoes the bad string into
`invalid hex color: {hex}`) — treat caught throw text as untrusted if you
render it.

## Internationalisation

User-facing runtime messages are translated through the core `T`
lookup (`SugarCraft\Core\I18n\T`; exact match → base language → `en` →
raw key). The shipped catalogue is **16 locales** under `lang/`
(`ar` `cs` `de` `en` `es` `fr` `it` `ja` `ko` `nl` `pl` `pt` `pt-br`
`ru` `tr` `zh-cn`), `en.php` being the source of truth, and it carries
**12 keys**: the width/height/max-width/max-height/tab-width/label
non-negative guards, `style.hyperlink_id_delimiter`,
`style.markup_depth`, `style.border_toggles_count`,
`style.expand_sides_count`, `table.width_nonneg` and
`tree.offset_nonneg`. Call sites: `src/Style.php`, `src/Markup.php`,
`src/Table/Table.php`, `src/Tree/Tree.php`, `src/Bar/HelpBar.php` and
`src/Bar/StatusBar.php`. Locale selection is the core's: `T::setLocale()`
is explicit, otherwise `T::detect()` consults `LC_ALL` → `LC_MESSAGES` →
`LANG` (sugar-core `src/I18n/T.php`). One deliberate exception: the
constraint layer under `src/Layout/` speaks hard-coded English — its
error strings are not routed through `Lang` (see the disclaimer in the
Layout section above).

## Accessibility

Colour alone must never carry meaning (WCAG 1.4.1): pair colour-coded
state with text, an icon+label, or structure. For constrained displays
this library offers honest degradation paths — `ColorProfile::Ascii`
(`NO_COLOR`) drops the palette but keeps text attributes emitting, and
OSC 8 hyperlinks survive it, so emphasis- and link-based affordances
still read. (`Theme::ansi()` is not one of these paths: it only changes
which palette slots colours resolve to — it neither drops nor keeps
attributes and never touches links.) `ColorProfile::NoTty` strips every
escape yet preserves padding, borders and alignment, so a piped capture
keeps the document's visual structure. Border *styles* (rounded vs
double vs thick) are decorative: never encode a state distinction in the
border family alone — combine it with a title or colour slot. Adaptive
colours (`AdaptiveColor` / `Theme::adaptive()`) track dark-vs-light
terminals so foreground/background contrast doesn't silently invert on
scheme change — but `Theme::adaptive()`'s tracking is a heuristic: it
reads `$COLORFGBG` only, and an unset, non-numeric or out-of-range last
field selects the dark scheme silently. Whichever scheme wins, verify
pairs against the WCAG AA floors — 4.5:1 for text, 3:1 for non-text
items such as borders and icons.

Emitting an attribute is not the same as using it safely, and three
need care. SGR `5`/`6` (`blink()` / `rapidBlink()`) fail WCAG 2.2.2
(pause, stop, hide) and 2.3.1 (three flashes): a terminal cannot offer
the user a way to pause or stop them, and rapid blink is strictly worse
— treat both as content you cannot deliver accessibly. SGR `8`
(`invisible()`) hides text from sight but not from selection, copy or a
screen reader's buffer — anyone who copies the line recovers what the
display withheld, so concealment is never a secret mechanism. SGR `2`
(`faint()`) lowers contrast off the AA floors by design: never make it
the sole carrier of a distinction (the zebra-striping example above
stripes with *italic* and keeps faint as decoration only).

## Performance — build once, render many

A `Style` instance is a value, and building one is the expensive half of
the pipeline: `Style::new()->border(Border::rounded())->padding(1, 2)`
costs roughly **twice** what `render()`ing a pre-built style costs
(measured on a dev box 2026-10: ~16.5 µs/op to rebuild per frame vs
~8.4 µs/op to `of()`+`render()` a reused instance, and reused instances
additionally benefit from the internal `render()` memo — see the class
docblock). Re-layout-per-frame TUIs should construct each style once at
setup (or keep a small keyed cache) and call `render()` per frame, not
rebuild the chain each time. `copy()` carries a still-valid memo; every
setter and `inherit()` correctly resets it, so only the repeated-render
path pays off.

On the layout side, `Solver::solve()` intentionally constructs a fresh
solver per call (the layout layer is stateless) — what the process-level
memoization removes is the `getenv()` decision, not the allocation
(measured: `SolverFactory::fromEnvironment()` ~350 ns/op after, ~470
ns/op with a per-call `getenv()`, dev box 2026-10).

## Accessor era (2026-10-24)

The fleet-wide bare-accessor sweep landed two renames in sugar-style —
reads whose bare names were free, each keeping a one-line deprecated
forwarder marked `REMOVED-IN: 2.0.0`:

| legacy read (kept as deprecated forwarder) | canonical bare read |
| --- | --- |
| `getDefaultProfile()` (static ambient read) | `defaultProfile()` — the `setDefaultProfile()` write keeps its name; no bare slot collided |
| `getBorderSides()` | `Style::borderSides()` |

`Border::getTitles()` already delegated to the bare `titles()` accessor
before this era and stays as found. Existing callers keep compiling
through the deprecated aliases.

### Exceptions — the 31 setter-blocked reads

The remaining **31 accessors are permanent legal exceptions** (owner
ruling 2026-10-24): for each one the bare twin is a live fluent setter,
and PHP admits one method per name — so the `get*` spelling is the
canonical permanent name of the read, neither renamed nor deprecated.
Every exception carries a one-sentence docblock naming its colliding
setter, and `tests/BareAccessorEraTest.php` rosters all 31 with
per-method setter evidence (the discovery walk, sentinel fixture,
staleness arms, BC identity, and this section's tally are pinned by it).
`Style.php` additionally frames the family in its class-level NAMING
EXCEPTION paragraph.

`Style.php` (21) — each read is blocked by its same-named fluent setter:

| read | colliding setter |
| --- | --- |
| `getUnderlineColor()` | `underlineColor(?Color $c): self` |
| `getUnderlineStyle()` | `underlineStyle(UnderlineStyle $style): self` |
| `getPaddingChar()` | `paddingChar(string $char): self` |
| `getMarginChar()` | `marginChar(string $char): self` |
| `getHyperlink()` | `hyperlink(string $url, string $id = ''): self` |
| `getForeground()` | `foreground(?Color $c): self` |
| `getBackground()` | `background(?Color $c): self` |
| `getWidth()` | `width(?int $w): self` |
| `getHeight()` | `height(?int $h): self` |
| `getMaxWidth()` | `maxWidth(?int $w): self` |
| `getMaxHeight()` | `maxHeight(?int $h): self` |
| `getAlign()` | `align(Align $h): self` |
| `getVerticalAlign()` | `verticalAlign(VAlign $v): self` |
| `getBorder()` | `border(?Border $b, bool ...$sides): self` |
| `getBorderForeground()` | `borderForeground(?Color $c): self` |
| `getBorderBackground()` | `borderBackground(?Color $c): self` |
| `getColorProfile()` | `colorProfile(ColorProfile $p): self` |
| `getTabWidth()` | `tabWidth(int $w): self` |
| `getMarginBackground()` | `marginBackground(?Color $c): self` |
| `getPadding()` | `padding(int ...$sides): self` |
| `getMargin()` | `margin(int ...$sides): self` |

`HelpBar` (5):

| read | colliding setter |
| --- | --- |
| `getKeySeparator()` | `keySeparator(string $separator): self` |
| `getSeparator()` | `separator(string $separator): self` |
| `getKeyStyle()` | `keyStyle(?Style $style): self` |
| `getLabelStyle()` | `labelStyle(?Style $style): self` |
| `getWidth()` | `width(?int $width): self` |

`StatusBar` (5):

| read | colliding setter |
| --- | --- |
| `getSeparator()` | `separator(string $separator): self` |
| `getWidth()` | `width(?int $width): self` |
| `getStyle()` | `style(?Style $style): self` |
| `getCaps()` | `caps(string $left, string $right): self` |
| `getFillChar()` | `fillChar(string $char): self` |

## Testing

```sh
cd sugar-style && composer install && vendor/bin/phpunit
```

The suite is around 1,000 tests and over 3,400 assertions, green at the
2026-11-03 docs-fidelity lane close (cited figure is a point-in-time
measurement, not a live-pinned number).

## Demos

### Border styles

![The same text block rendered under every built-in border style](.vhs/border.gif)

### Canvas (multi-layer compositor)

![A styled popover layer composited over a base layer](.vhs/canvas.gif)

### Layout dashboard

![A dashboard assembled from horizontal and vertical joins with placed panes](.vhs/dashboard.gif)

### Constraint-based layout dashboard

![Panels sized live by Length, Min, Max, and Percentage constraints](.vhs/constraint-dashboard.gif)

### List

![Three list flavours with default, custom, and nested enumerators](.vhs/list.gif)

### Style

![Foreground, background, bold, italic, underline, strikethrough, and adaptive colour on headline text](.vhs/style.gif)

### Table

![A rounded-border table with custom alignment and alternate-row shading](.vhs/table.gif)

### Tree

![A project structure rendered under three enumerator styles](.vhs/tree.gif)

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
