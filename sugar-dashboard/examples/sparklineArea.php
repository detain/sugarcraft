<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Plot\Chart\SparklineArea;
use SugarCraft\Dashboard\Layout\Grid\ChartDataPoint;
use SugarCraft\Dashboard\Layout\Grid\Options;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;

// Sparkline area
$component = SparklineArea::new([1.0, 2.0, 3.0, 2.5, 4.0], 25);
$component->setSize(60, 15);
echo $component->render();
