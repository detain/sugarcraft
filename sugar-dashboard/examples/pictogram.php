<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Media\Pictogram;
use SugarCraft\Dashboard\Layout\Grid\ChartDataPoint;
use SugarCraft\Dashboard\Layout\Grid\Options;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;

// Pictogram icon
$component = Pictogram::new([["label" => "Warnings", "value" => 3], ["label" => "Errors", "value" => 1], ["label" => "Info", "value" => 5]]);
$component->setSize(60, 15);
echo $component->render();
