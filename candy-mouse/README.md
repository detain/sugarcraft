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
| `Mark` | Wrap content with invisible Unicode sentinel markers |
| `Scanner` | Parse sentinels; `get(id)` and `hit(col, row)` lookups |
| `Zone` | Readonly bounding box (start/end col/row) |
| `ZoneClickTracker` | Press+Release dedup per button |
| `MouseEvent` | Immutable event (x, y, button, action enum) |
| `MouseAction` | `Press` / `Release` / `Drag` / `Scroll` enum |

## Sentinel design

Sentinels use private-use codepoints U+E000 (open) and U+E001 (close) — they never collide with ANSI SGR sequences or regular text, and terminal emulators render them invisibly. Scanning *reads* them from the rendered string to compute zone bounding boxes; it does not modify the string. Stripping the sentinels from the bytes actually written to the terminal is the consumer's job (e.g. sugar-crush's renderer scans, then strips).

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
