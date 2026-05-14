<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, Options, ItemOptions};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame};
use SugarCraft\Dash\Components\Card\{Text, Card, Stat};
use SugarCraft\Dash\Components\StatusBar\StatusIndicator;

// Dashboard Metrics Example
$grid = new StackedGrid(new Options(fitScreen: true));

// Individual stats
$stats = HStack::spaced(2,
    Stat::new('Total Users', '12,345'),
    Stat::new('Revenue', '$45,678'),
    Stat::new('Growth', '+12.5%')
);

// Status indicators
$statusRow = HStack::spaced(2,
    StatusIndicator::new('online'),
    StatusIndicator::new('offline'),
    StatusIndicator::new('warning')
);

$topRow = HStack::spaced(2,
    Card::titled($stats, 'Key Statistics')
);

$bottomRow = HStack::spaced(2,
    Card::titled($statusRow, 'Status')
);

$mainContent = VStack::spaced(2, $topRow, $bottomRow);

$grid->addItem(
    Frame::new(HStack::new(new Text('Dashboard Metrics Demo')))->withPadding(1),
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->addItem(
    Frame::new($mainContent)->withPadding(1),
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->setSize(80, 15);
echo $grid->render();
