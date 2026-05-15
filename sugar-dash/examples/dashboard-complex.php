<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, Options, ItemOptions, Donut, AreaChart, Chart, ChartType, ChartDataPoint, RadarChart, Sparkline, GaugeChart};
use SugarCraft\Dash\Layout\{VStack, Frame};
use SugarCraft\Dash\Components\Card\{Text, Card};
use SugarCraft\Dash\Components\Nav\Breadcrumb;
use SugarCraft\Dash\Components\Tree\Timeline;
use SugarCraft\Dash\Grid\AvatarGroup;

/**
 * Complex Analytics Dashboard - Real Multi-Column Dashboard
 *
 * Demonstrates advanced dashboard with:
 * - 2-column grid layout
 * - Multiple chart types
 * - Timeline and avatar components
 * - Breadcrumb navigation
 */
$grid = new StackedGrid(new Options(fitScreen: true));

// ============================================
// ROW 1: KEY METRICS - Gauges + Stats
// ============================================
$leftMetrics = VStack::spaced(1,
    GaugeChart::percent(73.2)->withLabel('Total Users'),
    GaugeChart::percent(45.8)->withLabel('Server Load')
);

$rightMetrics = VStack::spaced(1,
    GaugeChart::percent(89.1)->withLabel('Storage'),
    GaugeChart::percent(62.4)->withLabel('Memory')
);

$grid->addItem(
    Frame::new($leftMetrics, 'Performance Metrics')->withPadding(1),
    new ItemOptions(column: 0)
);

$grid->addItem(
    Frame::new($rightMetrics, 'System Health')->withPadding(1),
    new ItemOptions(column: 1)
);

// ============================================
// ROW 2: ANALYTICS CHARTS
// ============================================
$barChart = Chart::new([
    new ChartDataPoint('Jan', 30.0),
    new ChartDataPoint('Feb', 45.0),
    new ChartDataPoint('Mar', 25.0),
    new ChartDataPoint('Apr', 60.0),
    new ChartDataPoint('May', 55.0),
    new ChartDataPoint('Jun', 70.0),
], ChartType::Bar);

$areaChart = AreaChart::new([
    ['label' => 'Revenue', 'values' => [20.0, 35.0, 45.0, 40.0, 55.0, 60.0, 75.0]],
]);

$leftCharts = VStack::spaced(1, $barChart, $areaChart);

$donut = Donut::mocha([
    ['label' => 'Direct', 'value' => 40.0],
    ['label' => 'Organic', 'value' => 30.0],
    ['label' => 'Referral', 'value' => 20.0],
    ['label' => 'Social', 'value' => 10.0],
]);

$sparkline = Sparkline::new([3.0, 5.0, 2.0, 8.0, 6.0, 4.0, 7.0, 5.0, 9.0, 6.0, 8.0, 7.0], 40);
$rightCharts = VStack::spaced(1, $donut, $sparkline);

$grid->addItem(
    Frame::new($leftCharts, 'Revenue Analysis')->withPadding(1),
    new ItemOptions(column: 0)
);

$grid->addItem(
    Frame::new($rightCharts, 'Traffic Sources')->withPadding(1),
    new ItemOptions(column: 1)
);

// ============================================
// ROW 3: ACTIVITY FEED - Timeline + Avatar
// ============================================
$timeline = Timeline::new([
    ['title' => 'Deployment v2.4.1', 'time' => '10:15:00', 'type' => 'success'],
    ['title' => 'Database migration', 'time' => '09:45:23', 'type' => 'info'],
    ['title' => 'Cache cleared', 'time' => '09:30:11', 'type' => 'warning'],
    ['title' => 'Backup completed', 'time' => '08:00:00', 'type' => 'success'],
    ['title' => 'Security scan', 'time' => '07:15:33', 'type' => 'info'],
]);

$avatarGroup = AvatarGroup::compact([
    'Sarah Connor',
    'John Smith',
    'Maria Garcia',
    'James Wilson',
    'Emily Brown',
    'Michael Davis'
], 5);

$grid->addItem(
    Frame::new($timeline, 'Recent Activity')->withPadding(1),
    new ItemOptions(column: 0)
);

$grid->addItem(
    Frame::new($avatarGroup, 'Active Users')->withPadding(1),
    new ItemOptions(column: 1)
);

// ============================================
// ROW 4: NAVIGATION & STATUS
// ============================================
$breadcrumb = Breadcrumb::fromPath('Analytics / Reports / Q4 2024');

$stats = VStack::spaced(0,
    Text::new('Total Revenue: $1,234,567'),
    Text::new('Active Sessions: 8,429'),
    Text::new('Conversion Rate: 3.24%')
);

$grid->addItem(
    Frame::new($breadcrumb, 'Navigation')->withPadding(1),
    new ItemOptions(column: 0)
);

$grid->addItem(
    Frame::new($stats, 'Summary')->withPadding(1),
    new ItemOptions(column: 1)
);

$grid->setSize(140, 52);
echo $grid->render();
