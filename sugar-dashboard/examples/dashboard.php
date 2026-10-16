<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Layout\Grid\{StackedGrid, Options, ItemOptions};
use SugarCraft\Dashboard\Layout\{Frame, VStack};
use SugarCraft\Dashboard\Components\Card\Text;

$grid = new StackedGrid(new Options(fitScreen: true));

$grid->addItem(
    Frame::new(
        VStack::centered(
            Text::new('SugarDashboard Dashboard'),
            Text::new('Welcome to the TUI')
        )
    )->withPadding(1),
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->addItem(
    Frame::new(Text::new('Column 2 Panel'))->withPadding(1),
    new ItemOptions(column: 1)
);

$grid->setSize(80, 24);
echo $grid->render();