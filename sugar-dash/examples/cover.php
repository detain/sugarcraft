<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Card\Cover;
use SugarCraft\Dash\Layout\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;
use SugarCraft\Dash\Components\Card\Text;

// Cover overlay
$component = Cover::new(new Text("Cover Overlay Content"));
$component->setSize(60, 15);
echo $component->render();
