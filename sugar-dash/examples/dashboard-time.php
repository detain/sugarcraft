<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, Options, ItemOptions, Heatmap};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame};
use SugarCraft\Dash\Components\Card\{Text, Card};
use SugarCraft\Dash\Components\System\{Clock, Calendar, Timer, Stopwatch};

// Dashboard Time & Date Example
$grid = new StackedGrid(new Options(fitScreen: true));

// Clock
$clock = Clock::new();

// Calendar
$calendar = Calendar::new();

// Timer
$timer = Timer::new();

// Stopwatch
$stopwatch = Stopwatch::new();

// Heatmap
$heatmap = Heatmap::new([
    [5.0, 10.0, 15.0, 20.0, 18.0],
    [8.0, 12.0, 18.0, 22.0, 15.0],
    [3.0, 7.0, 14.0, 19.0, 12.0],
    [6.0, 11.0, 16.0, 21.0, 16.0],
    [4.0, 9.0, 13.0, 17.0, 14.0],
]);

$topRow = HStack::spaced(2,
    Card::titled($clock, 'Current Time'),
    Card::titled($calendar, 'Calendar')
);

$middleRow = HStack::spaced(2,
    Card::titled($timer, 'Timer'),
    Card::titled($stopwatch, 'Stopwatch')
);

$bottomRow = Card::titled($heatmap, 'Activity Heatmap (Last 5 Days)');

$mainContent = VStack::spaced(2, $topRow, $middleRow, $bottomRow);

$grid->addItem(
    Frame::new(HStack::new(Text::new('Dashboard Time & Date Demo')))->withPadding(1),
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->addItem(
    Frame::new($mainContent)->withPadding(1),
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->setSize(100, 30);
echo $grid->render();
