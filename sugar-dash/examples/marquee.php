<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\Marquee;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Marquee scrolling text
$component = Marquee::new("Scrolling text from left to right...");
$component->setSize(60, 15);
echo $component->render();
