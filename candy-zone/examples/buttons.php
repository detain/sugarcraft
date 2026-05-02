<?php

declare(strict_types=1);

/**
 * Two clickable "buttons" with mouse-zone tracking. Click on either,
 * watch the bounding-box query report which one was hit.
 *
 *   php examples/buttons.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Core\MouseAction;
use CandyCore\Core\MouseButton;
use CandyCore\Core\Msg\MouseMsg;
use CandyCore\Sprinkles\Border;
use CandyCore\Sprinkles\Style;
use CandyCore\Zone\Manager;

$mgr = Manager::newGlobal();

$ok     = $mgr->mark('btn:ok',     Style::new()->padding(0, 2)->border(Border::rounded())->render('  OK  '));
$cancel = $mgr->mark('btn:cancel', Style::new()->padding(0, 2)->border(Border::rounded())->render('Cancel'));

$frame = $ok . "  " . $cancel;
echo $mgr->scan($frame) . "\n\n";

// Pretend a click landed at row 1, col 5 — within the OK button's body.
$click = new MouseMsg(5, 1, MouseButton::Left, MouseAction::Press);

foreach (['btn:ok', 'btn:cancel'] as $id) {
    $z = $mgr->get($id);
    if ($z !== null && $z->inBounds($click)) {
        echo "  click hit: $id\n";
    }
}
