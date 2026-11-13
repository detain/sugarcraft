<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Layout\Grid\StackedGrid;
use SugarCraft\Dashboard\Layout\Grid\Options;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;
use SugarCraft\Dashboard\Layout\{Frame,VStack};
use SugarCraft\Dashboard\Components\Card\Text;

$grid = new StackedGrid(new Options(fitScreen: true));

$grid = $grid->withItem(
    Frame::new(
        VStack::centered(
            Text::new('SugarDashboard Dashboard'),
            Text::new('Welcome to the TUI')
        )
    )->withPadding(1),
    new ItemOptions(column: 0, expandVertical: true)
);

$grid = $grid->withItem(
    Frame::new(Text::new('Column 2 Panel'))->withPadding(1),
    new ItemOptions(column: 1)
);

$grid = $grid->setSize(80, 24);
echo $grid->render();