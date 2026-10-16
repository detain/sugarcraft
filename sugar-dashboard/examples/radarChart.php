<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Plot\Chart\RadarChart;
use SugarCraft\Dashboard\Layout\Grid\ChartDataPoint;
use SugarCraft\Dashboard\Layout\Grid\Options;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;

// Radar chart
$component = RadarChart::new(["Speed", "Reliability", "Comfort"], [["label" => "Metrics", "values" => [80.0, 65.0, 90.0]]]);
$component->setSize(60, 15);
echo $component->render();
