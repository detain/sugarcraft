<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Layout\Grid\StackedGrid;
use SugarCraft\Dashboard\Layout\Frame;
use SugarCraft\Dashboard\Components\Card\Text;
use SugarCraft\Dashboard\Layout\Grid\ChartDataPoint;
use SugarCraft\Dashboard\Layout\Grid\Options;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;

// Multi-column grid layout
$component = $grid = new StackedGrid(new Options(fitScreen: true)); $grid->addItem(Frame::new(Text::new("Col 1"))->withPadding(1), new ItemOptions(column: 0, expandVertical: true)); $grid->addItem(Frame::new(Text::new("Col 2"))->withPadding(1), new ItemOptions(column: 1)); $grid;
$component->setSize(60, 15);
echo $component->render();
