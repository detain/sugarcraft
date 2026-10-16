<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Nav\Scrollbar;
use SugarCraft\Dashboard\Layout\Grid\ChartDataPoint;
use SugarCraft\Dashboard\Layout\Grid\Options;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;

// Scrollbar
$component = Scrollbar::new(50);
$component->setSize(60, 15);
echo $component->render();
