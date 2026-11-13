<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Form\Checkbox;
use SugarCraft\Dashboard\Plot\Chart\ChartDataPoint;
use SugarCraft\Dashboard\Layout\Grid\Options;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;

// Checkbox control
$component = Checkbox::new([["label" => "Accept Terms", "checked" => true]]);
$component = $component->setSize(60, 15);
echo $component->render();
