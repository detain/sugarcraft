<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Plot\Chart\Donut;

// Donut chart
$component = Donut::mocha([["label" => "Category A", "value" => 35.0], ["label" => "Category B", "value" => 25.0], ["label" => "Category C", "value" => 40.0]]);
$component = $component->setSize(60, 15);
echo $component->render();
