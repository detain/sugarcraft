<?php

declare(strict_types=1);

/**
 * Two clickable lists composed into one frame, each owning its own
 * prefixed Manager. Mirrors bubblezone's `full-lipgloss` example: the
 * two child components both happen to use the literal id `"item-0"`
 * but the prefixes keep their bounding boxes from colliding.
 *
 *   php examples/nested-components.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Core\MouseAction;
use CandyCore\Core\MouseButton;
use CandyCore\Core\Msg\MouseMsg;
use CandyCore\Zone\Manager;

/**
 * @param Manager      $mgr
 * @param list<string> $items
 */
function renderColumn(Manager $mgr, array $items): string
{
    $rows = [];
    foreach ($items as $i => $label) {
        $rows[] = $mgr->mark("item-{$i}", str_pad("  {$label}", 18));
    }
    return implode("\n", $rows);
}

$leftMgr  = Manager::newPrefix('left-');
$rightMgr = Manager::newPrefix('right-');

$left  = renderColumn($leftMgr,  ['Apple', 'Pear', 'Quince']);
$right = renderColumn($rightMgr, ['Salt',  'Pepper', 'Sugar']);

$lLines = explode("\n", $left);
$rLines = explode("\n", $right);
$rows   = max(count($lLines), count($rLines));
$frameRows = [];
for ($i = 0; $i < $rows; $i++) {
    $frameRows[] = ($lLines[$i] ?? '') . ($rLines[$i] ?? '');
}
$frame = implode("\n", $frameRows);

// Each manager needs its own scan() call against the original
// marker-bearing frame so its zone map gets populated. Both managers
// see every marker (the protocol is prefix-agnostic) but each one's
// `get('item-0')` resolves via its own prefix → no collision.
$leftMgr->scan($frame);
$rightMgr->scan($frame);
// Either scan returns the same cleaned frame; pick one for display.
echo $leftMgr->scan($frame) . "\n";

// Click on the *first* row of each column. Same literal id ("item-0")
// in both components — the prefixes prevent a collision.
$leftClick  = new MouseMsg(2, 1, MouseButton::Left, MouseAction::Press);
$rightClick = new MouseMsg(20, 1, MouseButton::Left, MouseAction::Press);

$leftHit  = $leftMgr->anyInBounds($leftClick);
$rightHit = $rightMgr->anyInBounds($rightClick);

echo "\n  left click  → " . ($leftHit?->id  ?? 'miss') . "\n";
echo   "  right click → " . ($rightHit?->id ?? 'miss') . "\n";
