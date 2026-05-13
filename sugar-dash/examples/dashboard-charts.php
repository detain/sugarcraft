<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, VStack, HStack, Frame, Text, Options, ItemOptions, Card, Donut, AreaChart, Chart, ChartType, ChartDataPoint, FunnelChart, Sparkline};

// Dashboard Charts Example
$grid = new StackedGrid(new Options(fitScreen: true));

// Bar Chart
$barChart = Chart::new([
    new ChartDataPoint('Jan', 30.0),
    new ChartDataPoint('Feb', 45.0),
    new ChartDataPoint('Mar', 25.0),
    new ChartDataPoint('Apr', 60.0),
    new ChartDataPoint('May', 40.0),
], ChartType::Bar);

// Donut Chart
$donut = Donut::mocha([
    ['label' => 'Desktop', 'value' => 45.0],
    ['label' => 'Mobile', 'value' => 35.0],
    ['label' => 'Tablet', 'value' => 20.0],
]);

// Area Chart
$areaChart = AreaChart::new([
    ['label' => 'Series A', 'values' => [20.0, 40.0, 30.0, 50.0, 35.0]],
    ['label' => 'Series B', 'values' => [10.0, 30.0, 45.0, 25.0, 55.0]],
]);

// Funnel Chart
$funnel = FunnelChart::new([
    ['label' => 'Visitors', 'value' => 1000.0],
    ['label' => 'Signups', 'value' => 500.0],
    ['label' => 'Paying', 'value' => 200.0],
]);

// Sparkline
$spark = Sparkline::new([3.0, 5.0, 2.0, 8.0, 6.0, 4.0, 7.0, 5.0], 30);

$topRow = HStack::spaced(2,
    Card::titled($barChart, 'Monthly Revenue'),
    Card::titled($donut, 'Traffic Sources')
);

$bottomRow = HStack::spaced(2,
    Card::titled($areaChart, 'Growth Trend'),
    Card::titled($funnel, 'Conversion Funnel')
);

$mainContent = VStack::spaced(2, $topRow, $bottomRow);

$grid->addItem(
    Frame::new(HStack::new(Text::new('Dashboard Charts Demo'), Text::new('(Bar, Donut, Area, Funnel)')))->withPadding(1),
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->addItem(
    Frame::new($mainContent)->withPadding(1),
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->setSize(100, 30);
echo $grid->render();
