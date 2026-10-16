<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Plot\Chart\FunnelChart;
use SugarCraft\Dashboard\Layout\Grid\ChartDataPoint;
use SugarCraft\Dashboard\Layout\Grid\Options;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;

// Funnel chart
$component = FunnelChart::new([["label" => "Visitors", "value" => 1000.0], ["label" => "Signups", "value" => 500.0], ["label" => "Paying", "value" => 200.0]]);
$component->setSize(60, 15);
echo $component->render();
