<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Select\Select;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Dropdown select
$component = Select::new([["label" => "Option 1"], ["label" => "Option 2"], ["label" => "Option 3"]]);
$component->setSize(60, 15);
echo $component->render();
