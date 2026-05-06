<?php

declare(strict_types=1);

/**
 * Clickable list with focus-on-click. Mirrors bubblezone's
 * `list-default` example. Each row is its own zone; a synthetic mouse
 * event is dispatched against the manager, and `anyInBounds()` returns
 * the matched zone — the model uses that to refocus the list cursor.
 *
 *   php examples/list-default.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Core\MouseAction;
use CandyCore\Core\MouseButton;
use CandyCore\Core\Msg\MouseMsg;
use CandyCore\Zone\Manager;

$items = [
    'Strawberry',
    'Sour Cherry',
    'Lemon Lollipop',
    'Bubblegum',
    'Mint Toffee',
];

$mgr = Manager::newGlobal();

// Each item is wrapped in its own zone id so a click can be routed to
// the right row without computing y-coords by hand.
$rendered = [];
$cursor = 1; // currently highlighted row, 0-based.
foreach ($items as $i => $label) {
    $marker = $i === $cursor ? '>' : ' ';
    $rendered[] = $mgr->mark("item:{$i}", "{$marker} {$label}");
}
echo $mgr->scan(implode("\n", $rendered)) . "\n";

// Pretend a mouse click landed on row index 3 (col 1, row 4 — 1-based).
$click = new MouseMsg(1, 4, MouseButton::Left, MouseAction::Press);
$hit   = $mgr->anyInBounds($click);

if ($hit !== null) {
    $idx = (int) substr($hit->id, 5);
    echo "\n  click hit zone: {$hit->id}  (focus → {$items[$idx]})\n";
} else {
    echo "\n  click missed every row\n";
}
