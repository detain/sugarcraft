<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\StackedGrid;
use SugarCraft\Dash\Layout\Frame;
use SugarCraft\Dash\Components\Card\Text;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Multi-column grid layout
$component = $grid = new StackedGrid(new Options(fitScreen: true)); $grid->addItem(Frame::new(Text::new("Col 1"))->withPadding(1), new ItemOptions(column: 0, expandVertical: true)); $grid->addItem(Frame::new(Text::new("Col 2"))->withPadding(1), new ItemOptions(column: 1)); $grid;
$component->setSize(60, 15);
echo $component->render();
