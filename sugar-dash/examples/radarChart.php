<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Plot\Chart\RadarChart;
use SugarCraft\Dash\Layout\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Radar chart
$component = RadarChart::new(["Speed", "Reliability", "Comfort"], [["label" => "Metrics", "values" => [80.0, 65.0, 90.0]]]);
$component->setSize(60, 15);
echo $component->render();
