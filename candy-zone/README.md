# CandyZone

PHP port of [lrstanley/bubblezone](https://github.com/lrstanley/bubblezone) —
mouse-zone tracker for TUI apps. Wrap rendered chunks with named markers,
let CandyZone discover their bounding boxes, then ask zones whether a
{@see \CandyCore\Core\Msg\MouseMsg} fell inside them.

```php
use CandyCore\Zone\Manager;
use CandyCore\Gloss\Style;

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

## Test

```sh
cd candy-zone && composer install && vendor/bin/phpunit
```
