<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\RadarChart;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Radar chart
$component = RadarChart::new(
    ["Speed", "Reliability", "Comfort", "Safety", "Efficiency"],
    [
        ["label" => "Performance", "values" => [80.0, 65.0, 90.0, 75.0, 85.0], "color" => "#F38BA8"],
    ]
);
$component->setSize(60, 15);
echo $component->render();
