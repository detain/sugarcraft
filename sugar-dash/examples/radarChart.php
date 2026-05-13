<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\RadarChart;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Radar chart
$component = RadarChart::new([["label" => "Speed", "value" => 80.0], ["label" => "Reliability", "value" => 65.0], ["label" => "Comfort", "value" => 90.0]]);
$component->setSize(60, 15);
echo $component->render();
