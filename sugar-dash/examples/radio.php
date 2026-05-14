<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Select\Radio;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Radio buttons
$component = Radio::new([["label" => "Option A"], ["label" => "Option B"], ["label" => "Option C"]], 0);
$component->setSize(60, 15);
echo $component->render();
