<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\StackedGrid;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;
use SugarCraft\Dash\Layout\Frame;
use SugarCraft\Dash\Components\Card\Text;

// Multi-column grid layout
$grid = new StackedGrid(new Options(fitScreen: true));
$grid->addItem(Frame::new(new Text("Col 1"))->withPadding(1), new ItemOptions(column: 0, expandVertical: true));
$grid->addItem(Frame::new(new Text("Col 2"))->withPadding(1), new ItemOptions(column: 1));
$component = $grid;
$component->setSize(60, 15);
echo $component->render();
