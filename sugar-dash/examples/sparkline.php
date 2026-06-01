<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Plot\Chart\Sparkline;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Sparkline chart
$component = Sparkline::new(60)
    ->push(3.0)->push(5.0)->push(2.0)->push(8.0)
    ->push(6.0)->push(4.0)->push(7.0);
$component = $component
    ->withHeight(15)
    ->withDataPoints(true);
echo $component->render();
