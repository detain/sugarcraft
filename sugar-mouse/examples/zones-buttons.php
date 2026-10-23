<?php

declare(strict_types=1);

/**
 * Two clickable "buttons" with mouse-zone tracking. Click on either,
 * watch the bounding-box query report which one was hit.
 *
 *   php examples/zones-buttons.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Mouse\Zones\Manager;

$mgr = Manager::newGlobal();

// Plain padded content — styling (e.g. sugar-style borders) would ride the
// string unchanged; zones measure visible cells only.
$ok     = $mgr->mark('btn:ok',     '  OK  ');
$cancel = $mgr->mark('btn:cancel', ' Cancel ');

$frame = $ok . "  " . $cancel;
echo $mgr->scan($frame) . "\n\n";

// Pretend a click landed at row 1, col 5 — within the OK button's body.
$click = new MouseMsg(5, 1, MouseButton::Left, MouseAction::Press);

foreach (['btn:ok', 'btn:cancel'] as $id) {
    $z = $mgr->get($id);
    if ($z !== null && $z->inBounds($click) === true) {
        echo "  click hit: $id\n";
    }
}
