<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Layout\Grid\ASCIIBanner;
use SugarCraft\Dashboard\Layout\Grid\ChartDataPoint;
use SugarCraft\Dashboard\Layout\Grid\Options;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;

// ASCII banner
$component = ASCIIBanner::new("WELCOME");
$component->setSize(60, 15);
echo $component->render();
