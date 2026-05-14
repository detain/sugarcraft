<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\Pictogram;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Pictogram icon
$component = Pictogram::new([
    ['label' => 'Sales', 'value' => 75],
    ['label' => 'Marketing', 'value' => 45],
]);
$component->setSize(60, 15);
echo $component->render();
