<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Toast\Tooltip;
use SugarCraft\Dash\Layout\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Tooltip
$component = Tooltip::new("Hover me", "Help text appears");
$component->setSize(60, 15);
echo $component->render();
