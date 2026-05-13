<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, VStack, HStack, Frame, Text, Options, ItemOptions, Card, Donut, AreaChart, Chart, ChartType, ChartDataPoint, RadarChart, Heatmap, FunnelChart, Sparkline};

// Complex Dashboard - Full Featured
$grid = new StackedGrid(new Options(fitScreen: true));

// Charts row
$barChart = Chart::new([
    new ChartDataPoint('Jan', 30.0),
    new ChartDataPoint('Feb', 45.0),
    new ChartDataPoint('Mar', 25.0),
    new ChartDataPoint('Apr', 60.0),
], ChartType::Bar);

$donut = Donut::mocha([
    ['label' => 'Direct', 'value' => 40.0],
    ['label' => 'Organic', 'value' => 30.0],
    ['label' => 'Referral', 'value' => 20.0],
    ['label' => 'Social', 'value' => 10.0],
]);

$areaChart = AreaChart::new([
    ['label' => 'Revenue', 'values' => [20.0, 35.0, 45.0, 40.0, 55.0, 60.0]],
]);

$funnel = FunnelChart::new([
    ['label' => 'Visitors', 'value' => 1000.0],
    ['label' => 'Signups', 'value' => 400.0],
    ['label' => 'Paying', 'value' => 150.0],
]);

$sparkline = Sparkline::new([3.0, 5.0, 2.0, 8.0, 6.0, 4.0, 7.0, 5.0, 9.0, 6.0], 40);

// Stats row
$statsRow = HStack::spaced(2,
    Card::titled(Text::new('12,345'), 'Total Users'),
    Card::titled(Text::new('$45,678'), 'Revenue'),
    Card::titled(Text::new('+23%'), 'Growth'),
    Card::titled(Text::new('98.5%'), 'Uptime')
);

// Charts row
$chartsRow = HStack::spaced(2,
    Card::titled($barChart, 'Monthly Revenue'),
    Card::titled($donut, 'Traffic Sources'),
    Card::titled($areaChart, 'Growth Trend')
);

// Bottom row
$bottomRow = HStack::spaced(2,
    Card::titled($funnel, 'Conversion Funnel'),
    Card::titled($sparkline, 'Daily Visits')
);

$mainContent = VStack::spaced(2,
    $statsRow,
    $chartsRow,
    $bottomRow
);

$grid->addItem(
    Frame::new(HStack::new(Text::new('SugarCraft Analytics Dashboard'), Text::new('v2.0')))->withPadding(1),
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->addItem(
    Frame::new($mainContent)->withPadding(1),
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->setSize(120, 35);
echo $grid->render();
