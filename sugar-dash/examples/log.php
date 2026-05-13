<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\Log;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Log text
$component = Log::new("Log entry 1\nLog entry 2\nLog entry 3");
$component->setSize(60, 15);
echo $component->render();
