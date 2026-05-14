<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, Options, ItemOptions};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame};
use SugarCraft\Dash\Components\Card\{Text, Card};
use SugarCraft\Dash\Components\Nav\Breadcrumb;

// Dashboard Navigation Example
$grid = new StackedGrid(new Options(fitScreen: true));

// Breadcrumb
$breadcrumb = Breadcrumb::new([
    ['label' => 'Home'],
    ['label' => 'Products'],
    ['label' => 'Electronics'],
]);

$mainContent = VStack::spaced(2,
    Card::titled($breadcrumb, 'Navigation')
);

$grid->addItem(
    Frame::new($mainContent)->withPadding(1),
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->setSize(80, 10);
echo $grid->render();
