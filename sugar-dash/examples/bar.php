<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\Bar;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Status bar
$component = Bar::new("Loading...");
$component->setSize(60, 15);
echo $component->render();
