<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Layout\Grid\Picture;
use SugarCraft\Dashboard\Layout\Grid\ChartDataPoint;
use SugarCraft\Dashboard\Layout\Grid\Options;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;

// Picture frame
$component = Picture::new("https://placehold.co/300x200.png");
$component->setSize(60, 15);
echo $component->render();
