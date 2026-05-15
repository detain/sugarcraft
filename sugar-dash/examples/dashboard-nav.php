<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\StackedGrid;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;
use SugarCraft\Dash\Layout\Frame;
use SugarCraft\Dash\Components\Card\Card;
use SugarCraft\Dash\Components\Nav\Breadcrumb;

// Dashboard Navigation Example
$grid = new StackedGrid(new Options(fitScreen: true));

// Breadcrumb with flat string items
$breadcrumb = Breadcrumb::new(['Home', 'Products', 'Electronics']);

$mainContent = Card::titled($breadcrumb, 'Navigation');

$grid->addItem(
    Frame::new($mainContent)->withPadding(1),
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->setSize(80, 10);
echo $grid->render();
