<?php

declare(strict_types=1);

/**
 * Clickable list with focus-on-click. Mirrors bubblezone's
 * `list-default` example. Each row is its own zone; a synthetic mouse
 * event is dispatched against the manager, and `anyInBounds()` returns
 * the matched zone — the model uses that to refocus the list cursor.
 *
 * The second act drives the SAME cursor with keyboard messages only: the
 * row the click reached is also reachable with Down/Enter, because a
 * zone-driven action without a no-pointer route is a dead end for
 * keyboard users (README's Accessibility section — focus policy lives
 * app-side; this lib ships the hit-testing).
 *
 *   php examples/zones-list-default.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Mouse\Zones\Manager;

$items = [
    'Strawberry',
    'Sour Cherry',
    'Lemon Lollipop',
    'Bubblegum',
    'Mint Toffee',
];

$mgr = Manager::newUnprefixed();

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

// --- keyboard twin: move the same cursor with arrow keys + Enter. ---
// No manager, no zone lookup — focus walks the list by index, and the
// zones re-mark so the NEXT render shows the new highlight. A real app
// runs this in its update() on KeyMsg; here it is scripted.
$cursor = 0;
$script = [
    new KeyMsg(KeyType::Down, rune: ''),
    new KeyMsg(KeyType::Down, rune: ''),   //  -> row 2 ("Lemon Lollipop")
    new KeyMsg(KeyType::Enter, rune: ''),  // fires the focused row
];
foreach ($script as $msg) {
    if ($msg->type === KeyType::Down) {
        $cursor = min($cursor + 1, count($items) - 1);
    } elseif ($msg->type === KeyType::Enter) {
        echo "  keyboard focus lands on: {$items[$cursor]} (row {$cursor})\n";
    }
}
