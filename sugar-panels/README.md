<img src=".assets/icon.png" alt="" width="160">

<!-- BADGES:BEGIN -->
[![build status](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![test coverage](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-panels)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-panels)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-panels?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-panels)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP version](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarPanels

> Formerly published as `sugarcraft/sugar-boxer` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Boxer\` → `SugarCraft\Panels\`. <!-- @rename-keep -->

sugar-panels — a box-drawing layout engine that composes terminal content into horizontal and vertical panel layouts with borders and padding, for PHP 8.3+.

## Features

- **H/V composition** — build arbitrary layouts by nesting `horizontal()` and `vertical()` panels
- **Box-drawing borders** — Unicode box-drawing glyphs (╭ ╮ ╰ ╯ │ ─), any sugar-style `Border` style
- **No-border mode** — `noBorder()` flattens a subtree: neither its frames nor its dividers are painted, so adjacent panels touch (to hide one box's own frame without touching its neighbours' rules, use `withBorder(false)`)
- **Leaf padding** — inner whitespace around painted content; containers ignore it, their children fill the box
- **ANSI-aware content** — styled/coloured leaf text places by *visible* columns: escape sequences ride with the grapheme they style (zero width), wide graphemes keep their two columns, and a clipped or unbalanced span is auto-reset so colour never bleeds past the box
- **Word-wrap at paint time** — a leaf line wider than its region is re-wrapped to fit at cell boundaries; over-long words split at grapheme boundaries, and only height overflow is dropped (see *Small viewports*)
- **Flex / grow children** — `withFlex(int)` / `withGrow()`: a fixed child claims its **natural size** (widest visible line, floored by `minWidth`), a flex child fills the leftover by weight — so "sidebar = fixed, content = FILL" works without hand-counting columns
- **Dimension hints** — `min*` floors the natural size, `max*` caps the node's **total footprint** (frame + padding + content), and the cap is what a flex basis claims
- **Natural sizing** — `totalWidth()` / `totalHeight()` report the footprint a node asks for, measured from content
- **Constraint-solved sizing** — flex distribution and the legacy `min*` hint split share one engine: `SugarCraft\Constraints\GreedySolver` from `sugarcraft/sugar-constraints` solves both dispatch paths, so a row's child regions tile the available span by the same arithmetic
- **Leaf content** — leaf nodes take `string` (cast a `Stringable` yourself; the signature is strict)
- **Stateful diff renderer** — first paint and every resize emit the full frame; later same-size renders emit only diff ops from the per-instance frame cache. Every frame with at least one column is self-positioning (see *Frame protocol*). Call `resetPreviousFrame()` whenever terminal state may be unknown (clear, alt-screen switch, text capture). Outputs plain ANSI strings; works with any TUI framework
- **Immutable nodes** — every `with*()` returns a *new* `Node`; an existing node is never modified, so keep the chained result

## Where it fits

Nothing in this monorepo requires sugar-panels today — the in-repo consumer
count is zero, and the only sibling references are comments (a sugar-image
block-rendering regression test, the sugar-flexbox test parity roster, and a
sugar-table docblock using a panels content box as its example). It ships for
*your* application, as the pane composer of the ecosystem.

When an adjacent library does a similar-looking job:

- Need the **CSS flexbox computation** itself (a node tree solved for boxes,
  no painting) → `sugar-flexbox`. sugar-panels carries its own lighter H/V +
  flex split and paints the result.
- Need **modal foregrounds over existing content** (dimming, zone hit-testing)
  → `sugar-overlay`. It composites layers; sugar-panels tiles one region.
- Need a **tabular data grid** (row cursor, scrolling, per-cell styling) →
  `sugar-table`. It renders the table; sugar-panels frames the panes around it.

For nested horizontal/vertical panes with borders, titles and flex weights,
painted as plain ANSI strings, sugar-panels is the library.

## Install

```bash
composer require sugarcraft/sugar-panels
```

Snippets assume the Composer autoloader (`require 'vendor/autoload.php';`).

## Quick Start

```php
use SugarCraft\Panels\SugarPanels;

$panels = SugarPanels::new();

$layout = $panels->vertical(
    $panels->horizontal(
        $panels->leaf("Left panel"),
        $panels->leaf("Right panel"),
    ),
    $panels->leaf("Bottom bar"),
);

echo $panels->render($layout, 40, 5);
```

That prints (protocol and styling stripped — see *Frame protocol*):

```text
╭──────────────────────────────────────╮
│──────────────────────────────────────│
│╭────────────────────────────────────╮│
│╰────────────────────────────────────╯│
╰──────────────────────────────────────╯
```

Every node is bordered **by default**, including containers — so a nested layout
paints a frame at each level, and at five rows the inner boxes are squeezed to
nothing. Containers are usually scaffolding, so turn their frames off, and give
the viewport enough rows to hold what you asked for:

```php
use SugarCraft\Panels\SugarPanels;

$panels = SugarPanels::new();

$layout = $panels->vertical(
    $panels->horizontal(
        $panels->leaf("Left panel"),
        $panels->leaf("Right panel"),
    )->withBorder(false)->withGrow(),
    $panels->leaf("Bottom bar")->withBorder(false),
)->withBorder(false);

echo $panels->render($layout, 60, 7);
```

```text
╭────────────────────────────╮╭────────────────────────────╮
│Left panel                  ││Right panel                 │
│                            ││                            │
│                            ││                            │
│                            ││                            │
╰────────────────────────────╯╰────────────────────────────╯
Bottom bar
```

The horizontal row carries `withGrow()`, so it flexes to six of the seven rows
while `Bottom bar` — the fixed child — claims its measured natural single row at
the bottom. That claiming runs on the flex pass and needs at least one flexing
sibling; with no flex child at all the split is proportional instead (see *Flex
and natural sizes*). Every row the renderer emits is padded to the viewport
width, `Bottom bar`'s included.

The doubled `││` seam between the boxes is the documented frame rule (each panel
draws its own edge; borders are never merged into junction glyphs — the
divergence from the antecedent is deliberate, see *Border Characters*).

## Flex and natural sizes

A child with `flex === 0` is sized from its own content, so a sidebar does not
need a hand-picked width:

```php
use SugarCraft\Panels\SugarPanels;

$panels = SugarPanels::new();

$dashboard = $panels->horizontal(
    $panels->leaf("nav\nhome\nabout")->withBorder(false),
    $panels->leaf("main content area")->withBorder(false)->withGrow(),
)->withBorder(false)->withSpacing(1);

echo $panels->render($dashboard, 40, 3);
```

```text
nav   main content area
home
about
```

The sidebar is five columns wide because `about` is its widest line, then comes the
one column of spacing — six columns are consumed before `main` starts on column
seven. Escape runs cost no columns
(`Width::string` sees the visible text), and a CJK glyph charges two.

## Layout API

```php
use SugarCraft\Panels\SugarPanels;

$panels = SugarPanels::new();

// Leaf node with string content
$leaf = $panels->leaf('Hello, World!');

// Horizontal split (side by side)
$row = $panels->horizontal($leaf, $panels->leaf('second'));

// Vertical split (stacked)
$column = $panels->vertical($row, $leaf);

// Node with explicit dimensions. Every with*() returns a NEW node — a chain
// whose result is discarded has no effect on the original.
$sized = $panels->leaf('Panel')
    ->withMinWidth(20)
    ->withMaxWidth(80)
    ->withMinHeight(5)
    ->withMaxHeight(40)
    ->withPadding(1)              // inner padding
    ->withBorder(true)            // show box border (this is also the default)
    ->withSpacing(1);             // gap between children

// No-border (flat) layout: frames and dividers inside the subtree are dropped
$flat = $panels->noBorder($column);

echo $panels->render($sized, 40, 10);
echo $panels->render($flat, 40, 4);
```

## Styling with sugar-style

sugar-panels composes canonical styling primitives from
[`sugar-style`](https://github.com/sugarcraft/sugar-style):

```php
use SugarCraft\Core\Util\Color;
use SugarCraft\Panels\SugarPanels;
use SugarCraft\Style\{Align, Border, Style, VAlign};

$panels = SugarPanels::new();

// Start from a plain leaf; every with*() returns a NEW node, so reassign.
$node = $panels->leaf('Styled panel');

// Set the border character set — Border::normal(), rounded(), thick(),
// double(), block(), ascii(), hidden(), markdownBorder(); Border::catalog()
// lists them. Passing null clears the explicit style but preserves border
// visibility.
$node = $node->withBorderStyle(Border::rounded());  // ╭ ╮ ╰ ╯ │
$node = $node->withBorderStyle(Border::double());   // ╔ ╗ ╚ ╝ ║ ═
$node = $node->withBorderStyle(null);               // clear explicit style

// Apply foreground/background colours and attributes via Style
// (Style's constructor is private — build through the fluent API)
$node = $node->withStyle(Style::new()->fg(Color::parse('cyan'))->bg(Color::parse('black')));

// Box title text rendered in the top border
$node = $node->withTitle('My Panel');

// Outer spacing (top, right, bottom, left) — sugar-panels-specific
$node = $node->withMargin(1);                  // all sides
$node = $node->withMargin(1, 2);               // top/bottom=1, left/right=2
$node = $node->withMargin(1, 2, 1, 2);         // explicit all four

// Text alignment within the content area (default is left / top)
$node = $node->withAlignH(Align::Center);
$node = $node->withAlignH(Align::Right);
$node = $node->withAlignV(VAlign::Middle);
$node = $node->withAlignV(VAlign::Bottom);

echo $panels->render($node, 40, 10);
```

A container's `withBorderStyle()` picks the glyphs of its own frame; `withStyle()`
colours only leaf content — a style set on a container never reaches the frame
glyphs, they are always painted unstyled. Content styling belongs to the leaf.
There is no focus or active-panel facet — see
*Accessibility*.

## API Reference

### `SugarPanels`

| Method | Description |
|--------|-------------|
| `$panels->leaf(string)` | Leaf node with string content |
| `$panels->horizontal(Node ...)` | Horizontal (row) layout |
| `$panels->vertical(Node ...)` | Vertical (column) layout |
| `$panels->noBorder(Node)` | Flatten a subtree: no frames, no dividers (contrast `withBorder(false)`, which hides one box's frame only) |
| `$panels->render(Node, int $width, int $height)` | Paint one frame; throws `InvalidArgumentException` on a viewport outside the documented ranges |
| `$panels->resetPreviousFrame()` | Drop the frame cache — next `render()` repaints full, without erasing |
| `SugarPanels::MAX_VIEWPORT_DIMENSION` | 4096 cells — largest viewport side accepted |
| `SugarPanels::MAX_VIEWPORT_CELLS` | 1 000 000 cells — largest viewport area accepted |

### `Node`

| Method | Description |
|--------|-------------|
| `Node::leaf(string)` | Static leaf constructor |
| `Node::horizontal(Node ...)` | Static horizontal constructor |
| `Node::vertical(Node ...)` | Static vertical constructor |
| `Node::noBorder(Node)` | Static no-border constructor |
| `$node->totalWidth()` / `totalHeight()` | Footprint the node claims: natural content, floored by `min*`, capped by `max*`, plus padding, frame and margin |
| `->withMinWidth(int)` / `->withMinHeight(int)` | Floor for the natural size. That floor enters the flex path through fixed children's bases; a grown child gets only its weighted share, its `min*` is not applied, and `totalWidth()` / `totalHeight()` over-claim for grown children; in the legacy equal-split fallback, a `min*` acts as a proportional **weight**, not a floor |
| `->withMaxWidth(int)` / `->withMaxHeight(int)` | Cap on the node's painted footprint (frame + padding + content). `0` means no cap. Margin rides **outside** the cap: the claimed footprint is cap + margin, and `totalWidth()` / `totalHeight()` say so |
| `->withPadding(int)` | Inner padding (cells) — leaf nodes only; ignored on containers |
| `->withBorder(bool)` | Show/hide **this node's own** frame — **on by default** for leaves and containers; the chosen style survives a `false → true` round-trip (contrast `noBorder()`, which flattens a whole subtree) |
| `->withSpacing(int)` | Gap between children (cells). At `0` the divider shares the previous child's boundary, and it declines to overwrite a glyph that is already painted |
| `->withContent(string)` | Replace leaf content (new node) — a container paints its children, so content stored there is inert |
| `->withFlex(int)` | Fill leftover space by weight (0 = fixed, natural size) |
| `->withGrow()` | Shorthand for `withFlex(1)` |
| `->withBorderStyle(?Border)` | Border char set from sugar-style |
| `->withStyle(?Style)` | Style (colour, attributes) from sugar-style |
| `->withTitle(string)` | Box title text, painted into the top border (one line; newlines are flattened) |
| `->withMargin(int $top, ...)` | Outer margin (top/right/bottom/left) |
| `->withAlignH(?Align)` | Horizontal text alignment (null clears) |
| `->withAlignV(?VAlign)` | Vertical text alignment (null clears) |

## Border Characters

```
╭────┬────╮   ← top-left, top horiz, top-right, cross
│    │    │   ← vert bar
├────┼────┤   ← left-join, cross, right-join
╰────┴────╯   ← bottom-left, bottom horiz, bottom-right
```

Each panel draws its own frame — the renderer does **not** merge adjacent
borders into the junction glyphs (├ ┤ ┬ ┴ ┼) sketched above. Where two bordered
panels meet, the neighbour's left/top edge characters stand in for the join, so a
nested layout reads as doubled `││` seams rather than crosses.

**Divergences from the antecedent (recorded 2026-11):** this per-node framing is
the biggest one — upstream treilik/bubbleboxer places separator rules *between*
panels and shares edges, while sugar-panels frames every node individually
because border presets, titles and margins are per-node properties that a shared
edge cannot express.

At `withSpacing(0)` the divider has no gap of its own and shares the previous
child's boundary. It never paints over a visible glyph, and the verdict is taken
for the whole span at once: a bordered neighbour's own edge is the separator (its
`╮`/`╯` corners survive), a neighbour whose line simply ended early keeps its text
row intact instead of collecting half a rule across the padding, and in a
borderless layout the divider appears where a column or row is genuinely free —
which means a zero-gap layout can legitimately show no divider at all.

## Buffer diffing

The renderer keeps the previous frame's cell grid across renders and rebuilds a
`Buffer` from it (widths re-derived per cell, so wide graphemes and embedded SGR
spans stay intact), computes `current->diff(previous)` (from
[sugar-cellbuffer](https://github.com/sugarcraft/sugar-cellbuffer)), and emits only
the delta ANSI ops through the cellbuffer `DiffEncoder`. The current frame then
replaces the stored one for the next render.

**Frame protocol — every frame with at least one column is self-positioning.**
Such a frame opens
with a cursor home (`ESC [ H`), because diff ops address cells absolutely and the
encoder omits the move when the first change is already at (0,0): without the
home, a frame paints wherever the last one happened to leave the cursor. A full
frame that follows a **resize** additionally opens with an erase-in-display
(`ESC [ 2J`), since cells outside the new viewport are never visited and would
otherwise sit on screen as ghosts. The first frame, a delta, and a zero-area frame
erase nothing, and a manual `resetPreviousFrame()` does not erase either — a host
that owns a region did not ask to wipe it.

**Region hosts:** that home is the absolute screen row 1 column 1, and a *delta*
addresses every run with absolute CUPs — deltas are only safe when the renderer
owns the whole viewport. A host painting a sub-region of a bigger terminal (a
band in a dashboard, a pane beside another renderer's output) must call
`resetPreviousFrame()` before every render, strip the leading `ESC [ H` (and the
`ESC [ 2J` resize prologue when present), prepend its own cursor move to the
region origin, and write the rest verbatim: a full frame positions relatively
after that one token — cells contiguous, rows newline-separated — so it cannot
pull the cursor out of the region.

**Stateful contract:** the diff cache lives on the `SugarPanels` instance, and one
instance owns one output region. The first render (and every render after a size
change) emits the full frame; subsequent same-size renders emit only diff ops —
ops that make sense only against the screen still showing what *this* renderer
last painted there. Interleaving two renderers into one stream, or writing other
output between frames, merges the renderings. If the terminal may have been
touched outside this renderer — a clear, an alt-screen switch, another program —
call `resetPreviousFrame()` so the next render repaints fully.

**SSH bandwidth + flicker win:** a one-character change in a viewport produces a
handful of delta bytes instead of a full-screen repaint, so an SSH session carries
far less per-frame data and the full-screen flicker of rewrite-based terminals
disappears. The first render after startup or a resize is a full frame (no diff is
possible before it), which is why a new instance and a manual reset always paint
the whole grid.

## Input ranges

| Input | Behaviour |
|-------|-----------|
| `render()` viewport width/height | Negative, or larger than `MAX_VIEWPORT_DIMENSION` per side, or more than `MAX_VIEWPORT_CELLS` in total → `InvalidArgumentException` **before** anything is allocated. A viewport is host input (a winsize read, a tmux resize), so the failure is loud rather than an out-of-memory death |
| `render()` zero width or height | Valid: nothing paints, no escape bytes are emitted, and no diff state is left behind — a collapsed pane cannot poison the next size. The raw shape is degenerate though: zero height returns the empty string, zero width returns `height − 1` bare newlines — still no protocol, but a host that writes them to a real terminal will scroll |
| `withMin*` / `withMax*` / `withPadding` / `withSpacing` / `withMargin` / `withFlex` | Negative values clamp to `0`; `max*` of `0` means "no cap". Nothing throws — a hint is a hint |
| A container whose children collapse to zero columns | The container's own frame is painted first, then the children are skipped, so the layout still reads as a panel |
| Leaf / title content | Repaired, not rejected (see *Trust boundary*) |

## Small viewports

Content that does not fit is handled quietly — but quietly is not the same as
dropped. A line wider than the interior is **word-wrapped** at cell boundaries:
it reflows on spaces, and a single word still too long splits at grapheme
boundaries (a grapheme is atomic, so one wider than the region — a CJK glyph in
a one-column box — is emitted as its own oversized chunk and then clipped).
Only *height* overflow is dropped without a trace: the wrapped continuations
consume the row budget, and lines past the interior height are not painted at
all. A bordered box given fewer than two rows or two columns declines to draw its
frame at all — a box one cell wide or tall paints nothing — yet its parent still
counts the one-cell slot the node measured, so the space goes to an invisible
box rather than degrading to a visibly truncated rule. Nothing warns, because the renderer has no channel to
warn on and a TUI host repainting per frame does not want one. One hazard rides
the reflow: a line that already fits passes through verbatim, but a line that
needed re-wrapping is re-joined with single spaces — an intentional run of
whitespace (column alignment in a table, padded key bindings in a hint)
therefore collapses on wrapped lines and not on their neighbours. If alignment
matters, pre-wrap content narrower than the region.

Ask the model first: `totalWidth()` / `totalHeight()` return the footprint a node
claims **before word-wrap** — the unwrapped rectangle. The renderer reflows long
lines at paint time and rows past the interior height are simply not painted, so
a host that sized the viewport to the claim can still render into silence: a
single-line leaf that claims three rows at 201 columns wide overflowed to 28
painted rows inside a ten-cell pane (measured). Size the region to
`totalWidth()` first and the wrap stays cosmetic; when it must be narrower,
budget `ceil(claimedWidth / interiorWidth)` rows for the wrapped continuations.
A visible degradation cue is deliberately not invented here — it would change the
bytes of every clipped frame — and is left as a product decision.

## Trust boundary

Leaf and title content is treated as **trusted input**: escape sequences are
preserved (they ride in the cell of the grapheme they style), exactly as the
ANSI-aware feature above promises. Do not embed text from untrusted sources
without sanitising it first — e.g. `SugarCraft\Core\Util\Sanitize::untrusted()`
(or strip control sequences) before handing it to `leaf()` / `withTitle()` —
otherwise a payload can forge SGR, OSC-8 hyperlinks, or other terminal control
strings. See the fleet-wide [trust-boundary notes](https://github.com/detain/sugarcraft/blob/master/docs/TRUST-BOUNDARY.md).

What the library does guarantee is enforced at its own choke, in
`SugarCraft\Panels\Sanitize::painted()` and `Sanitize::paintedTitle()`, applied to
content and titles as they enter a `Node`:

- Malformed UTF-8 is repaired to replacement characters, so the width oracle and
  the painted bytes agree (a truncated multi-byte sequence used to bill zero
  columns while still occupying one).
- The mouse zone sentinels (`U+E000` / `U+E001`) are stripped. They are ordinary
  private-use text, so an escape-aware sweep would not notice them, and a forged
  pair in panel content could otherwise claim screen regions from a `sugar-mouse`
  host. Other private-use codepoints — the image markers in `U+E002..` — pass
  through.
- `CR` and `CRLF` become `LF`, and `TAB` becomes `sugar-core`'s tab stop. A
  carriage return reaching the grid repaints the frame's own rows from column
  zero, which erases borders and spills panel text into neighbouring panes.
- Remaining C0 controls and `DEL` are removed. `ESC`, `LF` and `BEL` are spared:
  `ESC` is the feature, `LF` is the line break, and `BEL` cannot be told apart
  from the terminator of a preceding OSC sequence — sweeping it would turn a
  terminated sequence into an unterminated one.
- C1 controls (`U+0080`–`U+009F`) are removed. They are the 8-bit twins of the
  spared vocabulary's introducers — xterm, VTE and kitty honour 8-bit CSI as
  `ESC [`, 8-bit OSC as `ESC ]` and DCS as `ESC P` — so one smuggled C1
  codepoint would reach the host's parser with full escape authority, and even
  an inert C1 charges a column the painter never advances.
- In a title, `LF` becomes a space: titles paint on one row of the border, and a
  newline there moves the cursor mid-frame.

Two consequences worth knowing. An **unterminated OSC** genuinely has no end: the
renderer treats the rest of the line as its payload, which is what a terminal does
too, so an unterminated sequence swallows the remainder of its row — sanitise
untrusted text rather than relying on the layout to survive it. And the
hostile-size containment is **fail-soft by design**: the per-cell carry budget and
the pending-escape budget drop styling bytes past the cap. Geometry stays intact —
columns, clipping and the frame are computed from visible width — but a payload
that relied on more than the budget of styling bytes will not look the way it was
written.

## Accessibility and text extraction

- **Delta frames are not a document.** A same-size re-render emits only the cells
  that changed, so capturing a window of output and reading it as text will miss
  most of the screen. To snapshot the grid, call `resetPreviousFrame()` and render
  once more (that frame is a full repaint), or accumulate the frames yourself.
- **Every row is the viewport width in terminal cells**, and rows are
  newline-separated — the last row carries no trailing newline — so
  `explode("\n", $frame)` after peeling the leading `ESC [ 2J` / `ESC [ H` gives a
  rectangular grid. The rectangle is measured in cells, not characters: a wide
  grapheme writes an empty string into each continuation cell, so a row holding
  CJK or emoji is rectangular in cells yet ragged in bytes, and SGR spans ride
  inside the rows until `SugarCraft\Core\Util\Ansi::strip()` reduces them to plain
  text.
- **Framing is not colour.** `Border::hidden()` and a borderless layout at
  `withSpacing(1)` draw no visible boundary, so structure is conveyed by position
  alone. If a layout's meaning depends on which box content belongs to, keep a
  visible frame or a title on it.
- **There is no focus model.** sugar-panels paints; it has no cursor, selection or
  active-panel concept, and there is no colour channel on the frame — borders are
  painted unstyled, and no `withStyle()` a node carries reaches them. A host that
  needs an "active panel" cue renders it itself (a distinct `Border` style or a
  title is the usual recipe).

## Examples

| File | Shows |
|------|-------|
| `examples/basic.php` | Two separate single-level splits — a three-column horizontal row and a two-pane vertical stack, sized by `min*` hints |
| `examples/borders.php` | Every `Border` preset, side by side with their names |
| `examples/nested.php` | A three-level vertical/horizontal/vertical nest — every box is bordered |
| `examples/flex.php` | Fixed sidebar sized by its content plus a growing pane |
| `examples/diff.php` | The stateful loop: full frame, delta frame, resize frame |

```bash
php examples/basic.php
```

Every example is executed by the suite (`tests/ReadmeSnippetsTest.php`), so a
snippet that drifts from the API fails CI rather than misleading a reader.

## Internationalization

The `Lang` facade and `lang/en.php` keys (`align.*`, `valign.*`) are wired but
currently **dormant**: the render path emits no user-facing strings, so no
production `Lang::t()` caller exists today. The pre-wired surface gives a
future label UI its locale plumbing; lookup follows the house chain exact
locale → base locale → `en` → raw key.

## License

MIT

## Credits & inspiration

Design antecedent: [treilik/bubbleboxer](https://github.com/treilik/bubbleboxer); SugarCraft is developed as a native PHP project.
