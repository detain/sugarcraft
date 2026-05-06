<img src=".assets/icon.png" alt="candy-zone" width="160" align="right">

# CandyZone

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-zone)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-zone)
[![Packagist Version](https://img.shields.io/packagist/v/candycore/candy-zone?label=packagist)](https://packagist.org/packages/candycore/candy-zone)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


PHP port of [lrstanley/bubblezone](https://github.com/lrstanley/bubblezone) —
mouse-zone tracker for TUI apps. Wrap rendered chunks with named markers,
let CandyZone discover their bounding boxes, then ask zones whether a
{@see \CandyCore\Core\Msg\MouseMsg} fell inside them.

```php
use CandyCore\Zone\Manager;
use CandyCore\Sprinkles\Style;

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

Markers are APC escape sequences (`ESC _ ... ESC \`) — terminals ignore them,
so they don't affect layout. {@see Manager::scan()} computes each zone's
bounding box in 1-based terminal cells, accounting for ANSI styling and
Unicode width.

## Manager API

Beyond `mark()` / `scan()` / `get()`:

- `setEnabled(bool)` / `isEnabled()` — flip marker emission off in
  non-interactive contexts (CI logs, file dumps). When off, `mark()`
  returns content verbatim and `scan()` is identity.
- `Manager::newPrefix(?string)` — namespace every id with a prefix so
  two CandyZone-aware components don't collide on `'item-0'`. Auto-
  generates a monotonic prefix when called bare.
- `prefix()` — read-only accessor for the prefix string.
- `get($id)` / `all()` / `clear(?$id)` — single-zone lookup, every
  zone, and targeted-or-wipe-all clear.
- `close()` — drop every zone + flip the manager into pass-through
  mode. Idempotent. PHP synchronous-scan has no worker to stop, so
  this is purely a state cleanup.

## Package-level facade

`CandyCore\Zone\Zones` mirrors bubblezone's package-level surface
(`bubblezone.DefaultManager` + `Mark` / `Scan` / `Clear` / `Get` /
`Close` / `SetEnabled` / `Enabled` / `NewPrefix` / `AnyInBounds*`)
as static methods over a single shared `Manager`:

```php
use CandyCore\Zone\Zones;

$marked = Zones::mark('header', $header);
$cleaned = Zones::scan($marked);
if (Zones::get('header')?->inBounds($mouse)) { /* … */ }
```

`Zones::setDefaultManager(?Manager)` swaps in a custom manager —
useful in tests (`Zones::setDefaultManager(null)` flushes state) or
when you want every package-level call routed through a prefixed
manager.

## Tips

- Each id should be unique within a `Manager`. Use
  `Manager::newPrefix()` per UI sub-tree so two child models don't
  shadow each other's ids.
- Run `scan()` once on the **full root frame**, not per sub-tree —
  nested zone bounds depend on the outer layout.
- `lipgloss.Width()` (CandySprinkles) and CandyZone interact cleanly:
  `scan()` strips markers before measurement.
- `Zone::isZero()` distinguishes "never rendered" from "rendered but
  empty bounding box".
- Organic shapes (ASCII art) report a rectangular bounding box —
  the marker pair only carries 4 corners' worth of information.
- The PHP port has a synchronous `scan()` (no background worker), so
  `close()` is purely a state reset / disable rather than a thread
  join.

## Test

```sh
cd candy-zone && composer install && vendor/bin/phpunit
```
