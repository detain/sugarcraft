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
- **Backdrop dimming** — dim the base content under the overlay using
  truecolor blending (0–100% opacity); lines whose *first byte* is already
  an escape introducer are carried through un-dimmed, because a line that
  starts styled has no plain cells for the dim to blend into.
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

`composite()` returns the full merged frame (or a diff — see the diff
section below). The background is the canvas: an overlay wider or taller
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

`withBackdrop(int $opacity)` takes 0–100 (clamped). Background lines that
already *start* with an escape sequence are carried through byte-exact —
the dim only blends plain runs, never content that arrives pre-styled.

## Animations

`animate()` steps a transition: progress `0.0` is the start state, `1.0`
the settled overlay. Pick a kind with `withAnimation()`; each kind applies
its default easing.

| Kind | Default easing | Motion |
|---|---|---|
| `AnimationKind::SLIDE` | `CubicBezier::easeOut()` | enters from the anchored edge |
| `AnimationKind::FADE` | `CubicBezier::easeInOut()` | opacity in from black (truecolor pen) |
| `AnimationKind::SCALE` | `CubicBezier::easeOut()` | line-by-line reveal |

```php
<?php
use SugarCraft\Overlay\{Animation\AnimationKind, Position, Veil};

$bg = "..............................\n..............................\n..............................";
$fg = "[dialog box]";

$veil  = Veil::new()->withAnimation(AnimationKind::SLIDE);
$frame = '';
for ($step = 0; $step <= 10; $step++) {
    $frame = $veil->animate($fg, $bg, Position::TOP, Position::LEFT, $step / 10);
}
echo $frame . "\n"; // settled frame
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
$step  = $slide->apply($fg, 0.5, Position::TOP, Position::LEFT);

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

(0 is a real z-index; the default. `maxZIndex()`/`minZIndex()` return
`null` only for an empty stack.)

## VeilStack

`VeilStack` is an immutable ordered collection of veils. Each veil paints
its own `withContent()` foreground onto the accumulated canvas in
z-index order; a veil with **no content** is a backdrop-only layer (its
`withBackdrop()` dims whatever is beneath it).

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
`withoutSession()` so stacked painting always emits full frames.

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
`Border::block()`, `Border::ascii()`, `Border::hidden()` — and the
rendering itself is done by `sugar-style` `Style`. `withBorder(null)`
clears the chrome.

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

$click = new MouseMsg(x: 0, y: 0, button: MouseButton::Left, action: MouseAction::Press);
echo ($scanned->isClickOutside($click) ? 'dismiss' : 'inside') . "\n";
```

Notes:

- `scan()` returns a **new** instance carrying the zones of that frame
  (a re-scan never retro-changes other clones). `hit()` returns the
  `Zone` at a cell or `null`; `Zone::$id` is the public id.
- `isClickOutside()` requires a prior `scan()` (throws otherwise) and
  always answers `false` unless `withClickOutsideDismiss(true)`.
- `scan()` sees zones from its own render only — zones do not accumulate
  across frames; re-scan after each render you want to hit-test.

## Buffer Diffing and RenderSession

`composite()` is stateful through a `RenderSession`: the first frame (or
any frame whose canvas dimensions changed) is emitted in FULL and
remembered; later frames at the same dimensions are compared against the
remembered output and emitted as a **diff** — cursor-move sequences
touching only changed cells. An unchanged re-composite emits the empty
string: nothing changed, nothing is sent.

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

For incremental updates over SSH the full frame of a typical 40×10 modal
costs ~1,940 bytes while a one-character change diffs down to a few
bytes; a diff whose cursor position could not be trusted is what
`resetPreviousFrame()` exists to recover from.

## Security — untrusted content

The compositor is marker-sensitive: U+E000/U+E001 zone sentinels become
**real hit-test zones**, OSC 8 sequences become **live hyperlinks**, and
SGR pen bytes restyle cells — all read straight out of the foreground and
background strings. `composite()`/`withContent()` therefore pass content
through **verbatim** (so this library's own `mark()` zones survive), which
means text from any source you do not control — network payloads,
transcripts, other processes — must be swept by you before it enters the
pipeline. Use sugar-core's sanitizer, which strips control sequences and
forged zone sentinels while preserving image markers (U+E002 and above):

```php
<?php
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Overlay\{Position, Veil};

$bg      = str_repeat("..............................\n", 3);
$hostile = (string) ($_ENV['MODAL_BODY'] ?? "fallback text"); // text from wherever you do NOT trust it

// Direct composite path: sanitize explicitly.
echo Veil::new()->composite(Sanitize::untrustedForMarkedFrames($hostile), $bg, Position::CENTER, Position::CENTER) . "\n";

// Stack content path: same sweep built in.
$veil = Veil::new()->withUntrustedContent($hostile);
echo $veil->composite($veil->content(), $bg, Position::CENTER, Position::CENTER) . "\n";
```

The law, exactly: **sweep at the boundary, render the result.** Marked
frames go through `Sanitize::untrustedForMarkedFrames()`; mark() only
text you assembled yourself, never raw payloads (a payload that contains
sentinels mints its own zones if you skip the sweep).

## Accessibility

Dismissal by click is only the pointer half of a modal. sugar-overlay
deliberately ships no key handling — wire the keyboard side in your app:
bind Esc/Enter to the same dismiss action `isClickOutside()` answers, and
keep focus movement inside sugar-focus / sugar-navstack so the overlay's
buttons are reachable without a mouse. When an overlay opens, announce it
(a title line or `withBorder()` frame helps screen-reader users locate the
new block) and return focus to the underlying pane when it closes.

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
