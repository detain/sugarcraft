<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Table\TableZebra;
use SugarCraft\Dashboard\Layout\Grid\ChartDataPoint;
use SugarCraft\Dashboard\Layout\Grid\Options;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;

// Zebra striped table
$component = TableZebra::new([["Name" => "Alice", "Age" => "30"], ["Name" => "Bob", "Age" => "25"], ["Name" => "Charlie", "Age" => "35"]]);
$component->setSize(60, 15);
echo $component->render();
