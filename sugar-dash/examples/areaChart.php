<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\AreaChart;

// Area chart
$component = AreaChart::new([["label" => "Series A", "values" => [20.0, 40.0, 30.0, 50.0]]]);
$component->setSize(60, 15);
echo $component->render();
