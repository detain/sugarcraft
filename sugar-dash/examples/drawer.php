<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Modal\Drawer;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Drawer panel
$component = Drawer::new(Text::new("Drawer Content"));
$component->setSize(60, 15);
echo $component->render();
