<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Tree\Timeline;
use SugarCraft\Dashboard\Layout\Grid\ChartDataPoint;
use SugarCraft\Dashboard\Layout\Grid\Options;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;

// Timeline display
$component = Timeline::new([["label" => "Event 1", "time" => "9:00 AM"], ["label" => "Event 2", "time" => "10:00 AM"]]);
$component->setSize(60, 15);
echo $component->render();
