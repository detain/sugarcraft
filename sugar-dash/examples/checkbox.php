<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Form\Checkbox;
use SugarCraft\Dash\Layout\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Checkbox control
$component = Checkbox::new([["label" => "Accept Terms", "checked" => true]]);
$component->setSize(60, 15);
echo $component->render();
