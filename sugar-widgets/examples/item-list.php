<?php

declare(strict_types=1);

/**
 * ItemList — render a scrollable list with title and selection.
 *
 *   php examples/item-list.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Forms\ItemList\ItemList;
use SugarCraft\Forms\ItemList\StringItem;

$items = array_map(
    fn(string $s) => new StringItem($s),
    ['SugarCraft', 'SugarStyle', 'SugarSpring', 'SugarMouse',
     'SugarWidgets', 'SugarCharts', 'SugarForms', 'CandyScriptui',
     'SugarMarkdown', 'SugarCli', 'CandyCodeshot', 'CandyMdview',
     'CandyAnsiInspect', 'SugarSshServer', 'CandySshLauncher', 'SugarMetrics'],
);

$list = ItemList::new($items, width: 40, height: 8)
    ->withTitle('SugarCraft libraries')
    ->withShowStatusBar(true);

echo $list->view() . "\n";
