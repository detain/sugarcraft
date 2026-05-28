# CandyMouse

Self-contained Mark/Scan/Get mouse hit-testing (bubblezone pattern) plus `ZoneClickTracker` for press/release deduplication. No external Manager wiring needed.

```
composer require sugarcraft/candy-mouse: dev-master
```

## Role

Replaces the model where consumers wire `candy-zone`'s `Manager` externally. Each consumer owns its own `Scanner` instance — click handling stays local, no shared global state.

## Quickstart

```php
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Mouse\ZoneClickTracker;
use SugarCraft\Mouse\MouseEvent;

// 1. Wrap interactive content with invisible zone markers.
$rendered = Mark::zone('btn-ok', '  OK  ')
              . Mark::zone('btn-cancel', 'Cancel');

// 2. Scan after rendering to populate the zone registry.
$scanner = Scanner::new()->scan($rendered);

// 3. Reverse-lookup on mouse events.
$zone = $scanner->hit($mouseX, $mouseY); // ?Zone

// 4. Deduplicate clicks so each press+release pair emits one click.
$tracker = new ZoneClickTracker();
$result = $tracker->track(new MouseEvent(5, 1, 0, MouseAction::Release));
if ($result !== null) {
    echo "Clicked zone: " . $result->zone->id;
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

Sentinels use private-use codepoints U+E000 (open) and U+E001 (close) — they never collide with ANSI SGR sequences or regular text. Scanning strips them from output.

## Coverage

[![codecov](https://codecov.io/gh/sugarcraft/candy-mouse/branch/master/graph/badge.svg?flag=candy-mouse)](https://codecov.io/gh/sugarcraft/candy-mouse)

## Upstream

Inspired by [lrstanley/bubblezone](https://github.com/lrstanley/bubblezone) — the Mark/Scan/Get pattern mirrors bubblezone's API. `ZoneClickTracker` addresses [bubblezone issue #10](https://github.com/lrstanley/bubblezone/issues/10).
