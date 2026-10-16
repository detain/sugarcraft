<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Select\Select;
use SugarCraft\Dashboard\Layout\Grid\ChartDataPoint;
use SugarCraft\Dashboard\Layout\Grid\Options;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;

// Dropdown select
$component = Select::new([["label" => "Option 1"], ["label" => "Option 2"], ["label" => "Option 3"]]);
$component->setSize(60, 15);
echo $component->render();
