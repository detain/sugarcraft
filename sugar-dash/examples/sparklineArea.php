<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Plot\Chart\SparklineArea;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Sparkline area
$component = SparklineArea::new([1.0, 2.0, 3.0, 2.5, 4.0], 25);
$component->setSize(60, 15);
echo $component->render();
