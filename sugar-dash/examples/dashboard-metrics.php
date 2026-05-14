<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, Options, ItemOptions};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame};
use SugarCraft\Dash\Components\Card\{Text, Card, Stat, Metric, MetricsGrid, Leaderboard, ActivityFeed};
use SugarCraft\Dash\Components\StatusBar\StatusIndicator;
use SugarCraft\Dash\Components\System\{ProgressList};
use SugarCraft\Dash\Components\Form\Rating;

// Dashboard Metrics Example
$grid = new StackedGrid(new Options(fitScreen: true));

// Individual stats
$stats = HStack::spaced(2,
    Stat::new('Total Users', '12,345'),
    Stat::new('Revenue', '$45,678'),
    Stat::new('Growth', '+12.5%'),
    Stat::new('Active Now', '234')
);

// Metrics grid
$metricsGrid = MetricsGrid::new([
    ['label' => 'CPU Usage', 'value' => '45%', 'trend' => 'up'],
    ['label' => 'Memory', 'value' => '62%', 'trend' => 'down'],
    ['label' => 'Disk', 'value' => '38%', 'trend' => 'stable'],
    ['label' => 'Network', 'value' => '1.2 Gbps', 'trend' => 'up'],
]);

// Status indicators
$statusRow = HStack::spaced(2,
    StatusIndicator::new('online'),
    StatusIndicator::new('offline'),
    StatusIndicator::new('warning'),
    StatusIndicator::new('error')
);

// Leaderboard
$leaderboard = Leaderboard::new([
    ['rank' => 1, 'label' => 'Alice', 'value' => '1,500 pts'],
    ['rank' => 2, 'label' => 'Bob', 'value' => '1,200 pts'],
    ['rank' => 3, 'label' => 'Charlie', 'value' => '1,100 pts'],
    ['rank' => 4, 'label' => 'Diana', 'value' => '950 pts'],
]);

// Activity feed
$activityFeed = ActivityFeed::new([
    ['label' => 'Alice posted a comment', 'time' => '2m ago'],
    ['label' => 'Bob updated status', 'time' => '5m ago'],
    ['label' => 'Charlie completed task', 'time' => '10m ago'],
]);

// Rating
$rating = Rating::of(4.5);

// Progress list
$progressList = ProgressList::new([
    ['label' => 'Task 1', 'progress' => 100],
    ['label' => 'Task 2', 'progress' => 65],
    ['label' => 'Task 3', 'progress' => 30],
]);

$topRow = HStack::spaced(2,
    Card::titled($stats, 'Key Statistics'),
    Card::titled($metricsGrid, 'System Metrics')
);

$middleRow = HStack::spaced(2,
    Card::titled($leaderboard, 'Leaderboard'),
    Card::titled($activityFeed, 'Activity Feed')
);

$bottomRow = HStack::spaced(2,
    Card::titled($rating, 'User Rating'),
    Card::titled($progressList, 'Task Progress')
);

$mainContent = VStack::spaced(2, $topRow, $middleRow, $bottomRow);

$grid->addItem(
    Frame::new(HStack::new(Text::new('Dashboard Metrics Demo')))->withPadding(1),
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->addItem(
    Frame::new($mainContent)->withPadding(1),
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->setSize(110, 35);
echo $grid->render();
