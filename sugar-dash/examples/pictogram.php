<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Media\Pictogram;
use SugarCraft\Dash\Layout\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Pictogram icon
$component = Pictogram::new([["label" => "Warnings", "value" => 3], ["label" => "Errors", "value" => 1], ["label" => "Info", "value" => 5]]);
$component->setSize(60, 15);
echo $component->render();
