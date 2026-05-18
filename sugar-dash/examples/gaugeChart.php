<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Plot\Chart\GaugeChart;

// Gauge chart showing 80% CPU usage
$component = GaugeChart::percent(80.0);
$component->setSize(60, 15);
echo $component->render();
