<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Select\Radio;
use SugarCraft\Dashboard\Layout\Grid\ChartDataPoint;
use SugarCraft\Dashboard\Layout\Grid\Options;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;

// Radio buttons
$component = Radio::new([["label" => "Option A"], ["label" => "Option B"], ["label" => "Option C"]], 0);
$component->setSize(60, 15);
echo $component->render();
