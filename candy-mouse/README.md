# CandyMouse

Self-contained Mark/Scan/Get mouse hit-testing (bubblezone pattern) plus `ZoneClickTracker` for press/release deduplication. No external Manager wiring needed.

```
composer require sugarcraft/candy-mouse: dev-master
```

## Role

Replaces the model where consumers wire `candy-zone`'s `Manager` externally. Each consumer owns its own `Scanner` instance — click handling stays local, no shared global state.

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

`Selection` / `SelectionRange` were ported upstream from sugar-crush's
`Tui\TextSelection`; sugar-crush itself has not been rewired onto them yet,
so for now they have no in-monorepo consumer beyond their own tests.

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
This departs from upstream bubblezone, which keeps the open position: opened
past the content's last column it reports an inverted rectangle no click
ever matches; opened short of it (`'12' . zone("\nabcdef")`) a box that
claims unpainted cols 3-6 of row 1 while missing the painted cols 1-2 of
row 2; and opened at column 1 a box that also spans the unpainted row 1.

## Multi-row zones

A zone spanning multiple rows (e.g. `"line1\nline2"`) is stored as the **smallest axis-aligned bounding box** that contains all marked cells.  The `inBounds()` check uses this rectangle, so an interior cell that was never part of the original content may still report as inside the zone.  Callers wrapping reflowed or indented text should be aware that the hit-test is approximate for multi-line content.

## See also

[candy-zone](../candy-zone) is the TEA-facing façade (`Manager`, hover /
drag / multi-click trackers, package-level `Zones`) layered over this
low-level Mark/Scan/Zone primitive. It delegates its marker wrapping and
bounding-box scanning to `SugarCraft\Mouse` — reach for candy-zone when you
want the Bubble Tea-style zone workflow, and for candy-mouse directly when you
just need self-contained hit-testing.

## Coverage

[![codecov](https://codecov.io/gh/sugarcraft/candy-mouse/branch/master/graph/badge.svg?flag=candy-mouse)](https://codecov.io/gh/sugarcraft/candy-mouse)

## Upstream

Inspired by [lrstanley/bubblezone](https://github.com/lrstanley/bubblezone) — the Mark/Scan/Get pattern mirrors bubblezone's API. `ZoneClickTracker` addresses [bubblezone issue #10](https://github.com/lrstanley/bubblezone/issues/10).
