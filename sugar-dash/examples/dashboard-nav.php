<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, Options, ItemOptions};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame, Sidebar};
use SugarCraft\Dash\Components\Card\{Text, Card};
use SugarCraft\Dash\Components\Tabs\{Tabs, TabsVertical};
use SugarCraft\Dash\Components\Nav\{Navbar, Breadcrumb, Menu};

// Dashboard Navigation Example
$grid = new StackedGrid(new Options(fitScreen: true));

// Navigation components
$navbar = Navbar::new('Dashboard');

// Tabs horizontal
$tabs = Tabs::new([
    ['label' => 'Overview'],
    ['label' => 'Analytics'],
    ['label' => 'Reports'],
    ['label' => 'Settings'],
]);

// Tabs vertical
$verticalTabs = TabsVertical::new([
    ['label' => 'Home'],
    ['label' => 'Profile'],
    ['label' => 'Messages'],
    ['label' => 'Settings'],
]);

// Sidebar menu
$sidebar = Sidebar::new([
    ['label' => 'Dashboard'],
    ['label' => 'Analytics'],
    ['label' => 'Customers'],
    ['label' => 'Products'],
    ['label' => 'Settings'],
]);

// Breadcrumb
$breadcrumb = Breadcrumb::new([
    ['label' => 'Home'],
    ['label' => 'Products'],
    ['label' => 'Electronics'],
    ['label' => 'Smartphones'],
]);

$mainContent = HStack::spaced(2,
    Card::titled($sidebar, 'Sidebar Menu'),
    Card::titled($verticalTabs, 'Vertical Tabs'),
    Card::titled($tabs, 'Horizontal Tabs')
);

$grid->addItem(
    Frame::new($navbar)->withPadding(1),
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->addItem(
    Frame::new($breadcrumb)->withPadding(1),
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->addItem(
    Frame::new($mainContent)->withPadding(1),
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->setSize(100, 25);
echo $grid->render();
