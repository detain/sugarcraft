<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Layout\HStack;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Horizontal stack of items
$component = HStack::new(Text::new("Left"), Text::new("Right"));
$component->setSize(60, 15);
echo $component->render();
