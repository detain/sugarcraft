<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Layout\Grid\{StackedGrid, Options, ItemOptions};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame};
use SugarCraft\Dash\Components\Card\{Text, Card, Stat, MetricsGrid, MetricCard};
use SugarCraft\Dash\Components\StatusBar\StatusIndicator;
use SugarCraft\Core\Util\Color;

/**
 * Metrics Dashboard - showcasing metrics and KPI displays
 *
 * Uses MetricsGrid with MetricCard objects in a framed panel
 * plus StatusIndicator components for status display.
 */

$grid = new StackedGrid(new Options(fitScreen: true));

// ============================================
// HEADER
// ============================================
$grid->addItem(
    Card::titled(Text::new('Metrics Dashboard'), ''),
    new ItemOptions(column: 0, expandVertical: false)
);

// ============================================
// ROW 1: METRICS GRID (full width)
// ============================================
$metricsGrid = MetricsGrid::new([
    new MetricCard('Total Users', '12,345', '+5.2%', 'up', Color::hex('#A6E3A1')),
    new MetricCard('Revenue', '$45,678', '+12.5%', 'up', Color::hex('#89B4FA')),
    new MetricCard('Growth', '23.4%', '-2.1%', 'down', Color::hex('#F9E2AF')),
    new MetricCard('Conversion', '3.2%', '+0.8%', 'up', Color::hex('#CBA6F7')),
    new MetricCard('Avg. Time', '2m 34s', '-8.3%', 'down', Color::hex('#94E2D5')),
    new MetricCard('Bounce Rate', '24%', '-2.0%', 'down', Color::hex('#F38BA8')),
])->withColumns(3);

$grid->addItem(
    Card::titled($metricsGrid, 'Key Performance Indicators'),
    new ItemOptions(column: 0, expandVertical: false)
);

// ============================================
// ROW 2: Individual Stats (left) + Status (right)
// ============================================
$stats = VStack::spaced(1,
    Stat::new('Total Users', '12,345'),
    Stat::new('Revenue', '$45,678'),
    Stat::new('Growth', '+12.5%')
);
$statsFrame = Card::titled($stats, 'Quick Stats');

$statusRow = VStack::spaced(1,
    StatusIndicator::new('online'),
    StatusIndicator::new('offline'),
    StatusIndicator::new('warning')
);
$statusFrame = Card::titled($statusRow, 'System Status');

$grid->addItem(
    $statsFrame,
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->addItem(
    $statusFrame,
    new ItemOptions(column: 1, expandVertical: true)
);

// Set size and render
$grid->setSize(100, 30);
echo $grid->render();
