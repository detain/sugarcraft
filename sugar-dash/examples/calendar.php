<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Calendar\Calendar;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Calendar
$component = Calendar::now();
$component->setSize(60, 15);
echo $component->render();
