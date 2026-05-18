<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Plot\Chart\SparklineBar;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Spark bar
$component = SparklineBar::new([3.0, 5.0, 2.0, 8.0, 6.0, 4.0], 20);
$component->setSize(60, 15);
echo $component->render();
