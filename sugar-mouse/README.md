# SugarMouse

> Formerly published as `sugarcraft/candy-mouse` (renamed 2026-10); the former
> `candy-zone` package was absorbed into this one (2026-10). <!-- @rename-keep -->

[![codecov](https://codecov.io/gh/sugarcraft/sugar-mouse/branch/master/graph/badge.svg?flag=sugar-mouse)](https://codecov.io/gh/sugarcraft/sugar-mouse)

Mouse zones for terminal apps — wrap rendered chunks with sentinel-tagged
zone markers, scan the frame to discover each zone's bounding box, then ask which
zone a mouse event landed in. One package, two layers:

- **Primitive layer (`SugarCraft\Mouse`)** — self-contained Mark/Scan/Get
  hit-testing (the three verbs name three collaborators: `Mark` emits the
  sentinel tags, `Scan` decodes them — usually driven through `Scanner` —
  and `get()` answers "where is this id", with `hit()` answering "which zone
  is under this cell"), `Scanner`, `ZoneClickTracker` press/release dedup, and the
  `Selection` / `SelectionRange` text-selection machine. No Manager wiring
  needed; each consumer owns its own `Scanner` instance.
- **Zones façade (`SugarCraft\Mouse\Zones`)** — the app-facing bubblezone
  surface: a `Manager` that owns a zone registry, the package-level `Zones`
  facade, and the hover / drag / multi-click trackers. The façade delegates
  marker encoding and geometry to the primitive layer **in this same
  package** — the wire format lives in exactly one place.

```
composer require sugarcraft/sugar-mouse
```

Requires PHP `^8.3`; the only package dependency is `sugarcraft/sugar-core`
(escape-sequence constants, `Sanitize`, `Width`, and the core `MouseMsg` /
`MouseWheelMsg` wire types the Zones layer consumes).

## Quickstart

```php
<?php

require 'vendor/autoload.php';

use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\MouseEvent;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Mouse\ZoneClickTracker;

// 1. Wrap interactive content with sentinel-tagged zone markers.
$rendered = Mark::zone('btn-ok', '  OK  ')
              . Mark::zone('btn-cancel', 'Cancel');

// 2. Scan after rendering to populate the zone registry.
$scanner = Scanner::new()->scan($rendered);

// 3. Reverse-lookup on mouse events ('  OK  ' spans cols 1-6 on row 1).
$zone = $scanner->hit(4, 1); // Zone 'btn-ok'

// 4. A click fires only when the release's fresh re-hit agrees with the
//    press zone on id AND box — resolve each event against the frame it
//    arrived in; never reuse the press-side zone for the release.
$tracker = new ZoneClickTracker();
$tracker->track(MouseEvent::press(4, 1), $scanner->hit(4, 1));
$click = $tracker->track(MouseEvent::release(4, 1), $scanner->hit(4, 1));
if ($click !== null) {
    echo "Clicked zone: " . $click->zone->id . "\n"; // btn-ok
}
```

## Key classes

| Class | Role |
|---|---|
| `Mark` | Wrap content with private-use sentinel tags (`Mark::new()`, `Mark::zone()`, `Mark::disabled()`; `Mark::isValidId()` is the id predicate both encoder and decoder share; `Mark::untrusted()` sweeps foreign framing bytes off untrusted labels; `Mark::escapeForMessage()` renders hostile ids safely in errors) |
| `Scan` | Stateless, reentrant decoder behind `Scanner` — an instance method: `(new Scan())->parse($rendered, $width)` returns `id => Zone`; `Scan::strip($rendered)` is the public static byte-wise marker remover |
| `Scanner` | Parse sentinels; `get(id)` and `hit(col, row)` lookups |
| `Zone` | Readonly bounding box (start/end col/row) with `width()` / `height()` and `inBounds(MouseEvent)` |
| `ZoneClickTracker` | Press+Release dedup per button |
| `MouseEvent` | Immutable event (x, y, button, action enum) |
| `MouseAction` | `Press` / `Release` / `Drag` / `Scroll` enum (the X10 button consts `BUTTON_LEFT/MIDDLE/RIGHT` hang off it — a legacy of the primitive's numeric-button vocabulary) |
| `Selection` | Press → drag → release text-selection state machine over a selectable region |
| `SelectionRange` | Normalized, inclusive stream range a selection covers; `extract()` copies its text out of a frame |
| `Sentinel` | The two framing codepoints (`U+E000` open / `U+E001` close), anchored to sugar-core |
| `ClickResult` | The agreed press+release pair `ZoneClickTracker::track()` returns on a confirmed click |
| `Lang` | Refusal-message lookup for this package's catalogue (`lang/`, `sugar-mouse:`-prefixed keys) |
| `Zones\MsgZoneInBounds` | Zone-bound Msg emitted by `Manager::anyInBoundsAndUpdate()` into a Model's `update()` — the only Zones-layer message that lives outside the `Msg\` namespace |

`Selection` / `SelectionRange` were ported from candy-code's
`Tui\TextSelection`, and candy-code now runs on them: `Tui\TextSelection` is
an immutable adapter over a cloned `Selection` machine, reads its geometry and
copied text through `SelectionRange`, and speaks the same 1-based cells end to
end. What it keeps locally is what this lib leaves to the app — a
drift-latched dragging flag, the release/copy record, and the highlight
repaint.

The `Zones` layer (`Manager`, `Zones`, `Zone` adapter, `ZoneHoverTracker`,
`DragTracker`, `ClickCounter`) is documented in its own section below.

## Sentinel design and trust boundary

Zone markers are two three-byte Unicode **private-use** codepoints:
U+E000 (open) and U+E001 (close), anchored to sugar-core's
`Sanitize::ZONE_SENTINEL_OPEN` / `::ZONE_SENTINEL_CLOSE` so a sanitizer sweep
and the markup it defends against can never drift apart.

**What they defend against.** Ordinary ASCII UI text cannot carry these
bytes, and well-behaved renderers never emit them inside escape payloads, so
normal rendering cannot fabricate a zone boundary. That is a policy of honest
output, not a property of the wire grammar: escape payloads are arbitrary
bytes (the decoder walks OSC/CSI runs as opaque pass-through, so a forged tag
buried inside one is inert), while an unterminated CSI/OSC run swallows the
rest of its frame — including *legitimate* markers — by design, so hostile
input can still disturb recorded geometry. Sweeping untrusted bytes before
composition is the consumer-side duty spelled out under **The consumer's
duty** below (`Mark::untrusted()` /
`Sanitize::untrustedForMarkedFrames()`). But the PUA is not empty space: pasted foreign frames,
image-overlay payloads and Nerd Font / powerline glyph runs all live in the
private-use area. Content that already contains `U+E000 <id> U+E001` is
structurally indistinguishable from a marker this lib emitted — the sentinels
*delimit* our markup, they authenticate nothing.

**The defenses in this lib:**

- **Id charset.** `Mark::isValidId()` accepts only
  `\A[A-Za-z0-9._:-]+\z`, matched byte-wise (no `/u`) and anchored with
  `\A…\z`, not `^…$` (a trailing newline slips past `$`). An id can never
  smuggle a sentinel, control, escape or non-ASCII byte into the stream.
- **The lone-sentinel rule.** A `U+E000` that does not begin a valid tag
  costs its own three bytes when scanning (an unterminated-open run at
  end-of-string loses the same three and keeps any id-shaped text after
  it); everything else is measured as visible text. The rule protects zone
  **identity** — foreign bytes cannot swallow a span and re-rectangle the
  frame — but it is NOT width-neutral: other private-use codepoints
  (`U+E002+` image-overlay markers, Nerd Font glyphs) count as one visible
  cell each, so a host that masks them for its own width math must measure
  against what actually reaches the terminal, or every such glyph drifts the
  recorded geometry one cell. That masking is a consumer-side duty like the
  sweep below (candy-code's `maskImageMarkers` is the precedent).
- **Bounded work.** `Mark::MAX_ID_BYTES` (256) is checked before any
  substring is materialized, so an attacker-sized span between a stray
  `U+E000` and a distant `U+E001` is rejected in O(1);
  `Mark::MAX_CONTENT_BYTES` (1 MiB) caps what `Mark` will emit around a
  chunk. Both caps bound the EMIT side; parsing is linear single-pass with
  no cap of its own, so feed `->parse()` frames you expect to be
  terminal-sized — a hostile multi-megabyte blob (or an unterminated CSI/OSC
  run, which swallows the rest of its frame by design) is caller input-trust
  like everything else untrusted.

**The consumer's duty.** Anything pasted or otherwise untrusted must be
swept *before* it is composed into a marked frame. This package re-exports
the surgical sweep as `Mark::untrusted()`, which **is** sugar-core's
`Sanitize::stripZoneSentinels()` — a `str_replace` of the two sentinel
codepoints and nothing else. `Sanitize::untrustedForMarkedFrames()` is NOT
the same operation but its superset: the ANSI/C0 sweep `Sanitize::untrusted()`
first, then the sentinel sweep (`stripZoneSentinels(untrusted($s))`) — and it
is that composed policy candy-code's renderer runs on its input path. Mind the
near-miss names: `Mark::untrusted()` strips *sentinels only*, while
`Sanitize::untrusted()` strips *ANSI* — different sweeps, one word. Any other host of marked frames owns the same responsibility —
the fleet-wide write-up is `docs/TRUST-BOUNDARY.md` in the sugarcraft
monorepo. The reservation is fleet-wide: image-overlay markers were moved to
U+E002+ so they can never collide **as tags** with this arena by
construction — they remain ordinary visible cells to the scanner, see the
lone-sentinel rule above.

Scanning *reads* the sentinels from the rendered string to compute zone
bounding boxes; it never modifies the string. Stripping the sentinels from
the bytes actually written to the terminal is the consumer's job (e.g.
candy-code's renderer scans, then strips; `Manager::scan()` returns the
stripped, display-ready frame).

## Scan rules

The instance method `parse()` (call it on a `new Scan()`, or go through
`Scanner::new()->scan()`) decodes exactly the grammar `Mark` encodes: a tag
is `U+E000 [/] <id> U+E001` with an id `Mark::isValidId()` accepts, and every
byte of a tag is zero-width. Anything else is measured as text, so a stray
sentinel cannot re-rectangle the zones after it (see the identity-vs-width
caveat above). The static `Scan::strip()` removes exactly the well-formed
tags — the public half of the parse/strip pair:

| Input to `parse()` | Result |
|---|---|
| Duplicate id in one render | throws `InvalidArgumentException` |
| Orphan close / unclosed open | tag skipped, no zone (a frame clipped to the viewport legitimately cuts one end off a zone) |
| Zone whose content paints no cell (`''`, `"\n"`, SGR only) | no zone — there is no cell to hit, so it cannot steal a neighbour's clicks |
| Empty-id tag (`U+E000 U+E001`) | zero-width, inert |
| Lone sentinel, or an id with spaces/escapes/non-ASCII/over `Mark::MAX_ID_BYTES` | only the 3 sentinel bytes are skipped; the following bytes count as visible text |

Line endings: `\n` and `\r\n` both start a new row. A lone `\r` returns to
column 1 on the same row, as a terminal does; a zone that paints again after
it widens its bounding box to start at column 1, and a zone that opened
mid-row but painted nothing before the `\r` (a progress-bar redraw) covers only
the cells it painted after it. The same holds for a leading `\n`: a zone
that painted nothing before the newline starts on the first row it paints,
at column 1, whatever column it opened on — `'123456789' . zone("\nabc")`
and a bare `zone("\nabc")` opened at column 1 are both cols 1-3 of row 2.
This departs from bubblezone's behavior, which keeps the open position: opened
past the content's last column it reports an inverted rectangle no click
ever matches; opened short of it (`'12' . zone("\nabcdef")`) a box that
claims unpainted cols 3-6 of row 1 while missing the painted cols 1-2 of
row 2; and opened at column 1 a box that also spans the unpainted row 1.

## Multi-row zones

A zone spanning multiple rows (e.g. `"line1\nline2"`) is stored as the
**smallest axis-aligned bounding box** that contains all cells it painted.
Consequences worth knowing before you mark reflowed or indented text:

- The box is a union rectangle: an interior cell that was never part of the
  content (the gap beside a short line) still reports as inside the zone.
  The hit-test for multi-line content is approximate *by design*.
- Only rows the zone actually painted extend it. Interior blank rows between
  painted rows stay covered (both ends are the zone's own cells); trailing
  blank lines inside the tags — content ending `"\n\n"` before the close
  tag — add nothing, so the box ends on the last row that holds a painted
  cell of the zone.

## Mouse-tracking modes

This lib is a text-processing component: it never writes to the TTY itself.
The terminal must be told to report mouse events before any tracker can see
one, and the trackers need different reporting:

| Mode | Reports | Needed by |
|---|---|---|
| `1000` | press / release / wheel | `ZoneClickTracker`, `ClickCounter` (clean press+release pairs, clicks) |
| `1002` | 1000 + drag (button held, crossing a cell) | `DragTracker` boundary crossings while pressed |
| `1003` | all motion, button held or not | `ZoneHoverTracker` (enter/exit with no press at all) |
| `1006` | SGR-style extended coordinates: reports arrive as `CSI < b ; x ; y M|m` — `M` press, `m` release, `b` the button code plus modifier bits | any of the above beyond row/col 223, where legacy X10 single-byte coordinates saturate |

`Manager::setMotionTracking(bool)` returns the all-motion byte string:
`\x1b[?1003h\x1b[?1006h` on enable and the LIFO `\x1b[?1006l\x1b[?1003l` on
disable — motion tracking plus the SGR coordinate companion, byte-identical
to sugar-core's `Ansi::mouseAllMotionOn()/Off()`. Core ships the same pairing
for the plain-click case (`mouseAllOn/Off` = 1000+1006) and the held-drag
case (`mouseCellMotionOn/Off` = 1002+1006). The other modes are the host
app's enable sequence; its input layer (e.g. sugar-input) turns the reports
into `MouseMsg` / `MouseEvent` values, which means the `b` field is decoded
before this lib ever sees a message: strip the modifier bits (4 shift, 8
alt, 16 ctrl; 32 marks a held-drag motion) before comparing button numbers,
and fold xterm's wheel pair (64 = up, 65 = down) into one wheel event.
Consequence: the hover tracker reacts to press and motion reports —
enter/exit fire on a plain `1000` press — but wheel reports are inert for
all three `Zones` trackers and for `ClickCounter` (a scroll is not a press;
see `MouseWheelMsg` gates). Press-free hover needs motion reporting
(`1003`); the drag tracker's cross-boundary moves need at least `1002`;
click trackers work on plain `1000` reporting alone.

**Mode lifecycle.** This lib never touches the TTY, so it cannot undo what
it never wrote: whoever enables `1000`/`1002`/`1003`/`1006` owns the
disable on every exit path — including crashes (a `register_shutdown` hook
or the frame runner's `finally`). A leaked `?1003` streams every mouse move
into the session for its remaining lifetime, a continuous byte flood that
hits assistive tech hardest (Orca and brltty users cannot wave it away).
Interop floor: `1002`/`1003` are the xterm family — Konsole ships no 1003
and tmux re-encodes reports — so design for `1000`-only degradation when
motion reports never arrive.

## Zones façade (`SugarCraft\Mouse\Zones`)

Wrap rendered chunks with named markers, let the `Manager` discover their
bounding boxes, then ask zones whether a
{@see \SugarCraft\Core\Msg\MouseMsg} fell inside them. The `Zones` layer is
the app-facing surface; the marker scheme and its parser live once in the
primitive `Mark` / `Scan` classes of this same package and are reused by
every SugarCraft zone consumer. `mark()` encodes through
`SugarCraft\Mouse\Mark::zone()` (which validates via `wrap()`), and `scan()`
takes its bounding boxes from the instance `parse()` method of a constructed
`SugarCraft\Mouse\Scan`, then strips
the markers so the returned frame is display-ready.

```php
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseAction;   // the WIRE enum (Press / Release / Motion)
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Mouse\Zones\Manager;

$z = Manager::newGlobal();

// Build a frame
$btnOk     = $z->mark('btn:ok',     '  OK  ');
$btnCancel = $z->mark('btn:cancel', 'Cancel');
$frame     = $btnOk . '   ' . $btnCancel;

// Scan once before printing — Manager records marker positions and strips them.
$displayable = $z->scan($frame);
echo $displayable;

// Later, when a MouseMsg arrives (1-based col, row — the same grid `get()`
// measures; here col 5 lands inside the six-cell "  OK  " button):
$click = new MouseMsg(5, 1, MouseButton::Left, MouseAction::Press);
if ($z->get('btn:ok')?->inBounds($click)) {
    // ...
}
```

Mind the two `MouseAction` enums: `SugarCraft\Core\MouseAction` is the
wire-level event kind carried by `MouseMsg` (above), while
`SugarCraft\Mouse\MouseAction` is this library's primitive marker-action
enum (with the X10 button constants). Same class name, different namespaces
— if a file needs both, alias one.

Never mark text you did not author without sweeping it first: a label that
already contains `U+E000 <id> U+E001` is structurally a tag, so
`$z->mark('item', Mark::untrusted($userLabel))` strips the framing bytes
(outside any id charset) before wrapping.

Markers are **private-use-area sentinel tags** — zero-width *to the
scanner* and not ANSI, so they never affect the recorded layout; hosts may
still render unknown PUA codepoints, see
[Sentinel design and trust boundary](#sentinel-design-and-trust-boundary)
for the full threat model):

```
open:  U+E000 <id> U+E001
close: U+E000 /<id> U+E001
```

`Manager::scan()` computes each zone's bounding box in 1-based terminal
cells, accounting for ANSI styling and Unicode (CJK) width, and takes the
optional `?int $width` clamp the primitive exposes.

**Two engines, two rules — and a persistent registry.** The primitive
`Scanner::scan()` REPLACES its whole zone set from the frame it parses, and
its `hit()` resolves ties by earliest registration. `Manager::scan()`
MERGES into its registry: ids re-recorded in a new frame are replaced, ids
not present are kept — a zone whose widget stopped rendering STAYS
clickable until something replaces it under the same id, `clear($id)`
removes it, or `close()` wipes the registry — so re-scan every frame you
want authoritative, and clear ids you retire. Manager hit-testing resolves
the SMALLEST covering area first (nested components: innermost wins), ties
between equal areas go to the first-recorded zone — matching the
primitive's registration-order rule. Use `allInBounds($msg)` for the full
stack (smallest first) and `zoneAt($col, $row)` to hit-test cells without a
mouse message (scroll wheel landed here, tooltip anchor, etc.).

**Id format:** the marked id — including any manager prefix, i.e.
`prefix() . $id` — must match `\A[A-Za-z0-9._:-]+\z` (ASCII letters, digits,
and `._:-`, byte-wise). The primitive validates this in `Mark::wrap()` and
`mark()` throws `\InvalidArgumentException` on a bad id, since an id carrying a
sentinel / control / whitespace byte would desync zone scanning. The real
consumer ids (`tab-N`, `cell:N`, `item:N`) and the auto-generated prefixes
(`1-`, `2-`, …) already comply.

### Manager API

Beyond `mark()` / `scan()` / `get()`:

- `setEnabled(bool)` / `isEnabled()` — flip marker emission off in
  non-interactive contexts (CI logs, file dumps). When off, `mark()`
  returns content verbatim and `scan()` is identity.
- `Manager::newPrefix(string $prefix = '')` — namespace every id with a
  prefix so two SugarMouse-aware components don't collide on `'item-0'`.
  Auto-generates a monotonic prefix when called bare.
- `prefix()` — read-only accessor for the prefix string.
- `get($id)` / `all()` / `clear(?$id)` — single-zone lookup, every
  zone, and targeted-or-wipe-all clear.
- `close()` — drop every zone + flip the manager into pass-through
  mode. Idempotent. To re-arm a closed default manager call
  `Zones::setDefaultManager(null)` (forces lazy reconstruction of a
  fresh enabled manager on the next `defaultManager()` call).
  `Zones::setEnabled(true)` instead only flips marking back on — the
  zone registry is already gone, so you must `scan()` again before
  any hit-test resolves.

### Package-level facade

`SugarCraft\Mouse\Zones\Zones` follows the package-level surface of
bubblezone (`DefaultManager` + `Mark` / `Scan` / `Clear` / `Get` /
`Close` / `SetEnabled` / `Enabled` / `NewPrefix` / `AnyInBounds*`)
as static methods over a single shared `Manager`:

```php
use SugarCraft\Mouse\Zones\Zones;

$marked = Zones::mark('header', $header);
$cleaned = Zones::scan($marked);
if (Zones::get('header')?->inBounds($mouse)) { /* … */ }
```

`Zones::setDefaultManager(?Manager)` swaps in a custom manager —
useful in tests (`Zones::setDefaultManager(null)` flushes state) or
when you want every package-level call routed through a prefixed
manager.

### Hover tracking

`ZoneHoverTracker` wraps a `Manager` and tracks which zone the cursor
is in across `MouseMsg` events. It emits `ZoneEnterMsg` when the cursor
crosses into a zone and `ZoneExitMsg` when it leaves — ideal for
tooltips, highlights, or data-fetch-on-hover. `update()` gates press and
motion but refuses wheel reports (`MouseWheelMsg`): a plain mode-`1000`
press inside a zone fires `ZoneEnterMsg`, scrolling does not, and
press-free hover tracking needs the terminal to report motion (mode
`1003`, see
[Mouse-tracking modes](#mouse-tracking-modes)):

```php
use SugarCraft\Mouse\Zones\Manager;
use SugarCraft\Mouse\Zones\ZoneHoverTracker;
use SugarCraft\Mouse\Zones\Msg\ZoneEnterMsg;
use SugarCraft\Mouse\Zones\Msg\ZoneExitMsg;

$tracker = new ZoneHoverTracker($manager);
// $manager must already have run scan() to populate zone registry.

[$tracker, $msg] = $tracker->update($mouseMsg);
if ($msg instanceof ZoneEnterMsg) {
    // cursor entered $msg->zone
} elseif ($msg instanceof ZoneExitMsg) {
    // cursor left $msg->zone
}
```

**Boundary crossing:** moving directly from zone A to zone B produces
an exit for A first; call `update()` again to receive the enter for B.
This two-step pattern lets the Program animate the exit before routing
the enter. `DragTracker` is deliberately the opposite: one boundary
crossing emits its `ZoneDragMoveMsg` in the same call — do not re-feed
the event there or you will spin.

**State accessors:**
- `currentZoneId()` — id of the hovered zone, or null
- `currentZone()` — `Zone` object, or null
- `withManager(Manager)` — rebind to a different manager (e.g. a
  prefixed manager in a sub-component)
- `withCurrentZoneId(?string)` — restore from a serialized state; null clears the hovered state

### Drag tracking

`DragTracker` wraps a `Manager` and tracks press → move → release
drag sequences within and across zones. It emits a `ZoneDragStartMsg` on
button-down inside a zone, `ZoneDragMoveMsg` when the cursor crosses a
zone boundary while dragging, and `ZoneDragEndMsg` on button release.
Cross-boundary moves only arrive when the terminal reports drag or all
motion (mode `1002` / `1003`, see
[Mouse-tracking modes](#mouse-tracking-modes)):

```php
use SugarCraft\Mouse\Zones\Manager;
use SugarCraft\Mouse\Zones\DragTracker;
use SugarCraft\Mouse\Zones\Msg\ZoneDragStartMsg;
use SugarCraft\Mouse\Zones\Msg\ZoneDragMoveMsg;
use SugarCraft\Mouse\Zones\Msg\ZoneDragEndMsg;

$tracker = new DragTracker($manager);
// $manager must already have run scan() to populate zone registry.

[$tracker, $msg] = $tracker->update($mouseMsg);
if ($msg instanceof ZoneDragStartMsg) {
    // drag started in $msg->originZone
} elseif ($msg instanceof ZoneDragMoveMsg) {
    // cursor crossed from $msg->originZone into $msg->currentZone
} elseif ($msg instanceof ZoneDragEndMsg) {
    // drag ended; started at $msg->originZone, released over
    // $msg->currentZone (null when the release was outside every zone)
}
```

**Origin vs. current zone:** the origin zone is fixed for the entire
drag and never changes. The current zone updates whenever the cursor
crosses a zone boundary during the drag. The end message's current zone
is resolved from the release event itself — the zone under the cursor at
release, or null outside all zones — not the last crossed one.

**Boundary crossing:** moving directly from zone A to zone B while
dragging emits ONE `ZoneDragMoveMsg(originZone: A, currentZone: B)` in
that same `update()` call.

**Button identity:** the Press records which button started the drag; a
Release of a different button is inert. Releasing the drag button always
ends the drag, even when the origin zone has vanished from the manager
(that end is silent, but the tracker clears instead of wedging).

**Click vs. zero-distance drag:** a press and release in the same spot of
the same zone satisfies both state machines — `ClickCounter` counts it and
`DragTracker` emits `ZoneDragStartMsg` followed by `ZoneDragEndMsg` with
`currentZone === originZone`. The façade ships no travel threshold
(bubblezone has none either); if a widget wants EITHER a click OR a drag
per gesture, gate drag effects on a start/end box difference or on at
least one emitted `ZoneDragMoveMsg`.

**State accessors:**
- `originZoneId()` / `originZone()` — zone the drag started from, or null
- `currentZoneId()` / `currentZone()` — zone the cursor is in, or null
- `dragButton()` — button that started the drag, or null
- `withManager(Manager)` — rebind to a different manager (state carried)
- `withZoneIds(?string $origin, ?string $current)` — restore from
  a serialized id snapshot (button identity is not carried by ids)

### Click tracking

`ClickCounter` wraps a `Manager` and tracks double/triple click
streaks inside zones. It emits `DoubleClickMsg` on the second press
and `TripleClickMsg` on the third press — all within a configurable
click interval (default 500 ms). The streak resets when the interval
expires or when a PRESS lands in a different zone (or outside every zone);
hover movement never touches the streak, and wheel reports are inert. Presses arrive
with plain mode-`1000` reporting (see
[Mouse-tracking modes](#mouse-tracking-modes)):

```php
use SugarCraft\Mouse\Zones\Manager;
use SugarCraft\Mouse\Zones\ClickCounter;
use SugarCraft\Mouse\Zones\Msg\DoubleClickMsg;
use SugarCraft\Mouse\Zones\Msg\TripleClickMsg;

$counter = new ClickCounter($manager);
// $manager must already have run scan() to populate zone registry.

[$counter, $msg] = $counter->update($mouseMsg);
if ($msg instanceof DoubleClickMsg) {
    // second press in same zone within interval
} elseif ($msg instanceof TripleClickMsg) {
    // third press in same zone within interval
}
```

**State accessors:**
- `clickCount()` — current streak count (0 when no streak is active)
- `withManager(Manager)` — rebind to a different manager (the streak is
  carried)
- `$counter->manager` / `$counter->clickIntervalMs` — public **readonly**
  properties; rebind via `withManager()` or tune by constructing a new
  counter. The third constructor parameter, `?callable $now`, is a test
  clock seam (defaults to `microtime(true)`); there are no setters.

### Motion tracking escape sequences

`Manager::setMotionTracking(bool $on)` returns the terminal escape
sequence that enables (`\x1b[?1003h\x1b[?1006h`) or disables
(`\x1b[?1006l\x1b[?1003l`) all-motion tracking (mode 1003) together
with SGR extended coordinates (mode 1006). Write the returned string to
the TTY to activate motion reporting before processing mouse move
events. This manager does not directly emit — it is a
text-processing component that produces the raw CSI sequence.

### Event vocabularies

Three action vocabularies meet here, and mixing them up is the classic
integration bug:

- **Core wire** (`SugarCraft\Core\MouseAction`): `Press` / `Release` /
  `Motion`, carried on `MouseMsg`; wheel is a distinct MESSAGE TYPE
  (`MouseWheelMsg`) whose action field core's reader fills with `Press`.
- **Primitive** (`SugarCraft\Mouse\MouseAction`): `Press` / `Release` /
  `Drag` / `Scroll`, carried on `MouseEvent` with X10 button ints — what
  `ZoneClickTracker` and `Selection` consume.
- **Wire bytes** (SGR mode 1006): one integer `b` encoding button plus
  modifier bits — decoded by your input layer before either enum exists.

`Zones\Zone::toMouseEvent()` is the one public bridge (Core → primitive,
wheel type matched first so it becomes `Scroll`, not `Press`).

### Coordinate order

Nearly everything is **(col, row)** — `Scanner::hit($col, $row)`,
`Manager::zoneAt($col, $row)`, `Zone::pos()` returning `[col, row]`, and
`MouseMsg`'s own `$x`/`$y`. Two islands are row-first by design, because a
text selection is addressed like a file:

| API | Order |
|---|---|
| `Selection::new($rowFrom, $rowTo, $colFrom, $colTo)`, `begin($row, $col)`, `dragTo($row, $col)` | row, col |
| `SelectionRange::new($rowA, $colA, $rowB, $colB)`, `extract()` indexing `$lines[$row - 1]` | row, col |

`SelectionRange`'s promoted fields are named (`startRow`, `startCol`, …),
so construction from a `range()` result is unambiguous.

### Accessibility

Zone tags are private-use-area codepoints: screen readers announce them as
Dotted-Circle garbage or "unknown character" noise, and the mouse
trackers (hover, drag, multi-click) are pointer-only affordances. Ship a
keyboard path for every zone-driven action, print ONLY the stripped frame
(`Manager::scan()` returns it display-ready — never `echo` marked bytes),
and follow the mode-lifecycle rules above so assistive terminals are not
flooded by leaked `?1003` motion reporting.

### Tips

- Each id should be unique within a `Manager`. Use
  `Manager::newPrefix()` per UI sub-tree so two child models don't
  shadow each other's ids.
- Run `scan()` once on the **full root frame**, not per sub-tree —
  nested zone bounds depend on the outer layout.
- `Manager::scan()` strips the markers from its return value, so
  measuring what you print (sugar-core's `Width::string()`) sees
  only visible cells.
- `Zone::isZero()` reports whether both corners are still 0 — the shape
  bubblezone's `Get()` returned for an unknown id. It is a compatibility
  check, NOT a content discriminator: `Scan` never records a zone that
  painted no cell, and every stored zone has corners ≥ 1, so `get()`
  returning null is how "never rendered" surfaces here.
- Organic shapes (ASCII art) report a rectangular bounding box —
  the marker pair only carries 4 corners' worth of information.
- Exception and refusal messages resolve through `SugarCraft\Mouse\Lang`
  against `lang/en.php` (five keys, `sugar-mouse:`-prefixed); the
  package ships `en` only.
- `scan()` is synchronous (no background worker), so
  `close()` is purely a state reset / disable rather than a thread
  join.
- `scan()` strips exactly the tags the primitive `Scan`'s instance `parse()`
  decodes:
  `U+E000 [/] <id> U+E001` where `<id>` passes `Mark::isValidId()` (or is
  empty) is removed whole. Any other sentinel — an open `U+E000` with no
  closing `U+E001`, one whose would-be id holds a space/newline/CR/escape/
  non-ASCII byte or exceeds `Mark::MAX_ID_BYTES`, or a bare `U+E001` — loses
  only its own 3 bytes, and the text after it is kept, because the scanner
  measures that text as visible cells. Orphan closes / unclosed opens with a
  valid id are removed and record no zone, but every open/close tag pair
  should still be balanced for a zone's bounds to be discovered.

### API summary

| Class | Method | Description |
|---|---|---|
| `Manager` | `newGlobal()` | Build a fresh un-prefixed manager independent of every other instance — NOT the shared default (that is `Zones::defaultManager()`) |
| `Manager` | `newPrefix(prefix)` | Create prefixed manager for isolation |
| `Manager` | `mark(name, rendered)` | Wrap output with zone marker |
| `Manager` | `scan(output, ?width = null)` | Merge-record zones into the registry (re-recorded ids replace, absent ids persist; a `newPrefix()` manager skips ids outside its prefix, a `newGlobal()` manager records every id), strip markers, clamp columns to `?width` |
| `Manager` | `anyInBounds(mouseMsg)` | Return the innermost zone under the mouse |
| `Manager` | `get(name)` | Get zone by name |
| `Manager` | `setMotionTracking(bool)` | Return paired CSI 1003+1006 enable / LIFO disable sequence |
| `Manager` | `allInBounds(mouseMsg)` | Every covering zone, smallest area first |
| `Manager` | `zoneAt(col, row)` | Cell hit-test without a mouse message |
| `Manager` | `anyInBoundsAndUpdate(model, msg)` | Dispatch `MsgZoneInBounds` into a Model |
| `Manager` | `clear(?id)` / `close()` | Drop one zone (id or wire id) / wipe registry + disable |
| `Zone` | `toMouseEvent(mouseMsg)` | Core→primitive action/button bridge |
| `Scan` | `strip(rendered)` | Public byte-wise removal of well-formed tags |
| `Mark` | `untrusted(text)` | Sweep foreign sentinel framing bytes off untrusted labels |
| `Zones` (static) | `mark` / `scan` / `get` / `clear` / `close` / `setEnabled` / `isEnabled` / `newPrefix` / `anyInBounds*` | Package-level facade over the default manager |
| `Zones` (static) | `defaultManager()` | Read the shared default `Manager` the package-level facade routes through (`Manager::newGlobal()` builds a different, independent instance) |
| `Zone` | `inBounds(mouseMsg)` | Test if mouse is inside zone |
| `ZoneHoverTracker` | `__construct(manager)` | Track hover state over a manager |
| `ZoneHoverTracker` | `update(mouseMsg)` | Process mouse event, return enter/exit msg |
| `ZoneHoverTracker` | `currentZone()` | Get the hovered Zone or null |
| `ZoneEnterMsg` | `zone` | Zone the cursor just entered |
| `ZoneExitMsg` | `zone` | Zone the cursor just left |
| `DragTracker` | `__construct(manager)` | Track drag sequences over a manager |
| `DragTracker` | `update(mouseMsg)` | Process mouse event, return drag msg |
| `DragTracker` | `originZone()` | Get the origin Zone or null |
| `DragTracker` | `currentZone()` | Get the current Zone or null |
| `ZoneDragStartMsg` | `originZone / currentZone` | Zone where drag started; zone at current cursor |
| `ZoneDragMoveMsg` | `originZone / currentZone` | Fixed origin zone; zone cursor just crossed into |
| `ZoneDragEndMsg` | `originZone / currentZone` | Zone drag started from; zone at release |
| `ClickCounter` | `__construct(manager, clickIntervalMs)` | Track double/triple click streaks |
| `ClickCounter` | `update(mouseMsg)` | Process press event, return double/triple msg |
| `ClickCounter` | `clickCount()` | Current streak count (0 = no streak) |
| `DoubleClickMsg` | `zone` | Zone of the second press |
| `TripleClickMsg` | `zone` | Zone of the third press |

## Examples

Runnable, self-explanatory files under `examples/` (after
`composer install`, run e.g. `php examples/zones-buttons.php`):

- `zones-buttons.php` — a button row through `Manager::newGlobal()`,
  marking, scanning and resolving clicks to zone ids.
- `zones-list-default.php` — a selectable list: one manager, per-row
  marked items, click-to-focus resolution.
- `zones-nested-components.php` — two `Manager::newPrefix()` managers
  (left/right panes) keeping identically-named ids from shadowing each
  other.

## Where this fits

The whole stack consumes this package through one of its two layers:
sugar-widgets and sugar-gallery drive the `Zones` façade; candy-code's TUI
runs the primitive `Selection` / `SelectionRange` machine for its
mouse text-selection, and its renderer owns the sentinel sweep described
above.

## Credits & inspiration

Originally inspired by [lrstanley/bubblezone](https://github.com/lrstanley/bubblezone) and the wider Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project. The Mark/Scan/Get pattern follows bubblezone's API; `ZoneClickTracker` addresses [bubblezone issue #10](https://github.com/lrstanley/bubblezone/issues/10).
