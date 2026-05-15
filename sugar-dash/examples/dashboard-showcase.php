<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, Options, ItemOptions, GaugeChart, AreaChart, Chart, ChartType, ChartDataPoint, Donut, Sparkline};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame};
use SugarCraft\Dash\Components\Card\Text;
use SugarCraft\Dash\Components\Nav\Breadcrumb;
use SugarCraft\Dash\Components\Tree\Timeline;
use SugarCraft\Dash\Grid\AvatarGroup;

/**
 * Production Server Dashboard - Real Multi-Column Dashboard
 *
 * Demonstrates proper StackedGrid usage with:
 * - Multiple columns for side-by-side panes
 * - VStack grouping within columns for row alignment
 * - Frame borders for distinct visual panels
 */
$grid = new StackedGrid(new Options(fitScreen: true));

// ============================================
// ROW 1: SERVER METRICS - 4 gauges side-by-side
// ============================================
$leftGauges = VStack::spaced(1,
    GaugeChart::cpu(67.5)->withLabel('CPU'),
    GaugeChart::cpu(42.3)->withLabel('Memory')
);

$rightGauges = VStack::spaced(1,
    GaugeChart::percent(78.9)->withLabel('Disk I/O'),
    GaugeChart::percent(23.4)->withLabel('Network')
);

$grid->addItem(
    Frame::new($leftGauges, 'System Metrics')->withPadding(1),
    new ItemOptions(column: 0)
);

$grid->addItem(
    Frame::new($rightGauges, 'Storage & Network')->withPadding(1),
    new ItemOptions(column: 1)
);

// ============================================
// ROW 2: CHARTS - Area + Sparkline vs Bar + Donut
// ============================================
$areaChart = AreaChart::new([
    ['label' => 'Requests', 'values' => [1200.0, 1450.0, 1320.0, 1680.0, 1890.0, 2100.0, 1950.0]],
    ['label' => 'Latency', 'values' => [45.0, 52.0, 48.0, 61.0, 55.0, 72.0, 68.0]],
]);

$sparkline = Sparkline::new([3.0, 5.0, 2.0, 8.0, 6.0, 4.0, 7.0, 5.0, 9.0, 6.0, 4.0, 8.0], 35);
$leftCharts = VStack::spaced(1, $areaChart, $sparkline);

$barChart = Chart::new([
    new ChartDataPoint('Mon', 1200.0),
    new ChartDataPoint('Tue', 1450.0),
    new ChartDataPoint('Wed', 1320.0),
    new ChartDataPoint('Thu', 1680.0),
    new ChartDataPoint('Fri', 1890.0),
    new ChartDataPoint('Sat', 2100.0),
    new ChartDataPoint('Sun', 1950.0),
], ChartType::Bar);

$donut = Donut::mocha([
    ['label' => 'CPU', 'value' => 35.0],
    ['label' => 'Memory', 'value' => 28.0],
    ['label' => 'Disk', 'value' => 22.0],
    ['label' => 'Swap', 'value' => 15.0],
]);
$rightCharts = VStack::spaced(1, $barChart, $donut);

$grid->addItem(
    Frame::new($leftCharts, 'Traffic (24h)')->withPadding(1),
    new ItemOptions(column: 0)
);

$grid->addItem(
    Frame::new($rightCharts, 'Resource Usage')->withPadding(1),
    new ItemOptions(column: 1)
);

// ============================================
// ROW 3: TEAM & TIMELINE - AvatarGroup + Timeline
// ============================================
$avatarGroup = AvatarGroup::compact(['Alice Chen', 'Bob Martinez', 'Carol Smith', 'Dave Wilson', 'Eve Johnson'], 5);

$timeline = Timeline::new([
    ['title' => 'Server started', 'time' => '14:23:01', 'type' => 'success'],
    ['title' => 'nginx reloaded', 'time' => '14:22:45', 'type' => 'info'],
    ['title' => 'SSL cert renewed', 'time' => '14:20:12', 'type' => 'success'],
    ['title' => 'Backup completed', 'time' => '14:00:00', 'type' => 'success'],
    ['title' => 'Health check passed', 'time' => '13:55:33', 'type' => 'info'],
]);

$grid->addItem(
    Frame::new($avatarGroup, 'Online Team')->withPadding(1),
    new ItemOptions(column: 0)
);

$grid->addItem(
    Frame::new($timeline, 'Recent Events')->withPadding(1),
    new ItemOptions(column: 1)
);

// ============================================
// ROW 4: FOOTER - Breadcrumb + Stats
// ============================================
$breadcrumb = Breadcrumb::new(['Home', 'Servers', 'prod-web-01']);

$statsText = Text::new('12,847 requests  •  99.97% uptime  •  127ms avg latency');

$footerLeft = VStack::spaced(0, $breadcrumb);
$footerRight = VStack::spaced(0, $statsText);

$grid->addItem(
    Frame::new($footerLeft, 'Navigation')->withPadding(1),
    new ItemOptions(column: 0)
);

$grid->addItem(
    Frame::new($footerRight, 'Status')->withPadding(1),
    new ItemOptions(column: 1)
);

$grid->setSize(140, 48);
echo $grid->render();
