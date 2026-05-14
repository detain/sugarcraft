<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Layout\ZStack;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Layered stack
$component = ZStack::new(Text::new("Back"), Text::new("Front"));
$component->setSize(60, 15);
echo $component->render();
