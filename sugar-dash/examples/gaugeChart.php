<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\GaugeChart;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Gauge chart
$component = GaugeChart::new([["label" => "CPU", "value" => 80.0], ["label" => "Memory", "value" => 60.0], ["label" => "Disk", "value" => 45.0]]);
$component->setSize(60, 15);
echo $component->render();
