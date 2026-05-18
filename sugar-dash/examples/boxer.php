<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Layout\Pad;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;
use SugarCraft\Dash\Components\Card\Text;

// Box component (Pad was formerly known as Boxer)
$component = Pad::new(new Text("Boxed Content"));
$component->setSize(60, 15);
echo $component->render();
