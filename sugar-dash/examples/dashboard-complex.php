<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Layout\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Sparkline;
use SugarCraft\Dash\Layout\Grid\{StackedGrid, Options, ItemOptions};
use SugarCraft\Dash\Plot\Chart\{Donut, AreaChart, Chart, ChartType, RadarChart, GaugeChart};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame};
use SugarCraft\Dash\Components\Card\{Text, Card, MetricsGrid, MetricCard};
use SugarCraft\Dash\Components\Nav\Breadcrumb;
use SugarCraft\Dash\Components\Tree\Timeline;
use SugarCraft\Dash\Layout\Grid\AvatarGroup;
use SugarCraft\Core\Util\Color;

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
// HEADER
// ============================================
$breadcrumb = Breadcrumb::fromPath('Analytics / Reports / Q4 2024');
$grid->addItem(
    Card::titled($breadcrumb, 'Navigation'),
    new ItemOptions(column: 0, expandVertical: false)
);

// ============================================
// ROW 1: KEY METRICS (using MetricsGrid for better display)
// ============================================
$metricsGrid = MetricsGrid::new([
    new MetricCard('Total Users', '73.2%', '+12.5%', 'up', Color::hex('#A6E3A1')),
    new MetricCard('Server Load', '45.8%', '+3.2%', 'up', Color::hex('#89B4FA')),
    new MetricCard('Storage', '89.1%', '-1.1%', 'down', Color::hex('#F9E2AF')),
    new MetricCard('Memory', '62.4%', '+5.7%', 'up', Color::hex('#CBA6F7')),
])->withColumns(4);

$grid->addItem(
    Card::titled($metricsGrid, 'Performance Metrics'),
    new ItemOptions(column: 0, expandVertical: false)
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
$barFrame = Card::titled($barChart, 'Revenue by Month');

$areaChart = AreaChart::new([
    ['label' => 'Revenue', 'values' => [20.0, 35.0, 45.0, 40.0, 55.0, 60.0, 75.0]],
]);
$areaFrame = Card::titled($areaChart, 'Revenue Trend');

$donut = Donut::mocha([
    ['label' => 'Direct', 'value' => 40.0],
    ['label' => 'Organic', 'value' => 30.0],
    ['label' => 'Referral', 'value' => 20.0],
    ['label' => 'Social', 'value' => 10.0],
]);
$donutFrame = Card::titled($donut, 'Traffic Sources');

$sparkline = Sparkline::new([3.0, 5.0, 2.0, 8.0, 6.0, 4.0, 7.0, 5.0, 9.0, 6.0, 8.0, 7.0], 40);
$sparkFrame = Card::titled($sparkline, 'Activity Trend');

// Charts in 2x2 grid
$grid->addItem(
    $barFrame,
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->addItem(
    $areaFrame,
    new ItemOptions(column: 1, expandVertical: true)
);

$grid->addItem(
    $donutFrame,
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->addItem(
    $sparkFrame,
    new ItemOptions(column: 1, expandVertical: true)
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
$timelineFrame = Card::titled($timeline, 'Recent Activity');

$avatarGroup = AvatarGroup::compact([
    'Sarah Connor',
    'John Smith',
    'Maria Garcia',
    'James Wilson',
    'Emily Brown',
    'Michael Davis'
], 5);
$avatarFrame = Card::titled($avatarGroup, 'Active Users');

$grid->addItem(
    $timelineFrame,
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->addItem(
    $avatarFrame,
    new ItemOptions(column: 1, expandVertical: true)
);

// ============================================
// ROW 4: SUMMARY STATS
// ============================================
$stats = VStack::spaced(1,
    Text::new('Total Revenue: $1,234,567'),
    Text::new('Active Sessions: 8,429'),
    Text::new('Conversion Rate: 3.24%')
);
$statsFrame = Card::titled($stats, 'Summary');

$grid->addItem(
    $statsFrame,
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->setSize(140, 52);
echo $grid->render();
