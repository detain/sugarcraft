<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\AreaChart;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Area chart
$component = AreaChart::new([["label" => "Series A", "values" => [20.0, 40.0, 30.0, 50.0]]);
$component->setSize(60, 15);
echo $component->render();
