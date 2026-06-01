<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Select\ComboBox;
use SugarCraft\Dash\Layout\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Combo box
$component = ComboBox::new("Search...", [["label" => "Result 1"], ["label" => "Result 2"]]);
$component->setSize(60, 15);
echo $component->render();
