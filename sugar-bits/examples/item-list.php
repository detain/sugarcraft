<?php

declare(strict_types=1);

/**
 * ItemList — render a scrollable list with title and selection.
 *
 *   php examples/item-list.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Bits\ItemList\ItemList;
use CandyCore\Bits\ItemList\StringItem;

$items = array_map(
    fn(string $s) => new StringItem($s),
    ['CandyCore', 'CandySprinkles', 'HoneyBounce', 'CandyZone',
     'SugarBits', 'SugarCharts', 'SugarPrompt', 'CandyShell',
     'CandyShine', 'CandyKit', 'CandyFreeze', 'SugarGlow',
     'SugarSpark', 'CandyWish', 'SugarWishlist', 'CandyMetrics'],
);

$list = ItemList::new($items, width: 40, height: 8)
    ->withTitle('SugarCraft libraries')
    ->withShowStatusBar(true);

echo $list->view() . "\n";
