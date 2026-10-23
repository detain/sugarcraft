# SugarMouse

> Formerly published as `sugarcraft/candy-mouse` (renamed 2026-10); the former
> `candy-zone` package was absorbed into this one (2026-10). <!-- @rename-keep -->

[![codecov](https://codecov.io/gh/sugarcraft/sugar-mouse/branch/master/graph/badge.svg?flag=sugar-mouse)](https://codecov.io/gh/sugarcraft/sugar-mouse)

Mouse zones for terminal apps — wrap rendered chunks with invisible zone
markers, scan the frame to discover each zone's bounding box, then ask which
zone a mouse event landed in. One package, two layers:

- **Primitive layer (`SugarCraft\Mouse`)** — self-contained Mark/Scan/Get
  hit-testing, `Scanner`, `ZoneClickTracker` press/release dedup, and the
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

## Quickstart

```php
<?php

require 'vendor/autoload.php';

use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\MouseEvent;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Mouse\ZoneClickTracker;

// 1. Wrap interactive content with invisible zone markers.
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
| `Mark` | Wrap content with invisible Unicode sentinel markers (`Mark::new()`, `Mark::zone()`, `Mark::disabled()`; `Mark::isValidId()` is the id predicate both encoder and decoder share) |
| `Scan` | Stateless, reentrant decoder behind `Scanner` — `parse($rendered, ?$width)` returns `id => Zone` |
| `Scanner` | Parse sentinels; `get(id)` and `hit(col, row)` lookups |
| `Zone` | Readonly bounding box (start/end col/row) |
| `ZoneClickTracker` | Press+Release dedup per button |
| `MouseEvent` | Immutable event (x, y, button, action enum) |
| `MouseAction` | `Press` / `Release` / `Drag` / `Scroll` enum |
| `Selection` | Press → drag → release text-selection state machine over a selectable region |
| `SelectionRange` | Normalised, inclusive stream range a selection covers; `extract()` copies its text out of a frame |

`Selection` / `SelectionRange` were lifted from candy-code's
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

**What they defend against.** ANSI escape sequences and ordinary ASCII UI
text can never carry these bytes, so normal rendering cannot fabricate a
zone boundary. But the PUA is not empty space: pasted foreign frames,
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
  costs exactly its own three bytes when scanning; everything after it is
  measured as visible text. Foreign PUA bytes can never swallow a span of
  the frame and shift the zones to its right or below it.
- **Bounded work.** `Mark::MAX_ID_BYTES` is checked before any substring
  is materialised, so an attacker-sized span between a stray `U+E000` and a
  distant `U+E001` is rejected in O(1).

**The consumer's duty.** Anything pasted or otherwise untrusted must be
swept *before* it is composed into a marked frame, via sugar-core's
`Sanitize::stripZoneSentinels()` / `Sanitize::untrustedForMarkedFrames()`
(candy-code's renderer does exactly this on its input path). Any other host
of marked frames owns the same responsibility — see
[`docs/TRUST-BOUNDARY.md`](../docs/TRUST-BOUNDARY.md). The reservation is fleet-wide: image-overlay markers were moved to
U+E002+ precisely so they can never collide with this arena by construction.

Scanning *reads* the sentinels from the rendered string to compute zone
bounding boxes; it never modifies the string. Stripping the sentinels from
the bytes actually written to the terminal is the consumer's job (e.g.
candy-code's renderer scans, then strips; `Manager::scan()` returns the
stripped, display-ready frame).

## Scan rules

`Scan::parse()` decodes exactly the grammar `Mark` encodes: a tag is
`U+E000 [/] <id> U+E001` with an id `Mark::isValidId()` accepts, and every
byte of a tag is zero-width. Anything else is measured as text, so a stray
sentinel can never shift the zones after it:

| Input | Result |
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
This departs from the original design's behaviour, which keeps the open position: opened
past the content's last column it reports an inverted rectangle no click
ever matches; opened short of it (`'12' . zone("\nabcdef")`) a box that
claims unpainted cols 3-6 of row 1 while missing the painted cols 1-2 of
row 2; and opened at column 1 a box that also spans the unpainted row 1.

## Multi-row zones

A zone spanning multiple rows (e.g. `"line1\nline2"`) is stored as the **smallest axis-aligned bounding box** that contains all marked cells.  The `inBounds()` check uses this rectangle, so an interior cell that was never part of the original content may still report as inside the zone.  Callers wrapping reflowed or indented text should be aware that the hit-test is approximate for multi-line content.

## Mouse-tracking modes

This lib is a text-processing component: it never writes to the TTY itself.
The terminal must be told to report mouse events before any tracker can see
one, and the trackers need different reporting:

| Mode | Reports | Needed by |
|---|---|---|
| `1000` | press / release / wheel | `ZoneClickTracker`, `ClickCounter` (clean press+release pairs, clicks) |
| `1002` | 1000 + drag (button held, crossing a cell) | `DragTracker` boundary crossings while pressed |
| `1003` | all motion, button held or not | `ZoneHoverTracker` (enter/exit with no press at all) |
| `1006` | SGR extended coordinates (`CSI <x;yM/m`) | any of the above beyond row/col 222, where legacy X10 encoding saturates |

`Manager::setMotionTracking(bool)` returns the `CSI ?1003 h/l` byte string
for the all-motion case — write it to the terminal yourself. The other modes
are the host app's enable sequence (its input layer — e.g. sugar-input —
turns the reports into `MouseMsg` / `MouseEvent` values). Consequence: the
hover tracker emits nothing unless motion is reported (`1003`), and the drag
tracker's cross-boundary moves need at least `1002`; click trackers work on
plain `1000` reporting alone.

## Zones façade (`SugarCraft\Mouse\Zones`)

Wrap rendered chunks with named markers, let the `Manager` discover their
bounding boxes, then ask zones whether a
{@see \SugarCraft\Core\Msg\MouseMsg} fell inside them. The `Zones` layer is
the app-facing surface; the marker scheme and its parser live once in the
primitive `Mark` / `Scan` classes of this same package and are reused by
every SugarCraft zone consumer. `mark()` encodes through
`SugarCraft\Mouse\Mark::zone()` (which validates via `wrap()`), and `scan()`
takes its bounding boxes from `SugarCraft\Mouse\Scan::parse()`, then strips
the markers so the returned frame is display-ready.

```php
use SugarCraft\Mouse\Zones\Manager;

$z = Manager::newGlobal();

// Build a frame
$btnOk     = $z->mark('btn:ok',     '  OK  ');
$btnCancel = $z->mark('btn:cancel', 'Cancel');
$frame     = $btnOk . '   ' . $btnCancel;

// Scan once before printing — Manager records marker positions and strips them.
$displayable = $z->scan($frame);
echo $displayable;

// Later, when a MouseMsg arrives:
if ($z->get('btn:ok')?->inBounds($mouseMsg)) {
    // ...
}
```

Markers are invisible **private-use-area sentinels** (not visible text or
ANSI, so they don't affect layout — see
[Sentinel design and trust boundary](#sentinel-design-and-trust-boundary)
for the full threat model):

```
open:  U+E000 <id> U+E001
close: U+E000 /<id> U+E001
```

`Manager::scan()` computes each zone's bounding box in 1-based terminal
cells, accounting for ANSI styling and Unicode (CJK) width.

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
  fresh enabled manager on the next `defaultManager()` call) or
  `Zones::setEnabled(true)` to keep zone state but re-enable marking.

### Package-level facade

`SugarCraft\Mouse\Zones\Zones` follows the package-level surface of the
original design (`DefaultManager` + `Mark` / `Scan` / `Clear` / `Get` /
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
tooltips, highlights, or data-fetch-on-hover. The terminal must be
reporting motion (mode `1003`, see
[Mouse-tracking modes](#mouse-tracking-modes)) for this tracker to
see anything at all:

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
the enter.

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
    // drag ended; started at $msg->originZone, released at $msg->currentZone
}
```

**Origin vs. current zone:** the origin zone is fixed for the entire
drag and never changes. The current zone updates whenever the cursor
crosses a zone boundary during the drag.

**Boundary crossing:** moving directly from zone A to zone B while
dragging produces a move message for A first; call `update()` again to
receive the move for B. This two-step pattern lets the Program animate
the transition before routing the next enter.

**State accessors:**
- `originZoneId()` / `originZone()` — zone the drag started from, or null
- `currentZoneId()` / `currentZone()` — zone the cursor is in, or null
- `withManager(Manager)` — rebind to a different manager
- `withZoneIds(?string $origin, ?string $current)` — restore from
  a serialized state

### Click tracking

`ClickCounter` wraps a `Manager` and tracks double/triple click
streaks inside zones. It emits `DoubleClickMsg` on the second press
and `TripleClickMsg` on the third press — all within a configurable
click interval (default 500 ms). The streak resets when the interval
expires or when the cursor moves to a different zone. Presses arrive
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
- `withManager(Manager)` — rebind to a different manager
- `$counter->manager` / `$counter->clickIntervalMs` — public
  constructor params for rebinding / tuning

### Motion tracking escape sequences

`Manager::setMotionTracking(bool $on)` returns the terminal escape
sequence that enables (`\x1b[?1003h`) or disables (`\x1b[?1003l`)
SGR mouse mode 1003 (all motion events). Write the returned string to
the TTY to activate motion reporting before processing mouse move
events. This manager does not directly emit — it is a
text-processing component that produces the raw CSI sequence.

### Tips

- Each id should be unique within a `Manager`. Use
  `Manager::newPrefix()` per UI sub-tree so two child models don't
  shadow each other's ids.
- Run `scan()` once on the **full root frame**, not per sub-tree —
  nested zone bounds depend on the outer layout.
- `Manager::scan()` strips the markers from its return value, so
  measuring what you print (sugar-core's `Width::string()`) sees
  only visible cells.
- `Zone::isZero()` distinguishes "never rendered" from "rendered but
  empty bounding box".
- Organic shapes (ASCII art) report a rectangular bounding box —
  the marker pair only carries 4 corners' worth of information.
- `scan()` is synchronous (no background worker), so
  `close()` is purely a state reset / disable rather than a thread
  join.
- `scan()` strips exactly the tags the primitive `Scan::parse()` decodes:
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
| `Manager` | `newGlobal()` | Create global manager |
| `Manager` | `newPrefix(prefix)` | Create prefixed manager for isolation |
| `Manager` | `mark(name, rendered)` | Wrap output with zone marker |
| `Manager` | `scan(output)` | Record positions, strip markers |
| `Manager` | `anyInBounds(mouseMsg)` | Return the innermost zone under the mouse |
| `Manager` | `get(name)` | Get zone by name |
| `Manager` | `setMotionTracking(bool)` | Return CSI 1003 h/l escape sequence |
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

## Where this fits

The whole stack consumes this package through one of its two layers:
sugar-widgets and sugar-gallery drive the `Zones` façade; candy-code's TUI
runs the primitive `Selection` / `SelectionRange` machine for its
mouse text-selection, and its renderer owns the sentinel sweep described
above.

## Credits & inspiration

Originally inspired by [lrstanley/bubblezone](https://github.com/lrstanley/bubblezone) and the wider Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project. The Mark/Scan/Get pattern follows bubblezone's API; `ZoneClickTracker` addresses [bubblezone issue #10](https://github.com/lrstanley/bubblezone/issues/10).
