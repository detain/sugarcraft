<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Layout\Grid\Segment;
use SugarCraft\Dashboard\Layout\Grid\ChartDataPoint;
use SugarCraft\Dashboard\Layout\Grid\Options;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;

// Segment display
$component = Segment::new("Segment 1");
$component->setSize(60, 15);
echo $component->render();
