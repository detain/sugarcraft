<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Select\ComboBox;
use SugarCraft\Dashboard\Plot\Chart\ChartDataPoint;
use SugarCraft\Dashboard\Layout\Grid\Options;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;

// Combo box
$component = ComboBox::new("Search...", [["label" => "Result 1"], ["label" => "Result 2"]]);
$component = $component->setSize(60, 15);
echo $component->render();
