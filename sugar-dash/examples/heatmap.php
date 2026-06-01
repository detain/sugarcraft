<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Plot\Chart\Heatmap;
use SugarCraft\Dash\Layout\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Heatmap
$component = Heatmap::new([[10.0, 20.0, 30.0], [15.0, 25.0, 35.0], [5.0, 15.0, 25.0]]);
$component->setSize(60, 15);
echo $component->render();
