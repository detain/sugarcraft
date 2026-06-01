<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\BorderText;
use SugarCraft\Dash\Layout\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Text with border
$component = BorderText::new("Bordered");
$component->setSize(60, 15);
echo $component->render();
