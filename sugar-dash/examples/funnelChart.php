<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Plot\Chart\FunnelChart;
use SugarCraft\Dash\Layout\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Funnel chart
$component = FunnelChart::new([["label" => "Visitors", "value" => 1000.0], ["label" => "Signups", "value" => 500.0], ["label" => "Paying", "value" => 200.0]]);
$component->setSize(60, 15);
echo $component->render();
