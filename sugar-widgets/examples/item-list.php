<?php

declare(strict_types=1);

/**
 * ItemList — render a scrollable list with title and selection.
 *
 *   php examples/item-list.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Widgets\ItemList\ItemList;
use SugarCraft\Widgets\ItemList\StringItem;

$items = array_map(
    fn(string $s) => new StringItem($s),
    ['SugarCraft', 'CandySprinkles', 'HoneyBounce', 'CandyMouse',
     'SugarWidgets', 'SugarCharts', 'CandyForms', 'CandyScriptui',
     'CandyShine', 'CandyKit', 'CandyCodeshot', 'CandyMdview',
     'CandyAnsiInspect', 'CandyWish', 'CandySshLauncher', 'CandyMetrics'],
);

$list = ItemList::new($items, width: 40, height: 8)
    ->withTitle('SugarCraft libraries')
    ->withShowStatusBar(true);

echo $list->view() . "\n";
