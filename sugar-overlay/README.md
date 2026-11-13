# sugar-overlay

Modal/overlay compositing for PHP terminal UIs. Composite foreground
content over a background at any position with cell (column/row) offsets,
backdrop dimming, border chrome, animated transitions, mouse-zone
hit-testing and incremental diff output for efficient redraws.

## Features

- **Composite overlays** — place a modal, dialog or tooltip over existing
  terminal content by vertical and horizontal anchor, with column/row
  offsets from the anchor.
- **Positioning** — nine named anchors (TOP, RIGHT, BOTTOM, LEFT, CENTER
  and the four corners). Placement is two-axis: `composite()` takes a
  vertical anchor and a horizontal anchor, and any case is valid on either
  axis — each axis reads only the component it cares about (see
  [Positioning](#positioning)).
- **Backdrop dimming** — darken the whole canvas except the overlay's own
  footprint by wrapping every non-empty background line in a truecolor dim
  pen (0–100% opacity). Styling inside a line that sets its own color later
  overrides the dim; the dim does not compound across stacked layers (two
  50% veils dim once, not twice).
- **Border chrome** — wrap the overlay in any sugar-style `Border`
  (normal, rounded, thick, double, …). Applied by `composite()` whenever a
  border is set.
- **Animations** — slide, fade and scale transitions with
  sugar-spring `CubicBezier` easing.
- **Z-index stacking** — `VeilStack` sorts layers and paints them
  bottom-to-top (`composite()` itself never reads the z-index).
- **Click-outside dismissal** — self-contained `sugar-mouse` hit-testing,
  no external manager needed.
- **Incremental diffing** — emit only the changed cells when a background
  is re-rendered at the same size (see
  [Buffer diffing](#buffer-diffing-and-rendersession)).

## Install

```bash
composer require sugarcraft/sugar-overlay
```

Every example below assumes the Composer autoloader is already required:
`require __DIR__ . '/vendor/autoload.php';` from a project script sitting
next to its `vendor/`, or the package-internal
`require __DIR__ . '/../vendor/autoload.php';` shape used by files inside
this library (its `examples/`, for instance).

## Quick Start

```php
<?php
use SugarCraft\Overlay\Position;
use SugarCraft\Overlay\Veil;

$background = "Main application content\nLine two of content\nLine three of content\nLine four of content";
$modal      = "+----------------+\n|  Are you sure? |\n|  [Y] Yes  [N] No |\n+----------------+";

$veil   = Veil::new();
$output = $veil->composite($modal, $background, Position::CENTER, Position::CENTER);

echo $output;
```

The one-call form of that centered modal is `Veil::centered($modal,
$background)` — see [Backdrop Dimming](#backdrop-dimming).

`composite()` returns the full merged frame (or a diff — see
[Buffer diffing](#buffer-diffing-and-rendersession)). The background is
the canvas: an overlay wider or taller
than it is clipped to the canvas, and a background with no cells (the
empty string, or rows of zero width) offers no canvas at all, so the
overlay itself is returned as the frame.

## Positioning

Placement is decided by two anchors plus offsets:

```
composite(string $foreground, string $background,
    Position $vertical, Position $horizontal, int $xOffset = 0, int $yOffset = 0): string
```

- `$vertical` pins the block's row: `TOP`-family cases to the first row,
  `BOTTOM`-family to the last, everything else vertically centered.
- `$horizontal` pins the block's column: `LEFT`-family to column 0,
  `RIGHT`-family to the last column, everything else horizontally
  centered.
- `$xOffset` / `$yOffset` shift from the anchor in columns/rows
  (`+` right/down, `-` left/up).

Offsets are applied verbatim — they are never clamped, and can push part
of the block (or all of it) off the canvas. Fully off-canvas yields just
the dimmed backdrop; pushing further changes nothing. The axes are also
asymmetric at the anchor stage: an overlay taller than the backdrop paints
from the top row, while a too-wide overlay can drop whole columns on the
x-axis — a deliberate divergence (slide-in animations paint partial-width
rows), not upstream behavior.

A host that needs to know which canvas cells an overlay covers — to lift
click zones underneath it, say — can ask `coveredCells($foreground,
$background, $vertical, $horizontal)`: it returns the clipped paint rect
(the exact anchor arithmetic `composite()` runs) or null when the overlay
paints nothing.

Any of the nine `Position` cases is accepted on either axis; the case's
other-half is ignored on that axis. So `TOP` as the horizontal anchor
means "horizontally centered", and `LEFT` as the vertical anchor means
"vertically centered" — the four corner cases (`TOP_LEFT`, …) are the
shorthand that carries both halves at once.

```php
<?php
use SugarCraft\Overlay\{Position, Veil};

$bg = "..............................\n..............................\n..............................";
$fg = "[dialog]";

echo Veil::new()->composite($fg, $bg, Position::TOP, Position::RIGHT) . "\n";
echo Veil::new()->composite($fg, $bg, Position::CENTER, Position::CENTER, 1, -1) . "\n";
```

## Corner positions

```php
<?php
use SugarCraft\Overlay\{Position, Veil};

$bg = "..............................\n..............................\n..............................";
$fg = "[info]";

echo Veil::new()->composite($fg, $bg, Position::BOTTOM_RIGHT, Position::BOTTOM_RIGHT) . "\n";
```

(Corner cases set both axes from one value; mixing `Position::BOTTOM`
with `Position::RIGHT` works identically.)

## Backdrop Dimming

```php
<?php
use SugarCraft\Overlay\{Position, Veil};

$bg = "underlying text\nmore underlying\ntext underneath";
$fg = "[modal]";

$veil = Veil::new()->withBackdrop(50); // 50% dim
echo $veil->composite($fg, $bg, Position::CENTER, Position::CENTER) . "\n";
```

That pair — 50% backdrop, CENTER on both axes — is the modal recipe, so
it has a preset: `Veil::centered($fg, $bg, $dimmingPercent = 50,
$xOffset = 0, $yOffset = 0)`. It delegates to the call spelled above and
emits byte-identical output; it is shorthand, never a second path.

`withBackdrop(int $opacity)` takes 0–100 (clamped) and darkens every row
outside the overlay footprint: each non-empty background line is wrapped in
`\e[38;2;v;v;v…\e[39m` with `v = round(255 × (1 − opacity/100))`. Lines that
arrive pre-styled are no longer skipped — the wrap dims them too, though any
SGR inside the line that sets its own color afterwards still overrides the
pen for its span. The wrap sets only the foreground: text carrying its own
explicit background (SGR 48) keeps that background at full brightness under
the scrim, so pre-styled rows degrade unevenly rather than dimming as one.
The pen is a **darkening** convention that assumes a light default
foreground: on a terminal that renders dark text (the usual light-theme
setup) the gray pen brightens that text instead of dimming it. During an
animation the scrim ramps with the transition —
the first frames are partially dimmed, full strength lands only with the
settled frame (see [Animations](#animations)).

Dims do not stack: the pen closest to the text wins, so when a second
scrim wraps an already-dimmed line the outer pen never reaches those
cells — the first dim to touch a cell governs and later veils cannot
darken it further. In a `VeilStack` this bites through ordering: a
content-less backdrop veil is usually added first, so its lighter dim
lands before the modal's heavier `withBackdrop()` ever sees those
cells, and the region reads at the backdrop's opacity, not the modal's.
Do not rely on stacked veils to reach a contrast target — dim once, at
the strength you need.

`composite()` anchors horizontally with a deliberate asymmetry: `baseX` is
**not** clamped, so an overlay wider than the canvas at `LEFT` keeps its
left cells and loses its right edge (the reverse at `RIGHT`). A
"shrink-to-fit-the-canvas" mode is a design decision this library does not
make for you. Vertically there is one clamp: `baseY` is floored at `0`, so
an overlay taller than the canvas and anchored `BOTTOM` is pinned to the
top row — the anchor tells you where the overlay wanted to sit, not which
of its edges survives.

## Animations

`animate()` steps a transition: progress `0.0` is the start state, `1.0`
the settled overlay. Values outside `0.0–1.0` saturate (they are clamped,
never extrapolated). Pick a kind with `withAnimation()`; each kind applies
its default easing. At `0.0`, `FADE` and `SCALE` render no foreground at
all and the scrim is fully transparent; `SLIDE` parks the block's full
extent outside the anchored edge — a whole foreground-width of columns at
`LEFT`/`RIGHT`, a whole foreground-height of rows at `TOP`/`BOTTOM` — so
clipping paints nothing but the backdrop, never a sliver.

| Kind | Default easing | Motion |
|---|---|---|
| `AnimationKind::SLIDE` | `CubicBezier::easeOut()` | enters from the anchored edge; a CENTER anchor has no edge to slide from, so SLIDE is a no-op there (only the scrim ramps) |
| `AnimationKind::FADE` | `CubicBezier::easeInOut()` | truecolor dim pen over the foreground, lifting to full brightness |
| `AnimationKind::SCALE` | `CubicBezier::easeOut()` | lines reveal from the middle outward (vertical, whole lines only) |

```php
<?php
use SugarCraft\Overlay\{Animation\AnimationKind, Position, Veil};

$bg = "..............................\n..............................\n..............................";
$fg = "[dialog box]";

// Each frame gets a FRESH veil: composite/animate state rides a shared
// RenderSession, so reusing one veil would emit deltas after step 0 —
// near-empty strings, not frames.
$frame = '';
for ($step = 0; $step <= 10; $step++) {
    $veil  = Veil::new()->withAnimation(AnimationKind::SLIDE);
    $frame = $veil->animate($fg, $bg, Position::TOP, Position::LEFT, $step / 10);
}
echo $frame . "\n"; // settled frame
```

For users who ask the platform for reduced motion, opt the veil out of
stepped transitions entirely:

```php
<?php
use SugarCraft\Overlay\{Animation\AnimationKind, Position, Veil};

// Apps read the platform preference from sugar-colorprofile, e.g.
// Probe::reducedMotion(), and pass it straight through.
$reduced = (bool) ($_ENV['REDUCE_MOTION'] ?? false);
$bg = "..............................\n..............................\n..............................";

$veil = Veil::new()->withAnimation(AnimationKind::FADE)->withReducedMotion($reduced);
echo $veil->animate("[dialog box]", $bg, Position::TOP, Position::LEFT, 0.3) . "\n";
// with the flag set, any progress paints the settled frame in one shot.
```

### Custom easing

`withAnimation()` always news the kind's default curve. For your own
`CubicBezier`, construct the animation class directly and drive the
compositor with its result — the public classes are `Slide`, `Fade` and
`Scale` (all take `?CubicBezier $easing = null`):

```php
<?php
use SugarCraft\Overlay\{Position, Veil};
use SugarCraft\Overlay\Animation\Slide;
use SugarCraft\Spring\Easing\CubicBezier;

$bg    = str_repeat("..............................\n", 3);
$fg    = "[dialog box]";
$slide = new Slide(CubicBezier::easeInOutQuad());
$step  = $slide->apply($fg, 0.8, Position::TOP, Position::LEFT);

echo Veil::new()->composite(
    $step['foreground'],
    $bg,
    Position::TOP,
    Position::LEFT,
    $step['horizontalOffset'],
    $step['verticalOffset'],
) . "\n";
```

`Slide::apply()` returns `array{foreground, verticalOffset,
horizontalOffset}`; `Fade` and `Scale` return the transformed foreground
string directly (their `apply()` takes `($foreground, $progress)`).
The example steps to `0.8` deliberately: at low progress the offsets still
place the whole block off-canvas, so a `composite()` of an early step
paints nothing but the backdrop.

## Z-Index and Stacking Order

`withZIndex()` is a **stack-order value, not a compositing parameter**:
`composite()` never reads it. Only `VeilStack` sorts by it — lowest first,
painted bottom-to-top — so z-index changes nothing until a stack reorders
your layers:

```php
<?php
use SugarCraft\Overlay\{Position, Veil, VeilStack};

$bg  = str_repeat("..............................\n", 4);
$fgA = "[panel A]";

// Same foreground, same canvas: zIndex cannot change a single composite().
$a = Veil::new()->withZIndex(0)->composite($fgA, $bg, Position::TOP, Position::LEFT);
$b = Veil::new()->withZIndex(99)->composite($fgA, $bg, Position::TOP, Position::LEFT);
echo ($a === $b ? 'identical' : 'different') . "\n"; // identical

// Inside a stack, higher z-index paints last (on top).
$stack = VeilStack::new()
    ->add(Veil::new()->withContent('[bottom]')->withZIndex(1))
    ->add(Veil::new()->withContent('[top]')->withZIndex(10));
echo $stack->composite($bg, Position::CENTER, Position::CENTER) . "\n";
```

(0 is a real z-index; it is the default. `maxZIndex()`/`minZIndex()`
return `null` only for an empty stack.)

## VeilStack

`VeilStack` is an immutable ordered collection of veils. Each veil paints
its own `withContent()` foreground onto the accumulated canvas in
z-index order; a veil with **no content** is a backdrop-only layer (its
`withBackdrop()` dims whatever is beneath it). Layers whose z-index ties
keep insertion order — `sorted()` relies on PHP's stable `usort` — so the
last-added tied layer paints last and lands on top.

```php
<?php
use SugarCraft\Overlay\{Position, Veil, VeilStack};

$background = str_repeat("base canvas line\n", 6);

$stack = VeilStack::new()
    ->add(Veil::new()->withBackdrop(40))                                   // z 0: dim layer
    ->add(Veil::new()->withContent('[panel]')->withZIndex(1))             // z 1: first paint
    ->add(Veil::new()->withContent('[modal]')->withZIndex(2));            // z 2: on top

echo $stack->composite($background, Position::CENTER, Position::CENTER) . "\n";
```

| Method | Description |
|---|---|
| `VeilStack::new()` | create an empty stack |
| `add(Veil $veil): self` | append a layer (returns a new stack) |
| `removeWhere(\Closure $predicate): self` | drop layers matching a test |
| `filter(\Closure $predicate): self` | keep layers matching a test |
| `clear(): self` | empty stack |
| `sorted(): array` | layers in z-index order (`@return list<Veil>`) |
| `all(): array` | layers in insertion order |
| `count(): int` / `isEmpty(): bool` | size |
| `maxZIndex(): ?int` / `minZIndex(): ?int` | z-range (`null` when empty) |
| `composite($background, $vertical, $horizontal, $xOffset = 0, $yOffset = 0): string` | paint all layers at one shared anchor |
| `compositeAll(string $background): string` | paint each layer at **its own** `withPosition()` anchor — see below |

Both passes feed every layer the **accumulated** canvas (later veils see
earlier veils, including their dimming) and run each veil
`withoutSession()` so stacked painting always emits full frames (the stack
never diffs — full frames are its architecture, not an oversight). A stack
holds at most `VeilStack::MAX_LAYERS` (256) layers; `add()` throws
`OverflowException` beyond the bound, which keeps the per-frame walk cost
of both `composite()` and `compositeAll()` finite.

The stack reads each veil's `content()` **verbatim** — it performs no
sweep of its own. Untrusted payloads must be swept per-veil with
`withUntrustedContent()` *before* `add()`; a forged zone sentinel that
reaches the stack survives into the composited frame and mints a phantom
hit-test zone with the attacker's chosen id (see
[Security](#security--untrusted-content)).

### Per-veil placement: withPosition()

`withPosition(?Position $vertical, ?Position $horizontal, int $x = 0, int
$y = 0)` stores an anchor + offsets **on the veil**; `compositeAll()`
reads them (`vPosition() ?? Position::CENTER`, and likewise horizontally,
plus `positionX()`/`positionY()`). Passing `null` for an axis clears that
anchor back to the stack default; the offsets always take the given
values.

```php
<?php
use SugarCraft\Overlay\{Position, Veil, VeilStack};

$background = str_repeat("base canvas line ................\n", 6);

$stack = VeilStack::new()
    ->add(Veil::new()->withContent('[status]')
        ->withPosition(Position::TOP, Position::RIGHT, 0, 1))
    ->add(Veil::new()->withContent('[help]')
        ->withPosition(Position::BOTTOM, Position::LEFT, 2, -1));

echo $stack->compositeAll($background) . "\n";
```

## Auto-Size (deprecated)

There is no fixed-width/height mode in sugar-overlay: an overlay has
always been measured from its own content (border chrome included).
`withAutoSize()` / `autoSize()` predate that reality, do nothing at all,
and are retained only so old call sites keep compiling. New code should
not call them; border chrome applies unconditionally (see below).

## Border Chrome

Wrap the overlay in a sugar-style `Border` — `composite()` renders the
bordered block and measures/anchors it as the foreground. The border is
applied **whenever one is set**; no other flag is involved:

```php
<?php
use SugarCraft\Overlay\{Position, Veil};
use SugarCraft\Style\Border;

$bg = "..............................\n..............................\n..............................";
$fg = "[confirmation]";

$veil = Veil::new()->withBorder(Border::rounded());
echo $veil->composite($fg, $bg, Position::CENTER, Position::CENTER) . "\n";
```

`sugar-style` `Border` exposes static factories — `Border::normal()`,
`Border::rounded()`, `Border::thick()`, `Border::double()`,
`Border::block()`, `Border::ascii()`, `Border::hidden()`,
`Border::markdownBorder()` — plus `Border::catalog()`, which returns the
catalogue of preset borders as an array rather than a single `Border` —
and the rendering itself is done by `sugar-style` `Style`.
`withBorder(null)` clears the chrome.

## Click-Outside Dismissal

Hit-testing is self-contained: mark interactive content with `mark()`,
composite, hand the rendered frame to `scan()`, and the returned instance
answers `hit()` / `isClickOutside()` for mouse events.

```php
<?php
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\{MouseButton, MouseAction};
use SugarCraft\Overlay\{Position, Veil};

$bg = "..............................\n..............................\n..............................";

$veil = Veil::new()->withClickOutsideDismiss(true);

// mark() BEFORE compositing: the zone box is extracted from where the
// markers actually land on the canvas, so scan the REAL rendered frame.
$buttons = $veil->mark('ok', '[OK]') . '  ' . $veil->mark('cancel', '[Cancel]');
$frame   = $veil->composite($buttons, $bg, Position::CENTER, Position::CENTER);

$scanned = $veil->scan($frame);

// Which zone covers each cell of the canvas? (hit() coordinates are 1-based.)
foreach (range(1, 3) as $row) {
    $line = '';
    foreach (range(1, 30) as $col) {
        $line .= substr($scanned->hit($col, $row)?->id ?? '.', 0, 1);
    }
    echo $line . "\n";
}

$click = new MouseMsg(x: 1, y: 1, button: MouseButton::Left, action: MouseAction::Press);
echo ($scanned->isClickOutside($click) ? 'dismiss' : 'inside') . "\n";
```

Notes:

- `scan()` returns a **new** instance carrying the zones of that frame
  (a re-scan never retro-changes other clones). Ordinary `with*()` clones
  made *after* the scan carry the zones forward — the scanner rides the
  clone — while the pre-scan original stays unscanned. `hit()` returns
  the `Zone` at a cell or `null`; `Zone::$id` is the public id. Both
  `hit()` and `isClickOutside()` are **1-based**: `isClickOutside()`
  forwards `MouseMsg::$x/$y` straight into `hit()`, and SGR mouse
  reports — what terminals emit and what sugar-core's `InputReader`
  decodes — are already 1-based and passed through unshifted, so
  construct the message from the report as-is. Only convert if your own
  event source counts from zero; subtracting one blindly shifts every
  edge click onto the wrong cell and makes a click on the canvas border
  self-dismiss the modal.
- `scan()` throws `InvalidArgumentException` when the frame carries two
  zones with the same id (`sugar-mouse` refuses ambiguous zones) — keep
  `mark()` ids unique per frame.
- `isClickOutside()` requires dismissal to be enabled: with the flag off
  it always answers `false`; with the flag on but no prior `scan()` it
  throws.
- `scan()` sees zones from its own render only — zones do not accumulate
  across frames; re-scan after each render you want to hit-test. When you
  are on the diffing path, the frames `composite()` returns after the
  first are deltas that carry **no zone markers**; scan
  `$veil->lastFullFrame()` instead (see below).

## Buffer Diffing and RenderSession

`composite()` is stateful through a `RenderSession`: the first frame (or
any frame whose canvas dimensions changed) is emitted in FULL and
remembered; later frames at the same dimensions are compared against the
remembered output and emitted as a **diff** — a frame-open home anchor
(`\e[1;1H`) plus cursor-move sequences touching only changed cells. An
unchanged re-composite emits only that anchor: the sugar-cellbuffer
`DiffEncoder` frames every encoded output with the same unconditional
home-CUP open, so “nothing changed” is anchor-only, never zero bytes.

Direct session drivers get one extra cold-start rule: the first
`RenderSession::diff()` call returns the empty string — the frame is
remembered but never emitted. Print your own initial full frame (from
`lastFullFrame()`) or gate emission on `shouldEmitFull()` first, or frame
one stays invisible and the screen desyncs silently. Later deltas are
diffed against the rebuilt frame one, so a cell frame one painted and a
later frame leaves blank is properly erased.

That is bandwidth-correct for a terminal redraw loop and surprising in a
demo: to force a full frame, use a fresh `Veil::new()`, call
`withoutSession()` (a copy with a brand-new session), or
`resetPreviousFrame()` (clears the remembered frame in place). A frame
with no cells at all resets the session, so the next composite comes out
full again.

> **Warning — the session is shared and mutable.** All `with*()` clones
> carry the *same* `RenderSession` object by design: `with*()` changes
> appearance, not render state. Compositing through a clone advances the
> original's diff memory too (and vice versa). If two render paths share
> one veil value — e.g. you keep a base modal in the model and derive a
> `withContent()` variant each frame — they interleave into one delta
> chain. For an isolated pipeline call `withoutSession()`, or construct a
> fresh `Veil::new()` per pipeline. `resetPreviousFrame()` mutates through
> the same shared session: it invalidates for **every** clone.

Over an SSH link a full frame is what costs: the Quick Start's frame is
89 bytes undimmed (209 once a 50% scrim wraps every row), while a
one-character change diffs down to 14 bytes. A diff whose cursor position
could not be trusted is what `resetPreviousFrame()` exists to recover
from. (These three figures are pinned live by `tests/ReadmeByteFigureTest.php`
— change the fixture, change the prose.)

> **Warning — deltas carry no zone markers.** The cursor-move diff is
> paint only: the `mark()` envelopes live exclusively in the FULL frame.
> Hit-testing on the diffing path must scan `$veil->lastFullFrame()` —
> the complete frame behind the most recent emission, exposed on both
> `Veil` and `RenderSession` — never the string `composite()` just
> returned. Scanning a delta finds zero zones, so every click reads as
> "outside" and a dismissal-enabled modal self-closes from frame two.

Extended color operands saturate before any arithmetic runs: a `38;5;n`
palette index is clamped to 0–255 and `38;2;r;g;b` components to 0–255
each, so a hostile `38;5;300` cannot push the grayscale-ramp formula
(38;5;232-255 is the grey ramp, not the 6×6×6 colour cube) past the
`0xRRGGBB` range the cell model stores (unclamped, it would have run the
ramp to 688 per channel — a value no frame can round-trip).

## Security — untrusted content

The compositor is marker-sensitive: U+E000/U+E001 zone sentinels become
**real hit-test zones**, OSC 8 sequences become **live hyperlinks**, and
SGR pen bytes restyle cells — all read straight out of the foreground and
background strings. `composite()`/`withContent()` therefore pass content
through **verbatim** (so this library's own `mark()` zones survive), which
means text from any source you do not control — network payloads,
transcripts, other processes — must be swept by you before it enters the
pipeline. Use sugar-core's sanitizer, which strips control sequences and
sweeps the U+E000/U+E001 zone-sentinel arena — forged and legitimate —
while preserving image markers (U+E002 and above):

```php
<?php
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Overlay\{Position, Veil};

$bg      = str_repeat("..............................\n", 3);
$hostile = (string) ($_ENV['MODAL_BODY'] ?? "fallback text"); // text from wherever you do NOT trust it

// Direct composite path: sweep raw payloads BEFORE marking. This
// sweep strips every U+E000/U+E001 sentinel — yours included — so
// hit-test zones must be added by mark() after the sweep, never before.
echo Veil::new()->composite(Sanitize::untrustedForMarkedFrames($hostile), $bg, Position::CENTER, Position::CENTER) . "\n";

// Content/stack path: sweep AT SET TIME. The stack reads content()
// verbatim — an unswept veil added to a VeilStack mints zones from
// forged sentinels — so untrusted bodies go through withUntrustedContent().
$veil = Veil::new()->withUntrustedContent($hostile);
echo $veil->composite($veil->content(), $bg, Position::CENTER, Position::CENTER) . "\n";
```

The two sweeps differ deliberately:

- `Sanitize::untrustedForMarkedFrames()` serves terminal-bound **marked
  frames**: it neutralizes control sequences and sweeps the ENTIRE
  U+E000 arena — forged sentinels and your legitimate zone markers
  alike — while leaving sugar-image's U+E002+ image-marker band intact.
  Order therefore matters: sweep the raw payload first, then `mark()`.
- `withUntrustedContent()` serves **display content**: it applies
  `Sanitize::untrustedForDisplay()` plus `stripZoneSentinels()` and
  folds TAB to its measured four cells. The frames-channel sanitizer is
  wrong here because its contract KEEPS CR and TAB — and both are
  geometry lies downstream: a bare CR repaints over cells the width math
  already counted (the diff model and the screen desync), and terminals
  stop TAB at the next 8-column tab stop while `Width` reports four
  cells, so every clip and anchor computed from the measured width cuts
  the row short or past its end.

The law, exactly: **sweep the raw payload at the boundary, then mark,
then render.** Marked frames go through
`Sanitize::untrustedForMarkedFrames()`; untrusted bodies entering
content/stack paths go through `withUntrustedContent()`; mark() only
text you swept and assembled yourself, never raw payloads (a payload
that contains sentinels mints its own zones if you skip the sweep).
Both sweeps preserve sugar-image's U+E002+ image-marker band.

## Zone markers and presentation

`mark()` wraps content in `U+E000 id U+E001 … U+E000 /id U+E001`
envelopes. These cost ZERO columns everywhere in the
compositor — measurement, clipping, and anchoring all account them like
escape sequences — but they are not invisible: the id characters are
printable bytes, and a host that forwards a marked frame straight to a
dumb pipe (or to a logger) sees `[Kept]`-style garbage where the zone
sat. The split is by consumer:

- the **scanner** needs the envelopes — `scan($veil->lastFullFrame())`
  reads them before anything is stripped;
- the **terminal you own** rides them harmlessly (private-use band);
- **presentation channels** you do not control (logs, plain-text
  fallbacks, paste-through) must strip first:
  `Sanitize::stripZoneSentinels($frame)` removes exactly the
  U+E000/U+E001 pair and leaves everything else — image markers included
  — byte-for-byte, but the id text between a pair survives as printable
  garbage. To remove whole `U+E000 id U+E001` envelopes (id included),
  use the tag-aware sweep sugar-mouse ships in its `src/Scan.php`:
  `Scan::strip($rendered)`. (The fleet does this today: candy-code's
  `src/Tui/Renderer.php` strips marker triples on its own transcript
  path.)

If your app never hit-tests, opt out entirely with
`Veil::withoutZoneMarkers()`: `mark()` then returns the content
byte-for-byte (id validation still throws — bad ids stay loud) and the
frame carries no envelopes to strip anywhere.

## Accessibility

Dismissal by click is only the pointer half of a modal. sugar-overlay
deliberately ships no key handling — wire the keyboard side in your app:
bind Esc/Enter to the same dismiss action `isClickOutside()` answers, and
keep focus movement inside sugar-focus / sugar-navstack so the overlay's
buttons are reachable without a mouse. When an overlay opens, announce it:
the **text** you emit (a title line) is the screen-reader cue; a
`withBorder()` frame is a sighted-user affordance that only adds
box-glyph noise for terminal screen readers, so never treat it as the
announcement. Return focus to the underlying pane when it closes.

For motion, call `withReducedMotion()` whenever the user has asked their
platform to reduce it (sugar-colorprofile's `Probe::reducedMotion()` is
the fleet-standard read — note it sees only the `REDUCE_MOTION` /
`PREFERS_REDUCED_MOTION` environment variables: a desktop accessibility
toggle reaches a terminal process only when exported into its
environment, so offer an in-app setting as well): `animate()` then treats every progress as 1.0
and paints the settled frame in one shot, with no dim-pen sweep and no
travel. During ordinary animation the scrim ramps with the animation
instead of snapping to full opacity at frame one.

## Shared foundations

Built on the monorepo's cell model: sugar-cellbuffer `Buffer` powers the
diff, sugar-mouse `Scanner`/`Mark` the hit-testing, sugar-spring
`CubicBezier` the easing, sugar-style `Border`/`Style` the chrome, and
sugar-core `Width` the terminal-column measurement.

## License

MIT — see [LICENSE](LICENSE).

## Credits

Design antecedent: rmhubbert/bubbletea-overlay (Go). sugar-overlay's
compositor semantics mirror that package's; it is its own codebase.
