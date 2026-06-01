<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Plot\Chart\Chart;
use SugarCraft\Dash\Layout\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Bar/Line chart
$component = Chart::new([new ChartDataPoint("Jan", 30.0), new ChartDataPoint("Feb", 45.0), new ChartDataPoint("Mar", 25.0)]);
$component->setSize(60, 15);
echo $component->render();
