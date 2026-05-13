<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\VStack;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Vertical stack of items
$component = VStack::new(Text::new("Item 1"), Text::new("Item 2"));
$component->setSize(60, 15);
echo $component->render();
