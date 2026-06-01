<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Layout\Grid\{StackedGrid, Options, ItemOptions};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame};
use SugarCraft\Dash\Components\Card\{Text, Card};
use SugarCraft\Dash\Components\Nav\{Breadcrumb, Navbar};

/**
 * Dashboard Navigation - showcasing navigation components
 *
 * Shows Breadcrumb and Navbar components.
 */

$grid = new StackedGrid(new Options(fitScreen: true));

// ============================================
// HEADER
// ============================================
$grid->addItem(
    Card::titled(Text::new('Navigation Components Dashboard'), ''),
    new ItemOptions(column: 0, expandVertical: false)
);

// ============================================
// ROW 1: Breadcrumb (full width)
// ============================================
$breadcrumb = Breadcrumb::new(['Home', 'Products', 'Electronics', 'Smartphones']);
$breadcrumbFrame = Card::titled($breadcrumb, 'Breadcrumb');

$grid->addItem(
    $breadcrumbFrame,
    new ItemOptions(column: 0, expandVertical: false)
);

// ============================================
// ROW 2: Navbar (full width)
// ============================================
$navbar = Navbar::new([
    ['label' => 'Home', 'active' => true],
    ['label' => 'About'],
    ['label' => 'Products'],
    ['label' => 'Contact'],
]);
$navbarFrame = Card::titled($navbar, 'Navbar');

$grid->addItem(
    $navbarFrame,
    new ItemOptions(column: 0, expandVertical: false)
);

// ============================================
// ROW 3: Menu Items (full width)
// ============================================
$menuItems = VStack::spaced(1,
    Text::new('Menu Item 1'),
    Text::new('Menu Item 2'),
    Text::new('Menu Item 3')
);
$menuFrame = Card::titled($menuItems, 'Menu');

$grid->addItem(
    $menuFrame,
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->setSize(100, 25);
echo $grid->render();
