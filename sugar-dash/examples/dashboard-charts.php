<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, Options, ItemOptions, Donut, AreaChart, Chart, ChartType, ChartDataPoint, FunnelChart, Sparkline};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame};
use SugarCraft\Dash\Components\Card\{Text, Card};

/**
 * Charts Dashboard - showcasing all chart types
 *
 * Shows Bar, Donut, Area, Funnel, and Sparkline charts
 * each in their own titled frame in a multi-column layout.
 */

$grid = new StackedGrid(new Options(fitScreen: true));

// ============================================
// HEADER
// ============================================
$titleText = Text::new('Charts Dashboard');
$headerContent = HStack::spaced(2, $titleText, Text::new('(Bar, Donut, Area, Funnel, Sparkline)'));

$grid->addItem(
    Card::titled($headerContent, ''),
    new ItemOptions(column: 0, expandVertical: false)
);

// ============================================
// ROW 1: Bar Chart + Donut Chart (2 columns)
// ============================================
$barChart = Chart::new([
    new ChartDataPoint('Jan', 30.0),
    new ChartDataPoint('Feb', 45.0),
    new ChartDataPoint('Mar', 25.0),
    new ChartDataPoint('Apr', 60.0),
    new ChartDataPoint('May', 40.0),
    new ChartDataPoint('Jun', 55.0),
], ChartType::Bar);
$barFrame = Card::titled($barChart, 'Monthly Revenue');

$donut = Donut::mocha([
    ['label' => 'Desktop', 'value' => 45.0],
    ['label' => 'Mobile', 'value' => 35.0],
    ['label' => 'Tablet', 'value' => 20.0],
]);
$donutFrame = Card::titled($donut, 'Traffic Sources');

$grid->addItem(
    $barFrame,
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->addItem(
    $donutFrame,
    new ItemOptions(column: 1, expandVertical: true)
);

// ============================================
// ROW 2: Area Chart + Funnel Chart (2 columns)
// ============================================
$areaChart = AreaChart::new([
    ['label' => 'Series A', 'values' => [20.0, 40.0, 30.0, 50.0, 35.0]],
    ['label' => 'Series B', 'values' => [10.0, 30.0, 45.0, 25.0, 55.0]],
]);
$areaFrame = Card::titled($areaChart, 'Growth Trend');

$funnel = FunnelChart::new([
    ['label' => 'Visitors', 'value' => 1000.0],
    ['label' => 'Signups', 'value' => 500.0],
    ['label' => 'Paying', 'value' => 200.0],
]);
$funnelFrame = Card::titled($funnel, 'Conversion Funnel');

$grid->addItem(
    $areaFrame,
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->addItem(
    $funnelFrame,
    new ItemOptions(column: 1, expandVertical: true)
);

// ============================================
// ROW 3: Sparkline (full width)
// ============================================
$spark = Sparkline::new([3.0, 5.0, 2.0, 8.0, 6.0, 4.0, 7.0, 5.0, 9.0, 3.0, 6.0, 8.0], 50);
$sparkFrame = Card::titled($spark, 'Activity Sparkline');

$grid->addItem(
    $sparkFrame,
    new ItemOptions(column: 0, expandVertical: false)
);

// Set size and render
$grid->setSize(120, 40);
echo $grid->render();
