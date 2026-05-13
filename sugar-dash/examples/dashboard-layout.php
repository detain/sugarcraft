<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, VStack, HStack, ZStack, Frame, Text, Options, ItemOptions};

// Dashboard Layout Example - Demonstrates StackedGrid, VStack, HStack, ZStack
$grid = new StackedGrid(new Options(fitScreen: true));

// Header row with HStack
$header = HStack::new(
    Text::new('SugarDash Layout Demo'),
    Text::new('v1.0.0')
);

// Left column with VStack
$leftColumn = VStack::spaced(1,
    Frame::new(Text::new('Panel 1'))->withPadding(1),
    Frame::new(Text::new('Panel 2'))->withPadding(1),
    Frame::new(Text::new('Panel 3'))->withPadding(1)
);

// Right column with stacked items
$rightColumn = VStack::spaced(1,
    Frame::new(Text::new('Wide Panel Content'))->withPadding(1),
    Frame::new(Text::new('Another Panel'))->withPadding(1)
);

$grid->addItem(
    Frame::new($header)->withPadding(1),
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->addItem(
    Frame::new($leftColumn)->withPadding(1),
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->setSize(80, 20);
echo $grid->render();
