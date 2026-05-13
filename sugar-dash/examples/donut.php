<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\Donut;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Donut chart
$component = Donut::mocha([["label" => "Category A", "value" => 35.0], ["label" => "Category B", "value" => 25.0], ["label" => "Category C", "value" => 40.0]]);
$component->setSize(60, 15);
echo $component->render();
