<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, Options, ItemOptions, Heatmap};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame};
use SugarCraft\Dash\Components\Card\{Text, Card};
use SugarCraft\Dash\Components\Calendar\{Calendar};
use SugarCraft\Dash\Components\System\{Clock, Timer, Stopwatch};

/**
 * Dashboard Time - showcasing time and date components
 *
 * Shows Clock, Calendar, Timer, Stopwatch, and Heatmap components.
 */

$grid = new StackedGrid(new Options(fitScreen: true));

// ============================================
// HEADER
// ============================================
$grid->addItem(
    Card::titled(Text::new('Time & Date Dashboard'), ''),
    new ItemOptions(column: 0, expandVertical: false)
);

// ============================================
// ROW 1: Clock + Calendar (2 columns)
// ============================================
$clock = Clock::new();
$clockFrame = Card::titled($clock, 'Current Time');

$calendar = Calendar::now();
$calendarFrame = Card::titled($calendar, 'Calendar');

$grid->addItem(
    $clockFrame,
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->addItem(
    $calendarFrame,
    new ItemOptions(column: 1, expandVertical: true)
);

// ============================================
// ROW 2: Timer + Stopwatch (2 columns)
// ============================================
$timer = Timer::fromMinutes(5);
$timerFrame = Card::titled($timer, 'Timer');

$stopwatch = Stopwatch::new();
$stopwatchFrame = Card::titled($stopwatch, 'Stopwatch');

$grid->addItem(
    $timerFrame,
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->addItem(
    $stopwatchFrame,
    new ItemOptions(column: 1, expandVertical: true)
);

// ============================================
// ROW 3: Heatmap (full width)
// ============================================
$heatmap = Heatmap::new([
    [5.0, 10.0, 15.0, 20.0, 18.0],
    [8.0, 12.0, 18.0, 22.0, 15.0],
    [3.0, 7.0, 14.0, 19.0, 12.0],
    [6.0, 11.0, 16.0, 21.0, 16.0],
    [4.0, 9.0, 13.0, 17.0, 14.0],
]);
$heatmapFrame = Card::titled($heatmap, 'Activity Heatmap (Last 5 Days)');

$grid->addItem(
    $heatmapFrame,
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->setSize(100, 30);
echo $grid->render();
