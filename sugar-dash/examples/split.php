<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Layout\Split;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Split pane layout
$component = Split::horizontal(Text::new("Left Panel"), Text::new("Right Panel"));
$component->setSize(60, 15);
echo $component->render();
