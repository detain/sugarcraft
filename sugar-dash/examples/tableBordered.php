<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\TableBordered;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Bordered table
$component = TableBordered::new([["Name" => "Alice", "Age" => "30"], ["Name" => "Bob", "Age" => "25"], ["Name" => "Charlie", "Age" => "35"]]);
$component->setSize(60, 15);
echo $component->render();
