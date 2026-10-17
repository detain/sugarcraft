# SugarMouse

> Formerly published as `sugarcraft/candy-mouse` (renamed 2026-10). <!-- @rename-keep -->

Self-contained Mark/Scan/Get mouse hit-testing plus `ZoneClickTracker` for press/release deduplication. No external Manager wiring needed.

```
composer require sugarcraft/sugar-mouse: dev-master
```

## Role

Replaces the model where consumers wire `sugar-mouse`'s `Manager` externally. Each consumer owns its own `Scanner` instance — click handling stays local, no shared global state.

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

// 4. A click emits only when the release lands on the pressed zone,
//    so track the full Press+Release pair.
$tracker = new ZoneClickTracker();
$tracker->track(MouseEvent::press(4, 1), $zone);
$click = $tracker->track(MouseEvent::release(4, 1), $zone);
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

`Selection` / `SelectionRange` were lifted from sugar-crush's
`Tui\TextSelection`, and sugar-crush now runs on them: `Tui\TextSelection` is
an immutable adapter over a cloned `Selection` machine, reads its geometry and
copied text through `SelectionRange`, and speaks the same 1-based cells end to
end. What it keeps locally is what this lib leaves to the app — a
drift-latched dragging flag, the release/copy record, and the highlight
repaint.

## Sentinel design

Sentinels use private-use codepoints U+E000 (open) and U+E001 (close) — they never collide with ANSI SGR sequences or regular text, and terminal emulators render them invisibly. Scanning *reads* them from the rendered string to compute zone bounding boxes; it does not modify the string. Stripping the sentinels from the bytes actually written to the terminal is the consumer's job (e.g. sugar-crush's renderer scans, then strips).

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

## See also

[sugar-mouse](../sugar-mouse) is the app-facing façade (`Manager`, hover /
drag / multi-click trackers, package-level `Zones`) layered over this
low-level Mark/Scan/Zone primitive. It delegates its marker wrapping and
bounding-box scanning to `SugarCraft\Mouse` — reach for sugar-mouse when you
want the Manager-based zone workflow, and for sugar-mouse directly when you
just need self-contained hit-testing.

## Coverage

[![codecov](https://codecov.io/gh/sugarcraft/sugar-mouse/branch/master/graph/badge.svg?flag=sugar-mouse)](https://codecov.io/gh/sugarcraft/sugar-mouse)

## Zones (bubblezone)

sugar-mouse — a mouse-zone tracker for TUI apps, for PHP 8.3+.
Wrap rendered chunks with named markers,
let SugarMouse discover their bounding boxes, then ask zones whether a
{@see \SugarCraft\Core\Msg\MouseMsg} fell inside them.
```php
use SugarCraft\Mouse\Zones\Manager;
use SugarCraft\Style\Style;

$z = Manager::newGlobal();

// Build a frame
$btnOk     = $z->mark('btn:ok',     Style::new()->padding(0, 2)->render('OK'));
$btnCancel = $z->mark('btn:cancel', Style::new()->padding(0, 2)->render('Cancel'));
$frame     = $btnOk . '   ' . $btnCancel;

// Scan once before printing — Manager records marker positions and strips them.
$displayable = $z->scan($frame);
echo $displayable;

// Later, when a MouseMsg arrives:
if ($z->get('btn:ok')?->inBounds($mouseMsg)) {
    // ...
}
```

SugarMouse is a thin app-facing façade over
[sugar-mouse](../sugar-mouse), the shared low-level hit-test primitive —
the marker scheme and its parser live there once and are reused by every
SugarCraft zone consumer. `mark()` delegates to
`SugarCraft\Mouse\Mark::wrap()` and `scan()` delegates its bounding-box
computation to `SugarCraft\Mouse\Scan::parse()`, then strips the markers
so the returned frame is display-ready.

Markers are invisible **private-use-area sentinels** (not visible text or
ANSI, so they don't affect layout):

```
open:  U+E000 <id> U+E001
close: U+E000 /<id> U+E001
```

`Manager::scan()` computes each zone's bounding box in 1-based terminal
cells, accounting for ANSI styling and Unicode (CJK) width.

**Id format:** the marked id — including any manager prefix, i.e.
`prefix() . $id` — must match `^[A-Za-z0-9._:-]+$` (ASCII letters, digits,
and `._:-`). sugar-mouse validates this in `Mark::wrap()` and `mark()`
throws `\InvalidArgumentException` on a bad id, since an id carrying a
sentinel / control / whitespace byte would desync zone scanning. The real
consumer ids (`tab-N`, `cell:N`, `item:N`) and the auto-generated prefixes
(`1-`, `2-`, …) already comply.

### Manager API

Beyond `mark()` / `scan()` / `get()`:

- `setEnabled(bool)` / `isEnabled()` — flip marker emission off in
  non-interactive contexts (CI logs, file dumps). When off, `mark()`
  returns content verbatim and `scan()` is identity.
- `Manager::newPrefix(?string)` — namespace every id with a prefix so
  two SugarMouse-aware components don't collide on `'item-0'`. Auto-
  generates a monotonic prefix when called bare.
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
tooltips, highlights, or data-fetch-on-hover:

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
zone boundary while dragging, and `ZoneDragEndMsg` on button release:

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
expires or when the cursor moves to a different zone:

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
- `lipgloss.Width()` (SugarStyle) and SugarMouse interact cleanly:
  `scan()` strips markers before measurement.
- `Zone::isZero()` distinguishes "never rendered" from "rendered but
  empty bounding box".
- Organic shapes (ASCII art) report a rectangular bounding box —
  the marker pair only carries 4 corners' worth of information.
- `scan()` is synchronous (no background worker), so
  `close()` is purely a state reset / disable rather than a thread
  join.
- `scan()` strips exactly the tags sugar-mouse's `Scan::parse()` decodes:
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
| `Manager` | `newPrefix(?prefix)` | Create prefixed manager for isolation |
| `Manager` | `mark(name, rendered)` | Wrap output with zone marker |
| `Manager` | `scan(output)` | Record positions, strip markers |
| `Manager` | `anyInBounds(mouseMsg)` | Return the innermost zone under the mouse |
| `Manager` | `get(name)` | Get zone by name |
| `Manager` | `setMotionTracking(bool)` | Return CSI 1003 h/l escape sequence |
| `Zone` | `inBounds(mouseMsg)` | Test if mouse is inside zone |
| `ZoneHoverTracker` | `new(manager)` | Track hover state over a manager |
| `ZoneHoverTracker` | `update(mouseMsg)` | Process mouse event, return enter/exit msg |
| `ZoneHoverTracker` | `currentZone()` | Get the hovered Zone or null |
| `ZoneEnterMsg` | `zone` | Zone the cursor just entered |
| `ZoneExitMsg` | `zone` | Zone the cursor just left |
| `DragTracker` | `new(manager)` | Track drag sequences over a manager |
| `DragTracker` | `update(mouseMsg)` | Process mouse event, return drag msg |
| `DragTracker` | `originZone()` | Get the origin Zone or null |
| `DragTracker` | `currentZone()` | Get the current Zone or null |
| `ZoneDragStartMsg` | `originZone / currentZone` | Zone where drag started; zone at current cursor |
| `ZoneDragMoveMsg` | `originZone / currentZone` | Fixed origin zone; zone cursor just crossed into |
| `ZoneDragEndMsg` | `originZone / currentZone` | Zone drag started from; zone at release |
| `ClickCounter` | `new(manager, clickIntervalMs)` | Track double/triple click streaks |
| `ClickCounter` | `update(mouseMsg)` | Process press event, return double/triple msg |
| `ClickCounter` | `clickCount()` | Current streak count (0 = no streak) |
| `DoubleClickMsg` | `zone` | Zone of the second press |
| `TripleClickMsg` | `zone` | Zone of the third press |

## Credits & inspiration

Originally inspired by [lrstanley/bubblezone](https://github.com/lrstanley/bubblezone) and the wider Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project. The Mark/Scan/Get pattern follows bubblezone's API; `ZoneClickTracker` addresses [bubblezone issue #10](https://github.com/lrstanley/bubblezone/issues/10).
