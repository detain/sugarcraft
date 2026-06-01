<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Layout\Center;
use SugarCraft\Dash\Layout\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;
use SugarCraft\Dash\Components\Card\Text;

// Center-aligned content
$component = Center::new(new Text("Centered Text"));
$component->setSize(60, 15);
echo $component->render();
