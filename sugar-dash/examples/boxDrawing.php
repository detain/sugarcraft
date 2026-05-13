<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\BoxDrawing;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Box drawing
$component = BoxDrawing::new("┌──┐\n│  │\n└──┘");
$component->setSize(60, 15);
echo $component->render();
