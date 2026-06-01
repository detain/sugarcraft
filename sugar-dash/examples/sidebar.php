<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Layout\Sidebar;
use SugarCraft\Dash\Layout\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Sidebar menu
$component = Sidebar::new([["label" => "Home"], ["label" => "Profile"], ["label" => "Settings"]]);
$component->setSize(60, 15);
echo $component->render();
