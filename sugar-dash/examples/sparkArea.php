<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Plot\Chart\SparkArea;
use SugarCraft\Dash\Layout\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Spark area
$component = SparkArea::new([1.0, 2.0, 3.0, 2.5, 4.0, 3.5, 5.0], 30);
$component->setSize(60, 15);
echo $component->render();
