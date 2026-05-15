<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, Options, ItemOptions, GaugeChart, AreaChart, Chart, ChartType, ChartDataPoint, Donut, Sparkline, AvatarGroup};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame, HAlign};
use SugarCraft\Dash\Components\Card\{Text, Card, MetricsGrid, MetricCard};
use SugarCraft\Dash\Components\Nav\Breadcrumb;
use SugarCraft\Dash\Components\Tree\Timeline;
use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Border;

/**
 * Production Server Dashboard - Real Multi-Column Dashboard
 *
 * Redesigned to look like a real TUI dashboard with proper framing:
 * - Header row with title + breadcrumb navigation
 * - Row 1: 4 metric gauges in a horizontal row (grouped in one frame)
 * - Row 2: AreaChart + Donut in separate frames side-by-side
 * - Row 3: AvatarGroup + Timeline in separate frames side-by-side
 * - Footer row with summary stats
 */

// Create the main dashboard grid
$grid = new StackedGrid(new Options(fitScreen: true));

// ============================================
// HEADER: Title + Breadcrumb in one frame
// ============================================
$breadcrumb = Breadcrumb::new(['Home', 'Servers', 'Production', 'prod-web-01']);

$headerContent = HStack::spaced(3, Text::new('Production Dashboard'), $breadcrumb);
$headerFrame = Card::titled($headerContent, '')->withPadding(1);

$grid->addItem(
    $headerFrame,
    new ItemOptions(column: 0, expandVertical: false)
);

// ============================================
// ROW 1: METRICS GRID - CPU, Memory, Disk, Network KPIs
// ============================================
$metricsGrid = MetricsGrid::new([
    new MetricCard('CPU', '67.5%', '+2.3%', 'up', Color::hex('#A6E3A1')),
    new MetricCard('Memory', '42.3%', '-1.8%', 'down', Color::hex('#89B4FA')),
    new MetricCard('Disk I/O', '78.9%', '+5.2%', 'up', Color::hex('#F9E2AF')),
    new MetricCard('Network', '23.4%', '-0.5%', 'down', Color::hex('#CBA6F7')),
])->withColumns(4);

$grid->addItem(
    $metricsGrid,
    new ItemOptions(column: 0, expandVertical: false)
);

// ============================================
// ROW 2: CHARTS - AreaChart (left) + Donut (right) in separate frames
// ============================================
$areaChart = AreaChart::new([
    ['label' => 'Requests', 'values' => [1200.0, 1450.0, 1320.0, 1680.0, 1890.0, 2100.0, 1950.0]],
    ['label' => 'Latency', 'values' => [45.0, 52.0, 48.0, 61.0, 55.0, 72.0, 68.0]],
]);
$areaFrame = Card::titled($areaChart, 'Traffic (24h)');

$donut = Donut::mocha([
    ['label' => 'CPU', 'value' => 35.0],
    ['label' => 'Memory', 'value' => 28.0],
    ['label' => 'Disk', 'value' => 22.0],
    ['label' => 'Swap', 'value' => 15.0],
]);
$donutFrame = Card::titled($donut, 'Resource Usage');

$grid->addItem(
    $areaFrame,
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->addItem(
    $donutFrame,
    new ItemOptions(column: 1, expandVertical: true)
);

// ============================================
// ROW 3: DATA - AvatarGroup (left) + Timeline (right)
// ============================================
$avatarGroup = AvatarGroup::compact(['Alice Chen', 'Bob Martinez', 'Carol Smith', 'Dave Wilson', 'Eve Johnson'], 5);
$avatarFrame = Card::titled($avatarGroup, 'Online Team');

$timeline = Timeline::new([
    ['title' => 'Server started', 'time' => '14:23:01', 'type' => 'success'],
    ['title' => 'nginx reloaded', 'time' => '14:22:45', 'type' => 'info'],
    ['title' => 'SSL cert renewed', 'time' => '14:20:12', 'type' => 'success'],
    ['title' => 'Backup completed', 'time' => '14:00:00', 'type' => 'success'],
    ['title' => 'Health check passed', 'time' => '13:55:33', 'type' => 'info'],
]);
$timelineFrame = Card::titled($timeline, 'Recent Events');

$grid->addItem(
    $avatarFrame,
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->addItem(
    $timelineFrame,
    new ItemOptions(column: 1, expandVertical: true)
);

// ============================================
// ROW 4: FOOTER - Status summary
// ============================================
$statusText = Text::new('12,847 requests  •  99.97% uptime  •  127ms avg latency  •  4 alerts');
$footerFrame = Card::titled($statusText, 'Summary');

$grid->addItem(
    $footerFrame,
    new ItemOptions(column: 0, expandVertical: false)
);

// Set size and render
$grid->setSize(140, 48);
echo $grid->render();
